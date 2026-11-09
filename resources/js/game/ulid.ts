/**
 * Event ids for the board's hits and skips: ULIDs, as `/sync` requires
 * (`BoardEventsData::rules()`), so they sort by time and are unique without
 * asking the server. 48 bits of milliseconds and 80 random bits in
 * Crockford's base 32.
 */
const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

export function ulid(
    now: number = Date.now(),
    random: (bytes: Uint8Array<ArrayBuffer>) => Uint8Array = (bytes) =>
        crypto.getRandomValues(bytes),
): string {
    let time = '';
    let ms = Math.floor(now);

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
