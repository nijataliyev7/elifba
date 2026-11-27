import { isComputerKey, seatKey } from '@/components/rooms/computer';
import {
    balanceChanges,
    PAIR_KEY,
    sideKey,
    sideOfKey,
    viewerSide,
} from '@/components/rooms/domino/sides';
import type {
    RoomPlayer,
    RoomRankedPlayer,
    RoomResult,
    RoomSpent,
    RoomState,
} from '@/rooms/contract';

/** What a moment changed one row's total by: a round's result, or a match's capture (`stripChange()`). */
export type TotalChange = RoomResult | { player_id: string; points: number };

/*
 * Rivals face to face (docs/architecture.md §12 "Rivals face to face (P2)",
 * ADR 027 decisions 3–5 and 15), the part that is pure: which two rows of
 * the state's ranking a device shows face to face, the gap between their
 * totals and the bar's split, and whether the lead changed between two
 * pairs the page drew. The rows are taken in the order the server gave
 * them: nothing here sorts, sums or judges. The gap is the difference of
 * two of the server's totals and the split their ratio, both for show.
 *
 * A match (ADR 034 decisions 23 and 24) is always a duel of its two seats,
 * its totals their pieces left, white then black; it hides nothing, so its
 * strip moves at every move the page saw arrive. `stripRows()`,
 * `stripChange()` and `stripMoment()` are the one place the strip looks at
 * the game's kind.
 *
 * A seat the computer plays (ADR 036 decision 11) has no player: its row is
 * keyed `COMPUTER_SEAT`, so the pair, its leader and an overtake name it as
 * any side, while it is never the viewer's own row nor a rival to throw at
 * (`rivalOf()`).
 *
 * A domino game (ADR 038 decision 48) shows its sides, their totals the
 * balances of its account, in the server's order: two sides face to face,
 * or at three and four seats the viewer against the leader, or the
 * runner-up when leading. Its balances change only at a hand's end, so its
 * strip moves there. A side is keyed by its one seat (a player's id, or
 * the computer's seat with its number, `seatKey()`), a pair by itself
 * (`sideKey()`).
 */

/** Two contenders face to face, or a race of three and more. */
export type RivalMode = 'duel' | 'race';

/** The two sides a device shows (`rivalPair()`). */
export type RivalPair = {
    mode: RivalMode;
    /** What the totals count: a race's points, a match's pieces left. */
    unit: 'points' | 'pieces';
    /** The viewer plays and stands on the left: the left side is «Siz». */
    mine: boolean;
    /** The left side (sky); null for an empty side. */
    left: RoomRankedPlayer | null;
    /** The right side (orange); null for an empty side. */
    right: RoomRankedPlayer | null;
    /**
     * `left.points − right.points`: its sign says who leads, its size is
     * the number shown. With an empty right side and a left side holding
     * a place, the left side's points (every other contender has none).
     * Null while no side holds a place.
     */
    gap: number | null;
    /** The leading side's player; null when level or without a gap. */
    leader: string | null;
    /** The left side's share of the bar in %, 12–88, 50 at 0 : 0; null without two sides. */
    split: number | null;
    /** The other contenders who share the right side's place. */
    more: number;
};

/** The bar's split never lets a side vanish. */
const SPLIT_MIN = 12;
const SPLIT_MAX = 88;

/**
 * The left side's share of the bar, in %: `left ÷ (left + right)`, held
 * between 12 and 88, and 50 at 0 : 0.
 */
export function splitOf(left: number, right: number): number {
    if (left + right <= 0) {
        return 50;
    }

    return Math.min(
        SPLIT_MAX,
        Math.max(SPLIT_MIN, (100 * left) / (left + right)),
    );
}

/**
 * The ranking's rows that are in the race, in the server's order: a row
 * whose player holds a place, or is present. A player who left keeps
 * their points and stays while they hold a place; one who left without
 * points, and a host who does not play (whose row is a left one), are
 * not contenders.
 */
