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

/** One of the teacher's own words in the pack shown. */
export type CustomWord = {
    id: string;
    text: string;
    /** The syllables the word plays with, joined by the server (`al-ma`). */
    syllables: string;
    /** The teacher's override as typed back into the field, or null when the server splits it. */
    override: string | null;
    /** False when the pack now has this word built in, which plays instead. */
    played: boolean;
};

export type WordLimits = {
    max_length: number;
    max_per_teacher: number;
    /** The teacher's words over all packs. */
    count: number;
};
