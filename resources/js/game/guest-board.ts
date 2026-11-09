/**
 * The guest board on a shared school computer (`/lovhe`,
 * docs/architecture.md §4, "Guest board"), without React: it opens the
 * board, keeps its bearer, asks what the board shows, and closes it. The
 * page renders its `view`; once a game is there, the page mounts the same
 * board as a teacher board (`board.ts`, `live.ts`), with `view.auth`.
 *
 * - **The secret** is answered once, by `POST /guest-boards`. It is kept
 *   only in this tab's `sessionStorage` (`elifba.guest-board`, as
 *   `{board_id, secret}`) and in memory, and sent only in the
 *   `Authorization` header: a reload of the tab continues with the same
 *   board, a new tab (or a closed one) starts with a new code.
 * - **Polling.** `GET /guest-boards/{board}` every 2 s while the board waits
 *   (a code, or a game that is over), every 10 s while it shows a live or
 *   paused game, and at once when a board request answers 401 or 404
 *   (`onRefused`). A 401 means the board was closed or has expired (or its
 *   unclaimed code ran out): the bearer is forgotten and a new board opens.
 * - **The next game.** Each game gets its own board (its own outbox key,
 *   epoch and tab fencing, all per game). Before the next game replaces the
 *   one on the board, `settle()` lets the old board flush its outbox; the
 *   page bounds the wait.
 */
import { z } from 'zod';
import {
    destroy as closeRoute,
    show as pollRoute,
    store as openRoute,
} from '@/routes/guest-boards';
import type { BoardProps } from '@/types';
import { GuestBoard, OpenedGuestBoard } from './contract';
import type { BoardAuth } from './http';
import { json, request, retryAfter } from './http';
import type { StorageLike, Timers } from './outbox';
import { defaultBackoff } from './outbox';

export const GUEST_BOARD_KEY = 'elifba.guest-board';

/** While the board waits for a game (or for the next one). */
export const WAITING_POLL_MS = 2000;

/** While it shows a live or paused game: a closed board or another game is noticed within this. */
export const PLAYING_POLL_MS = 10_000;

/** `onRefused` asks at once, but not more often than this. */
export const REFUSED_POLL_GAP_MS = 1000;

const StoredBoard = z.strictObject({
    board_id: z.ulid(),
    secret: z.string().regex(/^[A-Za-z0-9_-]{43}$/),
});

type Credentials = { boardId: string; token: string; auth: BoardAuth };

export type OpenResult =
    | { kind: 'ok'; board: GuestBoard; token: string }
    | { kind: 'failed'; afterMs?: number };

export type PollResult =
    | { kind: 'ok'; board: GuestBoard; props: BoardProps | null }
    /** Closed, expired or unknown: the bearer works no more. */
    | { kind: 'unauthorized' }
    | { kind: 'failed'; afterMs?: number };

export type GuestBoardHttp = {
    open: () => Promise<OpenResult>;
    poll: (boardId: string, auth: BoardAuth) => Promise<PollResult>;
    /** True once the board is closed (or was already gone). */
    close: (boardId: string, auth: BoardAuth) => Promise<boolean>;
};

export type GuestBoardPhase =
    /** Opening a board, or asking a stored one what it shows. */
    | 'opening'
    /** Unclaimed: the code and QR on the wall. */
    | 'waiting'
    /** A teacher's phone claimed it: their game, or the wait for the next one. */
    | 'claimed'
    /** «Lövhəni bağla» on this computer: nothing is kept. */
    | 'closed';

export type GuestBoardNotice = 'open_failed' | 'close_failed';

export type GuestBoardView = {
    phase: GuestBoardPhase;
    board: GuestBoard | null;
    /**
     * The game on the board, kept as first received while the board shows
     * it, so a poll never rebuilds the board.
     */
    props: BoardProps | null;
    /** The bearer for the board's requests and its channel; one object per board. */
    auth: BoardAuth | null;
    busy: boolean;
    notice: GuestBoardNotice | null;
};

export type GuestBoardClientOptions = {
    http: GuestBoardHttp;
    storage: StorageLike | null;
    timers?: Timers;
    now?: () => number;
};

const defaultTimers: Timers = {
    set: (callback, ms) => setTimeout(callback, ms),
    clear: (handle) => clearTimeout(handle as ReturnType<typeof setTimeout>),
};

export class GuestBoardClient {
    private credentials: Credentials | null = null;
    private phase: GuestBoardPhase = 'opening';
    private board: GuestBoard | null = null;
    private props: BoardProps | null = null;
    private busy = false;
    private notice: GuestBoardNotice | null = null;
    private timer: unknown = null;
    private polling = false;
    private opening = false;
    private openAttempt = 0;
    private lastPollAt = Number.NEGATIVE_INFINITY;
    private settle: (() => Promise<void>) | null = null;
    private disposed = false;
    private readonly timers: Timers;
    private readonly now: () => number;
    private readonly listeners = new Set<() => void>();
    private view: GuestBoardView;

