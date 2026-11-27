import type { RoomDomino, RoomDominoEnd } from '@/rooms/contract';

/*
 * A domino game's turns on a page (docs/frontend.md rule 15 "Domino" →
 * "Whose turn it is", "Motion", "Sound"; ADR 038 decisions 47, 51 and 89),
 * the part that is pure: which seat the viewer holds, whether it is the
 * viewer's turn, and the one timeline of a turn's steps — the act, then
 * what followed it by itself (`last.then`) a step at a time — which the
 * room's cues read (`roomCues()`) and the motion reads too, so a sound and
 * the step it belongs to keep one moment. Nothing here judges a play,
 * counts a pip or decides whose turn it is: the state says it.
 */

/**
 * A play's motion as the owner chose it («oturma», ADR 038 decision 89):
 * the tile lifts, flies to its place and settles, in ms.
 */
export const PLAY_MS = { lift: 90, fly: 280, settle: 140 } as const;

/** When a play's tile has settled, from its moment's start: its sound plays then. */
export const PLAY_LANDS_MS = PLAY_MS.lift + PLAY_MS.fly + PLAY_MS.settle;

/** How long one step of `last.then` takes: a seat drawing, a seat passing (ADR 038 decision 89). */
export const STEP_MS = { draw: 550, pass: 900 } as const;

/** How long «PAS» stands on a seat's chip (docs/frontend.md rule 15 "Domino" → "What happened by itself"). */
export const PASS_SHOWN_MS = 2000;

/** A hand's sheet rises — and a game's end closes — this long after the last step. */
export const AFTER_STEPS_MS = 300;

/**
 * A step of a turn, at `at` ms from the moment's start: the act — a play,
 * which lands at `lands`, or a «Sən aç» — then each seat that drew (how
 * many, never which) and each seat that passed, in the state's order.
 */
export type TurnStep =
    | {
          kind: 'play';
          seat: number;
          tile: string;
          end: RoomDominoEnd | null;
          /** A double, which lies across the line. */
          double: boolean;
          at: number;
          lands: number;
      }
    | { kind: 'yield'; seat: number; at: number }
    | { kind: 'draw'; seat: number; count: number; at: number }
    | { kind: 'pass'; seat: number; at: number };

/** A turn's steps and when the last one has ended: the seat to move's turn begins then. */
export type TurnSteps = { steps: TurnStep[]; ends: number };

/**
 * The steps of the turn the state shows (ADR 038 decision 89): the latest
 * act of the hand at 0 — a play settles at `PLAY_LANDS_MS`, a «Sən aç» is
 * at once — then `last.then` a step at a time: a seat that drew a step of
 * `STEP_MS.draw`, a seat that passed one of `STEP_MS.pass`, a seat that
 * drew and still passed both. Nothing before the hand's first act: a hand
 * that just opened, or the countdown, has no step and ends at 0.
 */
export function turnSteps(domino: Pick<RoomDomino, 'last'>): TurnSteps {
    const last = domino.last;

    if (last === null) {
        return { steps: [], ends: 0 };
    }

    const steps: TurnStep[] = [];
    let at = 0;

    if (last.kind === 'play') {
        steps.push({
            kind: 'play',
            seat: last.seat,
            tile: last.tile,
            end: last.end,
            double: last.tile[0] === last.tile[2],
            at: 0,
            lands: PLAY_LANDS_MS,
        });
        at = PLAY_LANDS_MS;
    } else {
        steps.push({ kind: 'yield', seat: last.seat, at: 0 });
    }

    for (const then of last.then) {
        if (then.drew > 0) {
            steps.push({ kind: 'draw', seat: then.seat, count: then.drew, at });
            at += STEP_MS.draw;
        }

        if (then.passed) {
            steps.push({ kind: 'pass', seat: then.seat, at });
            at += STEP_MS.pass;
        }
    }

    return { steps, ends: at };
}

/** The seat the viewer holds at the table, or null: a watcher, a host who does not sit, the screen (`viewer` null). */
export function viewerSeat(
    domino: Pick<RoomDomino, 'seats'>,
    viewer: string | null,
): number | null {
    if (viewer === null) {
        return null;
    }

    return domino.seats.find((seat) => seat.player_id === viewer)?.seat ?? null;
}

/**
 * Whether it is the viewer's turn (ADR 038 decision 51, as `yourTurn()` is
 * for a match): a hand is being played and the viewer holds the seat to
 * move — and plays it: a seat the server is playing for while its player
 * is away is not that player's turn (ADR 038 decision 90).
 */
export function yourPlay(
    domino: Pick<RoomDomino, 'phase' | 'turn' | 'seats'>,
    viewer: string | null,
): boolean {
    const seat = viewerSeat(domino, viewer);

    return (
        domino.phase === 'play' &&
        seat !== null &&
        domino.turn === seat &&
        domino.seats[seat]?.standing === 'playing'
    );
}
