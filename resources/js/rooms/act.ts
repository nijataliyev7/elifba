/**
 * A seat's act in a match (docs/architecture.md §12 "Board games", ADR 034
 * decision 9): `POST /rooms/{room}/acts` `{event_id, kind, ply, path}` — a
 * move with its path, a draw offer or its answer at the ply the device
 * saw, or resigning. The event id is one ULID per tap (`eventId()`), sent
 * again unchanged on a retry, so an act that reached the server is stored
 * once. The receipt says only whether it was taken — `accepted`,
 * `duplicate` or `late` — and the match comes with the next state; a 422
 * is a path that is no legal move. The seat is the session's account: the
 * body names nobody.
 */
import { RoomActReceipt } from './contract';
import type { RoomAct } from './contract';
import type { Reply } from './http';
import { sendForReceipt } from './receipt';
import type { WriteOutcome } from './receipt';

export type ActOutcome = WriteOutcome<RoomActReceipt>;

/** Waits before each retry: a seat's clock runs, so an act is sent again soon. */
export const ACT_RETRY_MS = [300, 700, 1500] as const;

export function sendAct(options: {
    act: RoomAct;
    post: (act: RoomAct) => Promise<Reply>;
    wait: (ms: number) => Promise<void>;
    /** Whether the page still shows the ply the act was made at. */
    keepTrying: () => boolean;
}): Promise<ActOutcome> {
    return sendForReceipt({
        body: options.act,
        post: options.post,
        receipt: (body) => {
            const parsed = RoomActReceipt.safeParse(body);

            return parsed.success ? parsed.data : null;
        },
        wait: options.wait,
        retries: ACT_RETRY_MS,
        keepTrying: options.keepTrying,
    });
}