    constructor(private readonly options: GuestBoardClientOptions) {
        this.timers = options.timers ?? defaultTimers;
        this.now = options.now ?? Date.now;
        this.view = this.buildView();
    }

    subscribe = (listener: () => void): (() => void) => {
        this.listeners.add(listener);

        return () => {
            this.listeners.delete(listener);
        };
    };

    getView = (): GuestBoardView => this.view;

    /** A stored board (a reload of this tab) continues; otherwise a new one opens. */
    start(): void {
        const stored = this.readStored();

        if (stored === null) {
            void this.open();

            return;
        }

        this.useCredentials(
            stored.board_id,
            `${stored.board_id}.${stored.secret}`,
        );
        void this.poll();
    }

    /** A board request was refused (401 / 404): ask what the board shows now. */
    pollNow(): void {
        if (this.credentials === null || this.polling || this.disposed) {
            return;
        }

        const wait = this.lastPollAt + REFUSED_POLL_GAP_MS - this.now();

        this.schedule(() => void this.poll(), Math.max(0, wait));
    }

    /**
     * The page's hook before the next game replaces the one on the board,
     * and before the board closes: flush what the board still holds.
     */
    setSettle(settle: (() => Promise<void>) | null): void {
        this.settle = settle;
    }

    /** «Lövhəni bağla»: the bearer stops working and this tab keeps nothing. */
    async close(): Promise<boolean> {
        const credentials = this.credentials;

        if (credentials === null || this.busy) {
            return false;
        }

        this.busy = true;
        this.notice = null;
        this.emit();

        await this.settle?.();

        const closed = await this.options.http.close(
            credentials.boardId,
            credentials.auth,
        );

        this.busy = false;

        if (this.disposed) {
            return false;
        }

        if (!closed) {
            this.notice = 'close_failed';
            this.emit();

            return false;
        }

        this.forget();
        this.phase = 'closed';
        this.emit();

        return true;
    }

    /** «Mənim deyil — yeni kod»: another teacher claimed it; close it and show a new code. */
    async notMine(): Promise<void> {
        if (await this.close()) {
            void this.open();
        }
    }

    /** After «Lövhəni bağla»: a new board with a new code. */
    reopen(): void {
        if (this.phase === 'closed') {
            void this.open();
        }
    }

    dismissNotice(): void {
        this.notice = null;
        this.emit();
    }

    dispose(): void {
        this.disposed = true;
        this.cancelTimer();
        this.listeners.clear();
    }

    // Internals.

    private async open(): Promise<void> {
        if (this.opening || this.disposed) {
            return;
        }

        this.opening = true;
        this.cancelTimer();
        this.phase = 'opening';
        this.emit();

        const result = await this.options.http.open();

        this.opening = false;

        if (this.disposed) {
            return;
        }

        if (
            result.kind === 'failed' ||
            !result.token.startsWith(`${result.board.id}.`)
        ) {
            this.openAttempt += 1;
            this.notice = 'open_failed';
            this.emit();
            this.schedule(
                () => void this.open(),
                (result.kind === 'failed' ? result.afterMs : undefined) ??
                    defaultBackoff(this.openAttempt),
            );

            return;
        }

        this.openAttempt = 0;
        this.notice = null;
        this.useCredentials(result.board.id, result.token);
        this.writeStored(result.board.id, result.token);
        this.board = result.board;
        this.phase = 'waiting';
        this.emit();
        this.schedule(() => void this.poll(), WAITING_POLL_MS);
    }

    private async poll(): Promise<void> {
        const credentials = this.credentials;

        if (credentials === null || this.polling || this.disposed) {
            return;
        }

        this.polling = true;
        this.cancelTimer();
        this.lastPollAt = this.now();

        try {
            const result = await this.options.http.poll(
                credentials.boardId,
                credentials.auth,
            );

            // Closed, or a new board opened, while the answer was on its way.
            if (this.disposed || credentials !== this.credentials) {
                return;
            }

            if (result.kind === 'unauthorized') {
                this.forget();
                void this.open();

                return;
            }

            if (result.kind === 'failed') {
                this.schedule(
                    () => void this.poll(),
                    result.afterMs ?? WAITING_POLL_MS,
                );

                return;
            }

            await this.apply(result.board, result.props);

            if (this.disposed || credentials !== this.credentials) {
                return;
            }

            const playing =
                result.board.game !== null &&
                (result.board.game.status === 'live' ||
                    result.board.game.status === 'paused');

            this.schedule(
                () => void this.poll(),
                playing ? PLAYING_POLL_MS : WAITING_POLL_MS,
            );
        } finally {
            this.polling = false;
        }
    }

