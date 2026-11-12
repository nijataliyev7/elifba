/**
 * The board controller (docs/architecture.md §4): everything the board
 * decides, without React or Phaser. The page renders its `view` and the
 * scene draws through the bridge; A7's `live.ts` feeds remote hits into
 * `touch()` and reads the same view for `client-state`.
 *
 * - **Load.** Flush what this tab's outbox still holds (made under the
 *   epoch before the reload), `ClaimBoard` (a new epoch), `GET /state`,
 *   and resume at the first open round. A finished or abandoned game opens
 *   on its result.
 * - **A touch** is judged by the engine and played on the canvas in the
 *   same call (`bridge.applyHit`), then queued in the outbox with the
 *   student at the board; the server's answer only fills in the points.
 *   A stale touch is played (it does nothing) but never queued.
 * - **Two sources, one touch.** A touch on the same item from the other
 *   source (`board` / `remote`) within 800 ms of an accepted one is the
 *   same touch — a child on the wall and the teacher on the phone at once:
 *   `duplicate`, neither played nor queued (§4, "Duplicates"). The rule
 *   lives here because direct touches never pass through `live.ts`.
 * - **The next round** comes once the solved effect has played (1.6 s)
 *   and, when the scene reports a praise line due for the solved round
 *   (`bridge.praise`, §3 "Voice"), once that line is over and a short gap
 *   has passed; never later than `SOLVED_CAP_MS` after the solve, so a
 *   clip that never ends cannot stall the class. Held while paused.
 * - **Who is at the board** is board state, kept in `sessionStorage`;
 *   null is the whole class (no points). «Başla» and every change of it
 *   during play start a turn of the voice (`bridge.startTurn`, §3
 *   "Voice"); a game that ends under play says its end (`bridge.gameOver`).
 * - **Fencing.** A 409 `board_replaced`, or another tab of this browser
 *   claiming the same game, stops this board for good.
 */
import type { BoardBridge, HitEffect, ItemTouch, PraiseSignal } from './bridge';
import type {
    GameStatus,
    Round,
    RoundProgress,
    SessionStatus,
} from './contract';
import type { BoardGame, Tally } from './engine';
import {
    applyHit,
    applySkip,
    boardGame,
    currentRound,
    isOver,
    progressOf,
    tally,
} from './engine';
import type { BoardHttp } from './http';
import type { AnsweredEntry, OutboxState, StorageLike, Timers } from './outbox';
import { defaultBackoff, Outbox } from './outbox';
import { ulid } from './ulid';

export type BoardPhase =
    /** Flushing the stored outbox, claiming the board, loading the rounds. */
    | 'loading'
    /** Rounds loaded; waiting for «Başla». */
    | 'ready'
    | 'playing'
    /** Every round is over, or the game was ended: the result. */
    | 'finished'
    /** Another window claimed the board; this one stopped. */
    | 'replaced';

export type BoardNotice =
    /** The game could not load; it tries again. */
    | 'load_failed'
    /** «End» while answers are still unsaved. */
    | 'end_unsaved'
    /** A pause, resume or end did not reach the server. */
    | 'status_failed'
    /** The outbox is full; new answers are not saved. */
    | 'outbox_full';

export type BoardView = {
    phase: BoardPhase;
    status: SessionStatus;
    epoch: number;
    /** The round on the canvas (during the solved effect, the round just solved). */
    round: Round | null;
    progress: RoundProgress | null;
    tally: Tally | null;
    /** Any round already solved or skipped: «Davam et» rather than «Başla». */
    resumed: boolean;
    pending: number;
    retrying: boolean;
    /** Stars this game earned, as the server stored or returned them. */
    points: number;
    /** What the server gave the round solved last on this board (null: none yet, or no one credited). */
    lastPoints: number | null;
    studentId: string | null;
    busy: boolean;
    notice: BoardNotice | null;
};

export type TouchSource = 'board' | 'remote';

export type TouchOptions = {
    source?: TouchSource;
    /** The sender's event id (A7: the remote's `client-hit`). */
    eventId?: string;
};

/**
 * What `touch()` came to: the effect the scene played, or `duplicate` (the
 * other source touched the same item a moment ago; nothing played).
 */
export type TouchResult =
    | HitEffect
    | (Omit<HitEffect, 'status'> & { status: 'duplicate' });

