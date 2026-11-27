import { ChevronsDown, ChevronsUp, Equal } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import {
    computerOf,
    isComputerKey,
    seatName,
} from '@/components/rooms/computer';
import { REVEAL_MS } from '@/components/rooms/motion';
import { reducedMotion } from '@/components/rooms/motion-dom';
import {
    interimPair,
    overtakeOf,
    pairBefore,
    rivalPair,
    stripChange,
    stripMoment,
    stripRows,
} from '@/components/rooms/rivalry';
import { notesRow } from '@/components/rooms/rivalry';
import type { RivalPair, TotalChange } from '@/components/rooms/rivalry';
import { useCountUp } from '@/components/rooms/use-motion';
import { useTranslation } from '@/hooks/use-translation';
import type { Translate } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type {
    RoomComputerLevel,
    RoomPlayer,
    RoomRankedPlayer,
    RoomSpent,
    RoomState,
} from '@/rooms/contract';

/**
 * The rivalry strip's three emoji (`content/rooms/rivalry.json`, the prop
 * `rivalry`): ⚡ where the sides meet, 👑 by the first place, 🔥 on an
 * overtake. Fluent Emoji files of the app's own; they show the state and
 * never stand for a person.
 */
export type RivalryIcons = { clash: string; leader: string; overtake: string };

/** In a reveal the page saw begin: the split slides and the totals count, as the ranking's rows move. */
const SLIDE_AT = REVEAL_MS.ranking + 250;
const SLIDE_MS = 700;
/** Then the gap takes its new words, and an overtake's light crosses the bar. */
const SETTLE_AT = SLIDE_AT + SLIDE_MS;
/** Then the sides take their new places, and an overtake's ribbon pops. */
const SWAP_AT = SETTLE_AT + 80;

export type Rivalry = {
    /** The pair the page draws now; null without two contenders (no strip). */
    pair: RivalPair | null;
    /** The pair the page drew last before this moment: in the round's question, or the reveal before. */
    previous: RivalPair | null;
    /** In a reveal: who took the lead from the pair drawn before (`overtakeOf()`). */
    overtake: string | null;
};

/** What a pair shows, for telling a pair drawn again from another one. */
function signature(pair: RivalPair | null): string {
    if (pair === null) {
        return '-';
    }

    const side = (row: RoomRankedPlayer | null) =>
        row === null ? '-' : `${row.player_id}:${row.points}:${row.place}`;

    return `${pair.mode}|${pair.unit}|${pair.mine}|${side(pair.left)}|${side(pair.right)}|${pair.more}`;
}

/**
 * The pair a page draws for a live round (ADR 027 decisions 3 and 15),
 * with the pair it drew last before this moment — the round's question,
 * or the reveal before — and, in a reveal, who took the lead; for a live
 * match the same at each move (`stripMoment()`, ADR 034 decision 24).
 * `viewer` is the phone's `me.player_id`; the screen passes null, since it
 * is the host's session but has no viewer (decision 7). A page that drew
 * nothing before (opened on the reveal) has no previous pair, and so no
 * overtake. The pair is the state's; the page keeps only what it drew.
 */
export function useRivalry(
    state: RoomState,
    viewer: string | null,
): Rivalry & { key: string } {
    const pair = rivalPair(state, viewer);
    const moment = stripMoment(state);
    const key = moment.key;
    const sign = signature(pair);
    const [drawn, setDrawn] = useState<{
        key: string;
        pair: RivalPair | null;
        previous: RivalPair | null;
    }>({ key, pair, previous: null });
    // On a new moment, what was drawn until then is the pair before it.
    const previous = drawn.key === key ? drawn.previous : drawn.pair;

    useEffect(() => {
        setDrawn((last) =>
            last.key !== key
                ? { key, pair, previous: last.pair }
                : signature(last.pair) !== sign
                  ? { ...last, pair }
                  : last,
        );
    }, [key, sign, pair]);

    return {
        key,
        pair,
        previous,
        overtake: moment.moves ? overtakeOf(previous, pair) : null,
    };
}

