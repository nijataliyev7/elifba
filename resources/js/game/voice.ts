/**
 * What the board says, and when (docs/architecture.md §3 "Voice", ADR
 * 015). The clips are content files the server names: a round's
 * `prompt.audio`, and the praise and retry clips of the board's props
 * (`BoardVoice`, PHP), empty when the owner turned praise off. This module
 * only decides; the scene plays (`board-scene.ts`, on the page's one
 * AudioContext), so Vitest can check every rule here.
 *
 * - The prompt is said once a round's items have arrived, but not when the
 *   round before had the same clip: «Hər hərfi oxu və vur!» is said once,
 *   not every round. A round shown already solved or skipped (a reload) is
 *   not said. The 🔊 button says it again whenever there is one.
 * - Praise, one of the phrases at random, follows a solved round.
 * - A retry phrase, one of them at random, follows a wrong tap, at most
 *   once a round.
 * - With the prompt switched off nothing says a prompt, not even 🔊.
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
};

export const SILENT: BoardVoice = { prompt: false, praise: [], retry: [] };

export class VoiceCues {
    /** The clip of the last round that arrived open, null for none. */
    private lastPrompt: string | null = null;
    private retried = new Set<string>();

    constructor(
        private readonly voice: BoardVoice,
        private readonly random: () => number = Math.random,
    ) {}

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

        return audio !== null && audio !== before ? audio : null;
    }

    /** The clip 🔊 says: the round's prompt, when prompts are spoken. */
    repeat(round: Round | null): string | null {
        return this.voice.prompt ? (round?.prompt.audio ?? null) : null;
    }

    /** Praise after a solved round, or null when there is none. */
    solved(): string | null {
        return this.voice.praise.length === 0
            ? null
            : this.pick(this.voice.praise);
    }

    /** A retry phrase after a wrong tap, once a round. */
    missed(roundId: string): string | null {
        if (this.voice.retry.length === 0 || this.retried.has(roundId)) {
            return null;
        }

        this.retried.add(roundId);

        return this.pick(this.voice.retry);
    }

    /** One of `clips` at random; never called with an empty list. */
    private pick(clips: string[]): string {
        return clips[
            Math.min(clips.length - 1, Math.floor(this.random() * clips.length))
        ];
    }

    /** Every clip the board may say besides prompts, to load up front. */
    phrases(): string[] {
        return [...new Set([...this.voice.praise, ...this.voice.retry])];
    }
}
