/**
 * What the board says, and when (docs/architecture.md §3 "Voice", ADR
 * 015, ADR 016). The clips are content files the server names: a round's
 * `prompt.audio`, and the praise, retry, greeting and end clips of the
 * board's props (`BoardVoice`, PHP), empty or null when the owner turned
 * praise off. A player's first name is a clip of its own that the board
 * fetches by ids (`name-voice.ts`); this module names the player, never
 * the name. It only decides; the scene plays (`board-scene.ts`, on the
 * page's one AudioContext), so Vitest can check every rule here.
 *
 * - The prompt is said once a round's items have arrived, but not when the
 *   round before had the same clip: «Hər hərfi oxu və vur!» is said once,
 *   not every round. A round shown already solved or skipped (a reload) is
 *   not said. The 🔊 button says it again whenever there is one.
 * - Praise, one of the phrases at random, follows a solved round. In a
 *   **turn** (one player at the board: it starts with «Başla» or «Davam
 *   et» and whenever the student at the board changes; a pause does not
 *   end it) the praise of the 1st, 4th, 7th … solved round comes after
 *   the player's name («Abbas! … Afərin!»), when the player is a chosen
 *   student or the home child, never the whole class (ADR 016 decision 6).
 * - A retry phrase, one of them at random, follows a wrong tap, at most
 *   once a round, never with a name.
 * - A home game's greeting («Zəhra! … Salam!») is said once, when «Başla»
 *   starts a game with no answered round; a class game has none.
 * - The end, once, after a game with a solved round: the home child's
 *   name, or a class game's student's when every round this board saw
 *   solved was theirs, then «Əla oynadın!»; «Əla oynadınız!» with no name
 *   when the whole class or several students played (ADR 016 decision 7).
 * - With the prompt switched off nothing says a prompt, not even 🔊; with
 *   praise off nothing else is said; with names off every phrase is said
 *   without a name; while the device is silent («Səssiz») nothing is said,
 *   and the turn still counts.
 */
import type { Round } from './contract';

/** The board's props `voice` (`BoardVoice::of()`, PHP). */
export type BoardVoice = {
    /** Whether the board says each round's `prompt.audio`. */
    prompt: boolean;
    /** Praise clips; empty when the owner turned praise off. */
    praise: string[];
    /** Retry clips; empty when the owner turned praise off. */
    retry: string[];
    /** Whether the board says a player's name (the name switch with praise on). */
    names: boolean;
    /** «Salam!»; null with praise off or before its clip is made. */
    greeting: string | null;
    /** «Əla oynadın!»; null likewise. */
    finish: string | null;
    /** «Əla oynadınız!»; null likewise. */
    finish_class: string | null;
};

export const SILENT: BoardVoice = {
    prompt: false,
    praise: [],
    retry: [],
    names: false,
    greeting: null,
    finish: null,
    finish_class: null,
};

/**
 * A player the board may name (props `students[]` and `child`): the URL of
 * their name's clip, ids only (`/games/{game}/players/{player}/voice`), or
 * null when the board does not say the name.
 */
export type VoicePlayer = { id: string; name_audio: string | null };

/** A player whose name has a clip to say. */
export type NamedPlayer = { id: string; name_audio: string };

/** Who may be at the board: a home game's child, or a class's students. */
export type VoicePlayers = {
    /** The home game's child, always the one at the board; null for a class. */
    child: VoicePlayer | null;
    students: readonly VoicePlayer[];
};

export const NO_PLAYERS: VoicePlayers = { child: null, students: [] };

/**
 * What the board says in a row on its one channel: a phrase and, when it
 * is due, a player's name before it, `NAME_GAP_MS` apart — «Abbas! …
 * Afərin!», «Abbas! … Salam!», «Abbas! … Əla oynadın!» (ADR 016 decision
 * 4, the order the owner chose by ear).
 */
export type VoiceLine = {
    phrase: string;
    /** The player whose name is said first; null for the phrase alone. */
    name: NamedPlayer | null;
};

/** The pause between a phrase and the name after it (ADR 016 decision 4, tuned by ear). */
export const NAME_GAP_MS = 150;

/** The praise of every this-many-th solved round of a turn carries the name, from the first. */
export const NAME_EVERY = 3;

/** Who solved a round while no student was chosen. */
const WHOLE_CLASS = '';

export class VoiceCues {
    /** The clip of the last round that arrived open, null for none. */
    private lastPrompt: string | null = null;
    private retried = new Set<string>();
    /** The player at the board this turn; null for the whole class. */
    private player: VoicePlayer | null;
    private solvedThisTurn = 0;
    /** Who solved the rounds this board saw solved (`WHOLE_CLASS` for nobody chosen). */
    private readonly solvers = new Set<string>();
    private greeted = false;
    private ended = false;
    private muted = false;

    constructor(
        private readonly voice: BoardVoice,
        private players: VoicePlayers = NO_PLAYERS,
        private readonly random: () => number = Math.random,
    ) {
        this.player = players.child;
    }

