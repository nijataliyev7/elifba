import type { Avatar, Letter } from './classroom';

/*
 * Page props of the welcome page (`WelcomeController`, frontend.md rule 10).
 * Every fact comes from the content and the code on the server.
 */

/** One round the wall plays (`Play\Support\SampleRounds`). */
export type SampleRound = {
    /** The prompt as the board shows it, cased on the server. */
    prompt: string;
    /** The target as text, shown in the prompt's frame. */
    show: string | null;
    items: { id: string; text: string }[];
    /** The item ids that solve the round. */
    solution: string[];
};

export type Sample = {
    /** `item` is the pack's item style (`balloon`, `card`…), drawn simply. */
    pack: { key: string; title: string; item: string; palette: string[] };
    rounds: SampleRound[];
};

export type WelcomeProps = {
    /** `elifba.source_url`. */
    sourceUrl: string;
    /** Mechanic keys, named by `games.mechanics.*`. */
    mechanics: string[];
    /** Every pack, title in the interface language. */
    packs: { key: string; title: string; palette: string[] }[];
    /** Keys of the packs a child who cannot read yet plays (picture or number levels). */
    picturePacks: string[];
    /** The alphabet in its order. */
    letters: Letter[];
    /** The first avatars of `content/avatars.json`. */
    avatars: Avatar[];
    /** Null when the content has no letter level that `find` plays. */
    sample: Sample | null;
};
