import { describe, expect, it } from 'vite-plus/test';
import { Round } from './contract';
import type { BoardVoice } from './voice';
import { SILENT, VoiceCues } from './voice';

/*
 * What the board says, and when (docs/architecture.md §3 "Voice", ADR
 * 015): the prompt once a round has arrived, never the same clip twice in
 * a row; praise after a solved round; a retry phrase at most once a
 * round; nothing the owner switched off.
 */

const fixtures = import.meta.glob<unknown>(
    '../../../tests/Fixtures/contract/round.*.json',
    { eager: true, import: 'default' },
);

function fixture(name: string): Round {
    const found = Object.entries(fixtures).find(([path]) =>
        path.endsWith(`/round.${name}.json`),
    );

    if (found === undefined) {
        throw new Error(`No fixture round.${name}.json`);
    }

    return Round.parse(found[1]);
}

function withAudio(round: Round, id: string, audio: string | null): Round {
    return { ...round, id, prompt: { ...round.prompt, audio } };
}

const ON: BoardVoice = {
    prompt: true,
    praise: ['/content/voice/praise-1.mp3', '/content/voice/praise-2.mp3'],
    retry: ['/content/voice/retry-1.mp3', '/content/voice/retry-2.mp3'],
};

const find = fixture('find-word');
const rabbit = withAudio(find, 'r1', '/content/voice/dovsan.mp3?v=1');
const cat = withAudio(find, 'r2', '/content/voice/pisik.mp3?v=1');
const teacherWord = withAudio(find, 'r3', null);

describe('the prompt', () => {
    it('is said when a round arrives, each new prompt once', () => {
        const cues = new VoiceCues(ON);

        expect(cues.arrived(rabbit, true)).toBe(rabbit.prompt.audio);
        expect(cues.arrived(cat, true)).toBe(cat.prompt.audio);
        expect(cues.arrived(rabbit, true)).toBe(rabbit.prompt.audio);
    });

    it('is not said again when the next round has the same clip (pop)', () => {
        const pop = fixture('pop-word');
        const cues = new VoiceCues(ON);
        const first = withAudio(pop, 'p1', '/content/voice/pop.mp3?v=1');
        const second = withAudio(pop, 'p2', '/content/voice/pop.mp3?v=1');

        expect(cues.arrived(first, true)).toBe('/content/voice/pop.mp3?v=1');
        expect(cues.arrived(second, true)).toBeNull();
    });

    it('is silent for a prompt without a clip, such as a teacher word', () => {
        const cues = new VoiceCues(ON);

        expect(cues.arrived(teacherWord, true)).toBeNull();
        expect(cues.repeat(teacherWord)).toBeNull();
        // The next voiced round is said.
        expect(cues.arrived(rabbit, true)).toBe(rabbit.prompt.audio);
    });

    it('is said again after a round without a clip in between', () => {
        const cues = new VoiceCues(ON);
        const again = withAudio(find, 'r4', rabbit.prompt.audio);

        expect(cues.arrived(rabbit, true)).toBe(rabbit.prompt.audio);
        expect(cues.arrived(teacherWord, true)).toBeNull();
        expect(cues.arrived(again, true)).toBe(rabbit.prompt.audio);
    });

    it('is not said for a round shown already solved or skipped', () => {
        const cues = new VoiceCues(ON);

        expect(cues.arrived(rabbit, false)).toBeNull();
        // It was never said, so it is said when it does arrive.
        expect(cues.arrived(rabbit, true)).toBe(rabbit.prompt.audio);
    });

    it('is said again by 🔊, as often as asked', () => {
        const cues = new VoiceCues(ON);

        cues.arrived(rabbit, true);

        expect(cues.repeat(rabbit)).toBe(rabbit.prompt.audio);
        expect(cues.repeat(rabbit)).toBe(rabbit.prompt.audio);
        expect(cues.repeat(null)).toBeNull();
    });

    it('is never said when the owner switched it off', () => {
        const cues = new VoiceCues({ ...ON, prompt: false });

        expect(cues.arrived(rabbit, true)).toBeNull();
        expect(cues.repeat(rabbit)).toBeNull();
        // Praise stays.
        expect(cues.solved()).not.toBeNull();
    });
});

describe('praise and retry', () => {
    it('praises a solved round with one of the phrases', () => {
        const picks = [0, 0.99, 0.5];
        const cues = new VoiceCues(ON, () => picks.shift() ?? 0);

        expect(cues.solved()).toBe(ON.praise[0]);
        expect(cues.solved()).toBe(ON.praise[1]);
        expect(cues.solved()).toBe(ON.praise[1]);
    });

    it('says one of the retry phrases once a round', () => {
        const picks = [0, 0.99];
        const cues = new VoiceCues(ON, () => picks.shift() ?? 0);

        expect(cues.missed('r1')).toBe(ON.retry[0]);
        expect(cues.missed('r1')).toBeNull();
        expect(cues.missed('r2')).toBe(ON.retry[1]);
    });

    it('says neither when praise is off (empty lists)', () => {
        const cues = new VoiceCues({ prompt: true, praise: [], retry: [] });

        expect(cues.solved()).toBeNull();
        expect(cues.missed('r1')).toBeNull();
        expect(cues.phrases()).toEqual([]);
    });

    it('loads every phrase up front, once', () => {
        expect(new VoiceCues(ON).phrases()).toEqual([
            ...ON.praise,
            ...ON.retry,
        ]);
    });
});

it('says nothing at all with SILENT', () => {
    const cues = new VoiceCues(SILENT);

    expect(cues.arrived(rabbit, true)).toBeNull();
    expect(cues.solved()).toBeNull();
    expect(cues.missed('r1')).toBeNull();
});
