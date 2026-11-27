import {
    CircleCheck,
    Flame,
    Hourglass,
    Landmark,
    TrendingUp,
} from 'lucide-react';
import { useState } from 'react';
import type { LucideIcon } from 'lucide-react';
import {
    dominoSeatName,
    dominoSideName,
    sideTone,
} from '@/components/rooms/domino/names';
import { sheetView, toTarget } from '@/components/rooms/domino/sheet';
import { sideKey } from '@/components/rooms/domino/sides';
import { sheetColumns } from '@/components/rooms/domino/stage';
import { TileSvg } from '@/components/rooms/domino/tile';
import { secondsUntil } from '@/components/rooms/use-server-now';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type {
    RoomComputerLevel,
    RoomDomino,
    RoomLedgerLine,
    RoomPlayer,
} from '@/rooms/contract';

/** A line of the account's icon: written to a balance, waiting, burnt, the bank's. */
const LINE_ICONS: Record<RoomLedgerLine, LucideIcon> = {
    gained: TrendingUp,
    opened: TrendingUp,
    released: TrendingUp,
    bank_taken: TrendingUp,
    waited: Hourglass,
    burnt_rival: Flame,
    burnt_third: Flame,
    bank_burnt: Flame,
    bank_added: Landmark,
};

/**
 * A hand's sheet (docs/frontend.md rule 15 "Domino" → "The sheet", ADR 038
 * decision 49): it opens by itself in the pause after a hand — on a phone
 * a sheet from the bottom over the table and the hand, on the screen
 * (`big`) a panel in the middle — and under the final once the game is
 * over (`still`). Top to bottom: «Əl :number bitdi» and how it ended;
 * every seat's tiles left, face up, small, with their count — «0-0 tək
 * qalıb: 10 xal» where it applies; in a blocked hand each side's count and
 * why it was won; every line of the account as a sentence, in order, by
 * its side's mark and an icon — a burnt amount in words with `Flame`; the
 * balances after it and how far each stands from 101; who opens next and
 * why; and a bar that runs out as the next hand is dealt. Every number is
 * the state's.
 *
 * On the screen's panel it stands in two columns (`sheetColumns()`) — the
 * hand's tiles and counts beside the account, the balances, who opens next
 * and the bar — and the screen fits it to the table (`ScreenFit`); each
 * seat's tiles left are a row of their own, so a name is never squeezed
 * out by its tiles.
 *
 * On a page that saw the hand end it rises when the hand's steps are over
 * (`rises`, ms into the moment); otherwise, and under reduced motion, it is
 * there at once.
 */
