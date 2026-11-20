import type { StandingGroup } from '@/components/rooms/standing-groups';
import type { RoomRankedPlayer, RoomState } from '@/rooms/contract';

/*
 * The final of a room game (docs/frontend.md rule 15 "The final", ADR
 * 025): the order and the moments in which its places open. The places
 * are the server's (`standingGroups()` of the state's ranking), so players
 * who share a place are one group: they open together and are emphasised
 * together. Nothing here ranks or adds up.
 */

/**
 * The rows a game's final opens (ADR 034 decision 25), the one place the
 * final looks at the game's kind: a race's are the state's ranking — the
 * room's standing once it is over; a board game's are its match's own two
 * places, the winner first or both first in a draw, by the outcome; a
 * match stopped without a result opens none.
 */
export function finalRows(
    state: Pick<RoomState, 'ranking' | 'match'>,
): RoomRankedPlayer[] {
    if (state.match === null || state.match.phase !== 'over') {
        return state.ranking;
    }

    return state.match.result.outcome === 'none'
        ? []
        : state.match.result.standing;
}

/** How a place of the final stands out: the first three, by place. */
export type FinalTier = 'first' | 'second' | 'third' | null;

export function finalTier(place: number | null): FinalTier {
    switch (place) {
        case 1:
            return 'first';
        case 2:
            return 'second';
        case 3:
            return 'third';
        default:
            return null;
    }
}

/** When the final's places open, in ms from its start. */
export type FinalTimeline = {
    /** By the group's index in the shown order (the first place first). */
    opensAt: number[];
    /** When the first place opens: the confetti's moment; null when nobody holds it. */
    winnerAt: number | null;
    /** When the last place has opened. */
    endsAt: number;
};

/**
 * The screen is the show; a phone's final is the same, quicker. `rest` is
 * the most the places after the third may take together, `restStep` the
 * most between two of them; `third`, `second` and `first` are the pauses
 * before each of the first three.
 */
const PACE = {
    screen: {
        start: 600,
        rest: 1600,
        restStep: 280,
        third: 800,
        second: 1000,
        first: 1500,
    },
    phone: {
        start: 300,
        rest: 800,
        restStep: 160,
        third: 450,
        second: 550,
        first: 800,
    },
} as const;

/**
 * The places open from the last to the first: the players without points
 * first, the places after the third quickly (however many play, within
 * `rest`), then the third, the second and, after the longest pause, the
 * first. A group opens all at once.
 */
export function finalTimeline(
    groups: StandingGroup[],
    pace: keyof typeof PACE,
): FinalTimeline {
    const p = PACE[pace];
    const opensAt: number[] = groups.map(() => 0);
    // The shown order is the first place first: open it backwards.
    const order = groups.map((_, index) => index).reverse();
    const rest = order.filter(
        (index) => finalTier(groups[index].place) === null,
    );
    const top = order.filter(
        (index) => finalTier(groups[index].place) !== null,
    );
    const step =
        rest.length <= 1 ? 0 : Math.min(p.restStep, p.rest / (rest.length - 1));
    let at: number = p.start;
    let opened = false;

    for (const index of rest) {
        at = opened ? at + step : at;
        opensAt[index] = Math.round(at);
        opened = true;
    }

    let winnerAt: number | null = null;

    for (const index of top) {
        const tier = finalTier(groups[index].place);
        const pause =
            tier === 'first' ? p.first : tier === 'second' ? p.second : p.third;

        at = opened ? at + pause : at;
        opensAt[index] = Math.round(at);
        opened = true;

        if (tier === 'first') {
            winnerAt = opensAt[index];
        }
    }

    return {
        opensAt,
        winnerAt,
        endsAt: groups.length === 0 ? 0 : Math.round(at),
    };
}

/** Whether the viewer holds the first place, alone or shared: their phone's confetti. */
export function holdsFirst(
    ranking: RoomRankedPlayer[],
    me: string | null,
): boolean {
    return (
        me !== null &&
        ranking.some((row) => row.player_id === me && row.place === 1)
    );
}
