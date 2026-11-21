import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import {
    carriesRoom,
    holdsSeat,
    roomCues,
    sameMoment,
    tauntsLive,
} from '@/components/rooms/sound-cues';
import type { RoomEar } from '@/components/rooms/sound-cues';
import type { ServerClock } from '@/rooms/clock';
import type { RoomState } from '@/rooms/contract';
import { roomSoundPlayer } from '@/rooms/sound';
import type {
    PhoneSound,
    RoomFinishVoice,
    RoomSoundState,
    RoomSoundUrls,
    RoomSpeaker,
    TauntVoiceUrls,
} from '@/rooms/sound';

/** How often the page listens for a beat of the clock (a countdown's second, a last second). */
const LISTEN_MS = 200;

/**
 * What the screen says after the final's fanfare (ADR 030): a member's
 * first-name clip by ids, and the end phrases' URLs from its props.
 */
export type RoomCheer = {
    nameUrl: (playerId: string) => string;
    finish: RoomFinishVoice;
};

/**
 * What a page says of taunts (ADR 029 decisions 44–48): the clips of its
 * prop `taunts`, by key, and whether this device hides taunts — its
 * «Mənə atılanları gizlət», the screen's «Atmacalar» — which silences them
 * with their reactions.
 */
export type RoomTauntVoices = { voices: TauntVoiceUrls; hidden: boolean };

/** What a page's sound controls offer: its state, the toggle and the phone's own taps. */
export type RoomSound = RoomSoundState & {
    /** The toggle, a gesture: on the screen it first unlocks; then it mutes and unmutes. */
    toggle: () => void;
    /** A phone's own tap or undo, played where it is made. */
    tap: (sound: Extract<PhoneSound, 'tap' | 'undo'>) => void;
};

const NO_SOUND: RoomSound = {
    muted: false,
    locked: true,
    toggle: () => {},
    tap: () => {},
};

const noSubscription = () => () => {};

/** The gestures that let a page make sound (a touch counts when it ends). */
const GESTURES = ['pointerup', 'touchend', 'keydown', 'click'] as const;

/**
 * Whether the browser lets this document make sound before a new gesture:
 * it already had one (`userActivation`, kept across Inertia visits), or
 * says so itself (`getAutoplayPolicy`, where there is one).
 */
function allowedWithoutGesture(): boolean {
    try {
        const browser = navigator as Navigator & {
            userActivation?: { hasBeenActive: boolean };
            getAutoplayPolicy?: (type: 'audiocontext') => string;
        };

        return (
            browser.userActivation?.hasBeenActive === true ||
            browser.getAutoplayPolicy?.('audiocontext') === 'allowed'
        );
    } catch {
        return false;
    }
}

/**
 * A room page's sound (ADR 025 "Sound", ADR 028, docs/frontend.md rule 15
 * "Sound"): the player of its speaker's files (`sounds`, null for none),
 * fed with what the page hears — the state and the estimated server clock
 * every 200 ms — through `roomCues()`; a phone in a room without a screen
 * carries the room's cues too (`carriesRoom()`). The first gesture on the page
 * unlocks sound unless the device muted it (at once where the document
 * already had one, as after «Qoşul»). Every cue stops when its moment
 * ends — one scheduled is dropped, one sounding fades out at once — and
 * when the page is hidden; coming back wakes the sound where the browser
 * lets it and plays nothing that was missed.
 *
 * `cheer` (ADR 030): the route of a member's first-name clip, by ids,
 * and the URLs of the end phrases, for what it says after the final's
 * fanfare — the screen's, and a phone's while it carries a room without
 * a screen (as amended 2026-11-19). A page without it says nothing.
 *
 * A match's seat (ADR 034 "Amended 2026-11-21 — whose turn it is") hears
 * its own turns on its phone, with a screen too: the player fetches their
 * files once the viewer holds a seat (`holdsSeat()`), and a `buzz` cue is
 * the player's one vibration.
 *
 * `taunts` (ADR 029 as amended 2026-11-19): the taunts' clips and the
 * device's choice to hide them. The page says each taunt it draws and saw
 * arrive — the target's phone, the screen — fetching the clips only while
 * the room's taunts are on and this device draws them, and a taunt being
 * said stops when they no longer are: the game over, the room closed, the
 * host's switch off, the taunts hidden. A page without it says none.
 */