/** Messages between the tabs of one browser (a `BroadcastChannel` in the page). */
export type TabChannel = {
    post: (message: unknown) => void;
    listen: (listener: (message: unknown) => void) => void;
    close: () => void;
};

export type BoardControllerOptions = {
    gameId: string;
    status: SessionStatus;
    epoch: number;
    /** Active students in class order, for "next". */
    studentIds: readonly string[];
    /** Points of the rounds solved before the page loaded, by round id (page props). */
    points?: Readonly<Record<string, number>>;
    http: BoardHttp;
    bridge: BoardBridge;
    storage: StorageLike | null;
    channel?: TabChannel | null;
    timers?: Timers;
    now?: () => number;
    makeId?: () => string;
    /** How long the solved effect plays before the next round. */
    solvedDelayMs?: number;
};

const SOLVED_DELAY_MS = 1600;

/** The pause between the praise line after a solved round and the next round. */
export const PRAISE_GAP_MS = 300;

/** The longest a solved round waits for its praise line before the next round comes. */
export const SOLVED_CAP_MS = 6000;

/** The wait after a solved round for the next one. */
type NextRound = {
    roundId: string;
    /** The solved effect has played. */
    effect: boolean;
    /** The praise line: none due, due (being or about to be said), or over and the gap after it passed. */
    line: 'none' | 'due' | 'said';
    /** `SOLVED_CAP_MS` has passed. */
    capped: boolean;
    handles: unknown[];
};

/** A touch from the other source this soon after an accepted one is the same touch (§4). */
export const CROSS_SOURCE_MS = 800;

export function boardStorageKey(
    gameId: string,
    what: 'outbox' | 'student',
): string {
    return `elifba.board.${gameId}.${what}`;
}

const defaultTimers: Timers = {
    set: (callback, ms) => setTimeout(callback, ms),
    clear: (handle) => clearTimeout(handle as ReturnType<typeof setTimeout>),
};

export class BoardController {
    private outbox: Outbox;
    private game: BoardGame | null = null;
    private shown: Round | null = null;
    private phase: BoardPhase = 'loading';
    private status: SessionStatus;
    private epoch: number;
    private studentId: string | null;
    private points: Record<string, number>;
    private lastPoints: number | null = null;
    /** The last accepted touch per round and item, for the cross-source rule. */
    private readonly accepted = new Map<
        string,
        { source: TouchSource; at: number }
    >();
    private busy = false;
    private notice: BoardNotice | null = null;
    private loading = true;
    /** The highest epoch another tab announced, heard even while loading. */
    private heardEpoch = 0;
    private loadAttempt = 0;
    private disposed = false;
    private readonly timers: Timers;
    private readonly now: () => number;
    private readonly makeId: () => string;
    private readonly handles = new Set<unknown>();
    private readonly listeners = new Set<() => void>();
    private readonly unsubscribeBridge: () => void;
    private readonly unsubscribePraise: () => void;
    /** The wait after a solved round, null when none. */
    private next: NextRound | null = null;
    private view: BoardView;

    constructor(private readonly options: BoardControllerOptions) {
        this.status = options.status;
        this.epoch = options.epoch;
        this.timers = options.timers ?? defaultTimers;
        this.now = options.now ?? Date.now;
        this.makeId = options.makeId ?? (() => ulid(this.now()));
        this.studentId = this.readStudent();
        this.points = { ...options.points };
        this.outbox = this.makeOutbox();
        this.unsubscribeBridge = options.bridge.onItemTouched((touch) => {
            this.touch(touch);
        });
        this.unsubscribePraise = options.bridge.onPraise((signal) =>
            this.praised(signal),
        );

        options.channel?.listen((message) => this.heard(message));

        this.view = this.buildView();
    }

    // The view, for React's useSyncExternalStore.

    subscribe = (listener: () => void): (() => void) => {
        this.listeners.add(listener);

        return () => {
            this.listeners.delete(listener);
        };
    };

    getView = (): BoardView => this.view;

    // Load.