/**
 * The strip of a live game (docs/frontend.md rule 15 "Rivals face to face",
 * "Board games" → "The strip"): `RivalryStrip` over the pair a page draws
 * (`useRivalry()`, which the caller keeps — a round's screen reads the
 * pair, a phone's throw its rival), its rows and what the moment changed
 * them by (`stripRows()`, `stripChange()`) — a round's and a match's alike,
 * and the level of the computer for a match's seat nobody holds
 * (`computerOf()`, ADR 036). `children` lies over it: a player's throw, as
 * T4 built it.
 */
export function GameStrip({
    rivalry,
    state,
    placeLabels,
    icons,
    moves,
    marks,
    ribbonInGap,
    big = false,
    naming,
    children,
}: {
    rivalry: Rivalry & { key: string };
    state: RoomState;
    placeLabels: string[];
    icons: RivalryIcons | null;
    /** A reveal the page saw begin, or a capture it saw arrive. */
    moves: boolean;
    marks?: SideMarks;
    ribbonInGap?: boolean;
    big?: boolean;
    /** A domino game's names, target and bank (`StripNaming`). */
    naming?: StripNaming;
    children?: ReactNode;
}) {
    if (rivalry.pair === null) {
        return null;
    }

    const change = stripChange(state);
    const strip = (
        <RivalryStrip
            key={`strip:${rivalry.key}`}
            pair={rivalry.pair}
            previous={rivalry.previous}
            overtake={rivalry.overtake}
            ranking={stripRows(state).rows}
            results={change.results}
            spent={change.spent}
            players={state.players}
            computer={computerOf(state.game)}
            placeLabels={placeLabels}
            icons={icons}
            moves={moves}
            marks={marks}
            ribbonInGap={ribbonInGap}
            big={big}
            naming={naming}
        />
    );

    return children === undefined ? (
        strip
    ) : (
        <div className="relative">
            {strip}
            {children}
        </div>
    );
}

/**
 * What a side shows beside its name in place of a place label: a match's
 * piece of its colour before the name and its clock after it (ADR 034
 * decision 23), by the row's `player_id` — the computer's seat by its own
 * key (`seatKey()`), with `Bot` where a player's side has its piece.
 */
export type SideMarks = Record<string, { lead?: ReactNode; tail?: ReactNode }>;

/**
 * What a domino game's strip says beyond a race's and a match's
 * (docs/frontend.md rule 15 "Domino" → "The strip", ADR 038 decision 48):
 * a side's name by its key — a pair by its two first names, «Onlar» on a
 * seat's phone —, the viewer's own as «Siz» or, at pairs, «Biz», the
 * target between the two names, and the bank while it holds points, in
 * the gap's row.
 */
export type StripNaming = {
    nameOf: (key: string | null) => string;
    you: string;
    target: number;
    bank: number;
    /** Under a side, a closed account's words: in the gap's row on the screen, in a row of their own on a phone (`notesRow()`). */
    notes?: Record<string, ReactNode>;
};

/**
 * Rivals face to face (docs/frontend.md rule 15 "Rivals face to face",
 * architecture §12 "Rivals face to face (P2)", ADR 027): two rows of the
 * state's ranking as a PK strip — the names with their places, the bar
 * split by their totals with ⚡ where the sides meet, and the gap in
 * words. The left side is sky, the right orange; on a player's phone the
 * left is «Siz». `big` is the screen's size.
 *
 * It stands still through the countdown, the study and the question.
 * In a reveal the page saw begin (`moves`), as the ranking's rows move,
 * the split slides and both totals count up from the round before
 * (`points − result.points`), then the gap takes its new words; when the
 * pair's sides changed, the sides it showed move first and then drop into
 * their new places. An overtake adds one light across the bar and a
 * ribbon until the next round. Under reduced motion all of it stands at
 * its new values, the ribbon included. From T2 the count starts from
 * before the taunts counted at this close (`spent`, ADR 029 decision 6);
 * a taunt shown now lies over the strip, which never moves under it.
 *
 * A side nobody holds is the computer's (docs/frontend.md rule 15 "The
 * computer's seat", ADR 036 decision 15): «Kompüter» beside the icon its
 * match gives it (`marks`) — with its level, «Kompüter (Orta)», on the
 * screen and wherever the strip names it in words (the gap, the ribbon);
 * on a phone the side's half has no room for the level beside the icon,
 * 👑 and the clock, so it is read out but not drawn there.
 */
