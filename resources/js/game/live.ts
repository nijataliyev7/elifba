/**
 * The live link between the teacher's phone and the board
 * (docs/architecture.md §4, client events, protocol v1). Both sides talk
 * over one private channel per game, `private-game.{id}`, with Reverb
 * client events; nothing here writes a result — the board's controller
 * does, through its outbox.
 *
 * - `LiveBoard` (the board): parses every event with its zod schema and
 *   drops what is malformed; a `client-hit` goes to
 *   `controller.touch(…, {source: 'remote'})` once per `event_id` and is
 *   acked with what it came to (a repeated `event_id` gets the same ack
 *   again, never a second touch); `client-student` and `client-control`
 *   drive the controller; `client-state` goes out on hello, after every
 *   change and every 5 s while a remote is around; "remote disconnected"
 *   after 10 s without a ping.
 * - `LiveRemote` (the phone): `client-hello` on every (re)subscription; a
 *   tap is pending until its ack and is sent once more, with the same
 *   `event_id`, after 1 s without one; `client-state` with a lower epoch
 *   than the last one is ignored (an older board); a ping every 5 s;
 *   "board not connected" after 10 s without a state; `ack_ms` samples.
 *
 * Both devices are the same teacher, so presence cannot tell them apart
 * (ADR 002): pings and states do. The channel comes from outside
 * (`socket.ts` adapts Echo; a guest board passes its own, with its bearer),
 * so this module never loads Echo.
 */
import type { BoardController, BoardPhase, BoardView } from './board';
import type {
    AckStatus,
    ClientState as ClientStatePayload,
    ControlAction,
    SessionStatus,
} from './contract';
import {
    ClientAck,
    ClientControl,
    ClientHello,
    ClientHit,
    ClientPing,
    ClientState,
    ClientStudent,
    SessionStatusEvent,
} from './contract';
import type { Timers } from './outbox';
import { ulid } from './ulid';

/** Client events by name; on the wire each is `client-{name}`. */
export type ClientEventName =
    | 'hello'
    | 'hit'
    | 'student'
    | 'control'
    | 'ping'
    | 'state'
    | 'ack';

export type SocketState = 'connecting' | 'connected' | 'disconnected';

/**
 * One game's channel as this module sees it. `gameChannel()` in
 * `socket.ts` adapts an Echo private channel; tests use a fake.
 */
export type LiveChannel = {
    /** Send a client event to the other members (never back to the sender). */
    whisper: (name: ClientEventName, payload: Record<string, unknown>) => void;
    onWhisper: (
        name: ClientEventName,
        listener: (payload: unknown) => void,
    ) => void;
    /** The server's `session.status` broadcast. */
    onStatus: (listener: (payload: unknown) => void) => void;
    /** Every successful subscription, the first and each one after a reconnect. */
    onSubscribed: (listener: () => void) => void;
    onSocket: (listener: (state: SocketState) => void) => void;
    close: () => void;
};

export const PING_MS = 5000;
export const STATE_MS = 5000;
/** Without a ping (board) or a state (remote) this long, the other side is gone. */
export const LOST_MS = 10_000;
export const RETRY_MS = 1000;
/** How long an answered tap stays on the phone's button. */
export const TAP_LINGER_MS = 1500;
/** `event_id`s the board remembers, to answer a retry without touching again. */
const ACKS_KEPT = 256;
const SAMPLES_KEPT = 500;

const defaultTimers: Timers = {
    set: (callback, ms) => setTimeout(callback, ms),
    clear: (handle) => clearTimeout(handle as ReturnType<typeof setTimeout>),
};

/** One timer that is set again or cleared as a whole. */
class Timer {
    private handle: unknown = null;

    constructor(private readonly timers: Timers) {}

    get armed(): boolean {
        return this.handle !== null;
    }

    set(callback: () => void, ms: number): void {
        this.clear();
        this.handle = this.timers.set(() => {
            this.handle = null;
            callback();
        }, ms);
    }