export function contenders(
    ranking: RoomRankedPlayer[],
    players: RoomPlayer[],
): RoomRankedPlayer[] {
    const present = new Set(
        players.filter((player) => player.present).map((player) => player.id),
    );

    return ranking.filter(
        (row) => row.place !== null || present.has(row.player_id),
    );
}

/** The part of a state the strip reads: the ranking, and a live game's match or domino. */
type StripState = Pick<RoomState, 'ranking'> & {
    match?: RoomState['match'];
    domino?: RoomState['domino'];
};

/**
 * The rows a strip draws: a race's are the state's ranking, in the
 * server's order; a match's are its two sides, white then black, each with
 * its pieces left as its total and 👑 (`place` 1) by the side with more —
 * neither when they are level (ADR 034 decision 23); a domino game's are
 * its sides in the server's order, each with its balance and its place —
 * none while every balance is level, so 👑 stands by neither (ADR 038
 * decision 48). `seats` says the rows are a game's own sides: always all
 * of them, in their own order. A side without a player is the computer's,
 * keyed `COMPUTER_SEAT` (`seatKey()`).
 */
export function stripRows(state: StripState): {
    rows: RoomRankedPlayer[];
    seats: boolean;
} {
    const domino = state.domino ?? null;

    if (domino !== null) {
        const level = domino.sides.every(
            (side) => side.balance === domino.sides[0].balance,
        );

        return {
            rows: domino.sides.map((side) => ({
                player_id: sideKey(domino, side),
                points: side.balance,
                place: level ? null : side.place,
            })),
            seats: true,
        };
    }

    if (state.match === null || state.match === undefined) {
        return { rows: state.ranking, seats: false };
    }

    const [white, black] = state.match.sides;

    return {
        rows: state.match.sides.map((side) => ({
            player_id: seatKey(side.player_id),
            points: side.pieces,
            place:
                white.pieces === black.pieces
                    ? null
                    : side.pieces === Math.max(white.pieces, black.pieces)
                      ? 1
                      : 2,
        })),
        seats: true,
    };
}

/** What the moment changed a row's total by: where a strip's count starts from (`pairBefore()`). */
export type StripChange = {
    results: TotalChange[] | null;
    spent: RoomSpent[];
};

/**
 * What the moment the state shows changed the strip's totals by: a
 * reveal's results and the taunts counted at its close; a match's last
 * move, which took `captured` pieces from the side that did not make it; a
 * domino hand's end, what its sheet's lines wrote to each side's balance
 * (`balanceChanges()`). Nothing in a race's question, nothing in a quiet
 * move, nothing during a hand.
 */
export function stripChange(
    state: Pick<RoomState, 'round' | 'match'> & {
        domino?: RoomState['domino'];
    },
): StripChange {
    const match = state.match;
    const domino = state.domino ?? null;

    if (domino !== null) {
        // In the order of the sides, as `balanceChanges()` gives them.
        const changes = balanceChanges(domino);

        return {
            results:
                changes?.map((change, index) => ({
                    player_id: sideKey(domino, domino.sides[index]),
                    points: change.points,
                })) ?? null,
            spent: [],
        };
    }

    if (match !== null) {
        const last = match.last;
        const mover = last?.path[last.path.length - 1];
        const color = match.position.find(
            (piece) => piece.square === mover,
        )?.color;
        const taken = match.sides.find((side) => side.color !== color);

        return {
            results:
                last === null ||
                last.captured.length === 0 ||
                taken === undefined
                    ? null
                    : [
                          {
                              player_id: seatKey(taken.player_id),
                              points: -last.captured.length,
                          },
                      ],
            spent: [],
        };
    }

    const reveal = state.round?.phase === 'reveal' ? state.round.reveal : null;

    return { results: reveal?.results ?? null, spent: reveal?.spent ?? [] };
}

/**
 * The strip's moment: its key — a race's round and whether it is revealed,
 * a match's ply, a domino game's hand and whether it has ended — and
 * whether a change of the lead within it is an overtake (a race's reveal;
 * every move of a match; a domino hand's end).
 */
