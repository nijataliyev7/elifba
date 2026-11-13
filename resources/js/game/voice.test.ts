import { describe, expect, it } from 'vite-plus/test';
import { Round } from './contract';
import type { BoardVoice, VoicePlayers } from './voice';
import { NO_PLAYERS, SILENT, VoiceCues } from './voice';

/*
 * What the board says, and when (docs/architecture.md §3 "Voice", ADR
 * 015, ADR 016): the prompt once a round has arrived, never the same clip
 * twice in a row; praise after a solved round, with the name of the
 * player at the board on the 1st, 4th, 7th … solved round of a turn; a
 * retry phrase at most once a round; a home game's greeting and the end
 * once each; nothing the owner switched off or the device silenced.
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
    names: true,
    greeting: '/content/voice/salam.mp3',
    finish: '/content/voice/ela-oynadin.mp3',
    finish_class: '/content/voice/ela-oynadiniz.mp3',
};

/** The clip path of a player's name: ids only, as the server sends it. */
const clipOf = (id: string) => `/games/g1/players/${id}/voice`;

const AYSEL = { id: 's1', name_audio: clipOf('s1') };
const NIHAT = { id: 's2', name_audio: clipOf('s2') };
/** A name off the list: shown, never said. */
const OFF_LIST = { id: 's3', name_audio: null };

const CLASS: VoicePlayers = {
    child: null,
    students: [AYSEL, NIHAT, OFF_LIST],
};

const ZEHRA = { id: 'c1', name_audio: clipOf('c1') };
const HOME: VoicePlayers = { child: ZEHRA, students: [] };

/** Whose name each praise of `rounds` solved rounds carries ('' for none). */
function namesOver(cues: VoiceCues, rounds: number): string[] {
    return Array.from({ length: rounds }, () => cues.solved()?.name?.id ?? '');
}

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
        const cues = new VoiceCues(ON, NO_PLAYERS, () => picks.shift() ?? 0);

        expect(cues.solved()?.phrase).toBe(ON.praise[0]);
        expect(cues.solved()?.phrase).toBe(ON.praise[1]);
        expect(cues.solved()?.phrase).toBe(ON.praise[1]);
    });

    it('says one of the retry phrases once a round', () => {
        const picks = [0, 0.99];
        const cues = new VoiceCues(ON, NO_PLAYERS, () => picks.shift() ?? 0);

        expect(cues.missed('r1')).toBe(ON.retry[0]);
        expect(cues.missed('r1')).toBeNull();
        expect(cues.missed('r2')).toBe(ON.retry[1]);
    });

    it('says neither when praise is off (empty lists)', () => {
        const cues = new VoiceCues({ ...SILENT, prompt: true });

        expect(cues.solved()).toBeNull();
        expect(cues.missed('r1')).toBeNull();
        expect(cues.phrases()).toEqual([]);
    });

    it('loads every phrase up front, once', () => {
        expect(new VoiceCues(ON).phrases()).toEqual([
            ...ON.praise,
            ...ON.retry,
            ON.greeting,
            ON.finish,
            ON.finish_class,
        ]);
    });
});

