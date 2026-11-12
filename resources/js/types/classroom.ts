/** A class row on the classes page, as `ClassController@index` sends it. */
export type ClassSummary = {
    id: string;
    name: string;
    grade: number | null;
    students_count: number;
};

/** The class on its own page (`ClassController@show`). */
export type SchoolClass = {
    id: string;
    name: string;
    grade: number | null;
    archived: boolean;
    /** Lowercase letters the class has learned, in alphabet order. */
    learned_letters: string[];
};

export type Student = {
    id: string;
    display_name: string;
    /** Key from `content/avatars.json`. */
    avatar: string;
};

/**
 * An active student on the class page: whether the board says the name
 * aloud (ADR 016 decision 10, derived from the name and Elifba's name
 * list), null while the account says no name (names or praise off).
 */
export type ActiveStudent = Student & {
    has_voice: boolean | null;
    /** The name as the list spells a first name typed otherwise («Lale» → «Lalə»), or null. */
    voice_spelling: string | null;
};

/** An avatar of `content/avatars.json` with its picture's URL (`Avatars::all()`). */
export type Avatar = {
    key: string;
    url: string;
};

/** One letter of the alphabet, both cases made by the server. */
export type Letter = {
    lower: string;
    upper: string;
};

export type PastedNameFlag =
    | 'invalid'
    | 'duplicate'
    | 'surname_first'
    | 'initial_added'
    | 'in_class'
    | 'taken';

/** One line of the paste preview (`PastedNameData::toArray()`). */
export type PastedName = {
    line: number;
    /** The line as understood; may hold a surname — kept only in memory. */
    text: string;
    display_name: string | null;
    flags: PastedNameFlag[];
    addable: boolean;
};
