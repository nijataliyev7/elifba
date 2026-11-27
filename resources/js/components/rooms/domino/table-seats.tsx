import { Bot, Check, ChevronDown, Eye, Users } from 'lucide-react';
import { useState } from 'react';
import type { ChoiceTable } from '@/components/rooms/catalog';
import { computerName, seatName } from '@/components/rooms/computer';
import { partnerOf, TABLES } from '@/components/rooms/domino/places';
import {
    seatGroups,
    withTable,
    withTableSeat,
} from '@/components/rooms/domino/table-choice';
import type { RoomChoice } from '@/components/rooms/game-settings';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomPlayer } from '@/rooms/contract';

const pressable =
    'outline-none transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-wait motion-reduce:transition-none';

/**
 * «Masa» (docs/frontend.md rule 15 "Domino" → "«Oyunlar» and the start
 * screen", ADR 038 decision 10): four 44px segments — «2 nəfər», «3
 * nəfər», «4 nəfər», «Para 2×2». A tap posts the whole choice, the seats
 * fitted to the new table, and the page waits for the state.
 */
export function TableSegments({
    table,
    choice,
    big = false,
}: {
    table: ChoiceTable;
    choice: RoomChoice;
    big?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <div className="grid grid-cols-4 gap-1 rounded-xl bg-muted p-1">
            {TABLES.map((one) => {
                const pressed = one === table.table;

                return (
                    <button
                        key={one}
                        type="button"
                        aria-pressed={pressed}
                        disabled={choice.pending !== null}
                        onClick={() =>
                            choice.choose(withTable(choice.shown, one), 'table')
                        }
                        className={cn(
                            pressable,
                            'rounded-lg px-1 leading-tight font-medium',
                            big
                                ? 'min-h-[max(2.75rem,6vh)] text-[max(0.9375rem,2vh)]'
                                : 'min-h-11 text-[0.8125rem]',
                            pressed
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground',
                        )}
                    >
                        {t(`rooms.table.${one}`)}
                    </button>
                );
            })}
        </div>
    );
}

/** A row of the choice's seat list: a present player. */
const option =
    'flex min-h-13 w-full items-center gap-2 px-3 text-left outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50';

/**
 * «Yerlər» (docs/frontend.md rule 15 "Domino", ADR 038 decisions 11–13),
 * in place of the rounds: one 52px row a seat in the order of the turns —
 * at «Para 2×2» in two groups, «1-ci para» (seats 0 and 2) and «2-ci para»
 * (1 and 3) — each its player's name, or `Bot` «Kompüter (Orta)» for a
 * seat nobody is left for, with a 44px button that opens the room's
 * present players as 52px rows. A player picked for a seat sits there:
 * one who sat elsewhere changes places, one who watched takes it. The
 * list has no «Kompüter» row: the room seats the computer by itself where
 * nobody is left, and under the seats one line says so.
 */
