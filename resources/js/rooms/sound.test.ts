import { describe, expect, it } from 'vite-plus/test';
import {
    BUZZ_MS,
    MIN_TAIL_MS,
    MUTE_STORAGE_KEYS,
    NAME_GAP_MS,
    NAME_LEAD_MS,
    PHRASE_GAP_MS,
    readMuted,
    ROOM_SOUNDS,
    roomSoundPlayer,
    SEAT_SOUNDS,
    SPEAKER_GAIN,
    STOP_MS,
    VOICE_GAIN,
    writeMuted,
} from './sound';
import type {
    AudioBufferLike,
    AudioContextLike,
    AudioSourceLike,
    StorageLike,
} from './sound';

/*
 * A room page's sound player (ADR 025 "Sound", ADR 028, docs/frontend.md
 * rule 15 "Sound"): nothing before a gesture, nothing while the device is
 * muted or the page hidden, its own speaker's files only, a long cue that
 * ends where it is asked to — from its middle when late, not at all when
 * too little is left — every cue stopped when its moment ends, and never a
 * throw where audio is missing, refused or a file fails. The screen's one
 * voice, the winner's name (ADR 030): said after its time from the clips
 * that arrived, never on a phone, never muted, hidden or locked. A taunt's
 * voice (ADR 029 as amended 2026-11-19): fetched once sound is on, said one
 * at a time and while its reaction lasts, untouched by the round's close,
 * silent while muted, hidden or locked.
 */

function memoryStorage(): StorageLike & { items: Map<string, string> } {
    const items = new Map<string, string>();

    return {
        items,
        getItem: (key) => items.get(key) ?? null,
        setItem: (key, value) => void items.set(key, value),
        removeItem: (key) => void items.delete(key),
    };
}

const refusing: StorageLike = {
    getItem: () => {
        throw new Error('blocked');
    },
    setItem: () => {
        throw new Error('blocked');
    },
    removeItem: () => {
        throw new Error('blocked');
    },
};

type Started = {
    sound: string;
    when: number;
    offset: number;
    gain: number;
    stopped: boolean;
    /** When it was told to stop, if at a time; how its gain fell. */
    stopAt?: number;
    ramp?: [number, number][];
};

/** How long each fake file lasts, in seconds: the long cues as ADR 028 makes them. */
const DURATIONS: Record<string, number> = {
    countdown: 3,
    clock: 5,
    roll: 2.5,
};

/** A fake Web Audio context: buffers know their sound's name, sources record their start. */
function fakeAudio({ allowed = true }: { allowed?: boolean } = {}) {
    const started: Started[] = [];
    const made: FakeContext[] = [];
    /** Each started source's `onended`, as the browser would call it when the file ends. */
    const endings = new Map<Started, () => void>();

    type FakeContext = AudioContextLike & { closed: boolean };

    const create = (): FakeContext => {
        const context: FakeContext = {
            state: 'suspended',
            currentTime: 10,
            destination: {},
            onstatechange: null,
            closed: false,
            resume: () => {
                if (!allowed) {
                    return Promise.reject(new Error('NotAllowedError'));
                }

                context.state = 'running';
                context.onstatechange?.();

                return Promise.resolve();
            },
            close: () => {
                context.closed = true;
                context.state = 'closed';

                return Promise.resolve();
            },
            createBuffer: () => ({ duration: 0 }),
            createGain: () => {
                const ramp: [number, number][] = [];
                const node = {
                    ramp,
                    gain: {
                        value: 1,
                        setValueAtTime: (value: number, time: number) =>
                            ramp.push([value, time]),
                        linearRampToValueAtTime: (
                            value: number,
                            time: number,
                        ) => ramp.push([value, time]),
                    },
                    connect: () => node,
                };

                return node;
            },
            createBufferSource: () => {
                let gain = 1;
                let ramp: [number, number][] = [];
                const source: AudioSourceLike & { record?: Started } = {
                    buffer: null,
                    onended: null,
                    connect: (node) => {
                        const target = node as {
                            gain?: { value: number };
                            ramp?: [number, number][];
                        };
                        gain = target.gain?.value ?? 1;
                        ramp = target.ramp ?? [];

                        return node;
                    },
                    start: (when = 0, offset = 0) => {
                        const name = (source.buffer as { name?: string } | null)
                            ?.name;

                        if (name !== undefined) {
                            source.record = {
                                sound: name,
                                when,
                                offset,
                                gain,
                                stopped: false,
                                ramp,
                            };
                            started.push(source.record);
                            endings.set(source.record, () =>
                                source.onended?.(),
                            );
                        }
                    },
                    stop: (when?: number) => {
                        if (source.record) {
                            source.record.stopped = true;

                            if (when !== undefined) {
                                source.record.stopAt = when;
                            }
                        }
                    },
                };

                return source;
            },
            decodeAudioData: (data) => {
                const name = new TextDecoder().decode(data);

                return Promise.resolve({
                    name,
                    duration: DURATIONS[name] ?? 0.2,
                } as AudioBufferLike);
            },
        };
        made.push(context);

        return context;
    };

    return {
        started,
        made,
        create,
        /** The file of `record` ends. */
        end: (record: Started) => endings.get(record)?.(),
    };
}