    clear(): void {
        if (this.handle !== null) {
            this.timers.clear(this.handle);
            this.handle = null;
        }
    }
}

/** A small store for React's `useSyncExternalStore`. */
class Store<View> {
    private readonly listeners = new Set<() => void>();

    constructor(protected view: View) {}

    subscribe = (listener: () => void): (() => void) => {
        this.listeners.add(listener);

        return () => {
            this.listeners.delete(listener);
        };
    };

    getView = (): View => this.view;

    protected update(change: Partial<View>): void {
        this.view = { ...this.view, ...change };

        for (const listener of this.listeners) {
            listener();
        }
    }

    protected clearListeners(): void {
        this.listeners.clear();
    }
}

// The board.

/** none: no remote since the page loaded; lost: 10 s without a ping. */
export type RemoteLink = 'none' | 'connected' | 'lost';

export type LiveBoardView = {
    remote: RemoteLink;
    /**
     * The pairing card is wanted: on load, and when the teacher or the
     * phone asks for it again; a hello hides it.
     */
    pairing: boolean;
    socket: SocketState;
};

export type LiveBoardTarget = Pick<
    BoardController,
    | 'touch'
    | 'setStudent'
    | 'skip'
    | 'pause'
    | 'resume'
    | 'end'
    | 'statusChanged'
    | 'getView'
    | 'subscribe'
>;

export type LiveBoardOptions = {
    channel: LiveChannel;
    board: LiveBoardTarget;
    timers?: Timers;
    /** Show the pairing card from the start (default true). */
    pairing?: boolean;
};

export class LiveBoard extends Store<LiveBoardView> {
    private readonly channel: LiveChannel;
    private readonly board: LiveBoardTarget;
    private readonly acks = new Map<string, AckStatus>();
    private readonly lost: Timer;
    private readonly tick: Timer;
    private readonly unsubscribeBoard: () => void;
    private lastSent: string | null = null;
    private holdState = false;
    private disposed = false;

    constructor(options: LiveBoardOptions) {
        super({
            remote: 'none',
            pairing: options.pairing ?? true,
            socket: 'connecting',
        });

        const timers = options.timers ?? defaultTimers;

        this.channel = options.channel;
        this.board = options.board;
        this.lost = new Timer(timers);
        this.tick = new Timer(timers);

        this.channel.onWhisper('hello', (payload) => this.hello(payload));
        this.channel.onWhisper('hit', (payload) => this.hit(payload));
        this.channel.onWhisper('student', (payload) => this.student(payload));
        this.channel.onWhisper('control', (payload) => this.control(payload));
        this.channel.onWhisper('ping', (payload) => this.ping(payload));
        this.channel.onStatus((payload) => this.serverStatus(payload));
        this.channel.onSubscribed(() => {
            this.update({ socket: 'connected' });
            this.sendState(true);
        });
        this.channel.onSocket((socket) => {
            if (socket !== 'connected') {
                this.update({ socket });
            }
        });

        this.unsubscribeBoard = this.board.subscribe(() => {
            if (!this.holdState) {
                this.sendState(false);
            }
        });
    }

    /** «Pultu qoş» on the board, or the phone's `pairing`. */
    showPairing(): void {
        this.update({ pairing: true });
    }

    hidePairing(): void {
        this.update({ pairing: false });
    }

    dispose(): void {
        this.disposed = true;
        this.lost.clear();
        this.tick.clear();
        this.unsubscribeBoard();
        this.channel.close();
        this.clearListeners();
    }

    private hello(payload: unknown): void {
        if (!ClientHello.safeParse(payload).success || !this.speaking()) {
            return;
        }

        this.heard();
        this.update({ pairing: false });
        this.sendState(true);
    }

