import { Timer } from 'lucide-react';
import {
    clockText,
    CLOCK_URGENT_MS,
    shownLeft,
    yourTurn,
} from '@/components/rooms/draughts/board';
import { PieceMark } from '@/components/rooms/draughts/piece';
import type { ReactNode } from 'react';
import { GameStrip } from '@/components/rooms/rivalry-strip';
import type {
    Rivalry,
    RivalryIcons,
    SideMarks,
} from '@/components/rooms/rivalry-strip';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomMatch, RoomSide, RoomState } from '@/rooms/contract';

/**
 * A match's rivalry strip (docs/frontend.md rule 15 "Board games" → "The
 * strip", ADR 034 decisions 23 and 24): the room's strip (`GameStrip`) over
 * the two seats — on a seat's phone «Siz» against the other seat, on a
 * watcher's phone and the screen white against black — each side with a
 * piece of its colour and its clock in place of a place label, the totals
 * the pieces left. The side to move's clock counts down on the estimated
 * server clock, for show; under ten seconds it shows tenths in the wrong
 * colour beside `Timer`. On a seat's phone its own clock, while it runs
 * (`yourTurn()`), is drawn active — ringed in the left side's colour,
 * where «Siz» stands, on the side's tint (ADR 034 "Amended 2026-11-21 —
 * whose turn it is"). `children` lies over it: a seat's throw.
 */
export function MatchStrip({
    rivalry,
    state,
    match,
    now,
    icons,
    placeLabels,
    moves,
    big = false,
    children,
}: {
    rivalry: Rivalry & { key: string };
    state: RoomState;
    match: RoomMatch;
    now: number | null;
    icons: RivalryIcons | null;
    placeLabels: string[];
    /** A move the page saw arrive (`RoomMotion.strip` and the board's arrival). */
    moves: boolean;
    big?: boolean;
    children?: ReactNode;
}) {
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
                    <SideClock
                        match={match}
                        side={side}
                        now={now}
                        big={big}
                        mine={
                            side.player_id === state.me.player_id &&
                            yourTurn(match, state.me.player_id)
                        }
                    />
                ),
            },
        ]),
    );

    return (
        <GameStrip
            rivalry={rivalry}
            state={state}
            placeLabels={placeLabels}
            icons={icons}
            moves={moves}
            marks={marks}
            ribbonInGap
            big={big}
        >
            {children}
        </GameStrip>
    );
}

/**
 * A side's clock in the strip's names row: «4:52», and «0:07,4» in the
 * wrong colour beside `Timer` in its last ten seconds; the viewer's own,
 * while it runs (`mine`), ringed in its side's colour.
 */
function SideClock({
    match,
    side,
    now,
    big,
    mine,
}: {
    match: RoomMatch;
    side: RoomSide;
    now: number | null;
    big: boolean;
    mine: boolean;
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
                mine &&
                    'rounded-md bg-rival-left-tint px-1 ring-2 ring-rival-left ring-inset',
                urgent && 'text-red-600 dark:text-red-400',
            )}
            data-turn-clock={mine ? '' : undefined}
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
