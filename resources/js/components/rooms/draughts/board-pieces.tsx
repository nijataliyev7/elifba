import { useEffect, useState } from 'react';
import type { CSSProperties } from 'react';
import {
    cellOf,
    crownedAt,
    FADE_MS,
    piecesBySquare,
    slideOf,
} from '@/components/rooms/draughts/board';
import { DraughtsPiece } from '@/components/rooms/draughts/piece';
import { reducedMotion } from '@/components/rooms/motion-dom';
import { cn } from '@/lib/utils';
import type { RoomLastMove, RoomPiece } from '@/rooms/contract';

/*
 * The pieces on the board (docs/frontend.md rule 15 "Board games" →
 * "Motion", ADR 034 decision 30): a piece standing on its square, the
 * piece of a move the page saw arrive sliding along the path a leg at a
 * time, and the pieces it took fading once it has landed. Under reduced
 * motion every one stands on its square at once.
 */

/** A move the page saw arrive (ADR 034 decision 30): its path, and the position it was made from. */
export type Arrival = { last: RoomLastMove; before: RoomPiece[]; key: string };

/** The position with the viewer's posted move: its piece moved to the path's last square until the state answers. */
export function pendingPosition(
    position: RoomPiece[],
    pending: string[] | null,
): RoomPiece[] {
    if (pending === null || pending.length < 2) {
        return position;
    }

    const from = pending[0];
    const to = pending[pending.length - 1];

    return position.map((piece) =>
        piece.square === from ? { ...piece, square: to } : piece,
    );
}

/** A piece's box on the board, 12.5% a side at its square. */
function placeOf(square: string, flipped: boolean): CSSProperties {
    const { col, row } = cellOf(square, flipped);

    return { left: `${col * 12.5}%`, top: `${row * 12.5}%` };
}

const box = 'absolute grid size-[12.5%] place-items-center';

export function PlacedPiece({
    piece,
    flipped,
    lifted,
}: {
    piece: RoomPiece;
    flipped: boolean;
    lifted: boolean;
}) {
    return (
        <span className={box} style={placeOf(piece.square, flipped)}>
            <DraughtsPiece
                color={piece.color}
                king={piece.king}
                className={cn(
                    'size-[80%] transition-transform duration-[80ms] motion-reduce:transition-none',
                    lifted &&
                        'scale-110 ring-[3px] ring-primary ring-offset-1 ring-offset-draughts-dark',
                )}
            />
        </span>
    );
}

/**
 * The piece of a move the page saw arrive: drawn at the path's start and
 * slid along it a leg at a time; a man that crowned turns into a king,
 * with one pop, from the landing where it crowned. Under reduced motion it
 * stands on its square at once.
 */
export function MovingPiece({
    piece,
    arrival,
    flipped,
}: {
    piece: RoomPiece;
    arrival: Arrival;
    flipped: boolean;
}) {
    const path = arrival.last.path;
    const legs = path.length - 1;
    const [still] = useState(() => reducedMotion());
    const [leg, setLeg] = useState(still ? legs : 0);
    const { legMs } = slideOf(arrival.last);
    const crown = crownedAt(arrival.last, piece.color);

    useEffect(() => {
        if (still) {
            return;
        }

        // The first leg starts once the piece has been drawn at the start.
        const timers = Array.from({ length: legs }, (_, index) =>
            window.setTimeout(() => setLeg(index + 1), index * legMs + 16),
        );

        return () => timers.forEach((timer) => window.clearTimeout(timer));
    }, [still, legs, legMs]);

    const end = cellOf(path[legs], flipped);
    const at = cellOf(path[leg], flipped);
    const king = piece.king && (crown === null || leg >= crown);

    return (
        <span
            data-moving
            className={cn(box, 'z-[2] ease-out')}
            style={{
                ...placeOf(path[legs], flipped),
                transform: `translate(${(at.col - end.col) * 100}%, ${(at.row - end.row) * 100}%)`,
                transition:
                    still || leg === 0 ? 'none' : `transform ${legMs}ms`,
            }}
        >
            <DraughtsPiece
                key={king ? 'king' : 'man'}
                color={piece.color}
                king={king}
                className={cn(
                    'size-[80%]',
                    crown !== null &&
                        king &&
                        !still &&
                        'motion-safe:animate-room-pop',
                )}
            />
        </span>
    );
}

/** The pieces a move the page saw arrive took: they stand until it has landed, then fade. */
export function Taken({
    arrival,
    flipped,
}: {
    arrival: Arrival;
    flipped: boolean;
}) {
    const [still] = useState(() => reducedMotion());
    const [gone, setGone] = useState(still);
    const [faded, setFaded] = useState(still);
    const { ms } = slideOf(arrival.last);
    const before = piecesBySquare(arrival.before);

    useEffect(() => {
        if (still) {
            return;
        }

        const fade = window.setTimeout(() => setGone(true), ms);
        const end = window.setTimeout(() => setFaded(true), ms + FADE_MS);

        return () => {
            window.clearTimeout(fade);
            window.clearTimeout(end);
        };
    }, [still, ms]);

    if (faded) {
        return null;
    }

    return arrival.last.captured.map((square) => {
        const piece = before.get(square);

        return piece === undefined ? null : (
            <span
                key={square}
                className={box}
                style={{
                    ...placeOf(square, flipped),
                    opacity: gone ? 0 : 1,
                    transition: `opacity ${FADE_MS}ms`,
                }}
            >
                <DraughtsPiece
                    color={piece.color}
                    king={piece.king}
                    className="size-[80%]"
                />
            </span>
        );
    });
}