    private hit(payload: unknown): void {
        const parsed = ClientHit.safeParse(payload);

        if (!parsed.success || !this.speaking()) {
            return;
        }

        this.heard();

        const hit = parsed.data;
        let status = this.acks.get(hit.event_id);

        if (status === undefined) {
            // The state goes after the ack: the phone waits for the ack.
            this.holdState = true;

            try {
                status =
                    this.board.touch(
                        { roundId: hit.round_id, itemId: hit.item_id },
                        { source: 'remote', eventId: hit.event_id },
                    )?.status ?? 'stale';
            } finally {
                this.holdState = false;
            }

            this.remember(hit.event_id, status);
        }

        this.channel.whisper('ack', {
            v: 1,
            event_id: hit.event_id,
            status,
        });
        this.sendState(false);
    }

    private student(payload: unknown): void {
        const parsed = ClientStudent.safeParse(payload);

        if (!parsed.success || !this.speaking()) {
            return;
        }

        this.heard();
        this.board.setStudent(parsed.data.student_id);
    }

    private control(payload: unknown): void {
        const parsed = ClientControl.safeParse(payload);

        if (!parsed.success || !this.speaking()) {
            return;
        }

        this.heard();

        switch (parsed.data.action) {
            case 'pause':
                void this.board.pause();
                break;
            case 'resume':
                void this.board.resume();
                break;
            case 'skip':
                this.board.skip();
                break;
            case 'end':
                void this.board.end();
                break;
            case 'pairing':
                this.showPairing();
                break;
        }
    }

    private ping(payload: unknown): void {
        if (ClientPing.safeParse(payload).success && this.speaking()) {
            this.heard();
        }
    }

    private serverStatus(payload: unknown): void {
        const parsed = SessionStatusEvent.safeParse(payload);

        if (parsed.success) {
            this.board.statusChanged(parsed.data.status);
        }
    }

    /** A replaced board stays silent: the board that replaced it answers. */
    private speaking(): boolean {
        return !this.disposed && this.board.getView().phase !== 'replaced';
    }

    /** Any event from a remote: it is there. */
    private heard(): void {
        const first = this.view.remote === 'none';

        if (this.view.remote !== 'connected') {
            this.update({ remote: 'connected' });
        }

        this.lost.set(() => this.update({ remote: 'lost' }), LOST_MS);

        if (first) {
            this.scheduleTick();
        }
    }

    private scheduleTick(): void {
        this.tick.set(() => {
            this.sendState(true);
            this.scheduleTick();
        }, STATE_MS);
    }

    /**
     * The board's snapshot for the phone: on hello, after a change (only
     * when it differs from the last one sent) and every 5 s.
     */
    private sendState(force: boolean): void {
        if (!this.speaking()) {
            return;
        }

        const state = boardState(this.board.getView());

        if (state === null) {
            return;
        }

        const serialized = JSON.stringify(state);

        if (!force && serialized === this.lastSent) {
            return;
        }

        this.lastSent = serialized;
        this.channel.whisper('state', state);
    }

    private remember(eventId: string, status: AckStatus): void {
        this.acks.set(eventId, status);

        if (this.acks.size > ACKS_KEPT) {
            const oldest = this.acks.keys().next();

            if (!oldest.done) {
                this.acks.delete(oldest.value);
            }
        }
    }
}

const SILENT_PHASES: readonly BoardPhase[] = ['loading', 'replaced'];

/** `client-state` from the board's view; null while there is nothing to tell. */
export function boardState(view: BoardView): ClientStatePayload | null {
    if (SILENT_PHASES.includes(view.phase)) {
        return null;
    }

    const round = view.phase === 'playing' ? view.round : null;
    const progress = round === null ? null : view.progress;

    return {
        v: 1,
        board_epoch: view.epoch,
        status: view.status,
        round_id: round?.id ?? null,
        round_index: round?.index ?? null,
        student_id: view.studentId,
        hit_item_ids: [...(progress?.hit_item_ids ?? [])],
        misses: progress?.misses ?? 0,
        last_points: view.lastPoints,
    };
}

// The remote.

/** pending until the ack; lost when the retry got none either. */
export type TapStatus = 'pending' | AckStatus | 'lost';

export type Tap = {
    eventId: string;
    roundId: string;
    itemId: string;
    status: TapStatus;
    retried: boolean;
};