export function useRoomSound({
    speaker,
    sounds,
    state,
    clock,
    cheer,
    taunts,
}: {
    speaker: RoomSpeaker;
    sounds: RoomSoundUrls | null;
    state: RoomState | null;
    clock: ServerClock;
    cheer?: RoomCheer;
    taunts?: RoomTauntVoices;
}): RoomSound {
    const [player] = useState(() =>
        sounds === null ? null : roomSoundPlayer({ speaker, urls: sounds }),
    );
    const snapshot = useSyncExternalStore(
        player?.subscribe ?? noSubscription,
        player?.snapshot ?? (() => NO_SOUND),
        () => NO_SOUND,
    );
    const stateRef = useRef(state);
    const cheerRef = useRef(cheer);
    const tauntsRef = useRef(taunts);
    const heard = useRef<RoomEar | null>(null);
    const listenRef = useRef<() => void>(() => {});

    useEffect(() => {
        cheerRef.current = cheer;
    }, [cheer]);

    // A taunt hidden now is silenced at once, with its reaction.
    useEffect(() => {
        tauntsRef.current = taunts;
        listenRef.current();
    }, [taunts]);

    // A new state is heard at once, not at the next beat of the clock.
    useEffect(() => {
        stateRef.current = state;
        listenRef.current();
    }, [state]);

    useEffect(() => {
        if (player === null) {
            return;
        }

        const listen = () => {
            const ear = { state: stateRef.current, now: clock.now() };
            const previous = heard.current;

            // A phone in a room without a screen fetches the room's files too; a match's seat, its turn's.
            if (speaker === 'phone' && carriesRoom(ear.state)) {
                player.carry();
            }

            if (speaker === 'phone' && holdsSeat(ear.state)) {
                player.seat();
            }

            // The taunts' clips while this device draws taunts; a taunt being said stops when it no longer does.
            const taunting = tauntsRef.current;
            const draws =
                taunting !== undefined &&
                !taunting.hidden &&
                tauntsLive(ear.state) &&
                (speaker === 'screen' || ear.state?.me.player_id != null);

            if (draws) {
                player.voices(taunting.voices);
            } else {
                player.hush();
            }

            if (previous !== null && !sameMoment(previous, ear, speaker)) {
                player.cancel();
            }

            for (const cue of roomCues(previous, ear, speaker, {
                tauntsHidden: !draws,
            })) {
                const voice = cheerRef.current;

                if (cue.sound === 'taunt') {
                    player.taunt(cue.key, cue.until);
                } else if (cue.sound === 'buzz') {
                    player.buzz(cue.at);
                } else if (cue.sound !== 'cheer') {
                    player.play(cue.sound, cue);
                } else if (voice !== undefined) {
                    player.say(
                        cue.players.map(voice.nameUrl),
                        cue.many ? voice.finish.many : voice.finish.one,
                        cue.at,
                    );
                }
            }

            heard.current = ear;
        };
        const unlock = (event?: Event) => {
            // The sound toggle decides for itself what its press means.
            const target = event?.target;

            if (
                target instanceof Element &&
                target.closest('[data-room-sound]') !== null
            ) {
                return;
            }

            if (player.snapshot().locked) {
                player.unlock();
            }
        };
        const visible = () => {
            if (document.visibilityState === 'visible') {
                player.resume();
            } else {
                player.hide();
            }
        };

        listenRef.current = listen;

        if (document.visibilityState === 'hidden') {
            player.hide();
        }

        listen();

        // A page reached by a tap (Inertia keeps the document) may sound at once.
        if (allowedWithoutGesture()) {
            unlock();
        }

        const timer = window.setInterval(listen, LISTEN_MS);
        GESTURES.forEach((name) =>
            document.addEventListener(name, unlock, { capture: true }),
        );
        document.addEventListener('visibilitychange', visible);

        return () => {
            listenRef.current = () => {};
            window.clearInterval(timer);
            GESTURES.forEach((name) =>
                document.removeEventListener(name, unlock, { capture: true }),
            );
            document.removeEventListener('visibilitychange', visible);
            player.release();
        };
    }, [player, clock, speaker]);

    if (player === null) {
        return NO_SOUND;
    }

    return {
        ...snapshot,
        // The screen's first press turns the room's sound on; after that, and on a phone, it mutes.
        toggle: () => {
            const now = player.snapshot();

            if (speaker === 'screen' && now.locked && !now.muted) {
                player.unlock();
            } else {
                player.setMuted(!now.muted);
            }
        },
        tap: (sound) => player.play(sound),
    };
}