describe('the name with the praise (ADR 016 decision 6)', () => {
    it('names the 1st, 4th and 7th solved round of a turn', () => {
        const cues = new VoiceCues(ON, CLASS);

        cues.turn('s1');

        expect(namesOver(cues, 8)).toEqual([
            's1',
            '',
            '',
            's1',
            '',
            '',
            's1',
            '',
        ]);
    });

    it('says the name before the praise, never alone or on a wrong tap', () => {
        const cues = new VoiceCues(ON, CLASS, () => 0);

        cues.turn('s2');

        expect(cues.solved()).toEqual({
            phrase: ON.praise[0],
            name: NIHAT,
        });
        expect(cues.missed('r1')).toBe(ON.retry[0]);
    });

    it('starts the count again for a new student, and for the same one after another', () => {
        const cues = new VoiceCues(ON, CLASS);

        cues.turn('s1');
        expect(namesOver(cues, 2)).toEqual(['s1', '']);

        cues.turn('s2');
        expect(namesOver(cues, 2)).toEqual(['s2', '']);

        cues.turn('s1');
        expect(namesOver(cues, 1)).toEqual(['s1']);
    });

    it('never names the whole class', () => {
        const cues = new VoiceCues(ON, CLASS);

        expect(cues.turn(null)).toBeNull();
        expect(namesOver(cues, 7)).toEqual(Array(7).fill(''));
        // A student no longer in the class is the whole class too.
        expect(cues.turn('gone')).toBeNull();
        expect(namesOver(cues, 1)).toEqual(['']);
    });

    it("names the home child, the one always at the board, whatever the turn's student", () => {
        const cues = new VoiceCues(ON, HOME);

        expect(cues.namesUpFront()).toEqual([ZEHRA]);
        expect(cues.turn(null)).toEqual(ZEHRA);
        expect(namesOver(cues, 4)).toEqual(['c1', '', '', 'c1']);
    });

    it('fetches the name of the student a turn starts with, and none without a clip', () => {
        const cues = new VoiceCues(ON, CLASS);

        expect(cues.namesUpFront()).toEqual([]);
        expect(cues.turn('s1')).toEqual(AYSEL);
        expect(cues.turn('s3')).toBeNull();
    });

    it('says the phrase alone for a name off the list', () => {
        const cues = new VoiceCues(ON, CLASS, () => 0);

        cues.turn('s3');

        expect(cues.solved()).toEqual({
            phrase: ON.praise[0],
            name: null,
        });
    });

    it('names a student added on the board from their first turn, and keeps the turn going on', () => {
        const cues = new VoiceCues(ON, CLASS);
        const tural = { id: 's4', name_audio: clipOf('s4') };

        cues.turn('s1');
        expect(namesOver(cues, 2)).toEqual(['s1', '']);

        cues.setPlayers({
            child: null,
            students: [...CLASS.students, tural],
        });
        expect(namesOver(cues, 2)).toEqual(['', 's1']);

        expect(cues.turn('s4')).toEqual(tural);
        expect(namesOver(cues, 1)).toEqual(['s4']);
    });
});

describe('the greeting and the end (ADR 016 decision 7)', () => {
    it('greets a home game once, the name before «Salam!», before the first answer only', () => {
        const cues = new VoiceCues(ON, HOME);

        cues.turn(null);

        expect(cues.greeting(true)).toEqual({
            phrase: ON.greeting,
            name: ZEHRA,
        });
        expect(cues.greeting(true)).toBeNull();

        // «Davam et» after a reload: a new board, a game already answered.
        const reloaded = new VoiceCues(ON, HOME);

        expect(reloaded.greeting(false)).toBeNull();
    });

    it('never greets a class', () => {
        const cues = new VoiceCues(ON, CLASS);

        cues.turn('s1');

        expect(cues.greeting(true)).toBeNull();
    });

    it("ends a home game once with the child's name before «Əla oynadın!»", () => {
        const cues = new VoiceCues(ON, HOME);

        cues.turn(null);
        cues.solved();

        expect(cues.finished(1)).toEqual({
            phrase: ON.finish,
            name: ZEHRA,
        });
        expect(cues.finished(1)).toBeNull();
    });

    it("ends a class game with the student's name when every round this board saw solved was theirs", () => {
        const cues = new VoiceCues(ON, CLASS);

        cues.turn('s1');
        cues.solved();
        cues.solved();
        // Another student came to the board and solved nothing.
        cues.turn('s2');

        expect(cues.finished(2)).toEqual({
            phrase: ON.finish,
            name: AYSEL,
        });
    });

    it('ends with «Əla oynadınız!» and no name when several students or the whole class played', () => {
        const several = new VoiceCues(ON, CLASS);

        several.turn('s1');
        several.solved();
        several.turn('s2');
        several.solved();

        expect(several.finished(2)).toEqual({
            phrase: ON.finish_class,
            name: null,
        });

        const whole = new VoiceCues(ON, CLASS);

        whole.turn(null);
        whole.solved();

        expect(whole.finished(1)).toEqual({
            phrase: ON.finish_class,
            name: null,
        });

        const mixed = new VoiceCues(ON, CLASS);

        mixed.turn('s1');
        mixed.solved();
        mixed.turn(null);
        mixed.solved();

        expect(mixed.finished(2)?.name).toBeNull();
    });

    it('ends without a name when the board saw no round solved (a reload), and says nothing after no solved round', () => {
        const reloaded = new VoiceCues(ON, CLASS);

        reloaded.turn('s1');

        expect(reloaded.finished(2)).toEqual({
            phrase: ON.finish_class,
            name: null,
        });
        expect(new VoiceCues(ON, CLASS).finished(0)).toBeNull();
        expect(new VoiceCues(ON, HOME).finished(0)).toBeNull();
    });

    it('says the phrase alone for a name off the list', () => {
        const cues = new VoiceCues(ON, CLASS);

        cues.turn('s3');
        cues.solved();

        expect(cues.finished(1)).toEqual({
            phrase: ON.finish,
            name: null,
        });
    });
});

