import type {
    RoomAsk,
    RoomLetterPrice,
    RoomLetters,
    RoomMine,
} from '@/rooms/contract';

/*
 * How the word games are laid out and how their reveal reads (docs/
 * frontend.md rule 15, architecture §12 "Room games"), as plain
 * functions. The letters and the spellings are drawn as the server sent
 * them — cased there, shuffled there — and nothing here judges: whether
 * the viewer's word was right is `mine.correct`, the server's word.
 */

/**
 * The rows of an anagram's tiles: as few rows as `perRow` allows, the
 * tiles shared out evenly, so eight letters stand four over four rather
 * than five over three and no tile stands alone on a row.
 */
export function tileRows(
    count: number,
    perRow: number,
): { rows: number; perRow: number } {
    if (count <= 0) {
        return { rows: 0, perRow: 0 };
    }

    const rows = Math.ceil(count / Math.max(1, perRow));

    return { rows, perRow: Math.ceil(count / rows) };
}

/** The text size of a letter in the word's places: smaller only when a row of places is too narrow for it. */
export function slotText(places: number): 'text-2xl' | 'text-xl' {
    return places <= 9 ? 'text-2xl' : 'text-xl';
}

/** The letters of a list of ids, as the question's tiles write them. */
export function lettersOf(ask: RoomAsk, ids: string[]): string[] {
    const letters = new Map(ask.items.map((item) => [item.id, item.text]));

    return ids.map((id) => letters.get(id) ?? '');
}

/**
 * What an anagram's reveal says of the viewer's word:
 * - `same`: right, and the round's word — one row, «Düzgün · sizin»;
 * - `other`: right, and another word the letters spell — the round's word,
 *   and theirs under it, green, «Sizin · bu söz də düzgündür»;
 * - `wrong`: the server said wrong — theirs under the word, red;
 * - null: no answer (or a host who does not play): the word alone.
 *
 * Right or wrong is `mine.correct` alone; the words are compared whole
 * only to know whether there is a second row to draw, never to judge.
 */
export function anagramMine(
    ask: RoomAsk,
    solution: string[],
    mine: RoomMine | null,
): 'same' | 'other' | 'wrong' | null {
    if (mine === null) {
        return null;
    }

    if (!mine.correct) {
        return 'wrong';
    }

    return lettersOf(ask, mine.picked).join('') ===
        lettersOf(ask, solution).join('')
        ? 'same'
        : 'other';
}

/*
 * «Anaqram» from ADR 037 (docs/frontend.md rule 15 "«Anaqram» (ADR 037)"):
 * the orders «Qarışdır» cycles through, the places with the bought
 * letters locked in front, and the price as the state words it. Nothing
 * here shuffles, judges or prices: the orders, the bought tiles and the
 * price are the server's, and the page only counts its own taps of
 * «Qarışdır» and lays out what it was sent.
 */

/**
 * The orders of the tray's tiles that «Qarışdır» cycles through: once a
 * letter is bought, the buyer's own (`letters.orders`, of the tiles left;
 * `[]` once every letter is bought); else the question's (`ask.orders`);
 * else, for a round built before ADR 037, the items' one order.
 */
export function trayOrders(
    ask: RoomAsk,
    letters: RoomLetters | null,
): string[][] {
    if (letters !== null && letters.bought.length > 0) {
        return letters.orders;
    }

    if (ask.orders.length > 0) {
        return ask.orders;
    }

    return [ask.items.map((item) => item.id)];
}

/**
 * The order the tray shows after `turns` taps of «Qarışdır»: the next one
 * each time, and after the last the first again. Nothing is drawn: an
 * order is only ever one the server sent.
 */
export function trayOrder(orders: string[][], turns: number): string[] {
    if (orders.length === 0) {
        return [];
    }

    return orders[Math.max(0, Math.floor(turns)) % orders.length];
}

/** Whether «Qarışdır» has another order to show: two or more. */
export function canShuffle(orders: string[][]): boolean {
    return orders.length >= 2;
}

/**
 * The row an answer is made of: the bought tiles first, in the word's
 * order, then the viewer's own taps — never a bought tile twice.
 */
export function rowOf(bought: string[], own: string[]): string[] {
    return [...bought, ...own.filter((id) => !bought.includes(id))];
}

/** A place of the word: empty, a letter the viewer tapped, or a bought one, locked in it. */
export type AnagramPlace =
    | { kind: 'empty'; next: boolean }
    | { kind: 'tapped' | 'bought'; id: string; text: string };

/**
 * The word's places, one per letter (`ask.pick`): the bought letters in
 * the first places, then the viewer's own taps, then the empty places —
 * the first of them the next to fill.
 */
export function placesOf(
    ask: RoomAsk,
    bought: string[],
    own: string[],
): AnagramPlace[] {
    const row = rowOf(bought, own);
    const letters = lettersOf(ask, row);

    return Array.from({ length: ask.pick }, (_, index): AnagramPlace => {
        const id = row[index];

        if (id === undefined) {
            return { kind: 'empty', next: index === row.length };
        }

        return {
            kind: index < bought.length ? 'bought' : 'tapped',
            id,
            text: letters[index],
        };
    });
}

/**
 * The next letter's price as the page says it — the key of the state's
 * word (`half`, `rest`, `none`), never a number — or null when there is
 * nothing to buy: no letters of the viewer's (a round that sells none, a
 * viewer who does not play) or every letter already bought.
 */
export function priceKey(
    letters: RoomLetters | null,
): `rooms.anagram.price.${RoomLetterPrice}` | null {
    if (letters === null || letters.price === null) {
        return null;
    }

    return `rooms.anagram.price.${letters.price}`;
}