export function HandSheet({
    domino,
    target,
    players,
    computer,
    viewer,
    now,
    rises = null,
    big = false,
    still = false,
    className,
}: {
    domino: RoomDomino;
    target: number;
    players: RoomPlayer[];
    computer: RoomComputerLevel | null;
    viewer: string | null;
    now: number | null;
    rises?: number | null;
    big?: boolean;
    /** Under the final: no bar, no next hand. */
    still?: boolean;
    className?: string;
}) {
    const { t } = useTranslation();
    const nameOf = (seat: number) =>
        dominoSeatName(t, players, domino, computer, seat);
    const view = sheetView(domino, nameOf);
    const until = view?.until ?? null;
    // How long the pause had left when the sheet was first drawn: the bar runs out from there.
    const [total] = useState(() =>
        until === null || now === null ? null : Math.max(1, until - now),
    );

    if (view === null) {
        return null;
    }

    const columns = sheetColumns(big, still);
    // The two parts of the sheet: the hand's tiles and counts, then the account; side by side on the screen's panel.
    const part = cn('flex min-w-0 flex-col', big ? 'gap-[1.6vh]' : 'gap-3');

    const sideName = (side: number) => {
        const one = domino.sides.find((each) => each.side === side);

        return one === undefined
            ? ''
            : dominoSideName(
                  t,
                  players,
                  domino,
                  computer,
                  viewer,
                  sideKey(domino, one),
              );
    };
    const mark = (side: number | null) => {
        const tone = sideTone(domino, viewer, side);

        return (
            <span
                aria-hidden
                className={cn(
                    'shrink-0 rounded-full',
                    big ? 'size-[1.4vh]' : 'size-2.5',
                    tone === 'left'
                        ? 'bg-rival-left'
                        : tone === 'right'
                          ? 'bg-rival-right'
                          : 'bg-muted-foreground',
                )}
            />
        );
    };
    const text = big ? 'text-[max(0.9375rem,2.2vh)]' : 'text-sm';
    const small = big ? 'text-[max(0.875rem,1.9vh)]' : 'text-xs';
    const icon = big ? 'size-[2.4vh] shrink-0' : 'size-4 shrink-0';
    const left = until === null ? null : secondsUntil(until, now);
    const share =
        until === null || total === null || now === null
            ? 0
            : Math.max(0, Math.min(1, (until - now) / total));

    return (
        <section
            data-sheet={view.hand}
            aria-labelledby={`sheet-${view.hand}`}
            data-columns={columns}
            className={cn(
                'bg-card text-card-foreground',
                columns === 2
                    ? 'grid grid-cols-2 items-start gap-x-[3vh]'
                    : 'flex min-h-0 flex-col',
                big
                    ? 'gap-y-[1.6vh] rounded-[2.4vh] border p-[2.6vh] shadow-2xl'
                    : 'gap-3 overflow-y-auto rounded-t-2xl border-t px-4 pt-4 pb-5 shadow-[0_-8px_24px_rgb(0_0_0/0.25)]',
                rises !== null &&
                    (big
                        ? 'motion-safe:animate-room-pop'
                        : 'motion-safe:animate-room-enter'),
                className,
            )}
            style={
                rises === null ? undefined : { animationDelay: `${rises}ms` }
            }
        >
            <header
                className={cn(
                    'flex flex-col gap-0.5',
                    columns === 2 && 'col-span-2',
                )}
            >
                <h2
                    id={`sheet-${view.hand}`}
                    className={cn(
                        'font-bold',
                        big ? 'text-[max(1.25rem,3.4vh)]' : 'text-lg',
                    )}
                >
                    {t('rooms.domino.sheet.title', { number: view.hand })}
                </h2>
                <p className={cn('font-medium', text)}>
                    {t(view.how.key, view.how.params)}
                </p>
            </header>

            <div className={part}>
                <div className="flex flex-col gap-1.5">
                    <h3
                        className={cn(
                            'font-semibold text-muted-foreground',
                            small,
                        )}
                    >
                        {t('rooms.domino.sheet.left')}
                    </h3>
                    {/* A seat a row: a name never gives way to the tiles beside it. */}
                    <ul
                        className={cn(
                            'grid grid-cols-1',
                            big ? 'gap-[1vh]' : 'gap-1.5',
                        )}
                    >
                        {view.left.map((seat) => (
                            <li
                                key={seat.seat}
                                data-left={seat.seat}
                                className={cn('flex items-center gap-2', text)}
                            >
                                {mark(domino.seats[seat.seat]?.side ?? null)}
                                <span className="min-w-[6rem] flex-1 truncate">
                                    {nameOf(seat.seat)}
                                </span>
                                <span className="flex shrink-0 gap-0.5">
                                    {seat.tiles.map((tile) => (
                                        <TileSvg
                                            key={tile}
                                            tile={tile}
                                            className={
                                                big
                                                    ? 'h-[4vh] w-[2vh]'
                                                    : 'h-7 w-3.5'
                                            }
                                        />
                                    ))}
                                </span>
                                <span className="min-w-14 shrink-0 text-right font-semibold tabular-nums">
                                    {seat.loneBlank
                                        ? t('rooms.domino.sheet.lone_blank', {
                                              count: seat.pips,
                                          })
                                        : t('rooms.game.points', {
                                              count: seat.pips,
                                          })}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>

                {view.counts !== null && (
                    <div className={cn('flex flex-col gap-1', text)}>
                        <p className="flex flex-wrap gap-x-4 gap-y-1">
                            {view.counts.map((count) => (
                                <span
                                    key={count.side}
                                    className="flex items-center gap-1.5"
                                >
                                    {mark(count.side)}
                                    {sideName(count.side)}:{' '}
                                    <b className="tabular-nums">{count.pips}</b>
                                </span>
                            ))}
                        </p>
                        {view.lower !== null && (
                            <p className="font-medium">
                                {t(view.lower.key, view.lower.params)}
                            </p>
                        )}
                    </div>
                )}
            </div>

            <div className={part}>
                {view.lines.length > 0 && (
                    <ol className="flex flex-col gap-1.5">
                        {view.lines.map((line, index) => {
                            const Icon = LINE_ICONS[line.line];

                            return (
                                <li
                                    key={index}
                                    data-line={line.line}
                                    className={cn(
                                        'flex items-center gap-2',
                                        text,
                                    )}
                                >
                                    {mark(line.side)}
                                    <Icon
                                        aria-hidden
                                        className={cn(
                                            icon,
                                            line.burnt &&
                                                'text-red-600 dark:text-red-400',
                                        )}
                                    />
                                    <span className="min-w-0">
                                        {line.side !== null && (
                                            <span className="font-semibold">
                                                {sideName(line.side)}:{' '}
                                            </span>
                                        )}
                                        {t(line.key, line.params)}
                                    </span>
                                </li>
                            );
                        })}
                    </ol>
                )}

                <ul className={cn('flex flex-col gap-1', text)}>
                    {domino.sides.map((side) => (
                        <li
                            key={side.side}
                            data-balance={side.side}
                            className="flex items-center gap-2"
                        >
                            {mark(side.side)}
                            <span className="min-w-0 flex-1 truncate font-medium">
                                {sideName(side.side)}
                            </span>
                            <b className="tabular-nums">{side.balance}</b>
                            <span
                                className={cn('text-muted-foreground', small)}
                            >
                                {t('rooms.domino.sheet.to_target', {
                                    count: toTarget(side.balance, target),
                                })}
                            </span>
                        </li>
                    ))}
                </ul>

                {view.next !== null && !still && (
                    <p
                        className={cn(
                            'flex items-center gap-2 font-medium',
                            text,
                        )}
                    >
                        <CircleCheck aria-hidden className={icon} />
                        {t(view.next.key, {
                            ...view.next.params,
                            why:
                                typeof view.next.params.why === 'string'
                                    ? t(view.next.params.why)
                                    : '',
                        })}
                    </p>
                )}

                {until !== null && !still && (
                    <div className="flex flex-col gap-1">
                        <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                            <div
                                className="h-full rounded-full bg-primary"
                                style={{ width: `${share * 100}%` }}
                            />
                        </div>
                        <span className={cn('text-muted-foreground', small)}>
                            {t('rooms.domino.sheet.until', {
                                seconds: left ?? 0,
                            })}
                        </span>
                    </div>
                )}
            </div>
        </section>
    );
}