    /** What the poll says the board shows. */
    private async apply(
        board: GuestBoard,
        props: BoardProps | null,
    ): Promise<void> {
        const credentials = this.credentials;
        const next =
            board.game !== null &&
            props !== null &&
            props.game.id === board.game.id
                ? props
                : null;

        this.board = board;
        this.phase = board.pairing !== null ? 'waiting' : 'claimed';

        if (next === null) {
            this.props = null;
        } else if (next.game.id !== this.props?.game.id) {
            // The next game: the board on the wall flushes first.
            if (this.props !== null) {
                this.emit();
                await this.settle?.();

                if (this.disposed || credentials !== this.credentials) {
                    return;
                }
            }

            this.props = next;
        }

        this.emit();
    }

    private useCredentials(boardId: string, token: string): void {
        this.credentials = {
            boardId,
            token,
            auth: {
                kind: 'bearer',
                token,
                onRefused: () => this.pollNow(),
            },
        };
    }

    /** The bearer works no more: this tab drops it and the board on the wall. */
    private forget(): void {
        this.cancelTimer();
        this.credentials = null;
        this.board = null;
        this.props = null;

        try {
            this.options.storage?.removeItem(GUEST_BOARD_KEY);
        } catch {
            // Storage blocked: nothing was kept.
        }
    }

    private readStored(): z.infer<typeof StoredBoard> | null {
        try {
            const raw = this.options.storage?.getItem(GUEST_BOARD_KEY) ?? null;

            if (raw === null) {
                return null;
            }

            const parsed = StoredBoard.safeParse(JSON.parse(raw));

            return parsed.success ? parsed.data : null;
        } catch {
            return null;
        }
    }

    private writeStored(boardId: string, token: string): void {
        try {
            this.options.storage?.setItem(
                GUEST_BOARD_KEY,
                JSON.stringify({
                    board_id: boardId,
                    secret: token.slice(boardId.length + 1),
                }),
            );
        } catch {
            // Storage blocked: a reload opens a new board.
        }
    }

    private schedule(callback: () => void, ms: number): void {
        this.cancelTimer();

        if (this.disposed) {
            return;
        }

        this.timer = this.timers.set(() => {
            this.timer = null;
            callback();
        }, ms);
    }

    private cancelTimer(): void {
        if (this.timer !== null) {
            this.timers.clear(this.timer);
            this.timer = null;
        }
    }

    private emit(): void {
        this.view = this.buildView();

        for (const listener of this.listeners) {
            listener();
        }
    }

    private buildView(): GuestBoardView {
        return {
            phase: this.phase,
            board: this.board,
            props: this.props,
            auth: this.credentials?.auth ?? null,
            busy: this.busy,
            notice: this.notice,
        };
    }
}

/** The props a poll carries are the teacher board's page props (`BoardProps`, PHP). */
function isBoardProps(value: unknown): value is BoardProps {
    if (typeof value !== 'object' || value === null) {
        return false;
    }

    const { game, students } = value as { game?: unknown; students?: unknown };

    return (
        typeof game === 'object' &&
        game !== null &&
        typeof (game as { id?: unknown }).id === 'string' &&
        Array.isArray(students)
    );
}

const PollResponse = z.strictObject({
    board: GuestBoard,
    props: z.custom<BoardProps>(isBoardProps).nullable(),
});

/** The guest board's own endpoints over `fetch`. */
export function guestBoardHttp(): GuestBoardHttp {
    return {
        async open() {
            let response: Response;

            try {
                response = await request('POST', openRoute.url());
            } catch {
                return { kind: 'failed' };
            }

            const parsed = OpenedGuestBoard.safeParse(await json(response));

            if (response.status === 201 && parsed.success) {
                return {
                    kind: 'ok',
                    board: parsed.data.board,
                    token: parsed.data.token,
                };
            }

            return { kind: 'failed', afterMs: retryAfter(response) };
        },

        async poll(boardId, auth) {
            let response: Response;

            try {
                response = await request(
                    'GET',
                    pollRoute.url(boardId),
                    undefined,
                    // The poll is the board's own question: a refusal is its answer.
                    withoutRefusal(auth),
                );
            } catch {
                return { kind: 'failed' };
            }

            if (response.status === 401) {
                return { kind: 'unauthorized' };
            }

            const parsed = PollResponse.safeParse(await json(response));

            return response.ok && parsed.success
                ? { kind: 'ok', ...parsed.data }
                : { kind: 'failed', afterMs: retryAfter(response) };
        },

        async close(boardId, auth) {
            try {
                const response = await request(
                    'DELETE',
                    closeRoute.url(boardId),
                    undefined,
                    withoutRefusal(auth),
                );

                return response.ok || response.status === 401;
            } catch {
                return false;
            }
        },
    };
}

function withoutRefusal(auth: BoardAuth): BoardAuth {
    return auth.kind === 'bearer'
        ? { kind: 'bearer', token: auth.token }
        : auth;
}
