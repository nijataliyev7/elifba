import type { BoardVoice } from '@/game/voice';
import type { Letter } from './classroom';

/*
 * Page props of the game pages (`Play\…` controllers). The round contract
 * (rounds, state, sync) is not here: it is zod in `@/game/contract`.
 */

/**
 * A pack on the setup page; titles in the interface language. `cover` is
 * the URL of the picture that stands for it (`style.cover`, one of its
 * words' pictures).
 */
export type SetupPack = {
    key: string;
    title: string;
    cover: string;
};

export type SetupUnit =
    | 'letter'
    | 'syllable'
    | 'word'
    | 'sentence'
    | 'picture'
    | 'number';

/** A level; `hint` is one line on what its rounds hold, built by the server («1–2 hərfli hecalar»). */
export type SetupLevel = {
    key: string;
    title: string;
    unit: SetupUnit;
    mechanics: string[];
    hint: string;
};

/**
 * Why a game cannot start, as the server says it: the message and its key
 * under `games.setup.errors.*` (`too_few_syllables`…); both null when it can.
 */
export type SetupRefusal = {
    startable: boolean;
    reason: string | null;
    code: string | null;
};

/**
 * One mechanic × pack × level the class could play, with the server's
 * reason when it cannot start (the check `GameSetupData::rules()` makes).
 */
export type SetupOption = SetupRefusal & {
    mechanic: string;
    pack: string;
    level: string;
    /** The board can draw this mechanic in this pack's look. */
    drawable: boolean;
    /**
     * The same answer for a game of the teacher's own words only
     * (`own_words`); null where such a game is not offered (letter and
     * sentence levels, `build_word`).
     */
    own: SetupRefusal | null;
};

/** What the setup form needs for one class or child (`GameSetupOptions::for()`). */
export type PlayersSetup = {
    options: SetupOption[];
    /** The learned letters, for the letter level's choice and the letter case. */
    letters: Letter[];
    /** True when the learned letters are all 32. */
    allLetters: boolean;
};

/** What every setup shares (`GameSetupOptions::shared()`). */
export type SharedSetup = {
    packs: SetupPack[];
    levels: SetupLevel[];
    letterCases: ('upper' | 'lower')[];
    rounds: { min: number; max: number; default: number };
    /** The longest teacher word, for adding one from the form. */
    wordMaxLength: number;
};

/**
 * `games/setup`: a class's game or a parent's child's home game (exactly one
 * of `schoolClass` and `child`, ADR 011), with the same form and rules.
 */
export type GameSetupProps = SharedSetup &
    PlayersSetup & {
        schoolClass: { id: string; name: string } | null;
        child: { id: string; display_name: string } | null;
    };

/**
 * A student for "who is at the board"; the avatar URL is null when the key
 * has no picture. `name_audio` is where the board fetches the clip of the
 * first name (`/games/{game}/players/{player}/voice`, ids only; ADR 016),
 * null when the board does not say the name.
 */
export type BoardStudent = {
    id: string;
    display_name: string;
    avatar: { key: string; url: string | null };
    name_audio: string | null;
};

/** The child at the board of a home game; `stars` is their total before this game. */
export type BoardChild = BoardStudent & { stars: number };

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
    /** Null in a home game (ADR 011). */
    schoolClass: { id: string; name: string } | null;
    /** The child of a home game, always the one at the board; null for a class. */
    child: BoardChild | null;
    pack: {
        key: string;
        title: string;
        style: {
            item: string;
            slot: string;
            effect: string;
            palette: string[];
            backdrop: string | null;
            /**
             * A URL per moment (docs/architecture.md §3 "Sounds"), null for
             * none: `hit`, `miss` and `solved` the owner's choice or the
             * pack's own; `arrive` and `leave` the pack's own (only `train`
             * has them).
             */
            sounds: {
                hit: string | null;
                miss: string | null;
                solved: string | null;
                arrive: string | null;
                leave: string | null;
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
    /** Active students in the class's order; empty in a home game. */
    students: BoardStudent[];
    /**
     * What the board says besides each round's `prompt.audio` (§3 "Voice",
     * `BoardVoice`, PHP): whether it says prompts at all, the praise and
     * retry clips (empty when the owner turned praise off), whether it says
     * names, and the greeting and end phrases (null with praise off).
     */
    voice: BoardVoice;
};

/** `/games/{game}/board`: the board's props and the teacher's own choice (Settings → Oyun). */
export type TeacherBoardProps = BoardProps & {
    phoneRemote: boolean;
};

/** `/games/{game}/remote`: the board's props and the guest board showing the game, if any. */
export type RemoteProps = BoardProps & {
    guestBoard: { id: string } | null;
};

/** A live or paused game of the account, as `/pult` lists it: a class's or a child's. */
export type PultGame = {
    id: string;
    status: 'live' | 'paused';
    mechanic: string;
    /** The level's title in the interface language and its unit (null for a level the content no longer has). */
    level: { title: string; unit: SetupUnit | null };
    schoolClass: { id: string; name: string } | null;
    child: { id: string; display_name: string } | null;
    started_at: string;
};

/**
 * `/pult/{code}` (claim an open guest board) and `/guest-boards/{board}/game`
 * (its next game): the account's live games, or a new game for one of its
 * active classes or children with that class's or child's setup
 * (`PultChoices`).
 */
export type GuestBoardChoicesProps = {
    code: string | null;
    board: { id: string } | null;
    games: PultGame[];
    classes: { id: string; name: string }[];
    children: { id: string; display_name: string }[];
    setup: SharedSetup & {
        classes: Record<string, PlayersSetup>;
        children: Record<string, PlayersSetup>;
    };
};