export function RivalryStrip({
    pair,
    previous,
    overtake,
    ranking,
    results,
    spent = [],
    players,
    computer = null,
    placeLabels,
    icons,
    moves,
    marks,
    ribbonInGap = false,
    big = false,
    naming,
}: {
    pair: RivalPair;
    previous: RivalPair | null;
    overtake: string | null;
    /** The strip's rows now: the totals now of the sides drawn before (`stripRows()`). */
    ranking: RoomRankedPlayer[];
    /** What the moment changed each total by: a reveal's results, a match's capture (`stripChange()`). */
    results: TotalChange[] | null;
    /** The taunts counted at this close, in a reveal (T2): the count starts before their cost. */
    spent?: RoomSpent[];
    players: RoomPlayer[];
    /** The level of the computer, for a match's side nobody holds; null without one. */
    computer?: RoomComputerLevel | null;
    placeLabels: string[];
    icons: RivalryIcons | null;
    /** A reveal the page saw begin (`RoomMotion.strip`), or a capture it saw arrive. */
    moves: boolean;
    /** A match's piece and clock by each side's name. */
    marks?: SideMarks;
    /** A match on a phone fits 375 × 667 with nothing to spare: the ribbon takes the gap's place, never a row of its own. */
    ribbonInGap?: boolean;
    big?: boolean;
    naming?: StripNaming;
}) {
    const { t } = useTranslation();
    const [animate] = useState(() => moves && !reducedMotion());
    const [step, setStep] = useState(animate ? 0 : 3);

    useEffect(() => {
        if (!animate) {
            return;
        }

        const timers = [
            window.setTimeout(() => setStep(1), SLIDE_AT),
            window.setTimeout(() => setStep(2), SETTLE_AT),
            window.setTimeout(() => setStep(3), SWAP_AT),
        ];

        return () => timers.forEach((timer) => window.clearTimeout(timer));
    }, [animate]);

    const staged = animate ? interimPair(previous, pair, ranking) : null;
    const swapped = staged !== null && step >= 3;
    const drawn = staged !== null && !swapped ? staged : pair;
    const before = pairBefore(drawn, results, spent);
    const sliding = animate && !swapped;
    const split = sliding && step === 0 ? before.split : drawn.split;
    const words = animate && step < 2 && previous !== null ? previous : drawn;
    const names = new Map(players.map((player) => [player.id, player]));
    const nameOf = (id: string | null) =>
        naming !== undefined
            ? naming.nameOf(id)
            : seatName(t, players, id, computer);
    const ribbon = overtake !== null && (!animate || step >= 3);
    const leftTook = overtake !== null && overtake === pair.left?.player_id;
    const overGap = ribbon && !big && ribbonInGap;
    const notesAt = naming?.notes === undefined ? null : notesRow(big);
    const leftNote = naming?.notes !== undefined && (
        <span className="flex min-w-0 justify-start">
            {drawn.left === null ? null : naming.notes[drawn.left.player_id]}
        </span>
    );
    // Clear of the throw's badge on the strip's bottom-right corner.
    const rightNote = naming?.notes !== undefined && (
        <span className={cn('flex min-w-0 justify-end', !big && 'pr-8')}>
            {drawn.right === null ? null : naming.notes[drawn.right.player_id]}
        </span>
    );

    return (
        <div
            role="group"
            aria-label={t('rooms.rivalry.label')}
            className={cn(
                'relative flex flex-col rounded-[14px] border bg-card bg-[linear-gradient(90deg,var(--rival-left-tint),transparent_38%,transparent_62%,var(--rival-right-tint))] shadow-xs',
                big
                    ? 'gap-[1vh] rounded-[2.4vh] px-[2.4vh] pt-[1.6vh] pb-[1.4vh]'
                    : 'gap-[5px] px-2.5 pt-2 pb-[7px]',
            )}
        >
            <div
                key={swapped ? 'swapped' : 'shown'}
                className={cn(
                    'flex flex-col',
                    big ? 'gap-[1vh]' : 'gap-[5px]',
                    swapped && 'motion-safe:animate-room-drop',
                )}
            >
                <div
                    className={cn(
                        'flex justify-between',
                        big
                            ? 'gap-[2vh] text-[3.3vh] leading-tight'
                            : 'gap-2.5 text-[13.5px] leading-[18px]',
                    )}
                >
                    <Side
                        row={drawn.left}
                        side="left"
                        pair={drawn}
                        name={
                            drawn.mine
                                ? (naming?.you ?? t('rooms.rivalry.you'))
                                : null
                        }
                        nameOf={nameOf}
                        players={names}
                        placeLabels={placeLabels}
                        icons={icons}
                        marks={marks}
                        big={big}
                    />
                    {naming !== undefined && (
                        <span
                            data-target={naming.target}
                            aria-label={t('rooms.rivalry.target', {
                                count: naming.target,
                            })}
                            className={cn(
                                'shrink-0 self-center rounded-full border bg-background/70 font-bold text-muted-foreground tabular-nums',
                                big
                                    ? 'px-[1.2vh] text-[2.2vh]'
                                    : 'px-1.5 text-[11px] leading-4',
                            )}
                        >
                            {naming.target}
                        </span>
                    )}
                    <Side
                        row={drawn.right}
                        side="right"
                        pair={drawn}
                        name={null}
                        nameOf={nameOf}
                        players={names}
                        placeLabels={placeLabels}
                        icons={icons}
                        marks={marks}
                        big={big}
                    />
                </div>

                <Bar
                    pair={drawn}
                    before={before}
                    split={split}
                    counting={sliding}
                    slides={animate && !swapped}
                    shine={animate && overtake !== null && step >= 2}
                    icons={icons}
                    big={big}
                />

                <div
                    className={cn(
                        'flex items-center justify-center',
                        big ? 'gap-[1.4vh]' : 'gap-1.5',
                        notesAt === 'gap' &&
                            'grid grid-cols-[minmax(0,1fr)_auto_auto_minmax(0,1fr)]',
                        overGap && 'invisible',
                    )}
                >
                    {notesAt === 'gap' && leftNote}
                    <Gap
                        key={gapWords(t, words, nameOf)}
                        pair={words}
                        words={gapWords(t, words, nameOf)}
                        // Words the ribbon is about to cover do not pop first.
                        pop={
                            animate &&
                            step >= 2 &&
                            !(ribbonInGap && !big && overtake !== null)
                        }
                        big={big}
                    />
                    {naming !== undefined && naming.bank > 0 && (
                        <span
                            data-bank={naming.bank}
                            className={cn(
                                'inline-flex shrink-0 items-center rounded-full bg-muted font-semibold text-foreground tabular-nums',
                                big
                                    ? 'px-[1.4vh] text-[2.4vh] leading-[1.6]'
                                    : 'px-2 text-xs leading-[18px]',
                            )}
                        >
                            {t('rooms.rivalry.bank', { count: naming.bank })}
                        </span>
                    )}
                    {notesAt === 'gap' && (
                        <>
                            {naming?.bank !== undefined && naming.bank <= 0 && (
                                <span />
                            )}
                            {rightNote}
                        </>
                    )}
                </div>
                {/* On a phone the accounts' words have a row of their own, always there, so none is cut and nothing jumps. */}
                {notesAt === 'below' && (
                    <div
                        data-notes
                        className={cn(
                            'grid h-4 grid-cols-2 items-center gap-1.5',
                            overGap && 'invisible',
                        )}
                    >
                        {leftNote}
                        {rightNote}
                    </div>
                )}
            </div>

            {ribbon && (
                <div
                    className={cn(
                        'pointer-events-none flex justify-center',
                        big
                            ? 'absolute inset-x-0 top-[6.6vh] z-[4]'
                            : overGap
                              ? 'absolute inset-x-0 bottom-0 z-[4]'
                              : 'mt-0.5',
                    )}
                >
                    <span
                        className={cn(
                            'inline-flex items-center font-bold whitespace-nowrap text-white shadow-[0_6px_18px_rgb(0_0_0/0.22),0_0_0_2px_rgb(255_255_255/0.9)] dark:shadow-[0_6px_18px_rgb(0_0_0/0.5),0_0_0_2px_rgb(255_255_255/0.25)]',
                            big
                                ? 'gap-[1vh] rounded-full py-[0.8vh] pr-[2.4vh] pl-[1.2vh] text-[2.9vh]'
                                : cn(
                                      'gap-1.5 rounded-full pr-[13px] pl-[7px] text-[13px]',
                                      // In the gap's place: no taller than the gap, clear of the bar and its ⚡.
                                      overGap ? 'py-0.5' : 'py-1',
                                  ),
                            leftTook
                                ? 'bg-[linear-gradient(180deg,var(--rival-left-hi),var(--rival-left))]'
                                : 'bg-[linear-gradient(180deg,var(--rival-right-hi),var(--rival-right))]',
                            animate && 'motion-safe:animate-room-pop',
                        )}
                    >
                        {icons !== null && (
                            <Emoji
                                src={icons.overtake}
                                className={big ? 'size-[4.2vh]' : 'size-5'}
                            />
                        )}
                        {pair.mine && leftTook
                            ? t('rooms.rivalry.took_lead_you')
                            : t('rooms.rivalry.took_lead', {
                                  name: nameOf(overtake),
                              })}
                    </span>
                </div>
            )}
        </div>
    );
}