export function stripMoment(
    state: Pick<RoomState, 'game' | 'round' | 'match'> & {
        domino?: RoomState['domino'];
    },
): { key: string; moves: boolean } {
    const game = state.game?.id ?? '-';
    const domino = state.domino ?? null;

    if (state.match !== null) {
        return { key: `${game}|ply:${state.match.ply}`, moves: true };
    }

    if (domino !== null) {
        const ended = domino.phase === 'sheet' || domino.phase === 'over';

        return {
            key: `${game}|hand:${domino.hand?.number ?? '-'}|${ended ? 'end' : 'open'}`,
            moves: ended,
        };
    }

    const round = state.round;
    const reveal = round?.phase === 'reveal';

    return {
        key: `${game}|${round?.index ?? '-'}|${reveal ? 'reveal' : 'open'}`,
        moves: reveal,
    };
}

/**
 * Whom a player's strip throws at (ADR 029 as amended, T4; ADR 034
 * decision 22): the right side of a pair whose left is the viewer — the
 * nearest rival in a race, the other seat in a match — with its name; none
 * on a watcher's phone, the screen, or a pair with an empty side — and none
 * when the other seat is the computer's: nobody is there to aim at (ADR 036
 * decision 13). In a domino game (ADR 038 decision 32) a pair across is
 * its first present player in the order of its seats, and nobody when only
 * computers sit there.
 */
export function rivalOf(
    pair: RivalPair | null,
    players: RoomState['players'],
    domino: RoomState['domino'] = null,
): { id: string; name: string | null } | null {
    const right = pair !== null && pair.mine ? pair.right : null;
    const named = (id: string) => ({
        id,
        name: players.find((player) => player.id === id)?.name ?? null,
    });

    if (right === null || isComputerKey(right.player_id)) {
        return null;
    }

    if (right.player_id.startsWith(`${PAIR_KEY}:`)) {
        const side =
            domino === null ? undefined : sideOfKey(domino, right.player_id);
        const present = new Set(
            players
                .filter((player) => player.present)
                .map((player) => player.id),
        );
        const first = side?.seats
            .map((seat) => domino?.seats[seat]?.player_id ?? null)
            .find((id): id is string => id !== null && present.has(id));

        return first === undefined ? null : named(first);
    }

    return named(right.player_id);
}

/**
 * Whom a viewer's throw may go to before presence is asked, in order
 * (ADR 029 as amended 2026-11-21, decisions 60–62): a race's rows as the
 * server ranked them; a match's two seats, and nobody for a viewer who
 * holds neither (ADR 034 decision 22); a domino game's players who sit on
 * another side than the viewer's, in the order of the seats — never a
 * partner or a computer's seat, and nobody for a viewer who does not sit
 * (ADR 038 decision 32). The viewer's own row is the caller's to drop.
 */
export function throwRows(state: StripState & Pick<RoomState, 'me'>): string[] {
    const me = state.me.player_id;
    const domino = state.domino ?? null;

    if (domino !== null) {
        const own = viewerSide(domino, me);

        return own === undefined
            ? []
            : domino.seats
                  .filter((seat) => seat.side !== own.side)
                  .map((seat) => seat.player_id)
                  .filter((id): id is string => id !== null);
    }

    const { rows, seats } = stripRows(state);
    const ids = rows.map((row) => row.player_id);

    return seats && (me === null || !ids.includes(me)) ? [] : ids;
}

/**
 * The pair a device shows (ADR 027 decision 3), from the state's ranking
 * as the server ordered it. `viewer` is `me.player_id`: null on the screen
 * and on the phone of a host who does not play, who both see the
 * screen's pair.
 *
 * - Fewer than two contenders: no pair (no strip).
 * - Two (a duel): on a player's phone the viewer left and the other right;
 *   elsewhere the two in the order of `players` (the order they joined),
 *   so the sides stay put.
 * - Three or more (a race): on a player's phone the viewer left and the
 *   first contender right, or the second when the viewer is the first;
 *   elsewhere the first left and the second right. A right side — on the
 *   screen either side — only while its row holds a place.
 * - A domino game (ADR 038 decision 48): its sides, every one in the game.
 *   Two face to face — the viewer's own on the left, and on a watcher's
 *   phone and the screen the side of seat 0 —; at three and four seats the
 *   viewer against the first side, or the second when the viewer's is
 *   first, and elsewhere the first two, in the server's order.
 */
