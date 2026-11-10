/**
 * Which picture stands behind each item of a round, for the item styles that
 * draw one (`animal`, `fruit`; docs/architecture.md §2 "Figures"). Pure, so
 * Vitest checks it without Phaser (`figures.test.ts`).
 *
 * A picture behind an item reads as that item's picture. So an item the
 * server lists pictures for (`pack.pictures`) stands in front of one of
 * them: a word of the pack its own (the ostrich behind «dəvəquşu»), a letter
 * or a syllable a word that begins with it (the horse, the bear or the bee
 * behind «A», the apple behind «al»), chosen by the round id, never one
 * already behind another item, never the round's own picture
 * (`prompt.image`, build_word's question, compared by path since the
 * round's URL was written when the game started). A letter or syllable that
 * no word of the pack begins with stands alone rather than behind a wrong
 * animal. Only a word without a picture of its own — a sentence's other
 * words, a teacher's word — stands in front of one of the pack's figures
 * (`pack.style.figures`) that shows none of the round's words: a figure is
 * left out when one of its words (`word:inək`, sent with it) is what an item
 * says, or begins it — the part of the content key after the unit
 * (`word:quşlar`, `word:narı` in a sentence; the keys are lower case from
 * the server, nothing is cased here) — when it is a picture that shows one
 * of the round's items, and when it is the round's own picture. The rest
 * are shuffled by the round id, so a reload shows the same, and dealt in
 * order: no figure twice in a round until every other one is used.
 */
import type { Round } from './contract';

/** A figure as `BoardProps` sends it (`pack.style.figures`). */
export type Figure = {
    url: string;
    /** Content keys of the pack's words drawn with this picture. */
    words: string[];
};

/**
 * The pictures that show a content key (`pack.pictures`): a word's own, the
 * words that begin with a letter or a syllable.
 */
export type Pictures = Record<string, string[]>;

type FigureRound = {
    id: string;
    items: Pick<Round['items'][number], 'id' | 'content_key'>[];
    prompt: Pick<Round['prompt'], 'image'>;
};

/** The picture's URL for each item id, or null when no picture may stand behind it. */
export function chooseFigures(
    round: FigureRound,
    figures: Figure[],
    pictures: Pictures = {},
): Map<string, string | null> {
    const own = (key: string) =>
        Object.hasOwn(pictures, key) ? pictures[key] : null;
    const said = round.items.map((item) => valueOf(item.content_key));
    const question =
        round.prompt.image === null ? null : pathOf(round.prompt.image);
    const taken = new Set(
        [
            round.prompt.image,
            ...round.items.flatMap((item) => own(item.content_key) ?? []),
        ]
            .filter((url): url is string => url !== null)
            .map(pathOf),
    );
    const used = new Set<string>();
    const free = figures.filter(
        (figure) =>
            !taken.has(pathOf(figure.url)) &&
            !figure.words.some((word) =>
                said.some((text) => text.startsWith(valueOf(word))),
            ),
    );
    const dealt = shuffled(free, seed(round.id));
    let next = 0;

    return new Map(
        round.items.map((item) => {
            const shown = (own(item.content_key) ?? []).filter(
                (url) => pathOf(url) !== question,
            );

            if (shown.length > 0) {
                const picture = pick(shown, used, seed(round.id + item.id));

                used.add(picture);

                return [item.id, picture];
            }

            // A letter or a syllable no word begins with stands alone.
            if (!item.content_key.startsWith('word:')) {
                return [item.id, null];
            }

            const figure =
                dealt.length === 0 ? null : dealt[next % dealt.length].url;

            next += 1;

            return [item.id, figure];
        }),
    );
}

/** One of `candidates` (never empty) not used yet, from the seed's place on. */
function pick(candidates: string[], used: Set<string>, start: number): string {
    for (let step = 0; step < candidates.length; step++) {
        const candidate = candidates[(start + step) % candidates.length];

        if (!used.has(candidate)) {
            return candidate;
        }
    }

    return candidates[start % candidates.length];
}

/** The texture key a picture is loaded under. */
export function figureKey(url: string): string {
    return `figure:${url}`;
}

/** What a content key names, without its unit: `word:inək` → `inək`. */
function valueOf(key: string): string {
    return key.slice(key.indexOf(':') + 1);
}

/** A URL's path, whatever host, scheme or `?v=` it was written with. */
function pathOf(url: string): string {
    try {
        return new URL(url, 'http://board.invalid').pathname;
    } catch {
        return url;
    }
}

/** FNV-1a: a round id as a 32-bit seed. */
function seed(text: string): number {
    let hash = 0x811c9dc5;

    for (let index = 0; index < text.length; index++) {
        hash ^= text.charCodeAt(index);
        hash = Math.imul(hash, 0x01000193) >>> 0;
    }

    return hash;
}

/** Fisher–Yates driven by mulberry32, so one seed always gives one order. */
function shuffled<T>(list: T[], start: number): T[] {
    const copy = [...list];
    let state = start;
    const next = () => {
        state = (state + 0x6d2b79f5) >>> 0;

        let t = state;

        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };

    for (let index = copy.length - 1; index > 0; index--) {
        const other = Math.floor(next() * (index + 1));

        [copy[index], copy[other]] = [copy[other], copy[index]];
    }

    return copy;
}
