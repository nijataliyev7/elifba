import { ArrowUpDown, ChevronDown, Check } from 'lucide-react';
import { useState } from 'react';
import { PieceMark } from '@/components/rooms/draughts/piece';
import type { RoomChoice } from '@/components/rooms/game-settings';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomMatchColor, RoomPlayer } from '@/rooms/contract';

type Seats = { white: string | null; black: string | null };

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
 */
export function SeatRows({
    seats,
    players,
    choice,
    big = false,
}: {
    seats: Seats;
    players: RoomPlayer[];
    choice: RoomChoice;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState<RoomMatchColor | null>(null);
    const present = players.filter((player) => player.present);
    const busy = choice.pending !== null;

    const seat = (color: RoomMatchColor, id: string) => {
        const other = color === 'white' ? seats.black : seats.white;
        const own = color === 'white' ? seats.white : seats.black;
        const next =
            color === 'white'
                ? { white: id, black: other === id ? own : other }
                : { black: id, white: other === id ? own : other };

        setOpen(null);
        choice.choose({ ...choice.shown, ...next }, 'seats');
    };

    const row = (color: RoomMatchColor) => {
        const id = color === 'white' ? seats.white : seats.black;
        const name =
            id === null
                ? null
                : (players.find((player) => player.id === id)?.name ??
                  t('rooms.show.nameless'));
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
                                'truncate font-semibold',
                                name === null &&
                                    'font-normal text-muted-foreground',
                            )}
                        >
                            {name ?? t('rooms.seats.waiting')}
                        </span>
                    </span>
                    {choice.pending === 'seats' && <Spinner />}
                    <Button
                        type="button"
                        variant="ghost"
                        className="size-11 shrink-0"
                        aria-label={t('rooms.seats.pick', { side })}
                        aria-expanded={open === color}
                        disabled={busy || present.length === 0}
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
                                    onClick={() => seat(color, player.id)}
                                    className="flex min-h-13 w-full items-center gap-2 px-3 text-left outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50"
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
        <div className={cn('flex flex-col gap-2', big && 'gap-[1vh]')}>
            {row('white')}
            <Button
                type="button"
                variant="outline"
                className="h-11 self-center"
                disabled={busy || seats.white === null || seats.black === null}
                onClick={() =>
                    choice.choose(
                        {
                            ...choice.shown,
                            white: seats.black,
                            black: seats.white,
                        },
                        'seats',
                    )
                }
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
 */
export function SeatNote({ seats, me }: { seats: Seats; me: string | null }) {
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
 * «Oyunçu gözlənilir». `big` is the screen's.
 */
export function SeatPair({
    seats,
    players,
    big = false,
}: {
    seats: Seats;
    players: RoomPlayer[];
    big?: boolean;
}) {
    const { t } = useTranslation();
    const seat = (color: RoomMatchColor) => {
        const id = color === 'white' ? seats.white : seats.black;
        const name =
            id === null
                ? null
                : (players.find((player) => player.id === id)?.name ??
                  t('rooms.show.nameless'));

        return (
            <span className="flex min-w-0 items-center gap-1.5">
                <PieceMark
                    color={color}
                    className={big ? 'size-[2.4vh]' : 'size-3.5'}
                />
                <span className="sr-only">{t(`rooms.seats.${color}`)}:</span>
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