/** The gap in words: the viewer's own on a player's phone, a name's elsewhere. */
function gapWords(
    t: Translate,
    pair: RivalPair,
    nameOf: (id: string | null) => string,
): string {
    if (pair.gap === null) {
        return t('rooms.rivalry.no_points');
    }

    // A match counts pieces: «:count daş öndəsiniz» (ADR 034 decision 23).
    const pieces = pair.unit === 'pieces';

    if (pair.mine) {
        return pair.gap > 0
            ? t(pieces ? 'rooms.rivalry.pieces_ahead' : 'rooms.rivalry.ahead', {
                  count: pair.gap,
              })
            : pair.gap < 0
              ? t(
                    pieces
                        ? 'rooms.rivalry.pieces_behind'
                        : 'rooms.rivalry.behind',
                    { count: -pair.gap },
                )
              : t('rooms.rivalry.level');
    }

    return pair.gap === 0
        ? t('rooms.rivalry.level_others')
        : t(pieces ? 'rooms.rivalry.pieces_leads' : 'rooms.rivalry.leads', {
              name: nameOf(pair.leader),
              count: Math.abs(pair.gap),
          });
}

/** A Fluent emoji of the strip: decoration, since the words say what it shows. */
function Emoji({ src, className }: { src: string; className: string }) {
    return (
        <img
            src={src}
            alt=""
            aria-hidden
            draggable={false}
            className={cn('shrink-0 select-none', className)}
        />
    );
}

