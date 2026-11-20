import { useEffect, useRef, useState } from 'react';
import {
    nextSquares,
    tapSquare,
    untapAll,
} from '@/components/rooms/draughts/taps';
import type { TapResult } from '@/components/rooms/draughts/taps';
import type { RoomSound } from '@/components/rooms/use-room-sound';
import { store as actsRoute } from '@/routes/rooms/acts';
import { sendAct } from '@/rooms/act';
import { eventId } from '@/rooms/answer';
import type { ServerClock } from '@/rooms/clock';
import type { RoomAct, RoomMatch } from '@/rooms/contract';
import { roomRequest } from '@/rooms/http';

type Sent = {
    ply: number;
    path: string[];
    status: 'sending' | 'taken' | 'failed';
};

export type MatchActs = {
    /** The squares tapped so far at this ply: the lifted piece first. */
    taps: string[];
    /** The squares the begun path may land on next. */
    marks: Set<string>;
    /** The viewer's move posted at this ply, until the state has it. */
    pending: string[] | null;
    /** The path of the last move this page posted and was not refused: the arrival it need not slide. */
    sentPath: string[] | null;
    /** The posted move was not taken: its piece stands back. */
    failed: boolean;
    /** An offer, its answer or a resignation is on its way. */
    busy: boolean;
    tap: ((square: string) => void) | undefined;
    undo: () => void;
    act: (kind: 'offer' | 'accept' | 'decline' | 'resign') => Promise<void>;
};

const wait = (ms: number) =>
    new Promise<void>((resolve) => window.setTimeout(resolve, ms));

/**
 * A seat's taps and acts on its own phone (docs/frontend.md rule 15 "Board
 * games" → "Taps", ADR 034 decision 9): taps choose among the state's
 * legal paths (`tapSquare()`); a whole path is posted at once
 * (`sendAct()`), its piece standing on its last square until the state
 * answers and back where it was with «Gediş göndərilmədi» if it was not
 * taken. An offer, its answer and a resignation are posted the same way.
 * Every act names the ply the page saw; the page then asks for the state,
 * which alone says what happened. Nothing here keeps a clock or a sound:
 * a tap's sound is the page's (`sound.tap`).
 */
export function useMatchActs({
    roomId,
    match,
    clock,
    refresh,
    onGone,
    sound,
}: {
    roomId: string;
    match: RoomMatch;
    clock: ServerClock;
    refresh: () => void;
    onGone: () => void;
    sound: RoomSound | null;
}): MatchActs {
    const [taps, setTaps] = useState<{ ply: number; squares: string[] }>({
        ply: -1,
        squares: [],
    });
    const [sent, setSent] = useState<Sent | null>(null);
    const [busy, setBusy] = useState(false);
    // What a retry reads: the ply the page shows now.
    const shown = useRef(match);

    useEffect(() => {
        shown.current = match;
    }, [match]);

    const mine = sent?.ply === match.ply ? sent : null;
    const pending =
        mine !== null && mine.status !== 'failed' ? mine.path : null;
    const paths =
        match.phase === 'play' && match.legal !== null && pending === null
            ? match.legal.paths
            : [];
    const squares = taps.ply === match.ply ? taps.squares : [];

    const post = (act: RoomAct, keepTrying: () => boolean) =>
        sendAct({
            act,
            post: (body) => roomRequest('POST', actsRoute.url(roomId), body),
            wait,
            keepTrying,
        });

    const move = (path: string[]) => {
        const ply = match.ply;

        setSent({ ply, path, status: 'sending' });
        void post(
            { event_id: eventId(clock.now() ?? 0), kind: 'move', ply, path },
            () => shown.current.ply === ply && shown.current.phase === 'play',
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

    const apply = (result: TapResult) => {
        if (result.sound !== null) {
            sound?.tap(result.sound);
        }

        setTaps({ ply: match.ply, squares: result.taps });

        if (result.send !== null) {
            move(result.send);
        }
    };

    const act = async (kind: 'offer' | 'accept' | 'decline' | 'resign') => {
        if (busy) {
            return;
        }

        const ply = match.ply;
        const id = eventId(clock.now() ?? 0);

        setBusy(true);
        await post(
            kind === 'resign'
                ? { event_id: id, kind }
                : { event_id: id, kind, ply },
            () => shown.current.phase !== 'over',
        ).then((outcome) => {
            setBusy(false);

            if (outcome.kind === 'gone') {
                onGone();
            } else {
                refresh();
            }
        });
    };

    return {
        taps: squares,
        marks: nextSquares(paths, squares),
        pending,
        sentPath: sent !== null && sent.status !== 'failed' ? sent.path : null,
        failed: mine?.status === 'failed',
        busy,
        tap:
            paths.length > 0
                ? (square) => apply(tapSquare(paths, squares, square))
                : undefined,
        undo: () => apply(untapAll(squares)),
        act,
    };
}
