import { useEffect, useRef, useState } from 'react';
import {
    litTiles,
    putBack,
    tapTarget,
    tapTile,
    targetsOf,
} from '@/components/rooms/domino/taps';
import type { DominoTap, PlayPath } from '@/components/rooms/domino/taps';
import type { RoomSound } from '@/components/rooms/use-room-sound';
import { store as actsRoute } from '@/routes/rooms/acts';
import { sendAct } from '@/rooms/act';
import { eventId } from '@/rooms/answer';
import type { ServerClock } from '@/rooms/clock';
import type { RoomAct, RoomDomino, RoomDominoEnd } from '@/rooms/contract';
import { roomRequest } from '@/rooms/http';

type Sent = {
    ply: number;
    path: PlayPath;
    status: 'sending' | 'taken' | 'failed';
};

export type DominoActs = {
    /** The tiles `mine.legal` lights, in the viewer's own turn. */
    lit: Set<string>;
    /** The tile lifted to be placed on a target; null: none. */
    picked: string | null;
    /** The ends whose targets the lifted tile lights. */
    targets: RoomDominoEnd[];
    /** The play posted at this ply, until the state has it. */
    pending: PlayPath | null;
    /** The play this page posted last, whatever its receipt said: its arrival need not fly. */
    posted: { ply: number; path: PlayPath } | null;
    /** The posted play was not taken: its tile is back in the hand. */
    failed: boolean;
    /** A «Sən aç» or «Oyuna qayıt» is on its way. */
    busy: boolean;
    tapTile: (tile: string) => void;
    tapTarget: (end: RoomDominoEnd) => void;
    putBack: () => void;
    yieldOpening: () => void;
    comeBack: () => void;
};

const wait = (ms: number) =>
    new Promise<void>((resolve) => window.setTimeout(resolve, ms));

/**
 * A seat's taps and acts on its own phone (docs/frontend.md rule 15
 * "Domino" → "Taps", "Away"; ADR 038 decisions 46, 88 and 26): taps choose
 * among the state's plays alone (`taps.ts`); a play is posted at once
 * (`sendAct()`), its tile standing at its end until the state answers, and
 * back in the hand with «Daş qoyulmadı. Yenidən cəhd edin.» if it was not
 * taken. «Tərəfdaşım açsın» and «Oyuna qayıt» are posted the same way.
 * Every act names its game and the ply the page saw; the page then asks
 * for the state, which alone says what happened, and a retry stops once
 * the page shows another ply or another game. Nothing here keeps a clock
 * or a sound: «Geri»'s `undo` is the page's (`sound.tap`).
 */
export function useDominoActs({
    roomId,
    gameId,
    domino,
    clock,
    refresh,
    onGone,
    sound,
}: {
    roomId: string;
    gameId: string;
    domino: RoomDomino;
    clock: ServerClock;
    refresh: () => void;
    onGone: () => void;
    sound: RoomSound | null;
}): DominoActs {
    const [pick, setPick] = useState<{ ply: number; tile: string } | null>(
        null,
    );
    const [sent, setSent] = useState<Sent | null>(null);
    const [busy, setBusy] = useState(false);
    // What a retry reads: the game and the state the page shows now — none once the game is gone from it.
    const shown = useRef<{ game: string; domino: RoomDomino } | null>({
        game: gameId,
        domino,
    });

    useEffect(() => {
        shown.current = { game: gameId, domino };

        return () => {
            shown.current = null;
        };
    }, [gameId, domino]);

    const showing = (test: (domino: RoomDomino) => boolean) =>
        shown.current !== null &&
        shown.current.game === gameId &&
        test(shown.current.domino);

    const mine = sent?.ply === domino.ply ? sent : null;
    const pending =
        mine !== null && mine.status !== 'failed' ? mine.path : null;
    const legal =
        domino.phase === 'play' && pending === null
            ? (domino.mine?.legal ?? null)
            : null;
    const picked = pick?.ply === domino.ply ? pick.tile : null;

    const post = (act: RoomAct, keepTrying: () => boolean) =>
        sendAct({
            act,
            post: (body) => roomRequest('POST', actsRoute.url(roomId), body),
            wait,
            keepTrying,
        });

    const play = (path: PlayPath) => {
        const ply = domino.ply;

        setSent({ ply, path, status: 'sending' });
        void post(
            {
                event_id: eventId(clock.now() ?? 0),
                game_id: gameId,
                kind: 'play',
                ply,
                path,
            },
            () =>
                showing(
                    (shownDomino) =>
                        shownDomino.ply === ply && shownDomino.phase === 'play',
                ),
        ).then((outcome) => {
            if (outcome.kind === 'gone') {
                onGone();

                return;
            }

            const taken =
                outcome.kind === 'receipt' && outcome.receipt.status !== 'late';

            setSent({ ply, path, status: taken ? 'taken' : 'failed' });
            refresh();
        });
    };

    const apply = (result: DominoTap) => {
        if (result.sound !== null) {
            sound?.tap(result.sound);
        }

        setPick(
            result.picked === null
                ? null
                : { ply: domino.ply, tile: result.picked },
        );

        if (mine?.status === 'failed') {
            // A new tap after a refusal: «Daş qoyulmadı» has been read.
            setSent(null);
        }

        if (result.send !== null) {
            play(result.send);
        }
    };

    const once = async (act: RoomAct, keepTrying: () => boolean) => {
        if (busy) {
            return;
        }

        setBusy(true);
        const outcome = await post(act, keepTrying);

        setBusy(false);

        if (outcome.kind === 'gone') {
            onGone();
        } else {
            refresh();
        }
    };

    return {
        lit: litTiles(legal),
        picked,
        targets: targetsOf(legal, picked),
        pending,
        posted: sent,
        failed: mine?.status === 'failed',
        busy,
        tapTile: (tile) => apply(tapTile(legal, picked, tile)),
        tapTarget: (end) => apply(tapTarget(legal, picked, end)),
        putBack: () => apply(putBack(picked)),
        yieldOpening: () => {
            const ply = domino.ply;

            void once(
                {
                    event_id: eventId(clock.now() ?? 0),
                    game_id: gameId,
                    kind: 'yield',
                    ply,
                },
                () => showing((shownDomino) => shownDomino.ply === ply),
            );
        },
        comeBack: () =>
            void once(
                {
                    event_id: eventId(clock.now() ?? 0),
                    game_id: gameId,
                    kind: 'back',
                },
                () => showing((shownDomino) => shownDomino.phase !== 'over'),
            ),
    };
}
