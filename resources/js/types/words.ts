/** A theme pack in the chooser (`WordController@index`), title in the interface language. */
export type WordPack = {
    key: string;
    title: string;
};

/** A built-in word of the pack shown, as the server sends it. */
export type BuiltInWord = {
    text: string;
    /** The syllables joined by the server (`al-ma`); shown as they are. */
    syllables: string;
    /** Whether this teacher hid it from their games. */
    hidden: boolean;
};

/** What one word gives a level: the word itself, or its syllables that fit. */
export type WordLevel = {
    /** The level's title in the interface language. */
    title: string;
    unit: 'word' | 'syllable';
    /** The word itself at a word level; its fitting syllables, lowercase, at a syllable level. */
    items: string[];
};

/** One of the teacher's own words; it plays in every theme pack. */
export type CustomWord = {
    id: string;
    text: string;
    /** The syllables the word plays with, joined by the server (`al-ma`). */
    syllables: string;
    /** The teacher's override as typed back into the field, or null when the server splits it. */
    override: string | null;
    /** The word and syllable levels it gives something, in content order. */
    levels: WordLevel[];
    /** Titles of the packs that have this word built in and play theirs, with its picture. */
    built_in_in: string[];
};

/** How many distinct items all of the teacher's words give one level. */
export type LevelTotal = {
    title: string;
    count: number;
};

export type WordLimits = {
    max_length: number;
    max_per_teacher: number;
    /** The teacher's words. */
    count: number;
};