    /** Flush the stored outbox, claim the board, load the rounds. */
    async load(): Promise<void> {
        this.loading = true;
        this.phase = 'loading';
        this.emit();

        try {
            await this.drainStored();

            if (this.disposed) {
                return;
            }

            const claimed = await this.options.http.claim();

            this.epoch = claimed.board_epoch;
            this.status = claimed.status;
            if (this.disposed) {
                return;
            }

            this.options.channel?.post({
                type: 'claimed',
                game: this.options.gameId,
                epoch: this.epoch,
            });

            const state = await this.options.http.state();

            if (this.disposed) {
                return;
            }

            this.game = boardGame(state);
            this.status = state.game.status;
            this.loading = false;
            this.loadAttempt = 0;
            this.notice = null;

            // Another tab claimed after this one while it was loading.
            if (this.heardEpoch > this.epoch) {
                this.replacedByTab();

                return;
            }

            this.shown = currentRound(this.game);
            this.phase =
                this.isFinal(this.status) || isOver(this.game)
                    ? 'finished'
                    : 'ready';
            this.emit();

            if (this.phase === 'finished') {
                void this.outbox.flush();
            }
        } catch {
            if (this.disposed) {
                return;
            }

            this.loadAttempt += 1;
            this.notice = 'load_failed';
            this.emit();
            this.later(
                () => void this.load(),
                defaultBackoff(this.loadAttempt),
            );
        }
    }

    /** «Başla»: the round appears and touches count. */
    start(): void {
        if (this.phase !== 'ready' || this.game === null) {
            return;
        }

        this.phase = 'playing';
        // A turn starts with the game on this board; a home game's
        // greeting, before the first prompt, only when nothing is answered.
        this.options.bridge.startTurn(this.studentId, !answered(this.game));
        this.showCurrent();
        this.options.bridge.setPaused(this.status === 'paused');
        this.emit();
    }

    // Play.

    /**
     * A touch on an item, from the canvas (`board`) or the remote
     * (`live.ts`). Judged and played at once; queued unless stale or a
     * cross-source duplicate. Ignored (null) while the board is not
     * playing or the game is paused.
     */
    touch(touch: ItemTouch, options: TouchOptions = {}): TouchResult | null {
        if (
            this.phase !== 'playing' ||
            this.status !== 'live' ||
            this.game === null
        ) {
            return null;
        }

        const source = options.source ?? 'board';
        const at = this.now();

        if (this.isOtherSourceEcho(touch, source, at)) {
            return { ...touch, status: 'duplicate', solved: false };
        }

        const outcome = applyHit(this.game, touch.roundId, touch.itemId);
        const effect: HitEffect = {
            roundId: touch.roundId,
            itemId: touch.itemId,
            status: outcome.verdict.status,
            solved:
                outcome.verdict.status === 'correct' && outcome.verdict.solved,
        };

        // The wait for the next round is there before the effect starts:
        // the scene reports its praise line while it plays it.
        if (effect.solved) {
            this.awaitNext(touch.roundId);
        }

        // The effect starts before anything else happens.
        this.options.bridge.applyHit(effect);

        if (effect.status === 'stale') {
            return effect;
        }

        this.game = outcome.game;
        this.accepted.set(touchKey(touch), { source, at });
        this.queue({
            type: 'hit',
            event_id: options.eventId ?? this.makeId(),
            round_id: touch.roundId,
            item_id: touch.itemId,
            source,
            student_id: this.studentId,
            client_at: new Date(at).toISOString(),
        });

        this.emit();

        return effect;
    }

    /** The teacher passes on the current round. */
    skip(): void {
        const round = this.game === null ? null : currentRound(this.game);

        if (
            this.phase !== 'playing' ||
            this.game === null ||
            round === null ||
            this.shown?.id !== round.id
        ) {
            return;
        }

        const outcome = applySkip(this.game, round.id);

        if (!outcome.skipped) {
            return;
        }

        this.game = outcome.game;
        this.queue({
            type: 'skip',
            event_id: this.makeId(),
            round_id: round.id,
            client_at: new Date(this.now()).toISOString(),
        });
        this.advance(round.id);
    }

    // Who is at the board.

    setStudent(studentId: string | null): void {
        const before = this.studentId;

        this.studentId =
            studentId !== null && this.options.studentIds.includes(studentId)
                ? studentId
                : null;
        this.writeStudent();

        // Another student at the board during play starts a new turn
        // («Başla» starts the first); nothing is said for the change.
        if (this.phase === 'playing' && this.studentId !== before) {
            this.options.bridge.startTurn(this.studentId, false);
        }

        this.emit();
    }

