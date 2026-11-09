/**
 * The board's outbox (docs/architecture.md §4, "Reconnects"): every hit and
 * skip the board makes waits here until `POST /games/{game}/sync` has
 * answered for it. The board is the only writer of results, so nothing it
 * judged may be lost to a dropped connection or a reload:
 *
 * - entries are kept in order in `sessionStorage` (one key per game), so a
 *   reload in the same tab sends them before it claims the board again;
 * - at most `cap` (500) entries wait; past that a new event is refused
 *   rather than an older one dropped, because the server judges each event
 *   after the ones before it;
 * - every entry carries the board epoch it was made under, and one request
 *   sends at most `batchSize` (50) consecutive entries of one epoch;
 * - an event that fails its zod schema is never queued, and a 422 for a
 *   batch is narrowed down to the one event the server refuses, which is
 *   dropped: one bad event never blocks the rest;
 * - a failed request (offline, 5xx, 429 with `Retry-After`) is retried with
 *   backoff; `retryNow()` (the browser's `online` event) skips the wait;
 * - a 409 `board_replaced` stops the outbox for good: another window has
 *   claimed the board, its entries can never be written, and this board
 *   stops.
 */
import { z } from 'zod';
import type {
    AnswerResult,
    GameStatus,
    SyncRequest,
    SyncResponse,
} from './contract';
import { BoardEvent } from './contract';

export type OutboxEntry = { epoch: number; event: BoardEvent };

/** What one `POST /sync` came to, as the transport reports it. */
export type SendResult =
    | { kind: 'ok'; response: SyncResponse }
    | { kind: 'replaced'; game: GameStatus }
    | { kind: 'invalid' }
    | { kind: 'retry'; afterMs?: number };

export type StorageLike = Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>;

export type Timers = {
    set: (callback: () => void, ms: number) => unknown;
    clear: (handle: unknown) => void;
};

export type OutboxState = {
    /** Entries not yet answered by the server. */
    size: number;
    /** The last request failed and a retry is scheduled. */
    retrying: boolean;
    /** A 409 stopped the outbox. */
    stopped: boolean;
};

export type AnsweredEntry = { entry: OutboxEntry; result: AnswerResult };

export type OutboxOptions = {
    key: string;
    storage: StorageLike | null;
    send: (request: SyncRequest) => Promise<SendResult>;
    /** The server's answers, in order, and the game's status after them. */
    onResults?: (answered: AnsweredEntry[], game: GameStatus) => void;
    onReplaced?: (game: GameStatus) => void;
    onChange?: (state: OutboxState) => void;
    cap?: number;
    batchSize?: number;
    /** Milliseconds to wait before retry number `attempt` (1, 2, …). */
    backoff?: (attempt: number) => number;
    timers?: Timers;
};

export type FlushResult = 'empty' | 'flushed' | 'retry' | 'replaced';

export const OUTBOX_CAP = 500;
export const OUTBOX_BATCH = 50;

/** 1 s, 2 s, 4 s … at most 30 s. */
export function defaultBackoff(attempt: number): number {
    return Math.min(30_000, 1000 * 2 ** Math.max(0, attempt - 1));
}

const StoredEntry = z.strictObject({
    epoch: z.int().min(0),
    event: BoardEvent,
});

const defaultTimers: Timers = {
    set: (callback, ms) => setTimeout(callback, ms),
    clear: (handle) => clearTimeout(handle as ReturnType<typeof setTimeout>),
};

export class Outbox {
    private queue: OutboxEntry[];
    private readonly cap: number;
    private readonly batchSize: number;
    private readonly backoff: (attempt: number) => number;
    private readonly timers: Timers;
    private attempt = 0;
    private timer: unknown = null;
    private inFlight: Promise<FlushResult> | null = null;
    private stopped = false;
    private disposed = false;
    /** After a 422, entries still to be sent one by one. */
    private isolate = 0;

    constructor(private readonly options: OutboxOptions) {
        this.cap = options.cap ?? OUTBOX_CAP;
        this.batchSize = options.batchSize ?? OUTBOX_BATCH;
        this.backoff = options.backoff ?? defaultBackoff;
        this.timers = options.timers ?? defaultTimers;
        this.queue = this.load();
    }

    get size(): number {
        return this.queue.length;
    }

    get state(): OutboxState {
        return {
            size: this.queue.length,
            retrying: this.timer !== null,
            stopped: this.stopped,
        };
    }

    entries(): OutboxEntry[] {
        return this.queue.map((entry) => ({ ...entry }));
    }

    /**
     * Queues an event made under `epoch` and sends it soon. Returns false,
     * queuing nothing, when the event fails its schema, the outbox is full
     * or it has stopped.
     */
    push(epoch: number, event: unknown): boolean {
        const parsed = StoredEntry.safeParse({ epoch, event });

        if (
            !parsed.success ||
            this.stopped ||
            this.disposed ||
            this.queue.length >= this.cap
        ) {
            return false;
        }

        this.queue.push(parsed.data);
        this.persist();
        this.changed();

        if (this.timer === null) {
            void this.flush();
        }

        return true;
    }

