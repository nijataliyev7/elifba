import {
    Bot,
    Eye,
    Flag,
    Hand,
    Hourglass,
    TriangleAlert,
    Undo2,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { computerOf } from '@/components/rooms/computer';
import { lineParts, turnBeginsAt } from '@/components/rooms/domino/line';
import type { LineIcon, LinePart } from '@/components/rooms/domino/line';
import { dominoSeatName } from '@/components/rooms/domino/names';
import { secondsUntil } from '@/components/rooms/use-server-now';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomDomino, RoomDominoGame, RoomPlayer } from '@/rooms/contract';

const ICONS: Record<LineIcon, LucideIcon> = {
    hand: Hand,
    hourglass: Hourglass,
    bot: Bot,
    eye: Eye,
    flag: Flag,
    alert: TriangleAlert,
};

/**
 * The line under the table (docs/frontend.md rule 15 "Domino" → "The line
 * under the table", "Whose turn it is"): words with an icon, as right and
 * wrong are (`lineParts()`). On a phone one 44px row — at most two parts,
 * each on a line of its own — beside «Geri» while a tile is lifted and
 * «Tərəfdaşım açsın» while the seat may give the opening. For the seat to
 * move the row is the band, filled in the left side's deep colour, which
 * pops in once — after the turn's steps — when the page saw the turn
 * begin, and stands still otherwise and under reduced motion. On the
 * screen (`big`) every part, larger.
 */
export function DominoLine({
    domino,
    game,
    players,
    viewer,
    now,
    picking = false,
    failed = false,
    mayYield = false,
    busy = false,
    onUndo,
    onYield,
    motion = false,
    arrived = false,
    big = false,
}: {
    domino: RoomDomino;
    game: RoomDominoGame;
    players: RoomPlayer[];
    viewer: string | null;
    now: number | null;
    picking?: boolean;
    failed?: boolean;
    mayYield?: boolean;
    busy?: boolean;
    onUndo?: () => void;
    onYield?: () => void;
    /** The page saw this moment begin: a new band pops in. */
    motion?: boolean;
    /** The page saw this moment's act arrive: the band waits for its steps. */
    arrived?: boolean;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const level = computerOf(game);
    const parts = lineParts(domino, {
        viewer,
        picking,
        failed,
        screen: big,
        seconds:
            domino.phase === 'countdown'
                ? secondsUntil(domino.starts_ms, now)
                : 0,
        nameOf: (seat) => dominoSeatName(t, players, domino, level, seat),
    });
    const shown = big ? parts : parts.slice(-2);
    const icon = big ? 'size-[3vh] shrink-0' : 'size-4 shrink-0';
    const band = shown.some((part) => part.band === true);
    const delay = arrived ? turnBeginsAt(domino) : 0;
    // The seat's turn begins when the steps before it end (ADR 038 decision 89): the band waits for them.
    const waits = band && motion && delay > 0;
    const before = shown.filter((part) => part.band !== true);
    const render = (list: LinePart[], inBand: boolean) =>
        list.map((part) => {
            const Icon = ICONS[part.icon];

            return (
                <span
                    key={part.id}
                    data-part={part.key}
                    className={cn(
                        'flex min-w-0 items-center gap-1.5',
                        !inBand && 'text-muted-foreground',
                        part.wrong && 'text-red-600 dark:text-red-400',
                        inBand &&
                            !part.band &&
                            'text-xs font-normal opacity-90',
                    )}
                >
                    <Icon aria-hidden className={icon} />
                    <span
                        className={cn(
                            'min-w-0',
                            !big &&
                                (list.length > 1 ? 'truncate' : 'line-clamp-2'),
                        )}
                    >
                        {t(part.key, part.params)}
                    </span>
                </span>
            );
        });

    return (
        <div
            role="status"
            aria-live="polite"
            data-line
            className={cn(
                'flex items-center',
                big
                    ? 'flex-wrap gap-x-[2vh] gap-y-[0.6vh] text-[max(1rem,2.6vh)]'
                    : 'min-h-11 gap-2 text-sm',
            )}
        >
            {band ? (
                // One band a turn: a tap that lifts a tile, or a refusal, changes its words, never its moment.
                <div
                    key={`band:${domino.ply}`}
                    data-turn-band=""
                    className="relative grid min-w-0 flex-1"
                >
                    {waits && before.length > 0 && (
                        // Until the turn's steps end, what came before it; then the band.
                        <div
                            className={cn(
                                'domino-window col-start-1 row-start-1 flex min-w-0 flex-col justify-center',
                                !big && 'min-h-11',
                            )}
                            style={{
                                animationDelay: '0ms',
                                animationDuration: `${delay}ms`,
                            }}
                        >
                            {render(before, false)}
                        </div>
                    )}
                    <div
                        className={cn(
                            'col-start-1 row-start-1 flex min-w-0',
                            waits && 'domino-window',
                        )}
                        style={
                            waits
                                ? {
                                      animationDelay: `${delay}ms`,
                                      animationDuration: '1ms',
                                      animationFillMode: 'forwards',
                                  }
                                : undefined
                        }
                    >
                        <div
                            className={cn(
                                'flex min-w-0 flex-1',
                                big
                                    ? 'flex-wrap gap-x-[2vh] rounded-[1.2vh] bg-rival-left-deep px-[1.6vh] py-[0.8vh] font-semibold text-white'
                                    : 'min-h-11 flex-col justify-center rounded-lg bg-rival-left-deep px-3 leading-tight font-semibold text-white',
                                motion && 'motion-safe:animate-room-pop',
                            )}
                            style={
                                motion
                                    ? { animationDelay: `${delay}ms` }
                                    : undefined
                            }
                        >
                            {render(shown, true)}
                        </div>
                    </div>
                </div>
            ) : (
                <div
                    className={cn(
                        'flex min-w-0 flex-1',
                        big
                            ? 'flex-wrap gap-x-[2vh]'
                            : 'flex-col justify-center',
                    )}
                >
                    {render(shown, false)}
                </div>
            )}
            {picking && onUndo !== undefined && (
                <Button
                    type="button"
                    variant="outline"
                    className="h-11 shrink-0"
                    onClick={onUndo}
                >
                    <Undo2 />
                    {t('rooms.domino.undo')}
                </Button>
            )}
            {mayYield && !picking && onYield !== undefined && (
                <Button
                    type="button"
                    variant="outline"
                    className="h-11 shrink-0"
                    disabled={busy}
                    onClick={onYield}
                >
                    <Users />
                    {t('rooms.domino.yield')}
                </Button>
            )}
        </div>
    );
}