    /** The next student in class order; after the last, the first again. */
    nextStudent(): void {
        const ids = this.options.studentIds;

        if (ids.length === 0) {
            this.setStudent(null);

            return;
        }

        const at = this.studentId === null ? -1 : ids.indexOf(this.studentId);

        this.setStudent(ids[(at + 1) % ids.length]);
    }

    // Status.

    async pause(): Promise<void> {
        await this.changeStatus('paused');
    }

    async resume(): Promise<void> {
        await this.changeStatus('live');
    }

    /**
     * Ends the game: first every answer must be saved, because the server
     * never writes to a finished game.
     */
    async end(): Promise<boolean> {
        if (this.busy || this.phase === 'replaced') {
            return false;
        }

        this.busy = true;
        this.notice = null;
        this.emit();

        const flushed = await this.outbox.flush();

        if (flushed === 'replaced') {
            this.busy = false;
            this.emit();

            return false;
        }

        if (this.outbox.size > 0) {
            this.busy = false;
            this.notice = 'end_unsaved';
            this.emit();

            return false;
        }

        const result = await this.options.http.status('finished');

        this.busy = false;

        if (result.kind === 'failed') {
            this.notice = 'status_failed';
            this.emit();

            return false;
        }

        this.applyStatus(result.game);

        return true;
    }

    /**
     * A status the server announced on the channel (`session.status`):
     * changed by another device, or by the scheduler. While this board is
     * changing the status itself, its own answer decides.
     */
    statusChanged(status: SessionStatus): void {
        if (
            this.busy ||
            this.loading ||
            this.phase === 'replaced' ||
            status === this.status
        ) {
            return;
        }

        this.applyStatus({ status, board_epoch: this.epoch });
    }

    /** The browser is online again: send at once. */
    online(): void {
        if (this.outbox.size > 0) {
            void this.outbox.retryNow();
        }
    }

    dismissNotice(): void {
        this.notice = null;
        this.emit();
    }

    /**
     * Before this board goes away while the tab stays (a guest board's next
     * game, or closing it): send what the outbox holds now, once, without
     * waiting for its backoff. Resolves with what is left; the caller bounds
     * the wait. What is left stays in this tab's storage under this game's
     * key and is sent first if the game comes back to this tab.
     */
    async settle(): Promise<number> {
        if (this.outbox.size > 0 && !this.disposed) {
            await this.outbox.retryNow();
        }

        return this.outbox.size;
    }

    dispose(): void {
        this.disposed = true;
        this.next = null;
        this.outbox.dispose();
        this.unsubscribeBridge();
        this.unsubscribePraise();

        for (const handle of this.handles) {
            this.timers.clear(handle);
        }

        this.handles.clear();

        this.options.channel?.close();

        this.listeners.clear();
    }

    // Internals.

    private makeOutbox(): Outbox {
        return new Outbox({
            key: boardStorageKey(this.options.gameId, 'outbox'),
            storage: this.options.storage,
            send: (request) => this.options.http.sync(request),
            timers: this.timers,
            onResults: (answered, game) => this.answered(answered, game),
            onReplaced: (game) => this.replaced(game),
            onChange: (state) => this.outboxChanged(state),
        });
    }

    /**
     * Before the claim, send what this tab made under the previous epoch.
     * Resolves when the stored outbox is empty; if another window replaced
     * this board meanwhile, those answers cannot be written and a fresh
     * outbox starts.
     */
    private async drainStored(): Promise<void> {
        let attempt = 0;

        while (this.outbox.size > 0 && !this.disposed) {
            const result = await this.outbox.flush();

            if (result === 'replaced') {
                this.outbox = this.makeOutbox();
                this.emit();

                return;
            }

            if (result === 'retry') {
                attempt += 1;
                await new Promise<void>((resolve) => {
                    this.later(resolve, defaultBackoff(attempt));
                });
            }
        }
    }

    /** The other source touched this item within the window: the same touch. */
    private isOtherSourceEcho(
        touch: ItemTouch,
        source: TouchSource,
        at: number,
    ): boolean {
        for (const [key, last] of this.accepted) {
            if (at - last.at >= CROSS_SOURCE_MS) {
                this.accepted.delete(key);
            }
        }

        const last = this.accepted.get(touchKey(touch));

        return last !== undefined && last.source !== source;
    }

