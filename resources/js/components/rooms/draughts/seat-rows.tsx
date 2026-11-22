import { ArrowUpDown, Bot, ChevronDown, Check } from 'lucide-react';
import { useState } from 'react';
import {
    withComputerPicked,
    withSeat,
    withSeatsSwapped,
} from '@/components/rooms/catalog';
import type { ChoiceSeats } from '@/components/rooms/catalog';
import {
    computerName,
    computerSeat,
    seatName,
} from '@/components/rooms/computer';
import { PieceMark } from '@/components/rooms/draughts/piece';
import type { RoomChoice } from '@/components/rooms/game-settings';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { Translate } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomMatchColor, RoomPlayer } from '@/rooms/contract';

/**
 * Who a seat of the choice shows: the computer with its level on the colour
 * it plays (`computerSeat()`), the seated player's name, or nobody yet.
 */
function seatShown(
    t: Translate,
    seats: ChoiceSeats,
    players: RoomPlayer[],
    color: RoomMatchColor,
): { id: string | null; computer: boolean; name: string | null } {
    const id = seats[color];
    const computer = computerSeat(seats) === color;

    return {
        id,
        computer,
        name: computer
            ? computerName(t, seats.computer)
            : id === null
              ? null
              : seatName(t, players, id, null),
    };
}

/** A row of a seat's list: a present player, or the computer. */
const option =
    'flex min-h-13 w-full items-center gap-2 px-3 text-left outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50';

/**
 * «Oyunçular» of a board game's choice (docs/frontend.md rule 15 "Board
 * games", ADR 034 decision 7), in place of the rounds: two 52px rows,
 * «Ağlar» and «Qaralar», each a piece of its colour and the seated
 * player's name — «Oyunçu gözlənilir» while nobody sits there — with a
 * 44px button that opens the room's present players as 52px rows; between
 * them «Yerləri dəyiş». A tap posts the whole choice and the page waits for
 * the state (`useRoomChoice()`); a player picked for the other seat
 * changes places. The seats are the state's: the server keeps a seat's
 * player while present and fills an empty one.
 *
 * Each seat's list ends with «Kompüter» beside `Bot` (rule 15 "The
 * computer's seat", ADR 036 decision 15): picked, the computer plays that
 * colour — its row says «Kompüter (Orta)» with `Bot` — and the player the
 * other; picked again, it is turned off where another present player can
 * take its seat (`withComputerPicked()`). «Yerləri dəyiş» then gives the
 * player the computer's colour. A room of one has the computer already
 * seated by the state and starts at once (ADR 036 as amended 2026-11-22).
 * Its level is `ComputerLevelSegments`, under the rows.
 */
