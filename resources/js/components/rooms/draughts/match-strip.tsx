import { Timer } from 'lucide-react';
import {
    clockText,
    CLOCK_URGENT_MS,
    shownLeft,
} from '@/components/rooms/draughts/board';
import { PieceMark } from '@/components/rooms/draughts/piece';
import { stripChange, stripRows } from '@/components/rooms/rivalry';
import { RivalryStrip, useRivalry } from '@/components/rooms/rivalry-strip';
import type { RivalryIcons, SideMarks } from '@/components/rooms/rivalry-strip';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomMatch, RoomSide, RoomState } from '@/rooms/contract';

/**
 * A match's rivalry strip (docs/frontend.md rule 15 "Board games" → "The
 * strip", ADR 034 decisions 23 and 24): `RivalryStrip` over the two seats
 * (`stripRows()`) — on a seat's phone «Siz» against the other seat, on a
 * watcher's phone and the screen white against black — each side with a
 * piece of its colour and its clock in place of a place label, the totals
 * the pieces left. The side to move's clock counts down on the estimated
 * server clock, for show; under ten seconds it shows tenths in the wrong
 * colour beside `Timer`. After a capture the page saw arrive the split
 * slides and the totals count (`moves`); a change of the lead between two
 * pairs the page drew is an overtake. `children` lies over it: a seat's
 * throw, as T4 built it.
 */
export function MatchStrip({
    state,
    match,
    now,
    icons,
    placeLabels,
    moves,
    big = false,
    children,
}: {
    state: RoomState;
    match: RoomMatch;
    now: number | null;
    icons: RivalryIcons | null;
    placeLabels: string[];
    /** A move the page saw arrive (`RoomMotion.strip` and the board's arrival). */
    moves: boolean;
    big?: boolean;
    children?: React.ReactNode;
}) {
    const rivalry = useRivalry(state, big ? null : state.me.player_id);

    if (rivalry.pair === null) {
        return null;
    }

    const change = stripChange(state);
    const marks: SideMarks = Object.fromEntries(
        match.sides.map((side) => [
            side.player_id,
            {
                lead: (
                    <PieceMark
                        color={side.color}
                        className={big ? 'size-[3vh]' : 'size-4'}
                    />
                ),
                tail: (
                    <SideClock match={match} side={side} now={now} big={big} />
                ),
            },
        ]),
    );

    return (
        <div className="relative">
            <RivalryStrip
                key={`strip:${rivalry.key}`}
                pair={rivalry.pair}
                previous={rivalry.previous}
                overtake={rivalry.overtake}
                ranking={stripRows(state).rows}
                results={change.results}
                spent={change.spent}
                players={state.players}
                placeLabels={placeLabels}
                icons={icons}
                moves={moves}
                marks={marks}
                big={big}
            />
            {children}
        </div>
    );
}

/** A side's clock in the strip's names row: «4:52», and «0:07,4» in the wrong colour beside `Timer` in its last ten seconds. */
function SideClock({
    match,
    side,
    now,
    big,
}: {
    match: RoomMatch;
    side: RoomSide;
    now: number | null;
    big: boolean;
}) {
    const { t } = useTranslation();
    const left = shownLeft(match, side, now);
    const urgent = left < CLOCK_URGENT_MS;
    const running = match.phase === 'play' && match.turn === side.color;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 font-semibold tabular-nums',
                running ? 'text-foreground' : 'text-muted-foreground',
                urgent && 'text-red-600 dark:text-red-400',
            )}
        >
            {urgent && (
                <Timer
                    aria-hidden
                    className={big ? 'size-[2.6vh]' : 'size-3.5'}
                />
            )}
            {clockText(left, t('rooms.decimal_mark'))}
        </span>
    );
}

/** The other seat of a match for a seat's phone: whom its strip's throw aims at. */
export function rivalOf(
    state: RoomState,
    match: RoomMatch,
): { id: string; name: string | null } | null {
    const mine = match.sides.some(
        (side) => side.player_id === state.me.player_id,
    );
    const other = match.sides.find(
        (side) => side.player_id !== state.me.player_id,
    );

    if (!mine || other === undefined) {
        return null;
    }

    return {
        id: other.player_id,
        name:
            state.players.find((player) => player.id === other.player_id)
                ?.name ?? null,
    };
}