/** waiting: no state since the page loaded; lost: 10 s without one. */
export type BoardLink = 'waiting' | 'connected' | 'lost';

export type LiveRemoteView = {
    socket: SocketState;
    board: BoardLink;
    /** The board's last snapshot (an older board's is ignored). */
    state: ClientStatePayload | null;
    /** The game's status: the board's last word or the server's, whichever came last. */
    status: SessionStatus | null;
    taps: readonly Tap[];
    /** `client-hit` → `client-ack`, in ms, from the first send of each tap. */
    ackMs: readonly number[];
};

export type LiveRemoteOptions = {
    channel: LiveChannel;
    /** This phone's tab, for `client-hello`. */
    deviceId: string;
    timers?: Timers;
    now?: () => number;
    makeId?: () => string;
};

type SentTap = Tap & { hit: Record<string, unknown>; sentAt: number };

export class LiveRemote extends Store<LiveRemoteView> {
    private readonly channel: LiveChannel;
    private readonly timers: Timers;
    private readonly now: () => number;
    private readonly makeId: () => string;
    private readonly lost: Timer;
    private readonly pinger: Timer;
    private readonly sent = new Map<string, SentTap>();
    private readonly tapTimers = new Map<string, Timer>();
    private samples: number[] = [];
    private disposed = false;

    constructor(private readonly options: LiveRemoteOptions) {
        super({
            socket: 'connecting',
            board: 'waiting',
            state: null,
            status: null,
            taps: [],
            ackMs: [],
        });

        this.channel = options.channel;
        this.timers = options.timers ?? defaultTimers;
        this.now = options.now ?? Date.now;
        this.makeId = options.makeId ?? (() => ulid(this.now()));
        this.lost = new Timer(this.timers);
        this.pinger = new Timer(this.timers);

        this.channel.onSubscribed(() => this.subscribed());
        this.channel.onSocket((socket) => {
            // "Connected" counts once the channel is subscribed again.
            if (socket !== 'connected') {
                this.pinger.clear();
                this.update({ socket });
            }
        });
        this.channel.onWhisper('state', (payload) => this.state(payload));
        this.channel.onWhisper('ack', (payload) => this.ack(payload));
        this.channel.onStatus((payload) => this.serverStatus(payload));
    }

    /** The teacher tapped an item; null while the socket is down. */
    tap(roundId: string, itemId: string): string | null {
        if (this.disposed || this.view.socket !== 'connected') {
            return null;
        }

        const at = this.now();
        const eventId = this.makeId();
        const hit = {
            v: 1,
            event_id: eventId,
            round_id: roundId,
            item_id: itemId,
            client_at: new Date(at).toISOString(),
        };

        this.sent.set(eventId, {
            eventId,
            roundId,
            itemId,
            status: 'pending',
            retried: false,
            hit,
            sentAt: at,
        });
        this.timerFor(eventId).set(() => this.retry(eventId), RETRY_MS);
        this.channel.whisper('hit', hit);
        this.publishTaps();

        return eventId;
    }

    /** Who is at the board; shown at once, the board's next state confirms it. */
    setStudent(studentId: string | null): void {
        if (this.disposed) {
            return;
        }

        this.channel.whisper('student', { v: 1, student_id: studentId });

        if (this.view.state !== null) {
            this.update({
                state: { ...this.view.state, student_id: studentId },
            });
        }
    }

    control(action: ControlAction): void {
        if (!this.disposed) {
            this.channel.whisper('control', { v: 1, action });
        }
    }

    /** The phone changed the status over HTTP itself (the board was gone). */
    statusChanged(status: SessionStatus): void {
        this.update({ status });
    }

    dispose(): void {
        this.disposed = true;
        this.lost.clear();
        this.pinger.clear();

        for (const timer of this.tapTimers.values()) {
            timer.clear();
        }

        this.tapTimers.clear();
        this.channel.close();
        this.clearListeners();
    }

    private subscribed(): void {
        if (this.disposed) {
            return;
        }

        this.update({ socket: 'connected' });
        this.channel.whisper('hello', {
            v: 1,
            device_id: this.options.deviceId,
        });
        this.schedulePing();

        if (!this.lost.armed) {
            this.armLost();
        }
    }