    private queue(event: unknown): void {
        if (!this.outbox.push(this.epoch, event)) {
            this.notice = this.outbox.state.stopped ? null : 'outbox_full';
        }
    }

    /**
     * After a solved round: the next one waits for the effect, for the
     * praise line when the scene reports one due, and never longer than
     * the cap.
     */
    private awaitNext(roundId: string): void {
        this.dropNext();

        const next: NextRound = {
            roundId,
            effect: false,
            line: 'none',
            capped: false,
            handles: [],
        };

        this.next = next;
        next.handles.push(
            this.later(() => {
                next.effect = true;
                this.tryNext();
            }, this.solvedDelay()),
            this.later(() => {
                next.capped = true;
                this.tryNext();
            }, SOLVED_CAP_MS),
        );
    }

    /** The scene's praise line for a solved round: due, then over. */
    private praised(signal: PraiseSignal): void {
        const next = this.next;

        if (next === null || next.roundId !== signal.roundId) {
            return;
        }

        // A line reported after the round moved on finds nothing waiting.
        if (signal.state === 'due') {
            next.line = 'due';

            return;
        }

        if (next.line === 'due') {
            next.handles.push(
                this.later(() => {
                    next.line = 'said';
                    this.tryNext();
                }, PRAISE_GAP_MS),
            );
        }
    }

    /** The next round, once its wait is over and the game is not paused. */
    private tryNext(): void {
        const next = this.next;

        if (next === null) {
            return;
        }

        if (this.phase !== 'playing') {
            this.dropNext();

            return;
        }

        const over = next.capped || (next.effect && next.line !== 'due');

        if (!over || this.status === 'paused') {
            return;
        }

        this.dropNext();
        this.advance(next.roundId);
    }

    private dropNext(): void {
        for (const handle of this.next?.handles ?? []) {
            this.clearLater(handle);
        }

        this.next = null;
    }

    private advance(fromRoundId: string): void {
        if (this.phase !== 'playing' || this.game === null) {
            return;
        }

        // Only move on from the round still on the canvas.
        if (this.shown !== null && this.shown.id !== fromRoundId) {
            return;
        }

        if (isOver(this.game)) {
            this.finish();

            return;
        }

        this.showCurrent();
        this.emit();
    }

    private showCurrent(): void {
        if (this.game === null) {
            return;
        }

        const round = currentRound(this.game);

        this.shown = round;

        if (round !== null) {
            this.options.bridge.showRound(
                round,
                progressOf(this.game, round.id),
            );
        }
    }

    private finish(): void {
        const played = this.phase === 'playing';

        this.phase = 'finished';
        this.dropNext();
        this.options.bridge.setPaused(true);

        // The end is said only when the game ends under play on this board.
        if (played && this.game !== null) {
            this.options.bridge.gameOver(tally(this.game).solved);
        }

        this.emit();
        void this.outbox.flush();
    }

    private async changeStatus(next: 'live' | 'paused'): Promise<void> {
        if (
            this.phase !== 'playing' ||
            this.busy ||
            this.isFinal(this.status)
        ) {
            return;
        }

        // Input stops (or starts) at once; the server is told after. A
        // next round held by the pause comes now.
        this.status = next;
        this.options.bridge.setPaused(next === 'paused');
        this.tryNext();
        this.busy = true;
        this.notice = null;
        this.emit();

        const result = await this.options.http.status(next);

        this.busy = false;

        if (result.kind === 'failed') {
            this.notice = 'status_failed';
            this.emit();

            return;
        }

        this.applyStatus(result.game);
    }

    /** The server's word on the game's status. */
    private applyStatus(game: GameStatus): void {
        this.status = game.status;

        if (
            this.isFinal(game.status) &&
            (this.phase === 'playing' || this.phase === 'ready')
        ) {
            // After the last round the server finishes the game itself, often
            // before the solved effect is over; `advance()` then shows the result.
            if (
                this.phase === 'playing' &&
                this.game !== null &&
                isOver(this.game)
            ) {
                this.emit();

                return;
            }

            this.finish();

            return;
        }

        if (this.phase === 'playing') {
            this.options.bridge.setPaused(game.status !== 'live');
            this.tryNext();
        }

        this.emit();
    }

