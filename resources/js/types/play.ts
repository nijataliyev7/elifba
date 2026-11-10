import type { Letter } from './classroom';

/*
 * Page props of the game pages (`Play\…` controllers). The round contract
 * (rounds, state, sync) is not here: it is zod in `@/game/contract`.
 */

/** A pack on the setup page; titles in the interface language. */
export type SetupPack = {
    key: string;
    title: string;
    style: { item: string; effect: string; palette: string[] };
};

export type SetupLevel = {
    key: string;
    title: string;
    unit: 'letter' | 'syllable' | 'word' | 'sentence';
    mechanics: string[];
};

/**
 * One mechanic × pack × level the class could play, with the server's
 * reason when it cannot start (the check `GameSetupData::rules()` makes).
 */
export type SetupOption = {
    mechanic: string;
    pack: string;
    level: string;
    /** The board can draw this mechanic in this pack's look (A8.2: find in every pack). */
    drawable: boolean;
    startable: boolean;
    reason: string | null;
    /**
     * The same answer for a game of the teacher's own words only
     * (`own_words`); null where such a game is not offered (letter and
     * sentence levels, `build_word`).
     */
    own: { startable: boolean; reason: string | null } | null;
};

export type GameSetupProps = {
    schoolClass: { id: string; name: string };
    packs: SetupPack[];
    levels: SetupLevel[];
    options: SetupOption[];
    /** The class's learned letters, for a letter level's choice. */
    letters: Letter[];
    letterCases: ('upper' | 'lower')[];
    rounds: { min: number; max: number; default: number };
    /** The longest teacher word, for adding one from the form. */
    wordMaxLength: number;
};

/** A student for "who is at the board"; the avatar URL is null when the key has no picture. */
export type BoardStudent = {
    id: string;
    display_name: string;
    avatar: { key: string; url: string | null };
};

export type BoardProps = {
    game: {
        id: string;
        status: 'live' | 'paused' | 'finished' | 'abandoned';
        board_epoch: number;
        mechanic: string;
        level: string;
        letter_case: 'upper' | 'lower';
        rounds: number;
        /** Points of the rounds solved so far, by round id (an empty list when none). */
        points: Record<string, number> | [];
    };
    schoolClass: { id: string; name: string };
    pack: {
        key: string;
        title: string;
        style: {
            item: string;
            slot: string;
            effect: string;
            palette: string[];
            backdrop: string | null;
            sounds: {
                hit: string | null;
                miss: string | null;
                solved: string | null;
            };
            /**
             * The pictures `animal` and `fruit` items that are no word of the
             * pack stand in front of, with the content keys of the pack's
             * words drawn with each; empty for other styles.
             */
            figures: { url: string; words: string[] }[];
        };
        /**
         * The pictures an `animal` or `fruit` item stands in front of, by
         * content key: a built-in word's own, and for each letter those of
         * the words that begin with it (`[]` when none does); empty for
         * other styles.
         */
        pictures: Record<string, string[]>;
    };
    /** Active students in the class's order. */
    students: BoardStudent[];
};

/** `/games/{game}/board`: the board's props and the teacher's own choice (Settings → Oyun). */
export type TeacherBoardProps = BoardProps & {
    phoneRemote: boolean;
};

/** `/games/{game}/remote`: the board's props and the guest board showing the game, if any. */
export type RemoteProps = BoardProps & {
    guestBoard: { id: string } | null;
};

/** A live or paused game of the teacher, as `/pult` lists it. */
export type PultGame = {
    id: string;
    status: 'live' | 'paused';
    mechanic: string;
    level: string;
    schoolClass: { id: string; name: string };
    started_at: string;
};

/**
 * `/pult/{code}` (claim an open guest board) and `/guest-boards/{board}/game`
 * (its next game): the teacher's live games, or a new game for one of
 * their active classes with that class's setup (`PultChoices`).
 */
export type GuestBoardChoicesProps = {
    code: string | null;
    board: { id: string } | null;
    games: PultGame[];
    classes: { id: string; name: string }[];
    setup: Omit<GameSetupProps, 'schoolClass' | 'options' | 'letters'> & {
        classes: Record<string, Pick<GameSetupProps, 'options' | 'letters'>>;
    };
};