const urls = {
    screen: Object.fromEntries(
        ROOM_SOUNDS.screen.map((name) => [name, `/content/${name}.mp3`]),
    ),
    phone: Object.fromEntries(
        ROOM_SOUNDS.phone.map((name) => [name, `/content/${name}.mp3`]),
    ),
};

/** Fetching a file gives its name as bytes; `missing` files fail. */
function loader(missing: string[] = []) {
    const asked: string[] = [];

    return {
        asked,
        load: (url: string) => {
            asked.push(url);
            const name = url.replace('/content/', '').replace('.mp3', '');

            return missing.includes(name)
                ? Promise.reject(new Error('404'))
                : Promise.resolve(new TextEncoder().encode(name).buffer);
        },
    };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

/** A name clip's route, by ids, as the screen builds it. */
const nameUrl = (member: string) => `/rooms/01room/members/${member}/voice`;

/** «Əla oynadın!», a public content file (the fake loader names it `finish`). */
const PHRASE = '/content/finish.mp3';

/**
 * Fetching a name clip gives `name:<member>` as bytes; `missing` answer
 * 404, `slow` stay pending until `arrive()`.
 */
function nameLoader({
    missing = [],
    slow = [],
}: { missing?: string[]; slow?: string[] } = {}) {
    const asked: string[] = [];
    const pending = new Map<string, () => void>();

    return {
        asked,
        arrive: (member: string) => pending.get(member)?.(),
        load: (url: string) => {
            asked.push(url);
            const member = url.split('/')[4];
            const bytes = new TextEncoder().encode(`name:${member}`).buffer;

            if (missing.includes(member)) {
                return Promise.reject(new Error('404'));
            }

            if (slow.includes(member)) {
                return new Promise<ArrayBuffer>((resolve) =>
                    pending.set(member, () => resolve(bytes)),
                );
            }

            return Promise.resolve(bytes);
        },
    };
}

/** A timer the test runs by hand: what was asked for, and whether it was cancelled. */
function manualLater() {
    const asked: { run: () => void; ms: number; cancelled: boolean }[] = [];

    return {
        asked,
        later: (run: () => void, ms: number) => {
            const entry = { run, ms, cancelled: false };
            asked.push(entry);

            return () => {
                entry.cancelled = true;
            };
        },
        /** Time passes: every timer not cancelled runs. */
        fire: () =>
            asked
                .filter((entry) => !entry.cancelled)
                .forEach((entry) => entry.run()),
    };
}

/** A player whose name clips come from `names` and whose timers are `timers`. */
function namingPlayer(
    speaker: 'screen' | 'phone',
    names: ReturnType<typeof nameLoader>,
    timers: ReturnType<typeof manualLater>,
    audio = fakeAudio(),
    storage: () => StorageLike | null = memoryStorage,
) {
    const files = loader();

    return {
        audio,
        files,
        player: roomSoundPlayer({
            speaker,
            urls: urls[speaker],
            storage,
            createContext: audio.create,
            load: (url) =>
                url.startsWith('/rooms/') ? names.load(url) : files.load(url),
            later: timers.later,
        }),
    };
}

describe('the device mute', () => {
    it('is remembered per speaker on this device, and a refusing storage never throws', () => {
        const storage = memoryStorage();

        expect(readMuted(storage, 'screen')).toBe(false);
        writeMuted(storage, 'screen', true);
        expect(storage.items.get(MUTE_STORAGE_KEYS.screen)).toBe('1');
        expect(readMuted(storage, 'screen')).toBe(true);
        expect(readMuted(storage, 'phone')).toBe(false);
        writeMuted(storage, 'screen', false);
        expect(readMuted(storage, 'screen')).toBe(false);

        expect(readMuted(refusing, 'phone')).toBe(false);
        expect(() => writeMuted(refusing, 'phone', true)).not.toThrow();
        expect(readMuted(null, 'phone')).toBe(false);
        expect(MUTE_STORAGE_KEYS.screen).not.toBe('elifba.board.muted');
    });
});

describe('roomSoundPlayer', () => {
    it('stays locked and silent until a gesture unlocks it, and only then fetches its files', async () => {
        const audio = fakeAudio();
        const files = loader();
        const player = roomSoundPlayer({
            speaker: 'screen',
            urls: urls.screen,
            storage: memoryStorage,
            createContext: audio.create,
            load: files.load,
        });

        player.play('countdown');
        expect(player.snapshot()).toEqual({ muted: false, locked: true });
        expect(audio.made).toHaveLength(0);
        expect(files.asked).toEqual([]);

        player.unlock();
        await settle();

        expect(player.snapshot()).toEqual({ muted: false, locked: false });
        expect(files.asked).toEqual(
            ROOM_SOUNDS.screen.map((name) => `/content/${name}.mp3`),
        );

        player.play('go');
        expect(audio.started).toEqual([
            {
                sound: 'go',
                when: 10,
                offset: 0,
                gain: SPEAKER_GAIN.screen,
                stopped: false,
                ramp: [],
            },
        ]);
    });

    it("plays only its own speaker's files: a phone that carries no room has no room moment to play", async () => {
        const audio = fakeAudio();
        const player = roomSoundPlayer({
            speaker: 'phone',
            urls: urls.phone,
            storage: memoryStorage,
            createContext: audio.create,
            load: loader().load,
        });

        player.unlock();
        await settle();
        player.play('clock');
        player.play('fanfare');
        player.play('tap');

        expect(audio.started.map(({ sound, gain }) => [sound, gain])).toEqual([
            ['tap', SPEAKER_GAIN.phone],
        ]);
    });

    it("carries the room's files only once asked: a phone in a room without a screen", async () => {
        const audio = fakeAudio();
        const files = loader();
        const player = roomSoundPlayer({
            speaker: 'phone',
            urls: { ...urls.phone, ...urls.screen },
            storage: memoryStorage,
            createContext: audio.create,
            load: files.load,
        });

        player.unlock();
        await settle();

        expect(files.asked).toEqual(
            ROOM_SOUNDS.phone.map((name) => `/content/${name}.mp3`),
        );
        player.play('countdown');
        expect(audio.started).toEqual([]);

        player.carry();
        player.carry();
        await settle();

        expect(files.asked).toEqual(
            [...ROOM_SOUNDS.phone, ...ROOM_SOUNDS.screen].map(
                (name) => `/content/${name}.mp3`,
            ),
        );
        player.play('countdown');
        player.play('tap');
        expect(audio.started.map(({ sound, gain }) => [sound, gain])).toEqual([
            ['countdown', SPEAKER_GAIN.phone],
            ['tap', SPEAKER_GAIN.phone],
        ]);
    });

    it("fetches the room's files on the gesture when it was asked to carry them before one", async () => {
        const audio = fakeAudio();
        const files = loader();
        const player = roomSoundPlayer({
            speaker: 'phone',
            urls: { ...urls.phone, ...urls.screen },
            storage: memoryStorage,
            createContext: audio.create,
            load: files.load,
        });

        player.carry();
        expect(files.asked).toEqual([]);

        player.unlock();
        await settle();

        expect(files.asked).toHaveLength(
            ROOM_SOUNDS.phone.length + ROOM_SOUNDS.screen.length,
        );
    });

    it("fetches a match's seat files once asked, not the room's whole set, and plays them at a phone's level", async () => {
        const audio = fakeAudio();
        const files = loader();
        const player = roomSoundPlayer({
            speaker: 'phone',
            urls: { ...urls.phone, ...urls.screen },
            storage: memoryStorage,
            createContext: audio.create,
            load: files.load,
        });

        player.unlock();
        player.seat();
        player.seat();
        await settle();

        expect(files.asked).toEqual(
            [...ROOM_SOUNDS.phone, ...SEAT_SOUNDS].map(
                (name) => `/content/${name}.mp3`,
            ),
        );
        player.play('capture');
        player.play('fanfare');
        expect(audio.started.map(({ sound, gain }) => [sound, gain])).toEqual([
            ['capture', SPEAKER_GAIN.phone],
        ]);

        // Carrying the room later fetches the rest, nothing twice.
        player.carry();
        await settle();
        expect(new Set(files.asked).size).toBe(files.asked.length);
        expect(files.asked).toHaveLength(
            ROOM_SOUNDS.phone.length + ROOM_SOUNDS.screen.length,
        );
    });

    it('buzzes once, when asked, only while unlocked, unmuted and shown, and drops a buzz whose moment ended', async () => {
        const audio = fakeAudio();
        const timers = manualLater();
        const storage = memoryStorage();
        const buzzed: number[] = [];
        const player = roomSoundPlayer({
            speaker: 'phone',
            urls: urls.phone,
            storage: () => storage,
            createContext: audio.create,
            load: loader().load,
            later: timers.later,
            vibrate: (ms) => buzzed.push(ms),
        });

        // Locked: no gesture yet.
        player.buzz(0);
        expect(timers.asked).toHaveLength(0);

        player.unlock();
        await settle();
        player.buzz(160);
        expect(timers.asked.map(({ ms }) => ms)).toEqual([160]);
        expect(buzzed).toEqual([]);
        timers.asked[0].run();
        expect(buzzed).toEqual([BUZZ_MS]);
        expect(BUZZ_MS).toBeLessThanOrEqual(50);

        // The moment ended before the piece landed: dropped.
        player.buzz(160);
        player.cancel();
        expect(timers.asked[1].cancelled).toBe(true);

        // Hidden: none asked for. Muted: one waiting is dropped, and one that runs anyway stays still.
        player.hide();
        player.buzz(0);
        player.resume();
        player.buzz(0);
        player.setMuted(true);
        player.buzz(0);
        expect(timers.asked).toHaveLength(3);
        expect(timers.asked[2].cancelled).toBe(true);
        timers.asked[2].run();
        expect(buzzed).toEqual([BUZZ_MS]);

        // A device without vibration never throws.
        const bare = roomSoundPlayer({
            speaker: 'phone',
            urls: urls.phone,
            storage: memoryStorage,
            createContext: fakeAudio().create,
            load: loader().load,
            later: (run) => {
                run();

                return () => {};
            },
            vibrate: () => {
                throw new Error('no vibration');
            },
        });

        bare.unlock();
        await settle();
        expect(() => bare.buzz(0)).not.toThrow();
    });

    it("never carries on the screen: it already is the room's speaker", async () => {
        const files = loader();
        const player = roomSoundPlayer({
            speaker: 'screen',
            urls: { ...urls.phone, ...urls.screen },
            storage: memoryStorage,
            createContext: fakeAudio().create,
            load: files.load,
        });

        player.unlock();
        player.carry();
        await settle();

        expect(files.asked).toEqual(
            ROOM_SOUNDS.screen.map((name) => `/content/${name}.mp3`),
        );
    });

    it('plays nothing while muted, makes no audio for a muted device, and mutes what was scheduled', async () => {
        const audio = fakeAudio();
        const storage = memoryStorage();
        writeMuted(storage, 'screen', true);
        const player = roomSoundPlayer({
            speaker: 'screen',
            urls: urls.screen,
            storage: () => storage,
            createContext: audio.create,
            load: loader().load,
        });

        player.unlock();
        expect(audio.made).toHaveLength(0);
        expect(player.snapshot().muted).toBe(true);

        // Turning it on is a gesture: it unlocks.
        player.setMuted(false);
        await settle();
        expect(readMuted(storage, 'screen')).toBe(false);
        expect(player.snapshot()).toEqual({ muted: false, locked: false });

        player.play('fanfare', { at: 3000 });
        player.setMuted(true);
        player.play('go');

        expect(
            audio.started.map(({ sound, stopped }) => [sound, stopped]),
        ).toEqual([['fanfare', true]]);
        expect(readMuted(storage, 'screen')).toBe(true);
    });

    it('ends a long cue where it is asked to, starting it from its middle when the page is late', async () => {
        const audio = fakeAudio();
        const player = roomSoundPlayer({
            speaker: 'screen',
            urls: urls.screen,
            storage: memoryStorage,
            createContext: audio.create,
            load: loader().load,
        });

        player.unlock();
        await settle();
        player.play('roll', { at: 3100, ends: true });
        player.play('roll', { at: 600, ends: true });
        // The countdown heard 1.2 s late: its last 1.8 s, ending at `opens_ms`.
        player.play('countdown', { at: 1800, ends: true });
        // The clock heard ahead: it starts 0.4 s from now and ends at the bell.
        player.play('clock', { at: 5400, ends: true });

        const round = (value: number) => Math.round(value * 1000) / 1000;

        expect(
            audio.started.map(({ sound, when, offset }) => [
                sound,
                round(when),
                round(offset),
            ]),
        ).toEqual([
            ['roll', 10.6, 0],
            ['roll', 10, 1.9],
            ['countdown', 10, 1.2],
            ['clock', 10.4, 0],
        ]);
    });

    it('plays nothing of a long cue with less than a quarter second of it left', async () => {
        const audio = fakeAudio();
        const player = roomSoundPlayer({
            speaker: 'screen',
            urls: urls.screen,
            storage: memoryStorage,
            createContext: audio.create,
            load: loader().load,
        });

        player.unlock();
        await settle();
        player.play('countdown', { at: MIN_TAIL_MS - 10, ends: true });
        player.play('clock', { at: 0, ends: true });
        player.play('roll', { at: -100, ends: true });
        player.play('countdown', { at: MIN_TAIL_MS + 10, ends: true });

        expect(
            audio.started.map(({ sound, offset }) => [
                sound,
                Math.round(offset * 1000),
            ]),
        ).toEqual([['countdown', 3000 - MIN_TAIL_MS - 10]]);
    });

    it('stops every cue of a moment that ended: one scheduled never plays, one sounding fades out at once', async () => {
        const audio = fakeAudio();
        const player = roomSoundPlayer({
            speaker: 'screen',
            urls: urls.screen,
            storage: memoryStorage,
            createContext: audio.create,
            load: loader().load,
        });

        player.unlock();
        await settle();
        // The round closed early: the clock was sounding, the ranking of another moment was not due yet.
        player.play('clock', { at: 2600, ends: true });
        player.play('ranking', { at: 420 });
        audio.made[0].currentTime = 10.2;
        player.cancel();

        const [clock, ranking] = audio.started;

        expect(clock.stopAt).toBeCloseTo(10.2 + STOP_MS / 1000, 6);
        expect(clock.ramp).toEqual([
            [SPEAKER_GAIN.screen, 10.2],
            [0, 10.2 + STOP_MS / 1000],
        ]);
        expect(ranking.stopped).toBe(true);
        expect(ranking.stopAt).toBeUndefined();

        // A cue played after the stop belongs to the next moment and plays.
        player.play('close');
        expect(audio.started.at(-1)).toMatchObject({
            sound: 'close',
            stopped: false,
        });
    });

    it('stops at once when the page is hidden and plays nothing until it is shown again', async () => {
        const audio = fakeAudio();
        const player = roomSoundPlayer({
            speaker: 'screen',
            urls: urls.screen,
            storage: memoryStorage,
            createContext: audio.create,
            load: loader().load,
        });

        player.unlock();
        await settle();
        player.play('fanfare');
        player.hide();
        player.play('close');

        expect(
            audio.started.map(({ sound, stopped }) => [sound, stopped]),
        ).toEqual([['fanfare', true]]);

        player.resume();
        player.play('close');
        expect(audio.started.map(({ sound }) => sound)).toEqual([
            'fanfare',
            'close',
        ]);
    });

    it('never throws where there is no audio, the browser refuses it or a file fails', async () => {
        const none = roomSoundPlayer({
            speaker: 'screen',
            urls: urls.screen,
            storage: () => refusing,
            createContext: () => null,
            load: loader().load,
        });

        expect(() => {
            none.unlock();
            none.play('countdown', { at: 3000, ends: true });
            none.setMuted(true);
            none.setMuted(false);
            none.hide();
            none.resume();
            none.cancel();
            none.release();
        }).not.toThrow();
        expect(none.snapshot().locked).toBe(true);

        const refused = fakeAudio({ allowed: false });
        const player = roomSoundPlayer({
            speaker: 'screen',
            urls: urls.screen,
            storage: memoryStorage,
            createContext: refused.create,
            load: loader().load,
        });

        player.unlock();
        await settle();
        player.play('go');
        expect(player.snapshot().locked).toBe(true);
        expect(refused.started).toEqual([]);

        const audio = fakeAudio();
        const partial = roomSoundPlayer({
            speaker: 'phone',
            urls: urls.phone,
            storage: memoryStorage,
            createContext: audio.create,
            load: loader(['sent']).load,
        });

        partial.unlock();
        await settle();
        partial.play('sent');
        partial.play('tap');
        expect(audio.started.map(({ sound }) => sound)).toEqual(['tap']);
    });

    it('closes its audio when the page goes, and a next gesture opens it again', async () => {
        const audio = fakeAudio();
        const player = roomSoundPlayer({
            speaker: 'phone',
            urls: urls.phone,
            storage: memoryStorage,
            createContext: audio.create,
            load: loader().load,
        });

        player.unlock();
        await settle();
        player.release();

        expect(audio.made[0].closed).toBe(true);
        expect(player.snapshot().locked).toBe(true);

        player.unlock();
        await settle();
        player.play('tap');
        expect(audio.made).toHaveLength(2);
        expect(audio.started.map(({ sound }) => sound)).toEqual(['tap']);
    });
});

describe("roomSoundPlayer and the winner's cheer (ADR 030)", () => {
    it('says the names that arrived in time, one after another, then the phrase, and skips one that has not', async () => {
        const names = nameLoader({ missing: ['m2'], slow: ['m3'] });
        const timers = manualLater();
        const { audio, files, player } = namingPlayer('screen', names, timers);

        player.unlock();
        await settle();
        // After the fanfare: 2.9 s from now, the clock at 10 s.
        player.say(['m1', 'm2', 'm3', 'm4'].map(nameUrl), PHRASE, 2900);
        await settle();

        // Fetched at once, names by ids; settled a quarter second before the time.
        expect(names.asked).toEqual(['m1', 'm2', 'm3', 'm4'].map(nameUrl));
        expect(files.asked).toContain(PHRASE);
        expect(timers.asked.map(({ ms }) => ms)).toEqual([2900 - NAME_LEAD_MS]);
        expect(audio.started).toEqual([]);

        timers.fire();
        // m3 arrives too late: it is dropped.
        names.arrive('m3');
        await settle();

        // Each fake clip lasts 0.2 s: a name, the gap, a name, the shorter gap, the phrase.
        expect(
            audio.started.map(({ sound, when, gain }) => [sound, when, gain]),
        ).toEqual([
            ['name:m1', 12.9, SPEAKER_GAIN.screen * VOICE_GAIN],
            [
                'name:m4',
                12.9 + 0.2 + NAME_GAP_MS / 1000,
                SPEAKER_GAIN.screen * VOICE_GAIN,
            ],
            [
                'finish',
                12.9 + 0.2 + NAME_GAP_MS / 1000 + 0.2 + PHRASE_GAP_MS / 1000,
                SPEAKER_GAIN.screen * VOICE_GAIN,
            ],
        ]);
        expect(VOICE_GAIN).toBeLessThan(1);
        expect(PHRASE_GAP_MS).toBe(150);
    });

    it('says the phrase alone when no name can be said', async () => {
        const names = nameLoader({ missing: ['m1'] });
        const timers = manualLater();
        const { audio, player } = namingPlayer('screen', names, timers);

        player.unlock();
        await settle();
        // A name off the list (404), then four sharing the first place (no name at all).
        player.say([nameUrl('m1')], PHRASE, 2900);
        player.say([], PHRASE, 2900);
        await settle();
        timers.fire();

        expect(audio.started.map(({ sound, when }) => [sound, when])).toEqual([
            ['finish', 12.9],
            ['finish', 12.9],
        ]);
    });

    it('fetches nothing while muted or locked', async () => {
        const timers = manualLater();

        // Locked: the screen's sound was never turned on.
        const locked = nameLoader();
        const lockedScreen = namingPlayer('screen', locked, timers);
        lockedScreen.player.say([nameUrl('m1')], PHRASE, 0);

        // Muted on this device.
        const storage = memoryStorage();
        writeMuted(storage, 'screen', true);
        const quiet = nameLoader();
        const muted = namingPlayer(
            'screen',
            quiet,
            timers,
            fakeAudio(),
            () => storage,
        );
        muted.player.unlock();
        await settle();
        muted.player.say([nameUrl('m1')], PHRASE, 0);
        timers.fire();
        await settle();

        expect(locked.asked).toEqual([]);
        expect(quiet.asked).toEqual([]);
        for (const page of [lockedScreen, muted]) {
            expect(page.files.asked).not.toContain(PHRASE);
        }
        expect(timers.asked).toEqual([]);
    });

    it("says the cheer on a phone too, at a phone's level: one in a room without a screen", async () => {
        const names = nameLoader();
        const timers = manualLater();
        const { audio, player } = namingPlayer('phone', names, timers);

        player.unlock();
        await settle();
        player.say([nameUrl('m1')], PHRASE, 2900);
        await settle();
        timers.fire();

        expect(names.asked).toEqual([nameUrl('m1')]);
        expect(audio.started.map(({ sound, gain }) => [sound, gain])).toEqual([
            ['name:m1', SPEAKER_GAIN.phone * VOICE_GAIN],
            ['finish', SPEAKER_GAIN.phone * VOICE_GAIN],
        ]);
    });

    it('drops the cheer with its moment or a hidden page', async () => {
        const names = nameLoader();
        const timers = manualLater();
        const { audio, player } = namingPlayer('screen', names, timers);

        player.unlock();
        await settle();

        // A new game began before the cheer was due.
        player.say([nameUrl('m1')], PHRASE, 2900);
        await settle();
        player.cancel();

        // The page was hidden before it was due.
        player.say([nameUrl('m2')], PHRASE, 2900);
        await settle();
        player.hide();

        // Muted before it was due.
        player.resume();
        player.say([nameUrl('m3')], PHRASE, 2900);
        await settle();
        player.setMuted(true);

        timers.fire();
        await settle();

        expect(timers.asked.map(({ cancelled }) => cancelled)).toEqual([
            true,
            true,
            true,
        ]);
        expect(audio.started).toEqual([]);
    });

    it('stops a cheer being said when its moment ends', async () => {
        const names = nameLoader();
        const timers = manualLater();
        const { audio, player } = namingPlayer('screen', names, timers);

        player.unlock();
        await settle();
        player.say([nameUrl('m1')], PHRASE, 0);
        await settle();
        timers.fire();
        audio.made[0].currentTime = 10.1;
        player.cancel();

        expect(audio.started).toMatchObject([
            { sound: 'name:m1', stopped: true },
            { sound: 'finish', stopped: true },
        ]);
    });
});

describe("roomSoundPlayer and a taunt's voice (ADR 029 as amended 2026-11-19)", () => {
    /** The taunts' clips as the prop gives them: the fake loader names each `taunt-<key>`. */
    const VOICES = {
        turtle: '/content/taunt-turtle.mp3',
        dice: '/content/taunt-dice.mp3',
        silly: '/content/taunt-silly.mp3',
    };

    function taunting(
        speaker: 'screen' | 'phone',
        {
            missing = [],
            storage = memoryStorage,
        }: { missing?: string[]; storage?: () => StorageLike | null } = {},
    ) {
        const audio = fakeAudio();
        const files = loader(missing);
        const player = roomSoundPlayer({
            speaker,
            urls: urls[speaker],
            storage,
            createContext: audio.create,
            load: files.load,
        });

        return { audio, files, player };
    }

    it('fetches the clips once sound is unlocked and on, and says a taunt at the speaker’s level times VOICE_GAIN', async () => {
        const { audio, files, player } = taunting('phone');

        // Before a gesture: nothing fetched, nothing said.
        player.voices(VOICES);
        player.taunt('turtle', 3000);
        expect(files.asked).toEqual([]);

        player.unlock();
        await settle();
        expect(files.asked).toEqual(
            expect.arrayContaining(Object.values(VOICES)),
        );
        // The same list again asks for nothing more.
        const asked = files.asked.length;
        player.voices(VOICES);
        player.voices({ ...VOICES });
        expect(files.asked).toHaveLength(asked);

        player.taunt('turtle', 3000);
        expect(audio.started).toEqual([
            {
                sound: 'taunt-turtle',
                when: 10,
                offset: 0,
                gain: SPEAKER_GAIN.phone * VOICE_GAIN,
                stopped: false,
                ramp: [],
            },
        ]);

        // The screen says it at its own, louder level.
        const screen = taunting('screen');
        screen.player.voices(VOICES);
        screen.player.unlock();
        await settle();
        screen.player.taunt('dice', 3000);
        expect(screen.audio.started.map(({ gain }) => gain)).toEqual([
            SPEAKER_GAIN.screen * VOICE_GAIN,
        ]);
    });

    it('keeps one taunt at a time and drops one whose reaction is over', async () => {
        const { audio, player } = taunting('screen');

        player.voices(VOICES);
        player.unlock();
        await settle();
        player.taunt('turtle', 3000);
        // Two more arrive while it is said: they wait.
        player.taunt('dice', 2500);
        player.taunt('silly', 500);
        expect(audio.started.map(({ sound }) => sound)).toEqual([
            'taunt-turtle',
        ]);

        // 1.2 s later the first ends: the second follows; the third's reaction is over by then and is dropped.
        audio.made[0].currentTime = 11.2;
        audio.end(audio.started[0]);
        expect(audio.started.map(({ sound, when }) => [sound, when])).toEqual([
            ['taunt-turtle', 10],
            ['taunt-dice', 11.2],
        ]);
        audio.end(audio.started[1]);
        expect(audio.started).toHaveLength(2);
    });

    it("is not cut by the round's close, and stops when the game ends, the page is hidden or the device muted", async () => {
        const { audio, player } = taunting('phone');

        player.voices(VOICES);
        player.unlock();
        await settle();

        // The round closes while it is said: its moment's cues stop, the taunt goes on.
        player.taunt('turtle', 3000);
        player.cancel();
        expect(audio.started[0]).toMatchObject({ stopped: false });

        // The game ends: it fades out within STOP_MS, and one waiting is dropped.
        player.taunt('dice', 3000);
        player.hush();
        expect(audio.started[0]).toMatchObject({
            stopped: true,
            stopAt: 10 + STOP_MS / 1000,
        });
        audio.end(audio.started[0]);
        expect(audio.started).toHaveLength(1);

        // Hidden: stopped; nothing said until shown again.
        player.taunt('silly', 3000);
        player.hide();
        expect(audio.started[1]).toMatchObject({ stopped: true });
        player.taunt('turtle', 3000);
        expect(audio.started).toHaveLength(2);
        player.resume();

        // Muted: stopped, and nothing said while muted.
        player.taunt('dice', 3000);
        player.setMuted(true);
        expect(audio.started[2]).toMatchObject({ stopped: true });
        player.taunt('turtle', 3000);
        expect(audio.started).toHaveLength(3);
    });

    it('says none that has not arrived, fetches nothing while muted, and never throws for a clip that fails', async () => {
        const storage = memoryStorage();
        writeMuted(storage, 'phone', true);

        // Muted from the start: no audio, nothing fetched.
        const muted = taunting('phone', { storage: () => storage });
        muted.player.voices(VOICES);
        muted.player.unlock();
        await settle();
        expect(muted.files.asked).toEqual([]);

        // A clip that fails stays silent; one not in the list is never said.
        const failing = taunting('phone', { missing: ['taunt-dice'] });
        failing.player.voices(VOICES);
        failing.player.unlock();
        await settle();
        expect(() => failing.player.taunt('dice', 3000)).not.toThrow();
        failing.player.taunt('what_now', 3000);
        expect(failing.audio.started).toEqual([]);
        // Said nothing when its reaction is already over.
        failing.player.taunt('turtle', 0);
        expect(failing.audio.started).toEqual([]);
    });
});