    /**
     * Who may be at the board now: a student added on the board
     * (architecture §4 "A student added on the board") has a name to say
     * from their first turn. The turn going on keeps its player.
     */
    setPlayers(players: VoicePlayers): void {
        this.players = players;
    }

    /**
     * The clip to say once the round's items have arrived, or null: not
     * when the round before had the same clip, so a round without one (a
     * teacher word) in between makes the next clip said again.
     */
    arrived(round: Round, open: boolean): string | null {
        if (!open) {
            return null;
        }

        const audio = this.voice.prompt ? round.prompt.audio : null;
        const before = this.lastPrompt;

        this.lastPrompt = audio;

        return audio !== null && audio !== before && !this.muted ? audio : null;
    }

    /** The clip 🔊 says: the round's prompt, when prompts are spoken. */
    repeat(round: Round | null): string | null {
        return this.voice.prompt && !this.muted
            ? (round?.prompt.audio ?? null)
            : null;
    }

    /**
     * A turn starts: «Başla» or «Davam et», or another student at the board
     * (null: the whole class; a home game's child is always at the board).
     * Returns the player whose name this turn may say, so the board can
     * fetch the clip now, or null.
     */
    turn(studentId: string | null): NamedPlayer | null {
        this.player =
            this.players.child ??
            this.players.students.find((student) => student.id === studentId) ??
            null;
        this.solvedThisTurn = 0;

        return this.named(this.player);
    }

    /**
     * The greeting when «Başla» starts the game: only at home, only when
     * no round is answered yet (`fresh`; not «Davam et» after a reload),
     * and once.
     */
    greeting(fresh: boolean): VoiceLine | null {
        const child = this.players.child;

        if (!fresh || this.greeted || child === null) {
            return null;
        }

        this.greeted = true;

        return this.line(this.voice.greeting, child);
    }

    /**
     * Praise after a solved round, or null when there is none; with the
     * player's name on the 1st, 4th, 7th … solved round of the turn.
     */
    solved(): VoiceLine | null {
        const nth = this.solvedThisTurn;

        this.solvedThisTurn += 1;
        this.solvers.add(this.player?.id ?? WHOLE_CLASS);

        if (this.voice.praise.length === 0) {
            return null;
        }

        return this.line(
            this.pick(this.voice.praise),
            nth % NAME_EVERY === 0 ? this.player : null,
        );
    }

    /** A retry phrase after a wrong tap, once a round; never a name. */
    missed(roundId: string): string | null {
        if (this.voice.retry.length === 0 || this.retried.has(roundId)) {
            return null;
        }

        this.retried.add(roundId);

        return this.muted ? null : this.pick(this.voice.retry);
    }

    /**
     * The end of a game played on this board, once; `solvedInGame` is the
     * game's solved rounds, those before a reload included. Nothing after
     * no solved round.
     */
    finished(solvedInGame: number): VoiceLine | null {
        if (this.ended) {
            return null;
        }

        this.ended = true;

        if (solvedInGame === 0 && this.solvers.size === 0) {
            return null;
        }

        const child = this.players.child;

        if (child !== null) {
            return this.line(this.voice.finish, child);
        }

        const [only] = [...this.solvers];
        const student =
            this.solvers.size === 1
                ? this.players.students.find((s) => s.id === only)
                : undefined;

        return student === undefined
            ? this.line(this.voice.finish_class, null)
            : this.line(this.voice.finish, student);
    }

    /**
     * The device's silent mode («Səssiz»): nothing is said meanwhile, and
     * turns, rounds and solvers still count.
     */
    mute(muted: boolean): void {
        this.muted = muted;
    }

    /** Every clip the board may say besides prompts, to load up front. */
    phrases(): string[] {
        const { greeting, finish, finish_class: finishClass } = this.voice;

        return [
            ...new Set([
                ...this.voice.praise,
                ...this.voice.retry,
                ...[greeting, finish, finishClass].filter(
                    (url): url is string => url !== null,
                ),
            ]),
        ];
    }

    /** The names to fetch with the scene: a home game's child's. */
    namesUpFront(): NamedPlayer[] {
        const child = this.named(this.players.child);

        return child === null ? [] : [child];
    }

    /** A phrase with the player's name when it has a clip; null without a phrase. */
    private line(
        phrase: string | null,
        player: VoicePlayer | null,
    ): VoiceLine | null {
        return phrase === null || this.muted
            ? null
            : { phrase, name: this.named(player) };
    }

    private named(player: VoicePlayer | null): NamedPlayer | null {
        return this.voice.names && player !== null && player.name_audio !== null
            ? { id: player.id, name_audio: player.name_audio }
            : null;
    }

    /** One of `clips` at random; never called with an empty list. */
    private pick(clips: string[]): string {
        return clips[
            Math.min(clips.length - 1, Math.floor(this.random() * clips.length))
        ];
    }
}