/**
 * One end of the names row: the side's place as a filled label in its
 * colour (not in a duel), the name — «Siz» for the viewer, «Kompüter» for
 * the seat nobody holds — 👑 by the first place, «çıxıb» for one who left
 * and, on the right, how many others share its place. The right end is
 * mirrored; an empty side is «—».
 */
function Side({
    row,
    side,
    pair,
    name,
    nameOf,
    players,
    placeLabels,
    icons,
    marks,
    big,
}: {
    row: RoomRankedPlayer | null;
    side: 'left' | 'right';
    pair: RivalPair;
    name: string | null;
    nameOf: (id: string | null) => string;
    players: Map<string, RoomPlayer>;
    placeLabels: string[];
    icons: RivalryIcons | null;
    marks?: SideMarks;
    big: boolean;
}) {
    const { t } = useTranslation();
    const right = side === 'right';
    const computer = isComputerKey(row?.player_id ?? null);

    if (row === null) {
        return (
            <span
                className={cn('text-muted-foreground', right && 'text-right')}
            >
                —
            </span>
        );
    }

    const label =
        pair.mode === 'race' && row.place !== null
            ? (placeLabels[row.place - 1] ?? null)
            : null;
    const extra = big ? 'text-[2.2vh]' : 'text-xs';
    const mark = marks?.[row.player_id];
    const parts: ReactNode[] = [
        mark?.lead !== undefined && (
            <span key="lead" className="flex shrink-0 items-center">
                {mark.lead}
            </span>
        ),
        label !== null && (
            <span
                key="place"
                className={cn(
                    'shrink-0 font-bold text-white tabular-nums',
                    big
                        ? 'rounded-[0.8vh] px-[0.9vh] text-[2.1vh] leading-normal'
                        : 'rounded-md px-[5px] text-[10.5px] leading-4',
                    right ? 'bg-rival-right' : 'bg-rival-left',
                )}
            >
                {label}
            </span>
        ),
        <span key="name" className="min-w-0 truncate font-semibold">
            {computer && !big ? (
                // A phone's half has no room for the level: «Kompüter» is drawn, the level read out.
                <>
                    <span aria-hidden>{t('rooms.computer.name')}</span>
                    <span className="sr-only">{nameOf(row.player_id)}</span>
                </>
            ) : (
                (name ?? nameOf(row.player_id))
            )}
        </span>,
        row.place === 1 && icons !== null && (
            <Emoji
                key="crown"
                src={icons.leader}
                className={cn(big ? 'size-[3.6vh]' : '-mt-0.5 size-[18px]')}
            />
        ),
        mark?.tail !== undefined && (
            <span key="tail" className="flex shrink-0 items-center">
                {mark.tail}
            </span>
        ),
        players.get(row.player_id)?.present === false && (
            <span
                key="away"
                className={cn(
                    'shrink-0 font-medium whitespace-nowrap text-muted-foreground',
                    extra,
                )}
            >
                {t('rooms.rivalry.away')}
            </span>
        ),
        right && pair.more > 0 && (
            <span
                key="more"
                className={cn(
                    'shrink-0 font-medium whitespace-nowrap text-muted-foreground',
                    extra,
                )}
            >
                {big
                    ? t('rooms.rivalry.more', { count: pair.more })
                    : t('rooms.rivalry.more_short', { count: pair.more })}
            </span>
        ),
    ];

    return (
        <span
            className={cn(
                'flex max-w-1/2 min-w-0 items-center',
                big ? 'gap-[1vh]' : 'gap-[5px]',
                right
                    ? 'justify-end text-rival-right-text'
                    : 'text-rival-left-text',
            )}
        >
            {right ? parts.toReversed() : parts}
        </span>
    );
}

