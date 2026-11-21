/**
 * A bought letter of an anagram's word (docs/architecture.md §12 "«Anaqram»:
 * a clue, a shuffle and a bought letter (ADR 037)", decision 12):
 * `POST /rooms/{room}/letters` `{event_id, round_id, position}`. The event
 * id is one ULID per tap (`eventId()`), sent again unchanged on a retry, so
 * a purchase that reached the server is stored once; the position is the
 * next letter's place in the word, so a second tap names a letter already
 * bought and buys nothing. The receipt says what was done — `accepted`,
 * `duplicate`, `bought`, `late` — and the page reads the tiles, its own
 * orders and the next price from the state it asks for next. The buyer is
 * the session's account: the body never names it, nor a letter.
 */
import { RoomLetterReceipt } from './contract';
import type { RoomLetter } from './contract';
import type { Reply } from './http';
import { sendForReceipt } from './receipt';
import type { WriteOutcome } from './receipt';

export type LetterOutcome = WriteOutcome<RoomLetterReceipt>;

/** Waits before each retry: a purchase is sent again soon, twice at most, while its round still takes answers. */
export const LETTER_RETRY_MS = [400, 1200] as const;

/**
 * Sends the purchase, and the same body again while nothing usable comes
 * back and `keepTrying()` says the round is still shown taking answers. A
 * 422 is a place the server will not sell (a round that sells none, a
 * place ahead of the letters bought).
 */
export function sendLetter(options: {
    letter: RoomLetter;
    post: (letter: RoomLetter) => Promise<Reply>;
    wait: (ms: number) => Promise<void>;
    keepTrying?: () => boolean;
}): Promise<LetterOutcome> {
    return sendForReceipt({
        body: options.letter,
        post: options.post,
        receipt: (body) => {
            const parsed = RoomLetterReceipt.safeParse(body);

            return parsed.success ? parsed.data : null;
        },
        wait: options.wait,
        retries: LETTER_RETRY_MS,
        keepTrying: options.keepTrying,
    });
}
