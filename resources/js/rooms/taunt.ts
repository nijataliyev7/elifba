/**
 * A player's taunt (docs/architecture.md §12 "Taunts (T2)", ADR 029
 * decision 13): `POST /rooms/{room}/taunts` `{event_id, target_id, taunt}`.
 * The event id is one ULID per tap (`eventId()`), sent again unchanged on a
 * retry, so a taunt that reached the server is stored and paid for once.
 * The receipt says what was done — `accepted`, `duplicate`, `late`, `off`,
 * `cooling`, `no_points` — and the page reads its new balance and cooldown
 * from the state it asks for next. The thrower is the session's account:
 * the body never names it.
 */
import { RoomTauntReceipt } from './contract';
import type { RoomTaunt } from './contract';
import type { Reply } from './http';
import { sendForReceipt } from './receipt';
import type { WriteOutcome } from './receipt';

export type TauntOutcome = WriteOutcome<RoomTauntReceipt>;

/** Waits before each retry: a taunt is sent again soon, twice at most. */
export const TAUNT_RETRY_MS = [400, 1200] as const;

/**
 * Sends the taunt, and the same body again while nothing usable comes
 * back. A 422 is a rival who is no present player of the room any more.
 */
export function sendTaunt(options: {
    taunt: RoomTaunt;
    post: (taunt: RoomTaunt) => Promise<Reply>;
    wait: (ms: number) => Promise<void>;
}): Promise<TauntOutcome> {
    return sendForReceipt({
        body: options.taunt,
        post: options.post,
        receipt: (body) => {
            const parsed = RoomTauntReceipt.safeParse(body);

            return parsed.success ? parsed.data : null;
        },
        wait: options.wait,
        retries: TAUNT_RETRY_MS,
    });
}
