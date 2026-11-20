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
 * "Motion", ADR 034 decision 30 as amended 2026-11-21, «Qalxma + oturma»):
 * a piece standing on its square, which rises a little over a shadow of
 * its own when the viewer lifts it; the piece of a move the page saw
 * arrive, raised, sliding along the path a leg at a time and settling onto
 * the board as it lands — the moment its sound plays; and the pieces it
 * took fading once it has landed. Transform and opacity only. Under
 * reduced motion every one stands on its square at once: nothing rises,
 * slides, settles or fades, and a lifted piece shows its ring alone.
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

/** The piece with its ring, the part that rises (`raised`): 2px up and 1.06 larger, in 80 ms. */
const lift = 'relative grid size-[80%] place-items-center';
const raised = 'motion-safe:-translate-y-0.5 motion-safe:scale-[1.06]';

/** The shadow under a piece that is up: it grows and moves off, in 120 ms. */
function PieceShadow({ up }: { up: boolean }) {
    return (
        <span
            aria-hidden
            className={cn(
                'absolute size-[80%] rounded-full bg-[radial-gradient(closest-side,rgb(0_0_0/0.38),rgb(0_0_0/0.16)_70%,transparent)] opacity-0 transition-[opacity,translate,scale] duration-[120ms] ease-out motion-reduce:hidden',
                up &&
                    'motion-safe:translate-x-[1.5px] motion-safe:translate-y-[3px] motion-safe:scale-[1.12] motion-safe:opacity-100',
            )}
        />
    );
}

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
            <PieceShadow up={lifted} />
            <span
                className={cn(
                    lift,
                    'transition-transform duration-[80ms] ease-out motion-reduce:transition-none',
                    lifted && raised,
                )}
            >
                {/* The lifted ring: its own element, so the piece keeps its own shadows. */}
                <span
                    aria-hidden
                    className={cn(
                        'absolute inset-0 rounded-full opacity-0 shadow-[0_0_0_1px_var(--draughts-dark),0_0_0_4px_var(--primary)] transition-opacity duration-[80ms] motion-reduce:transition-none',
                        lifted && 'opacity-100',
                    )}
                />
                <DraughtsPiece
                    color={piece.color}
                    king={piece.king}
                    className="size-full"
                />
            </span>
        </span>
    );
}

/**
 * The piece of a move the page saw arrive: drawn at the path's start,
 * raised over its shadow as the first leg begins, slid along the path a
 * leg at a time, and settled onto the board as the last leg ends (140 ms,
 * `animate-draughts-settle`); a man that crowned turns into a king, with
 * one pop, from the landing where it crowned. Under reduced motion it
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
    const [landed, setLanded] = useState(still);
    const { legMs, ms } = slideOf(arrival.last);
    const crown = crownedAt(arrival.last, piece.color);

    useEffect(() => {
        if (still) {
            return;
        }

        // The first leg starts once the piece has been drawn at the start; the last one ends where it lands.
        const timers = [
            ...Array.from({ length: legs }, (_, index) =>
                window.setTimeout(() => setLeg(index + 1), index * legMs + 16),
            ),
            window.setTimeout(() => setLanded(true), ms + 16),
        ];

        return () => timers.forEach((timer) => window.clearTimeout(timer));
    }, [still, legs, legMs, ms]);

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
            <PieceShadow up={leg > 0 && !landed} />
            <span
                className={cn(
                    lift,
                    landed
                        ? !still && 'motion-safe:animate-draughts-settle'
                        : 'transition-transform duration-[80ms] ease-out',
                    leg > 0 && !landed && raised,
                )}
            >
                <DraughtsPiece
                    key={king ? 'king' : 'man'}
                    color={piece.color}
                    king={king}
                    className={cn(
                        'size-full',
                        crown !== null &&
                            king &&
                            !still &&
                            'motion-safe:animate-room-pop',
                    )}
                />
            </span>
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