    private schedulePing(): void {
        this.pinger.set(() => {
            if (this.view.socket !== 'connected') {
                return;
            }

            this.channel.whisper('ping', { v: 1, t: this.now() });
            this.schedulePing();
        }, PING_MS);
    }

    private armLost(): void {
        this.lost.set(() => this.update({ board: 'lost' }), LOST_MS);
    }

    private state(payload: unknown): void {
        const parsed = ClientState.safeParse(payload);

        if (!parsed.success || this.disposed) {
            return;
        }

        const state = parsed.data;
        const last = this.view.state;

        // An older board (another tab, before a reload) is not the board.
        if (last !== null && state.board_epoch < last.board_epoch) {
            return;
        }

        this.armLost();
        this.update({ state, status: state.status, board: 'connected' });

        // A new board (reloaded, or opened elsewhere) has not heard this
        // phone yet: hello hides its pairing card.
        if (last !== null && state.board_epoch > last.board_epoch) {
            this.channel.whisper('hello', {
                v: 1,
                device_id: this.options.deviceId,
            });
        }
    }

    private ack(payload: unknown): void {
        const parsed = ClientAck.safeParse(payload);

        if (!parsed.success || this.disposed) {
            return;
        }

        const tap = this.sent.get(parsed.data.event_id);

        // Another phone's tap, or the second ack of a retried one.
        if (tap === undefined || tap.status !== 'pending') {
            return;
        }

        this.samples = [...this.samples, this.now() - tap.sentAt].slice(
            -SAMPLES_KEPT,
        );
        tap.status = parsed.data.status;
        this.linger(tap.eventId);
        this.publishTaps({ ackMs: this.samples });
    }

    private serverStatus(payload: unknown): void {
        const parsed = SessionStatusEvent.safeParse(payload);

        if (parsed.success && !this.disposed) {
            this.update({ status: parsed.data.status });
        }
    }

    /** No ack after 1 s: the same event once more; no ack after that either: lost. */
    private retry(eventId: string): void {
        const tap = this.sent.get(eventId);

        if (tap === undefined || tap.status !== 'pending') {
            return;
        }

        if (tap.retried) {
            tap.status = 'lost';
            this.linger(eventId);
            this.publishTaps();

            return;
        }

        if (this.view.socket === 'connected') {
            this.channel.whisper('hit', tap.hit);
        }

        tap.retried = true;
        this.timerFor(eventId).set(() => this.retry(eventId), RETRY_MS);
        this.publishTaps();
    }

    private linger(eventId: string): void {
        this.timerFor(eventId).set(() => {
            this.sent.delete(eventId);
            this.tapTimers.delete(eventId);
            this.publishTaps();
        }, TAP_LINGER_MS);
    }

    private timerFor(eventId: string): Timer {
        let timer = this.tapTimers.get(eventId);

        if (timer === undefined) {
            timer = new Timer(this.timers);
            this.tapTimers.set(eventId, timer);
        }

        return timer;
    }

    private publishTaps(change: Partial<LiveRemoteView> = {}): void {
        this.update({
            ...change,
            taps: [...this.sent.values()].map(
                ({ eventId, roundId, itemId, status, retried }) => ({
                    eventId,
                    roundId,
                    itemId,
                    status,
                    retried,
                }),
            ),
        });
    }
}

/** Count, median and 95th percentile of `ack_ms` samples (nearest rank). */
export function ackStats(samples: readonly number[]): {
    count: number;
    p50: number | null;
    p95: number | null;
} {
    if (samples.length === 0) {
        return { count: 0, p50: null, p95: null };
    }

    const sorted = [...samples].sort((a, b) => a - b);
    const rank = (p: number) =>
        sorted[Math.min(sorted.length - 1, Math.ceil(p * sorted.length) - 1)];

    return { count: sorted.length, p50: rank(0.5), p95: rank(0.95) };
}
