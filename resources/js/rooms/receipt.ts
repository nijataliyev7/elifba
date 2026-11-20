/**
 * A device's own write to a room and its receipt (docs/architecture.md §12
 * "The race" → "An answer", "Taunts (T2)", "Board games"): an answer, a
 * taunt or a seat's act, each with one event id per tap that every retry
 * repeats, so a write that reached the server is stored once (`duplicate`
 * on the retry). The one loop the three share: the same body again while
 * nothing usable comes back (no connection, a server error, the limiter),
 * after each of `retries`, while `keepTrying()` says the page still shows
 * what it was for.
 */
import type { Reply } from './http';

export type WriteOutcome<Receipt> =
    | { kind: 'receipt'; receipt: Receipt }
    /** 422: refused as written; nothing was stored. */
    | { kind: 'refused' }
    /** 401, 403, 404, 419: removed, the room gone or closed, the session over. */
    | { kind: 'gone' }
    /** Nothing usable came back, and retrying no longer makes sense. */
    | { kind: 'failed' };

export async function sendForReceipt<Body, Receipt>(options: {
    body: Body;
    post: (body: Body) => Promise<Reply>;
    /** The receipt in a 200's body, or null when it is not one. */
    receipt: (body: unknown) => Receipt | null;
    wait: (ms: number) => Promise<void>;
    retries: readonly number[];
    keepTrying?: () => boolean;
}): Promise<WriteOutcome<Receipt>> {
    const keepTrying = options.keepTrying ?? (() => true);

    for (let attempt = 0; ; attempt++) {
        let reply: Reply | null = null;

        try {
            reply = await options.post(options.body);
        } catch {
            reply = null;
        }

        if (reply !== null) {
            if (reply.status === 200) {
                const receipt = options.receipt(reply.body);

                if (receipt !== null) {
                    return { kind: 'receipt', receipt };
                }
            } else if (reply.status === 422) {
                return { kind: 'refused' };
            } else if ([401, 403, 404, 419].includes(reply.status)) {
                return { kind: 'gone' };
            }
        }

        if (attempt >= options.retries.length || !keepTrying()) {
            return { kind: 'failed' };
        }

        await options.wait(options.retries[attempt]);

        if (!keepTrying()) {
            return { kind: 'failed' };
        }
    }
}
