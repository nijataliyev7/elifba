import { describe, expect, it } from 'vite-plus/test';
import type { NameClipSource } from './name-voice';
import { NameVoices } from './name-voice';

/*
 * The clips of players' names on a board (docs/architecture.md §3 "Voice",
 * ADR 016 decision 8): fetched once by the ids-only path, decoded, kept in
 * memory only and dropped with the scene; a clip not there yet is null.
 */

const AYSEL = { id: 's1', name_audio: '/games/g1/players/s1/voice' };
const NIHAT = { id: 's2', name_audio: '/games/g1/players/s2/voice' };

/** A source whose fetches the test answers one by one. */
function source() {
    const fetched: string[] = [];
    const pending: ((bytes: ArrayBuffer | null) => void)[] = [];
    const decoded = new Map<ArrayBuffer, AudioBuffer>();
    const clips: NameClipSource = {
        fetch: (url) => {
            fetched.push(url);

            return new Promise((resolve) => pending.push(resolve));
        },
        decode: async (bytes) => {
            const clip = { duration: 0.9 } as AudioBuffer;

            decoded.set(bytes, clip);

            return clip;
        },
    };
    const answer = async (bytes: ArrayBuffer | null) => {
        pending.shift()?.(bytes);
        // The fetch, then the decode.
        await Promise.resolve();
        await Promise.resolve();
    };

    return { clips, fetched, decoded, answer };
}

describe('name clips', () => {
    it('fetches a clip once by its ids-only path and has it decoded', async () => {
        const { clips, fetched, decoded, answer } = source();
        const names = new NameVoices(clips);

        names.load(AYSEL);
        names.load(AYSEL);

        expect(fetched).toEqual(['/games/g1/players/s1/voice']);
        // Not there yet: a praise now is said without the name.
        expect(names.get('s1')).toBeNull();

        const bytes = new ArrayBuffer(8);

        await answer(bytes);

        expect(names.get('s1')).toBe(decoded.get(bytes));

        names.load(AYSEL);
        expect(fetched).toHaveLength(1);
    });

    it('keeps each player apart', async () => {
        const { clips, answer } = source();
        const names = new NameVoices(clips);

        names.load(AYSEL);
        names.load(NIHAT);
        await answer(new ArrayBuffer(8));

        expect(names.get('s1')).not.toBeNull();
        expect(names.get('s2')).toBeNull();
    });

    it('has no clip after a refusal, and asks again at the next turn', async () => {
        const { clips, fetched, answer } = source();
        const names = new NameVoices(clips);

        names.load(AYSEL);
        await answer(null);

        expect(names.get('s1')).toBeNull();

        names.load(AYSEL);
        expect(fetched).toHaveLength(2);
    });

    it('has no clip when it cannot be decoded', async () => {
        const { clips, answer } = source();
        const names = new NameVoices({
            ...clips,
            decode: () => Promise.reject(new Error('EncodingError')),
        });

        names.load(AYSEL);
        await answer(new ArrayBuffer(8));

        expect(names.get('s1')).toBeNull();
    });

    it('drops every clip with the scene, and keeps none that arrives after', async () => {
        const { clips, fetched, answer } = source();
        const names = new NameVoices(clips);

        names.load(AYSEL);
        names.load(NIHAT);
        await answer(new ArrayBuffer(8));
        names.dispose();

        expect(names.get('s1')).toBeNull();

        await answer(new ArrayBuffer(8));
        expect(names.get('s2')).toBeNull();

        names.load(AYSEL);
        expect(fetched).toHaveLength(2);
    });
});
