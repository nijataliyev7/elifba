import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import type { RoomMatchState } from '@/components/rooms/checkers/match';
import type { RoomSound } from '@/components/rooms/use-room-sound';
import { roomSoundPlayer } from '@/rooms/sound';
import type { RoomSoundUrls, RoomSpeaker } from '@/rooms/sound';

const GESTURES = ['pointerdown', 'keydown'] as const;

const SILENT: RoomSound = {
    muted: false,
    locked: true,
    toggle: () => {},
    tap: () => {},
};

/**
 * A board match's sound (ADR 034 decision 12), from the room's own files
 * (`content/rooms/sounds.json`) — no new file. The room's moments are the
 * screen's, and a phone's in a room without a screen (`carries`, ADR 028 as
 * amended): «go» when a match the page saw begin starts, «question» when a
 * move gives the turn to a side, «fanfare» when a match the page watched
 * ends. A phone's own taps are quiet («tap» on a piece, «sent» on a move
 * made), played where they are made. Each device keeps its own mute, and
 * nothing sounds before a gesture unlocks the page's audio.
 */
export function useMatchSound({
    speaker,
    sounds,
    view,
    carries,
}: {
    speaker: RoomSpeaker;
    sounds: RoomSoundUrls | null;
    view: RoomMatchState | null;
    /** A phone in a room without a screen plays the room's moments too. */
    carries: boolean;
}): RoomSound & { sent: () => void } {
    const [player] = useState(() =>
        sounds === null ? null : roomSoundPlayer({ speaker, urls: sounds }),
    );
    const snapshot = useSyncExternalStore(
        player?.subscribe ?? (() => () => {}),
        player?.snapshot ?? (() => SILENT),
        () => SILENT,
    );
    const heard = useRef<{ id: string; ply: number; over: boolean } | null>(
        null,
    );

    useEffect(() => {
        if (player === null) {
            return;
        }

        const unlock = (event: Event) => {
            const target = event.target;

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
        const visible = () =>
            document.visibilityState === 'visible'
                ? player.resume()
                : player.hide();

        GESTURES.forEach((name) =>
            document.addEventListener(name, unlock, { capture: true }),
        );
        document.addEventListener('visibilitychange', visible);

        return () => {
            GESTURES.forEach((name) =>
                document.removeEventListener(name, unlock, { capture: true }),
            );
            document.removeEventListener('visibilitychange', visible);
            player.release();
        };
    }, [player]);

    useEffect(() => {
        if (player !== null && speaker === 'phone' && carries) {
            player.carry();
        }
    }, [player, speaker, carries]);

    // Each moment once, and only one the page saw happen: a first read only takes note.
    useEffect(() => {
        const match = view?.match ?? null;

        if (match === null) {
            return;
        }

        const before = heard.current;
        heard.current = { id: match.id, ply: match.ply, over: match.over };
        const room = player !== null && (speaker === 'screen' || carries);

        if (before === null || !room) {
            return;
        }

        if (before.id !== match.id) {
            if (!match.over) {
                player.play('go');
            }

            return;
        }

        if (match.over && !before.over) {
            player.play('fanfare');
        } else if (match.ply > before.ply) {
            player.play('question');
        }
    }, [view, player, speaker, carries]);

    if (player === null) {
        return { ...SILENT, sent: () => {} };
    }

    return {
        ...snapshot,
        toggle: () => {
            const now = player.snapshot();

            if (speaker === 'screen' && now.locked && !now.muted) {
                player.unlock();
            } else {
                player.setMuted(!now.muted);
            }
        },
        tap: (sound) => player.play(sound),
        sent: () => player.play('sent'),
    };
}
