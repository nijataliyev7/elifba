import { Bot, Medal, Trophy } from 'lucide-react';
import { useEffect } from 'react';
import type { CSSProperties } from 'react';
import {
    COMPUTER_SEAT,
    computerOf,
    seatName,
} from '@/components/rooms/computer';
import {
    finalRows,
    finalSpent,
    finalTier,
    finalTimeline,
    holdsFirst,
} from '@/components/rooms/final';
import type { FinalTier } from '@/components/rooms/final';
import { celebrate } from '@/components/rooms/motion-dom';
import { carriesRoom } from '@/components/rooms/sound-cues';
import { SpentMark } from '@/components/rooms/taunt-parts';
import { standingGroups } from '@/components/rooms/standing-groups';
import type { StandingGroup } from '@/components/rooms/standing-groups';
import { useCountUp } from '@/components/rooms/use-motion';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type {
    RoomComputerLevel,
    RoomPlayer,
    RoomRankedPlayer,
    RoomSpent,
    RoomState,
} from '@/rooms/contract';

/**
 * A game's final as a page draws it once the game is over (ADR 025; ADR
 * 034 decision 25): the rows `finalRows()` opens — a race's room standing,
 * a match's own two places — with the taunts `finalSpent()` counts beside
 * them; on a phone the viewer's own row «siz», its confetti the room's in
 * a room without a screen (`carriesRoom()`); on the screen (`big`) larger
 * and at the screen's pace. A match's row without a player is the
 * computer's, at the level the game says (`computerOf()`, ADR 036).
 */
export function GameFinal({
    state,
    placeLabels,
    animate,
    big = false,
}: {
    state: RoomState;
    placeLabels: string[];
    /** The page saw the game end. */
    animate: boolean;
    big?: boolean;
}) {
    return (
        <FinalStanding
            ranking={finalRows(state)}
            players={state.players}
            computer={computerOf(state.game)}
            me={big ? null : state.me.player_id}
            placeLabels={placeLabels}
            animate={animate}
            forRoom={!big && carriesRoom(state)}
            spent={finalSpent(state)}
            big={big}
        />
    );
}

/**
 * The final of a room game (docs/frontend.md rule 15 "The final", ADR
 * 025): the state's ranking once the game is over — the room's standing —
 * in the server's places (`standingGroups()`), the first three emphasised
 * by size, weight and an icon (`Trophy` on the room's amber for the first,
 * `Medal` for the second and the third), the others plain, players
 * without points last under «–». A place that players share is one group,
 * emphasised and opened together.
 *
 * When the page saw the game end (`animate`), the labels stand from the
 * start and the names open from the last place to the first
 * (`finalTimeline()`), each total counting up from 0; when the first place
 * opens, confetti bursts — on the screen, and on a phone for a player who
 * holds it, or for everyone in a room without a screen (`forRoom`, ADR 025
 * as amended 2026-11-19), where each phone is the room's final. Opened on the final, or under reduced motion, it is
 * complete and still. Nothing is ranked or added up here.
 *
 * A row of the seat nobody held in a match is the computer's (ADR 036
 * decision 15): `Bot` and «Kompüter (Orta)» where a player's name stands —
 * never «siz», since it is nobody's own.
 */