    private answered(answered: AnsweredEntry[], game: GameStatus): void {
        // Keyed by round: a `duplicate` answer repeats the points of the
        // round it solved and must not count twice.
        for (const { entry, result } of answered) {
            if (entry.event.type === 'hit' && result.points !== null) {
                this.points[entry.event.round_id] = result.points;
            }

            if (entry.event.type === 'hit' && result.round_solved) {
                this.lastPoints = result.points;
            }
        }

        // A status the board did not cause (A7: ended from the phone).
        if (this.isFinal(game.status) && !this.isFinal(this.status)) {
            this.applyStatus(game);

            return;
        }

        this.emit();
    }

    private replaced(game: GameStatus): void {
        if (this.loading) {
            return;
        }

        this.phase = 'replaced';
        this.status = game.status;
        this.dropNext();
        this.options.bridge.setPaused(true);
        this.emit();
    }

    /** Another tab of this browser claimed the same game. */
    private heard(message: unknown): void {
        if (
            typeof message !== 'object' ||
            message === null ||
            !('type' in message) ||
            message.type !== 'claimed' ||
            !('game' in message) ||
            message.game !== this.options.gameId ||
            !('epoch' in message) ||
            typeof message.epoch !== 'number'
        ) {
            return;
        }

        this.heardEpoch = Math.max(this.heardEpoch, message.epoch);

        // While loading, the claim's answer decides (`load()` compares).
        if (message.epoch <= this.epoch || this.loading) {
            return;
        }

        this.replacedByTab();
    }

    private replacedByTab(): void {
        this.phase = 'replaced';
        this.dropNext();
        this.options.bridge.setPaused(true);
        this.emit();
        // What is still queued would be refused (409); this clears it.
        void this.outbox.retryNow();
    }

    private outboxChanged(state: OutboxState): void {
        if (state.size === 0 && this.notice === 'outbox_full') {
            this.notice = null;
        }

        this.emit();
    }

    private isFinal(status: SessionStatus): boolean {
        return status === 'finished' || status === 'abandoned';
    }

    private solvedDelay(): number {
        return this.options.solvedDelayMs ?? SOLVED_DELAY_MS;
    }

    private later(callback: () => void, ms: number): unknown {
        const handle = this.timers.set(() => {
            this.handles.delete(handle);
            callback();
        }, ms);

        this.handles.add(handle);

        return handle;
    }

    private clearLater(handle: unknown): void {
        if (this.handles.delete(handle)) {
            this.timers.clear(handle);
        }
    }

    private emit(): void {
        this.view = this.buildView();

        for (const listener of this.listeners) {
            listener();
        }
    }

    private buildView(): BoardView {
        const game = this.game;
        const outbox = this.outbox.state;

        return {
            phase: this.phase,
            status: this.status,
            epoch: this.epoch,
            round: this.shown,
            progress:
                game !== null && this.shown !== null
                    ? progressOf(game, this.shown.id)
                    : null,
            tally: game === null ? null : tally(game),
            resumed: game !== null && answered(game),
            pending: outbox.size,
            retrying: outbox.retrying,
            points: Object.values(this.points).reduce((a, b) => a + b, 0),
            lastPoints: this.lastPoints,
            studentId: this.studentId,
            busy: this.busy,
            notice: this.notice,
        };
    }

    private readStudent(): string | null {
        const stored = this.read(
            boardStorageKey(this.options.gameId, 'student'),
        );

        return stored !== null && this.options.studentIds.includes(stored)
            ? stored
            : null;
    }

    private writeStudent(): void {
        const key = boardStorageKey(this.options.gameId, 'student');

        try {
            if (this.studentId === null) {
                this.options.storage?.removeItem(key);
            } else {
                this.options.storage?.setItem(key, this.studentId);
            }
        } catch {
            // Storage blocked: the choice lives until the page goes.
        }
    }

    private read(key: string): string | null {
        try {
            return this.options.storage?.getItem(key) ?? null;
        } catch {
            return null;
        }
    }
}

/** Any round already solved, skipped or hit: «Davam et» rather than «Başla». */
function answered(game: BoardGame): boolean {
    return Object.values(game.progress).some(
        (p) => p.solved || p.skipped || p.hit_item_ids.length > 0,
    );
}

function touchKey(touch: ItemTouch): string {
    return `${touch.roundId}\u0000${touch.itemId}`;
}