export function rivalPair(
    state: Pick<RoomState, 'ranking' | 'players'> & {
        match?: RoomState['match'];
        domino?: RoomState['domino'];
    },
    viewer: string | null,
): RivalPair | null {
    const domino = state.domino ?? null;

    if (domino !== null) {
        return dominoPair(domino, stripRows(state).rows, viewer);
    }

    const strip = stripRows(state);
    const rows = strip.seats
        ? strip.rows
        : contenders(strip.rows, state.players);

    if (rows.length < 2) {
        return null;
    }

    const mode: RivalMode = rows.length === 2 ? 'duel' : 'race';
    const own =
        viewer === null
            ? undefined
            : rows.find((row) => row.player_id === viewer);
    let left: RoomRankedPlayer | null;
    let right: RoomRankedPlayer | null;

    if (own !== undefined) {
        left = own;
        const other =
            mode === 'duel'
                ? rows.find((row) => row !== own)
                : rows[0] !== own
                  ? rows[0]
                  : rows[1];
        right =
            other === undefined || (mode === 'race' && other.place === null)
                ? null
                : other;
    } else if (strip.seats) {
        // A match's watcher and the screen: white on the left.
        [left, right] = [rows[0], rows[1]];
    } else if (mode === 'duel') {
        const joined = state.players.map((player) => player.id);
        const order = (row: RoomRankedPlayer) => joined.indexOf(row.player_id);
        [left, right] =
            order(rows[1]) < order(rows[0])
                ? [rows[1], rows[0]]
                : [rows[0], rows[1]];
    } else {
        left = rows[0].place !== null ? rows[0] : null;
        right = left !== null && rows[1].place !== null ? rows[1] : null;
    }

    return withNumbers({
        mode,
        unit: strip.seats ? 'pieces' : 'points',
        mine: own !== undefined,
        left,
        right,
        more:
            right === null || right.place === null
                ? 0
                : rows.filter(
                      (row) =>
                          row !== left &&
                          row !== right &&
                          row.place === right.place,
                  ).length,
    });
}

/** A domino game's pair (`rivalPair()`): its sides' rows, never re-sorted. */
function dominoPair(
    domino: NonNullable<RoomState['domino']>,
    rows: RoomRankedPlayer[],
    viewer: string | null,
): RivalPair | null {
    if (rows.length < 2) {
        return null;
    }

    const mode: RivalMode = rows.length === 2 ? 'duel' : 'race';
    const mine = viewerSide(domino, viewer);
    const own =
        mine === undefined
            ? undefined
            : rows.find((row) => row.player_id === sideKey(domino, mine));
    let left: RoomRankedPlayer;
    let right: RoomRankedPlayer;

    if (own !== undefined) {
        left = own;
        right =
            mode === 'duel'
                ? (rows.find((row) => row !== own) ?? rows[1])
                : rows[0] !== own
                  ? rows[0]
                  : rows[1];
    } else if (mode === 'duel') {
        // A watcher and the screen: the side of seat 0 on the left, so the sides stay put.
        const first = domino.sides.find((side) => side.seats.includes(0));
        const key = first === undefined ? null : sideKey(domino, first);

        [left, right] =
            rows[1].player_id === key ? [rows[1], rows[0]] : [rows[0], rows[1]];
    } else {
        [left, right] = [rows[0], rows[1]];
    }

    return withNumbers({
        mode,
        unit: 'points',
        mine: own !== undefined,
        left,
        right,
        more:
            right.place === null
                ? 0
                : rows.filter(
                      (row) =>
                          row !== left &&
                          row !== right &&
                          row.place === right.place,
                  ).length,
    });
}