export function TableSeats({
    table,
    players,
    choice,
    big = false,
}: {
    table: ChoiceTable;
    players: RoomPlayer[];
    choice: RoomChoice;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState<number | null>(null);
    const present = players.filter((player) => player.present);
    const busy = choice.pending !== null;
    const groups = seatGroups(table.table, table.seats.length);
    const robot = big ? 'size-[max(1rem,2.4vh)] shrink-0' : 'size-4 shrink-0';

    const row = (seat: number) => {
        const id = table.seats[seat] ?? null;
        const label = t('rooms.table.seat', { number: seat + 1 });

        return (
            <div key={seat} className="flex flex-col">
                <div
                    data-table-seat={seat}
                    className={cn(
                        'flex items-center gap-3 rounded-xl border bg-card pl-3',
                        big
                            ? 'min-h-[max(3.25rem,7vh)] text-[max(1rem,2.2vh)]'
                            : 'min-h-13',
                    )}
                >
                    <span className="flex min-w-0 flex-1 flex-col leading-tight">
                        <span className="text-xs text-muted-foreground">
                            {label}
                        </span>
                        <span className="flex min-w-0 items-center gap-1.5 font-semibold">
                            {id === null && (
                                <Bot aria-hidden className={robot} />
                            )}
                            <span className="truncate">
                                {id === null
                                    ? computerName(t, table.computer)
                                    : seatName(t, players, id, null)}
                            </span>
                        </span>
                    </span>
                    {choice.pending === 'table' && <Spinner />}
                    <Button
                        type="button"
                        variant="ghost"
                        className="size-11 shrink-0"
                        aria-label={t('rooms.seats.pick', { side: label })}
                        aria-expanded={open === seat}
                        disabled={busy || present.length === 0}
                        onClick={() => setOpen(open === seat ? null : seat)}
                    >
                        <ChevronDown
                            className={cn(
                                'size-5 transition-transform motion-reduce:transition-none',
                                open === seat && 'rotate-180',
                            )}
                        />
                    </Button>
                </div>
                {open === seat && (
                    <ul className="mt-1 flex flex-col overflow-hidden rounded-xl border bg-card">
                        {present.map((player) => (
                            <li
                                key={player.id}
                                className="border-b last:border-b-0"
                            >
                                <button
                                    type="button"
                                    disabled={busy}
                                    aria-pressed={player.id === id}
                                    onClick={() => {
                                        setOpen(null);
                                        choice.choose(
                                            withTableSeat(
                                                choice.shown,
                                                seat,
                                                player.id,
                                            ),
                                            'table',
                                        );
                                    }}
                                    className={option}
                                >
                                    <span className="min-w-0 flex-1 truncate">
                                        {player.name ??
                                            t('rooms.show.nameless')}
                                    </span>
                                    {player.id === id && (
                                        <Check aria-hidden className="size-4" />
                                    )}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        );
    };

    return (
        <div className={cn('flex flex-col gap-3', big && 'gap-[1.6vh]')}>
            {groups.map((group, index) => (
                <div
                    key={index}
                    className={cn('flex flex-col gap-2', big && 'gap-[1vh]')}
                >
                    {groups.length > 1 && (
                        <span
                            className={cn(
                                'font-medium text-muted-foreground',
                                big
                                    ? 'text-[max(0.875rem,1.9vh)]'
                                    : 'text-[0.8125rem]',
                            )}
                        >
                            {t('rooms.table.pair', { number: index + 1 })}
                        </span>
                    )}
                    {group.map(row)}
                </div>
            ))}
            {table.seats.includes(null) && (
                <p
                    className={cn(
                        'flex items-center gap-1.5 text-muted-foreground',
                        big ? 'text-[max(0.875rem,1.9vh)]' : 'text-[0.8125rem]',
                    )}
                >
                    <Bot aria-hidden className={robot} />
                    {t('rooms.table.computers')}
                </p>
            )}
        </div>
    );
}

/**
 * The seats of the choice in one line, as the start screen shows them
 * beside the game: each seat's name in the order of the turns, `Bot` and
 * «Kompüter (Orta)» for the computer's. `big` is the screen's.
 */
export function TableLine({
    table,
    players,
    big = false,
}: {
    table: ChoiceTable;
    players: RoomPlayer[];
    big?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <span
            data-table-line
            className={cn(
                'flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1',
                big ? 'text-[max(0.9375rem,2.4vh)]' : 'text-sm',
            )}
        >
            {table.seats.map((id, seat) => (
                <span key={seat} className="flex min-w-0 items-center gap-1">
                    {id === null && (
                        <Bot
                            aria-hidden
                            className={cn(
                                'shrink-0',
                                big ? 'size-[2.4vh]' : 'size-3.5',
                            )}
                        />
                    )}
                    <span className="min-w-0 truncate">
                        {id === null
                            ? computerName(t, table.computer)
                            : seatName(t, players, id, null)}
                    </span>
                </span>
            ))}
        </span>
    );
}

/**
 * A player's own place in the choice, in words (rule 15 "«Oyunlar» and the
 * start screen"): «Siz oynayırsınız» — at pairs «Tərəfdaşınız: :name»,
 * or «Tərəfdaşınız: Kompüter (Orta)» — or «Bu oyunu izləyəcəksiniz».
 */
export function TableNote({
    table,
    players,
    me,
}: {
    table: ChoiceTable;
    players: RoomPlayer[];
    me: string | null;
}) {
    const { t } = useTranslation();
    const seat = me === null ? -1 : table.seats.indexOf(me);
    const partner =
        seat < 0 || table.table === null ? null : partnerOf(table.table, seat);
    const partnerId =
        partner === null ? undefined : (table.seats[partner] ?? null);

    return (
        <p
            data-table-note
            className="flex items-center justify-center gap-2 text-center text-sm font-medium"
        >
            {seat < 0 ? (
                <>
                    <Eye aria-hidden className="size-4 shrink-0" />
                    {t('rooms.table.watching')}
                </>
            ) : (
                <>
                    <Users aria-hidden className="size-4 shrink-0" />
                    {partnerId === undefined
                        ? t('rooms.table.you_play')
                        : t('rooms.table.partner', {
                              name:
                                  partnerId === null
                                      ? computerName(t, table.computer)
                                      : seatName(t, players, partnerId, null),
                          })}
                </>
            )}
        </p>
    );
}
