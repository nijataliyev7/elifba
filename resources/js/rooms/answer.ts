/**
 * A player's answer (docs/architecture.md §12 "The race" → "An answer",
 * ADR 021 decision 1): `POST /rooms/{room}/answers` `{event_id, round_id,
 * picked}`. The event id is one ULID per answer made on this device and
 * goes again unchanged with every retry, so an answer that reached the
 * server is stored once (`duplicate` on the retry). The receipt says only
 * whether the answer was taken — `accepted`, `duplicate`, `answered` (the
 * account answered the round already) or `late` — never whether it was
 * right: the page learns that from the reveal.
 */
import { RoomAnswerReceipt } from './contract';
import type { RoomAnswer } from './contract';
import type { Reply } from './http';
import { sendForReceipt } from './receipt';
import type { WriteOutcome } from './receipt';

const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

/**
 * A ULID: 48 bits of milliseconds and 80 random bits in Crockford's base
 * 32, lowercase as the server stores ids. The milliseconds are the
 * server's clock as the page estimates it, so the device's own clock
 * never leaves it.
 */
export function eventId(
    serverMs: number,
    random: (bytes: Uint8Array<ArrayBuffer>) => Uint8Array = (bytes) =>
        crypto.getRandomValues(bytes),
): string {
    let time = '';
    let ms = Math.max(0, Math.floor(serverMs));

    for (let i = 0; i < 10; i++) {
        time = ALPHABET[ms % 32] + time;
        ms = Math.floor(ms / 32);
    }

    const bytes = random(new Uint8Array(16));
    let rest = '';

    for (let i = 0; i < 16; i++) {
        rest += ALPHABET[bytes[i] % 32];
    }

    return time + rest;
}

export type AnswerOutcome = WriteOutcome<RoomAnswerReceipt>;

/** Waits before each retry: a lost answer is sent again soon, then less often. */
export const RETRY_MS = [500, 1000, 2000, 4000] as const;

/**
 * Sends the answer and retries the same body while nothing usable comes
 * back (no connection, a server error, the limiter) and `keepTrying()`
 * says the page still shows that round. A 422 is an answer that is not the
 * round's items in the count it asks.
 */
export function sendAnswer(options: {
    answer: RoomAnswer;
    post: (answer: RoomAnswer) => Promise<Reply>;
    wait: (ms: number) => Promise<void>;
    keepTrying: () => boolean;
}): Promise<AnswerOutcome> {
    return sendForReceipt({
        body: options.answer,
        post: options.post,
        receipt: (body) => {
            const parsed = RoomAnswerReceipt.safeParse(body);

            return parsed.success ? parsed.data : null;
        },
        wait: options.wait,
        retries: RETRY_MS,
        keepTrying: options.keepTrying,
    });
}