/**
 * The bar: each side a gradient from its highlight to its fill under one
 * gloss, the totals inside its ends and ⚡ on the meeting point, which
 * moves with it. Without two sides a grey bar with no split and no ⚡.
 */
function Bar({
    pair,
    before,
    split,
    counting,
    slides,
    shine,
    icons,
    big,
}: {
    pair: RivalPair;
    /** The pair at the totals before the round: where the count starts. */
    before: RivalPair;
    split: number | null;
    counting: boolean;
    slides: boolean;
    shine: boolean;
    icons: RivalryIcons | null;
    big: boolean;
}) {
    const number = cn(
        'absolute inset-y-0 z-[1] flex items-center font-extrabold tabular-nums',
        big ? 'text-[3.8vh]' : 'text-sm',
    );

    if (split === null || pair.left === null || pair.right === null) {
        return (
            <div
                className={cn(
                    'relative rounded-full bg-muted shadow-[inset_0_0_0_1px_var(--border)]',
                    big ? 'h-[6.4vh]' : 'h-6',
                )}
            >
                {pair.left !== null && (
                    <span
                        className={cn(
                            number,
                            'text-muted-foreground',
                            big ? 'left-[2vh]' : 'left-2.5',
                        )}
                    >
                        <Total
                            points={pair.left.points}
                            from={before.left?.points ?? pair.left.points}
                            counting={counting}
                        />
                    </span>
                )}
            </div>
        );
    }

    return (
        <div
            className={cn(
                'relative rounded-full bg-[linear-gradient(180deg,var(--rival-right-hi),var(--rival-right)_70%)] shadow-[inset_0_1px_0_rgb(255_255_255/0.35),inset_0_-1px_0_rgb(0_0_0/0.12),0_1px_3px_rgb(0_0_0/0.12)]',
                big ? 'h-[6.4vh]' : 'h-6',
            )}
        >
            <span
                className={cn(
                    'absolute inset-y-0 left-0 rounded-l-full bg-[linear-gradient(180deg,var(--rival-left-hi),var(--rival-left)_70%)] shadow-[inset_0_1px_0_rgb(255_255_255/0.35)]',
                    slides &&
                        'transition-[width] duration-700 ease-[cubic-bezier(0.2,0.8,0.2,1)] motion-reduce:transition-none',
                )}
                style={{ width: `${split}%` }}
            >
                {icons !== null && (
                    <span
                        className={cn(
                            'absolute top-1/2 right-0 z-[2] grid translate-x-1/2 -translate-y-1/2 place-items-center',
                            big ? 'size-[9.5vh]' : 'size-[34px]',
                        )}
                    >
                        <span
                            aria-hidden
                            className="absolute inset-0 rounded-full bg-[radial-gradient(circle,rgb(255_255_255/0.95)_0_28%,rgb(255_244_200/0.6)_45%,rgb(255_255_255/0)_70%)]"
                        />
                        <Emoji
                            src={icons.clash}
                            className={cn(
                                'relative drop-shadow-[0_1px_1px_rgb(0_0_0/0.25)]',
                                big ? 'size-[6vh]' : 'size-[22px]',
                            )}
                        />
                    </span>
                )}
            </span>
            <span
                aria-hidden
                className={cn(
                    'pointer-events-none absolute top-0.5 h-[38%] rounded-full bg-[linear-gradient(180deg,rgb(255_255_255/0.32),rgb(255_255_255/0))]',
                    big ? 'inset-x-[1.6vh]' : 'inset-x-1.5',
                )}
            />
            <span
                className={cn(
                    number,
                    'text-white [text-shadow:0_1px_2px_rgb(0_0_0/0.3)]',
                    big ? 'left-[2vh]' : 'left-2.5',
                )}
            >
                <Total
                    points={pair.left.points}
                    from={before.left?.points ?? pair.left.points}
                    counting={counting}
                />
            </span>
            <span
                className={cn(
                    number,
                    'text-white [text-shadow:0_1px_2px_rgb(0_0_0/0.3)]',
                    big ? 'right-[2vh]' : 'right-2.5',
                )}
            >
                <Total
                    points={pair.right.points}
                    from={before.right?.points ?? pair.right.points}
                    counting={counting}
                />
            </span>
            {shine && (
                <span
                    aria-hidden
                    className="pointer-events-none absolute inset-0 z-[1] animate-room-shine overflow-hidden rounded-full bg-[linear-gradient(105deg,transparent_35%,rgb(255_255_255/0.75)_50%,transparent_65%)] bg-size-[60%_100%] bg-no-repeat opacity-0 motion-reduce:hidden"
                />
            )}
        </div>
    );
}