export function FinalStanding({
    ranking,
    players,
    computer = null,
    me,
    placeLabels,
    animate,
    big = false,
    forRoom = false,
    spent = [],
}: {
    ranking: RoomRankedPlayer[];
    players: RoomPlayer[];
    /** The level of the computer, for a match's row without a player; null without one. */
    computer?: RoomComputerLevel | null;
    me: string | null;
    placeLabels: string[];
    /** The page saw the game end: the places open; otherwise they stand. */
    animate: boolean;
    /** The screen's size and pace. */
    big?: boolean;
    /** A phone in a room without a screen: its confetti is the room's, whoever won. */
    forRoom?: boolean;
    /** The taunts counted at the game's end, by row: a match's «−1» beside its points (ADR 034 decision 22). */
    spent?: RoomSpent[];
}) {
    const groups = standingGroups(ranking, placeLabels);
    const timeline = finalTimeline(groups, big ? 'screen' : 'phone');
    const { t } = useTranslation();
    const nameOf = (key: string) => seatName(t, players, key, computer);
    const costs = new Map(spent.map((cost) => [cost.player_id, cost.count]));
    const winnerAt = timeline.winnerAt;
    const cheers =
        animate &&
        winnerAt !== null &&
        (big || forRoom || holdsFirst(ranking, me));

    useEffect(() => {
        if (!cheers || winnerAt === null) {
            return;
        }

        const timer = window.setTimeout(
            () => celebrate(big ? 'screen' : 'phone'),
            winnerAt + 150,
        );

        return () => window.clearTimeout(timer);
    }, [cheers, winnerAt, big]);

    const top = groups
        .map((group, index) => ({ group, index }))
        .filter(({ group }) => finalTier(group.place) !== null);
    const rest = groups
        .map((group, index) => ({ group, index }))
        .filter(({ group }) => finalTier(group.place) === null);
    const restRows = rest.reduce(
        (sum, { group }) => sum + group.rows.length,
        0,
    );

    const place = ({
        group,
        index,
    }: {
        group: StandingGroup;
        index: number;
    }) => (
        <Place
            key={group.place ?? 'none'}
            group={group}
            tier={finalTier(group.place)}
            nameOf={nameOf}
            costs={costs}
            me={me}
            opensAt={animate ? timeline.opensAt[index] : null}
            big={big}
        />
    );

    return (
        <div
            data-final
            className={cn('flex flex-col', big ? 'gap-[1.4vh]' : 'gap-2')}
        >
            {top.length > 0 && (
                <ol
                    className={cn(
                        'flex flex-col',
                        big ? 'gap-[1.4vh]' : 'gap-2',
                    )}
                >
                    {top.map(place)}
                </ol>
            )}
            {rest.length > 0 && (
                <ol
                    className={cn(
                        'overflow-hidden rounded-xl border bg-card',
                        big && 'rounded-2xl',
                        // Many players: two columns, so twenty fit the screen.
                        big &&
                            restRows > REST_COLUMNS_FROM &&
                            'columns-2 gap-0 [column-rule:1px_solid_var(--border)]',
                    )}
                >
                    {rest.map(place)}
                </ol>
            )}
        </div>
    );
}

/** On the screen, the places after the third stand in two columns from this many rows. */
const REST_COLUMNS_FROM = 6;

/** A tier's sizes: its row's height, its text, its icon's disc. */
const SIZE = {
    first: {
        phone: 'min-h-16 text-xl font-bold',
        big: 'min-h-[12vh] text-[5vh] font-bold',
    },
    second: {
        phone: 'min-h-13 text-lg font-semibold',
        big: 'min-h-[9vh] text-[3.8vh] font-semibold',
    },
    third: {
        phone: 'min-h-13 text-lg font-semibold',
        big: 'min-h-[9vh] text-[3.8vh] font-semibold',
    },
    rest: {
        phone: 'min-h-11',
        big: 'min-h-[6.4vh] text-[2.6vh]',
    },
} as const;

/**
 * One place of the final: its label (and for the first three its icon),
 * standing from the start, beside the names and totals of the players who
 * share it, which open at `opensAt` ms (null: already open).
 */