describe('nothing the owner switched off or the device silenced', () => {
    it('says every phrase without a name with the name switch off', () => {
        const off = { ...ON, names: false };
        const home = new VoiceCues(off, HOME, () => 0);

        expect(home.namesUpFront()).toEqual([]);
        expect(home.turn(null)).toBeNull();
        expect(home.greeting(true)).toEqual({
            phrase: ON.greeting,
            name: null,
        });
        expect(home.solved()).toEqual({
            phrase: ON.praise[0],
            name: null,
        });
        expect(home.finished(1)).toEqual({
            phrase: ON.finish,
            name: null,
        });

        const school = new VoiceCues(off, CLASS);

        expect(school.turn('s1')).toBeNull();
        expect(namesOver(school, 4)).toEqual(['', '', '', '']);
    });

    it('says no greeting, praise, name or end with praise off', () => {
        // The server sends empty lists and nulls, and `names` false with them.
        const off = { ...SILENT, prompt: true };
        const cues = new VoiceCues(off, HOME);

        expect(cues.namesUpFront()).toEqual([]);
        expect(cues.turn(null)).toBeNull();
        expect(cues.greeting(true)).toBeNull();
        expect(cues.solved()).toBeNull();
        expect(cues.finished(1)).toBeNull();
    });

    it('says no greeting, praise, name or end while the device is silent («Səssiz»), and the turn still counts', () => {
        const cues = new VoiceCues(ON, HOME);

        cues.mute(true);
        cues.turn(null);

        expect(cues.greeting(true)).toBeNull();
        expect(namesOver(cues, 3)).toEqual(['', '', '']);
        expect(cues.missed('r1')).toBeNull();

        cues.mute(false);

        // The 4th solved round of the turn names the child again.
        expect(namesOver(cues, 1)).toEqual(['c1']);
        expect(cues.finished(4)).toEqual({
            phrase: ON.finish,
            name: ZEHRA,
        });

        const silent = new VoiceCues(ON, HOME);

        silent.mute(true);
        silent.solved();

        expect(silent.finished(1)).toBeNull();
    });

    it('says no prompt while the device is silent, and the next prompt after it', () => {
        const cues = new VoiceCues(ON);

        cues.mute(true);

        expect(cues.arrived(rabbit, true)).toBeNull();
        expect(cues.repeat(rabbit)).toBeNull();

        cues.mute(false);

        expect(cues.arrived(cat, true)).toBe(cat.prompt.audio);
    });
});

it('says nothing at all with SILENT', () => {
    const cues = new VoiceCues(SILENT, HOME);

    expect(cues.arrived(rabbit, true)).toBeNull();
    expect(cues.turn(null)).toBeNull();
    expect(cues.greeting(true)).toBeNull();
    expect(cues.solved()).toBeNull();
    expect(cues.missed('r1')).toBeNull();
    expect(cues.finished(1)).toBeNull();
});
