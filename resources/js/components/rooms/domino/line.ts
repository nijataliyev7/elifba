import {
    turnSteps,
    viewerSeat,
    yourPlay,
} from '@/components/rooms/domino/turn';
import type { RoomDomino } from '@/rooms/contract';

/*
 * The line under a domino table (docs/frontend.md rule 15 "Domino" → "The
 * line under the table", "What happened by itself", "Whose turn it is"),
 * the part that is pure: its parts as keys with their icon, in the order
 * they are said. It reads the state alone — who is to move, who passed or
 * drew, a turn the server played — and the page's own taps; it judges
 * nothing.
 */

export type LineIcon = 'hand' | 'hourglass' | 'bot' | 'eye' | 'flag' | 'alert';

export type LinePart = {
    /** What a new part is: a new one pops in. */
    id: string;
    key: string;
    params: Record<string, string | number>;
    icon: LineIcon;
    /** The seat's own turn: the band in the left side's deep colour. */
    band?: boolean;
    /** A play not taken: the wrong colour. */
    wrong?: boolean;
};

/** What the line reads beside the state: the page's taps and how it names a seat. */
export type LineInput = {
    viewer: string | null;
    /** A tile that fits both ends is lifted: «Ucu seçin…». */
    picking: boolean;
    /** The viewer's play was not taken. */
    failed: boolean;
    /** The server's seconds to the game's start, for the countdown. */
    seconds: number;
    /** The screen: nobody's device, so no «Oyunu izləyirsiniz». */
    screen?: boolean;
    nameOf: (seat: number) => string;
};

/**
 * The line's parts, the most important last — a phone shows the last two:
 * in the countdown «Oyun :seconds saniyəyə başlayır»; for a watcher «Oyunu
 * izləyirsiniz» first; a turn the server played for a player; the viewer's
 * own pass; and whose turn it is — for the seat to move the band
 * «Sizin növbənizdir» (or at a hand's first tile «:tile ilə açın», «İstədiyiniz
 * daşla açın»), with what it drew or what its partner gave it before it;
 * «Ucu seçin…» while a tile is lifted; a play not taken in the wrong
 * colour.
 */
export function lineParts(domino: RoomDomino, input: LineInput): LinePart[] {
    const seat = viewerSeat(domino, input.viewer);
    const parts: LinePart[] = [];

    if (seat === null && input.screen !== true) {
        parts.push({
            id: 'watching',
            key: 'rooms.domino.watching',
            params: {},
            icon: 'eye',
        });
    }

    if (domino.phase === 'countdown') {
        parts.push({
            id: 'starts',
            key: 'rooms.domino.starts_in',
            params: { seconds: input.seconds },
            icon: 'hourglass',
        });

        return parts;
    }

    if (domino.phase !== 'play') {
        return parts;
    }

    const last = domino.last;
    const lastPlayer =
        last === null ? null : (domino.seats[last.seat]?.player_id ?? null);

    if (last !== null && last.stood_in && lastPlayer !== null) {
        parts.push({
            id: `stood:${domino.ply}`,
            key: 'rooms.domino.stood_in',
            params: { name: input.nameOf(last.seat) },
            icon: 'bot',
        });
    }

    if (
        seat !== null &&
        last !== null &&
        last.then.some((step) => step.seat === seat && step.passed)
    ) {
        parts.push({
            id: `pass:${domino.ply}`,
            key: 'rooms.domino.pass_own',
            params: {},
            icon: 'flag',
        });
    }

    if (yourPlay(domino, input.viewer)) {
        const drew =
            last?.then.find((step) => step.seat === seat && step.drew > 0)
                ?.drew ?? 0;

        if (drew > 0) {
            parts.push({
                id: `drew:${domino.ply}`,
                key: 'rooms.domino.drew_own',
                params: { count: drew },
                icon: 'hand',
            });
        } else if (last?.kind === 'yield') {
            parts.push({
                id: `yielded:${domino.ply}`,
                key: 'rooms.domino.yielded',
                params: {},
                icon: 'hand',
            });
        }

        parts.push(
            input.failed
                ? {
                      id: `failed:${domino.ply}`,
                      key: 'rooms.domino.play_failed',
                      params: {},
                      icon: 'alert',
                      wrong: true,
                  }
                : input.picking
                  ? {
                        id: `pick:${domino.ply}`,
                        key: 'rooms.domino.pick_end',
                        params: {},
                        icon: 'hand',
                        band: true,
                    }
                  : { ...yourTurnWords(domino), id: `turn:${domino.ply}` },
        );

        return parts;
    }

    const mover = domino.seats[domino.turn];

    parts.push({
        id: `their:${domino.turn}`,
        key: 'rooms.domino.their_turn',
        params: { name: input.nameOf(domino.turn) },
        icon:
            mover === undefined || mover.standing === 'playing'
                ? 'hourglass'
                : 'bot',
    });

    return parts;
}

/**
 * The band's words: «Sizin növbənizdir», and at a hand's first tile the
 * tile R6 or R7 opens with — the one play offered, when the hand opened
 * so — or «İstədiyiniz daşla açın» for a seat with the right (R8, R9).
 */
function yourTurnWords(
    domino: Extract<RoomDomino, { phase: 'play' }>,
): Omit<LinePart, 'id'> {
    const legal = domino.mine?.legal ?? null;
    const first = domino.line.length === 0;
    const forced =
        domino.hand.why === 'lowest_double' ||
        domino.hand.why === 'lowest_tile';

    if (first && forced && legal !== null && legal.length === 1) {
        return {
            key: 'rooms.domino.open_with',
            params: { tile: legal[0].tile },
            icon: 'hand',
            band: true,
        };
    }

    return {
        key: first ? 'rooms.domino.open_any' : 'rooms.domino.your_turn',
        params: {},
        icon: 'hand',
        band: true,
    };
}

/**
 * When the seat to move's turn begins, ms after its moment began: after
 * the steps that came before it (`turnSteps()`), so the band pops in, as
 * `turn` sounds, when the last step ends.
 */
export function turnBeginsAt(domino: Pick<RoomDomino, 'last'>): number {
    return turnSteps(domino).ends;
}