function Place({
    group,
    tier,
    nameOf,
    costs,
    me,
    opensAt,
    big,
}: {
    group: StandingGroup;
    tier: FinalTier;
    /** A row's name by its key: a player's, or the computer's. */
    nameOf: (key: string) => string;
    costs: Map<string, number>;
    me: string | null;
    opensAt: number | null;
    big: boolean;
}) {
    const { t } = useTranslation();
    const size = SIZE[tier ?? 'rest'][big ? 'big' : 'phone'];
    const open: CSSProperties | undefined =
        opensAt === null ? undefined : { animationDelay: `${opensAt}ms` };
    const glow: CSSProperties | undefined =
        opensAt === null || tier !== 'first'
            ? undefined
            : { animationDelay: `${opensAt + 300}ms` };
    const Icon = tier === 'first' ? Trophy : Medal;

    return (
        <li
            data-place={group.place ?? 'none'}
            data-tier={tier ?? undefined}
            style={glow}
            className={cn(
                'grid break-inside-avoid',
                big
                    ? 'grid-cols-[12vh_minmax(0,1fr)]'
                    : 'grid-cols-[3.75rem_minmax(0,1fr)]',
                tier === null
                    ? 'border-b last:border-b-0'
                    : cn(
                          'overflow-hidden border bg-card',
                          big ? 'rounded-2xl' : 'rounded-xl',
                      ),
                tier === 'first' &&
                    'border-amber-400 bg-amber-400/10 dark:border-amber-300/60',
                glow && 'motion-safe:animate-room-glow',
            )}
        >
            <span
                className={cn(
                    'flex flex-col items-center justify-center gap-0.5 border-r bg-muted/40 py-1.5 font-semibold text-muted-foreground',
                    big ? 'gap-[0.6vh] text-[2.4vh]' : 'text-sm',
                    tier === 'first' &&
                        'border-amber-400/60 bg-amber-400/15 text-foreground dark:border-amber-300/40',
                    tier === null && big && 'text-[2.2vh]',
                )}
            >
                {tier !== null && (
                    <span
                        aria-hidden
                        className={cn(
                            'flex items-center justify-center rounded-full',
                            big ? 'size-[6vh]' : 'size-8',
                            tier === 'first'
                                ? 'bg-amber-400 text-amber-950'
                                : 'bg-muted text-foreground',
                        )}
                    >
                        <Icon className={big ? 'size-[3.4vh]' : 'size-4.5'} />
                    </span>
                )}
                {group.label ?? '–'}
            </span>
            <ul
                style={open}
                className={cn(
                    'flex min-w-0 flex-col justify-center',
                    open && 'motion-safe:animate-room-open',
                )}
            >
                {group.rows.map((row) => {
                    const mine = row.player_id === me;

                    return (
                        <li
                            key={row.player_id}
                            data-player={row.player_id}
                            className={cn(
                                'flex min-w-0 items-center gap-2 px-3 py-1.5',
                                big && 'gap-[1.6vh] px-[2vh]',
                                size,
                                // The first place keeps its amber; «siz» says whose row it is.
                                mine && tier !== 'first' && 'bg-accent',
                            )}
                        >
                            {row.player_id === COMPUTER_SEAT && (
                                <Bot
                                    aria-hidden
                                    className="size-[1em] shrink-0"
                                />
                            )}
                            <span className="min-w-0 flex-1 truncate">
                                {nameOf(row.player_id)}
                            </span>
                            {mine && (
                                <span className="shrink-0 rounded-full bg-background px-2 py-0.5 text-xs font-medium text-muted-foreground">
                                    {t('rooms.show.you')}
                                </span>
                            )}
                            {(costs.get(row.player_id) ?? 0) > 0 && (
                                <SpentMark
                                    count={costs.get(row.player_id) ?? 0}
                                    className={big ? 'text-[0.6em]' : 'text-sm'}
                                />
                            )}
                            <FinalPoints
                                points={row.points}
                                opensAt={opensAt}
                                big={big}
                            />
                        </li>
                    );
                })}
            </ul>
        </li>
    );
}

/** A total of the final: the server's points, counted up from 0 as its place opens. */
function FinalPoints({
    points,
    opensAt,
    big,
}: {
    points: number;
    opensAt: number | null;
    big: boolean;
}) {
    const { t } = useTranslation();
    const shown = useCountUp(points, {
        enabled: opensAt !== null,
        delay: (opensAt ?? 0) + 150,
        duration: 650,
    });

    return (
        <span
            className={cn(
                'shrink-0 text-right whitespace-nowrap tabular-nums',
                big ? 'min-w-[12vh]' : 'min-w-16',
            )}
        >
            {t('rooms.game.points', { count: shown })}
        </span>
    );
}