/** A side's total: the server's points, counted up from the round before while the split slides. */
function Total({
    points,
    from,
    counting,
}: {
    points: number;
    from: number;
    counting: boolean;
}) {
    const shown = useCountUp(points, {
        from,
        enabled: counting,
        delay: SLIDE_AT,
        duration: SLIDE_MS,
    });

    return <span data-strip-total={points}>{shown}</span>;
}

/**
 * The gap's pill: a disc in the leading side's colour with an arrow — on
 * a player's phone ▲ when the viewer leads and ▼ when behind, elsewhere ▲
 * — and the words; «=» on a grey disc when level; the words alone with no
 * pair yet. It pops when it takes new words in a reveal.
 */
function Gap({
    pair,
    words,
    pop,
    big,
}: {
    pair: RivalPair;
    words: string;
    pop: boolean;
    big: boolean;
}) {
    const icon = big ? 'size-[2.2vh]' : 'size-3';
    const lead = pair.gap === null || pair.gap === 0 ? null : pair.gap > 0;

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full bg-muted font-semibold text-foreground',
                big
                    ? 'gap-[0.8vh] text-[2.4vh] leading-[1.6]'
                    : 'gap-[5px] text-xs leading-[18px]',
                pair.gap === null
                    ? big
                        ? 'px-[1.6vh]'
                        : 'px-[9px]'
                    : big
                      ? 'pr-[1.6vh] pl-[0.6vh]'
                      : 'pr-[9px] pl-1',
                pop && 'motion-safe:animate-room-pop',
            )}
        >
            {pair.gap !== null && (
                <span
                    aria-hidden
                    className={cn(
                        'grid shrink-0 place-items-center rounded-full text-white',
                        big ? 'size-[3vh]' : 'size-4',
                        lead === null
                            ? 'bg-muted-foreground'
                            : lead
                              ? 'bg-rival-left'
                              : 'bg-rival-right',
                    )}
                >
                    {lead === null ? (
                        <Equal className={icon} strokeWidth={3} />
                    ) : pair.mine && !lead ? (
                        <ChevronsDown className={icon} strokeWidth={3} />
                    ) : (
                        <ChevronsUp className={icon} strokeWidth={3} />
                    )}
                </span>
            )}
            {words}
        </span>
    );
}