    /**
     * Sends everything queued, batch by batch, until the queue is empty, a
     * request fails (a retry is then scheduled) or the board is replaced.
     * A call while a flush is running joins it; entries pushed meanwhile are
     * sent by the same run.
     */
    flush(): Promise<FlushResult> {
        if (this.stopped) {
            return Promise.resolve('replaced');
        }

        if (this.disposed) {
            return Promise.resolve('retry');
        }

        this.inFlight ??= this.run().finally(() => {
            this.inFlight = null;
        });

        return this.inFlight;
    }

    /** Retries at once, without waiting for the backoff (the browser is online again). */
    retryNow(): Promise<FlushResult> {
        this.cancelTimer();
        this.attempt = 0;

        return this.flush();
    }

    /** Stops sending and scheduling (the page goes away), keeping what is stored. */
    dispose(): void {
        this.cancelTimer();
        this.disposed = true;
    }

    private async run(): Promise<FlushResult> {
        this.cancelTimer();

        if (this.queue.length === 0) {
            return 'empty';
        }

        while (this.queue.length > 0) {
            if (this.stopped) {
                return 'replaced';
            }

            if (this.disposed) {
                return 'retry';
            }

            const batch = this.nextBatch();
            const result = await this.send(batch);

            if (this.stopped) {
                return 'replaced';
            }

            switch (result.kind) {
                case 'ok':
                    this.answered(batch, result.response);
                    break;
                case 'invalid':
                    this.refused(batch);
                    break;
                case 'replaced':
                    this.replaced(result.game);

                    return 'replaced';
                case 'retry':
                    this.scheduleRetry(result.afterMs);

                    return 'retry';
            }
        }

        return 'flushed';
    }

    private nextBatch(): OutboxEntry[] {
        const limit = this.isolate > 0 ? 1 : this.batchSize;
        const epoch = this.queue[0].epoch;
        const batch: OutboxEntry[] = [];

        for (const entry of this.queue) {
            if (entry.epoch !== epoch || batch.length >= limit) {
                break;
            }

            batch.push(entry);
        }

        return batch;
    }

    private async send(batch: OutboxEntry[]): Promise<SendResult> {
        try {
            return await this.options.send({
                board_epoch: batch[0].epoch,
                events: batch.map((entry) => entry.event),
            });
        } catch {
            return { kind: 'retry' };
        }
    }

    private answered(batch: OutboxEntry[], response: SyncResponse): void {
        const byId = new Map(
            response.results.map((result) => [result.event_id, result]),
        );
        const answered: AnsweredEntry[] = [];

        for (const entry of batch) {
            const result = byId.get(entry.event.event_id);

            if (result !== undefined) {
                answered.push({ entry, result });
            }
        }

        this.remove(batch.length);
        this.attempt = 0;
        this.isolate = Math.max(0, this.isolate - batch.length);
        this.options.onResults?.(answered, response.game);
    }

    /** A 422: narrow a batch down to single events, and drop a single one. */
    private refused(batch: OutboxEntry[]): void {
        if (batch.length > 1) {
            this.isolate = batch.length;

            return;
        }

        this.remove(1);
        this.isolate = Math.max(0, this.isolate - 1);
    }

    private replaced(game: GameStatus): void {
        this.stopped = true;
        this.cancelTimer();
        this.queue = [];
        this.persist();
        this.changed();
        this.options.onReplaced?.(game);
    }

    private scheduleRetry(afterMs?: number): void {
        if (this.disposed) {
            return;
        }

        this.attempt += 1;
        const wait = afterMs ?? this.backoff(this.attempt);

        this.timer = this.timers.set(() => {
            this.timer = null;
            void this.flush();
        }, wait);
        this.changed();
    }

    private cancelTimer(): void {
        if (this.timer !== null) {
            this.timers.clear(this.timer);
            this.timer = null;
        }
    }

    private remove(count: number): void {
        this.queue = this.queue.slice(count);
        this.persist();
        this.changed();
    }

    private changed(): void {
        this.options.onChange?.(this.state);
    }

    private load(): OutboxEntry[] {
        let raw: string | null = null;

        try {
            raw = this.options.storage?.getItem(this.options.key) ?? null;
        } catch {
            return [];
        }

        if (raw === null) {
            return [];
        }

        let stored: unknown;

        try {
            stored = JSON.parse(raw);
        } catch {
            return [];
        }

        if (!Array.isArray(stored)) {
            return [];
        }

        const entries: OutboxEntry[] = [];

        for (const item of stored) {
            const parsed = StoredEntry.safeParse(item);

            if (parsed.success && entries.length < this.cap) {
                entries.push(parsed.data);
            }
        }

        return entries;
    }

    private persist(): void {
        const storage = this.options.storage;

        try {
            if (this.queue.length === 0) {
                storage?.removeItem(this.options.key);
            } else {
                storage?.setItem(this.options.key, JSON.stringify(this.queue));
            }
        } catch {
            // Storage full or blocked: the queue lives on in memory.
        }
    }
}