export function SeatRows({
    seats,
    players,
    choice,
    big = false,
}: {
    seats: ChoiceSeats;
    players: RoomPlayer[];
    choice: RoomChoice;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState<RoomMatchColor | null>(null);
    const present = players.filter((player) => player.present);
    const busy = choice.pending !== null;
    // Two players change places; against the computer the one player takes its colour.
    const seated = [seats.white, seats.black].filter(
        (seat) => seat !== null,
    ).length;
    const swappable = seated === (seats.computer === null ? 2 : 1);
    const robot = big ? 'size-[max(1rem,2.4vh)] shrink-0' : 'size-4 shrink-0';

    const pick = (next: Parameters<RoomChoice['choose']>[0]) => {
        setOpen(null);
        choice.choose(next, 'seats');
    };

    const row = (color: RoomMatchColor) => {
        const { id, computer, name } = seatShown(t, seats, players, color);
        const side = t(`rooms.seats.${color}`);

        return (
            <div className="flex flex-col">
                <div
                    className={cn(
                        'flex items-center gap-3 rounded-xl border bg-card pl-3',
                        big
                            ? 'min-h-[max(3.25rem,7vh)] text-[max(1rem,2.2vh)]'
                            : 'min-h-13',
                    )}
                >
                    <PieceMark
                        color={color}
                        className={big ? 'size-[max(1.5rem,3.4vh)]' : 'size-6'}
                    />
                    <span className="flex min-w-0 flex-1 flex-col leading-tight">
                        <span className="text-xs text-muted-foreground">
                            {side}
                        </span>
                        <span
                            className={cn(
                                'flex min-w-0 items-center gap-1.5 font-semibold',
                                name === null &&
                                    'font-normal text-muted-foreground',
                            )}
                        >
                            {computer && <Bot aria-hidden className={robot} />}
                            <span className="truncate">
                                {name ?? t('rooms.seats.waiting')}
                            </span>
                        </span>
                    </span>
                    {choice.pending === 'seats' && <Spinner />}
                    <Button
                        type="button"
                        variant="ghost"
                        className="size-11 shrink-0"
                        aria-label={t('rooms.seats.pick', { side })}
                        aria-expanded={open === color}
                        disabled={busy}
                        onClick={() => setOpen(open === color ? null : color)}
                    >
                        <ChevronDown
                            className={cn(
                                'size-5 transition-transform motion-reduce:transition-none',
                                open === color && 'rotate-180',
                            )}
                        />
                    </Button>
                </div>
                {open === color && (
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
                                    onClick={() =>
                                        pick(
                                            withSeat(
                                                choice.shown,
                                                color,
                                                player.id,
                                            ),
                                        )
                                    }
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
                        {/* The computer, after the players: picked again, it is turned off — in a room of two or more. */}
                        <li>
                            <button
                                type="button"
                                disabled={busy}
                                aria-pressed={computer}
                                onClick={() =>
                                    pick(
                                        withComputerPicked(
                                            choice.shown,
                                            color,
                                            present.length,
                                        ),
                                    )
                                }
                                className={option}
                            >
                                <Bot aria-hidden className={robot} />
                                <span className="min-w-0 flex-1 truncate">
                                    {t('rooms.computer.name')}
                                </span>
                                {computer && (
                                    <Check aria-hidden className="size-4" />
                                )}
                            </button>
                        </li>
                    </ul>
                )}
            </div>
        );
    };

    return (
        <div className={cn('flex flex-col gap-2', big && 'gap-[1vh]')}>
            {row('white')}
            <Button
                type="button"
                variant="outline"
                className="h-11 self-center"
                disabled={busy || !swappable}
                onClick={() => pick(withSeatsSwapped(choice.shown))}
            >
                <ArrowUpDown />
                {t('rooms.seats.swap')}
            </Button>
            {row('black')}
        </div>
    );
}

/**
 * A player's own seat in the choice, in words (rule 15 "«Oyunlar» and the
 * start screen"): «Siz ağlarla oynayırsınız», «Siz qaralarla
 * oynayırsınız» or «Bu oyunu izləyəcəksiniz», with a piece of its colour.
 * Whom it plays — another player or the computer — is the choice's line.
 */
export function SeatNote({
    seats,
    me,
}: {
    seats: ChoiceSeats;
    me: string | null;
}) {
    const { t } = useTranslation();
    const color: RoomMatchColor | null =
        me === null
            ? null
            : seats.white === me
              ? 'white'
              : seats.black === me
                ? 'black'
                : null;

    return (
        <p className="flex items-center justify-center gap-2 text-center text-sm font-medium">
            {color !== null && <PieceMark color={color} />}
            {color === 'white'
                ? t('rooms.seats.you_white')
                : color === 'black'
                  ? t('rooms.seats.you_black')
                  : t('rooms.seats.watching')}
        </p>
    );
}

/**
 * The two seats of the choice in one line, as the start screen shows them
 * beside the game: a piece of each colour and its player's name, or
 * «Oyunçu gözlənilir» — the computer's seat `Bot` and «Kompüter (Orta)».
 * `big` is the screen's.
 */
export function SeatPair({
    seats,
    players,
    big = false,
}: {
    seats: ChoiceSeats;
    players: RoomPlayer[];
    big?: boolean;
}) {
    const { t } = useTranslation();
    const seat = (color: RoomMatchColor) => {
        const { computer, name } = seatShown(t, seats, players, color);

        return (
            <span className="flex min-w-0 items-center gap-1.5">
                <PieceMark
                    color={color}
                    className={big ? 'size-[2.4vh]' : 'size-3.5'}
                />
                <span className="sr-only">{t(`rooms.seats.${color}`)}:</span>
                {computer && (
                    <Bot
                        aria-hidden
                        className={cn(
                            'shrink-0',
                            big ? 'size-[2.4vh]' : 'size-3.5',
                        )}
                    />
                )}
                <span
                    className={cn(
                        'min-w-0 truncate',
                        name === null && 'text-muted-foreground',
                    )}
                >
                    {name ?? t('rooms.seats.waiting')}
                </span>
            </span>
        );
    };

    return (
        <span
            className={cn(
                'flex min-w-0 items-center',
                big ? 'gap-[2vh] text-[max(0.9375rem,2.4vh)]' : 'gap-3 text-sm',
            )}
        >
            {seat('white')}
            {seat('black')}
        </span>
    );
}

/**
 * «Növbəti oyunda :name ağlarla oynayır» (docs/frontend.md rule 15 "Board
 * games" → "The end"): who plays white in the next game — a player, or the
 * computer when the colours' change gives it white — under «Yenidən oyna»
 * on the phone and the screen.
 */
export function SeatNext({
    seats,
    players,
    className,
}: {
    /** The next game's seats: the choice's. */
    seats: ChoiceSeats;
    players: RoomPlayer[];
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <span className={className}>
            {t('rooms.seats.next', {
                name:
                    seatShown(t, seats, players, 'white').name ??
                    t('rooms.show.nameless'),
            })}
        </span>
    );
}
