/**
 * The clips of players' first names on a board (docs/architecture.md §3
 * "Voice", ADR 016 decision 8). A name's clip has no public URL: the board
 * fetches it from `GET /games/{game}/players/{player}/voice` (ids only)
 * with its own headers (`nameClip()` in `http.ts`, a guest board's bearer
 * included) and keeps it only decoded in memory, on the page's one
 * AudioContext (`stage.ts`); nothing is written to storage, and the clips
 * go with the scene (`dispose()`). A home game's child's clip is fetched
 * with the scene, a student's when the student comes to the board
 * (`VoiceCues.turn()`). A clip asked for before it has arrived is not
 * there yet: the phrase is said without the name.
 */
import type { NamedPlayer } from './voice';

export type NameClipSource = {
    /** The clip's bytes, or null when there is none (a 404, the network down). */
    fetch: (url: string) => Promise<ArrayBuffer | null>;
    /** Decodes them on the page's AudioContext. */
    decode: (bytes: ArrayBuffer) => Promise<AudioBuffer>;
};

/** What the scene needs of the name clips. */
export type NameClips = {
    /** Fetches a player's clip, once; again only after a failure. */
    load: (player: NamedPlayer) => void;
    /** The decoded clip, or null while it is loading or when there is none. */
    get: (playerId: string) => AudioBuffer | null;
    dispose: () => void;
};

export class NameVoices implements NameClips {
    private readonly clips = new Map<string, AudioBuffer | 'loading'>();
    private disposed = false;

    constructor(private readonly source: NameClipSource) {}

    load(player: NamedPlayer): void {
        if (this.disposed || this.clips.has(player.id)) {
            return;
        }

        this.clips.set(player.id, 'loading');
        void this.fetch(player);
    }

    get(playerId: string): AudioBuffer | null {
        const clip = this.clips.get(playerId);

        return clip === undefined || clip === 'loading' ? null : clip;
    }

    dispose(): void {
        this.disposed = true;
        this.clips.clear();
    }

    private async fetch(player: NamedPlayer): Promise<void> {
        let clip: AudioBuffer | null = null;

        try {
            const bytes = await this.source.fetch(player.name_audio);

            clip =
                bytes === null || this.disposed
                    ? null
                    : await this.source.decode(bytes);
        } catch {
            clip = null;
        }

        if (this.disposed) {
            return;
        }

        if (clip === null) {
            // Asked for again at the player's next turn.
            this.clips.delete(player.id);
        } else {
            this.clips.set(player.id, clip);
        }
    }
}