/** A pair's gap, leader and split from its two sides. */
function withNumbers(
    sides: Pick<
        RivalPair,
        'mode' | 'unit' | 'mine' | 'left' | 'right' | 'more'
    >,
): RivalPair {
    const { left, right } = sides;
    const gap =
        left === null
            ? null
            : right !== null
              ? left.points - right.points
              : left.place !== null
                ? left.points
                : null;

    return {
        ...sides,
        gap,
        leader:
            gap === null || gap === 0 || left === null
                ? null
                : gap > 0
                  ? left.player_id
                  : (right?.player_id ?? null),
        split:
            left === null || right === null
                ? null
                : splitOf(left.points, right.points),
    };
}

/**
 * Who took the lead between the pair the page last drew (in the round's
 * question, or in the reveal before) and the reveal's (ADR 027 decision
 * 15): the leader of `current` when it is another than `previous`'s. Both
 * must have two sides; becoming level is no overtake, leading from level
 * is one. A page without a pair drawn before shows none.
 */
export function overtakeOf(
    previous: RivalPair | null,
    current: RivalPair | null,
): string | null {
    if (
        previous === null ||
        current === null ||
        previous.left === null ||
        previous.right === null ||
        current.left === null ||
        current.right === null ||
        current.leader === null
    ) {
        return null;
    }

    return previous.leader === current.leader ? null : current.leader;
}

/** Whether two pairs show the same players on the same sides. */
export function sameSides(a: RivalPair, b: RivalPair): boolean {
    return (
        a.left?.player_id === b.left?.player_id &&
        a.right?.player_id === b.right?.player_id
    );
}

/**
 * What a strip draws first in a reveal whose pair has other members or
 * sides than the pair drawn before (ADR 027 decision 14): the sides it
 * showed, each with its new total from `ranking`, still at the places it
 * showed; then the sides take their new places. Null when nothing has to
 * change sides, or either pair lacks a side.
 */
export function interimPair(
    previous: RivalPair | null,
    current: RivalPair | null,
    ranking: RoomRankedPlayer[],
): RivalPair | null {
    if (
        previous === null ||
        current === null ||
        previous.left === null ||
        previous.right === null ||
        current.left === null ||
        current.right === null ||
        sameSides(previous, current)
    ) {
        return null;
    }

    const now = (side: RoomRankedPlayer) =>
        ranking.find((row) => row.player_id === side.player_id);
    const left = now(previous.left);
    const right = now(previous.right);

    if (left === undefined || right === undefined) {
        return null;
    }

    return withNumbers({
        mode: previous.mode,
        unit: previous.unit,
        mine: previous.mine,
        left: { ...left, place: previous.left.place },
        right: { ...right, place: previous.right.place },
        more: previous.more,
    });
}

/**
 * The same pair at the totals before the round — each side's points less
 * its result of the round, with the taunts counted at this close added
 * back (`points − result.points + spent`, ADR 025's count as ADR 029
 * amends it) — where a reveal's movement starts from; a match's before the
 * move (`stripChange()`).
 */
export function pairBefore(
    pair: RivalPair,
    results: TotalChange[] | null,
    spent: RoomSpent[] = [],
): RivalPair {
    if (results === null) {
        return pair;
    }

    const won = new Map(
        results.map((result) => [result.player_id, result.points]),
    );
    const paid = new Map(spent.map((cost) => [cost.player_id, cost.count]));
    const before = (side: RoomRankedPlayer | null) =>
        side === null
            ? null
            : {
                  ...side,
                  points:
                      side.points -
                      (won.get(side.player_id) ?? 0) +
                      (paid.get(side.player_id) ?? 0),
              };

    return withNumbers({
        mode: pair.mode,
        unit: pair.unit,
        mine: pair.mine,
        left: before(pair.left),
        right: before(pair.right),
        more: pair.more,
    });
}
