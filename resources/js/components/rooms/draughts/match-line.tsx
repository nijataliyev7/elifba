import {
    Eye,
    Hand,
    Handshake,
    Hourglass,
    TriangleAlert,
    Undo2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { secondsUntil } from '@/components/rooms/use-server-now';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomMatch, RoomPlayer, RoomSide } from '@/rooms/contract';

/**
 * The line under the board (docs/frontend.md rule 15 "Board games"): words
 * with an icon, as right and wrong are — in the countdown «Oyun :seconds
 * saniyəyə başlayır»; for the seat to move «Sizin növbənizdir», with «…
 * vurmaq məcburidir» when a capture is compulsory and «Geri» while a path
 * is begun; «:name oynayır» while the other seat thinks; «Oyunu
 * izləyirsiniz» first for a watcher. A standing draw offer says itself
 * here to everyone but the seat it is made to (that seat has its card),
 * and a move not taken says so in the wrong colour. On a phone it is one
 * 44px row — at most two parts, each on a line of its own, or one part in
 * two lines, beside «Geri» — so the match still fits 375 × 667. On the
 * screen (`big`) whose turn it is and a standing offer, larger.
 */
export function MatchLine({
    match,
    seat,
    players,
    now,
    begun = false,
    failed = false,
    onUndo,
    big = false,
}: {
    match: RoomMatch;
    /** The viewer's side; null for a watcher and the screen. */
    seat: RoomSide | null;
    players: RoomPlayer[];
    now: number | null;
    /** A path is begun: «Geri» takes it back. */
    begun?: boolean;
    failed?: boolean;
    onUndo?: () => void;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const nameOf = (color: string | null) => {
        const side = match.sides.find((one) => one.color === color);

        return (
            players.find((player) => player.id === side?.player_id)?.name ??
            t('rooms.show.nameless')
        );
    };
    const icon = big ? 'size-[3vh] shrink-0' : 'size-4 shrink-0';
    const parts: ReactNode[] = [];

    if (seat === null && !big) {
        parts.push(
            <Part key="watching" icon={<Eye aria-hidden className={icon} />}>
                {t('rooms.match.watching')}
            </Part>,
        );
    }

    if (match.phase === 'countdown') {
        parts.push(
            <Part
                key="starts"
                icon={<Hourglass aria-hidden className={icon} />}
            >
                {t('rooms.match.starts_in', {
                    seconds: secondsUntil(match.starts_ms, now),
                })}
            </Part>,
        );
    } else if (match.phase === 'play') {
        const mine = seat !== null && seat.color === match.turn;

        parts.push(
            mine ? (
                <Part
                    key="turn"
                    icon={<Hand aria-hidden className={icon} />}
                    strong
                >
                    {match.legal?.capture === true
                        ? t('rooms.match.your_turn_capture')
                        : t('rooms.match.your_turn')}
                </Part>
            ) : (
                <Part
                    key="turn"
                    icon={<Hourglass aria-hidden className={icon} />}
                >
                    {t('rooms.match.their_turn', { name: nameOf(match.turn) })}
                </Part>
            ),
        );

        if (
            match.offer !== null &&
            (seat === null || seat.color === match.offer)
        ) {
            parts.push(
                <Part
                    key="offer"
                    icon={<Handshake aria-hidden className={icon} />}
                >
                    {seat !== null
                        ? t('rooms.match.offer.made')
                        : t('rooms.match.offer.seen', {
                              name: nameOf(match.offer),
                          })}
                </Part>,
            );
        }
    }

    const shown = failed
        ? [
              <Part
                  key="failed"
                  icon={<TriangleAlert aria-hidden className={icon} />}
                  wrong
              >
                  {t('rooms.match.move_failed')}
              </Part>,
          ]
        : // A phone's line is one 44px row: at most two parts, a watcher's «Oyunu izləyirsiniz» giving way to an offer.
          big
          ? parts
          : parts.slice(-2);

    if (big) {
        return (
            <div
                role="status"
                aria-live="polite"
                className="flex flex-col items-start gap-y-1 text-[max(1rem,2.6vh)]"
            >
                {shown}
            </div>
        );
    }

    return (
        <div
            role="status"
            aria-live="polite"
            className="flex min-h-11 items-center gap-3 text-sm"
        >
            <div
                className={cn(
                    'flex min-w-0 flex-1 flex-col',
                    shown.length > 1
                        ? '[&>*>span]:truncate'
                        : '[&>*>span]:line-clamp-2',
                )}
            >
                {shown}
            </div>
            {begun && onUndo !== undefined && (
                <Button
                    type="button"
                    variant="outline"
                    className="h-11 shrink-0"
                    onClick={onUndo}
                >
                    <Undo2 />
                    {t('rooms.match.undo')}
                </Button>
            )}
        </div>
    );
}

function Part({
    icon,
    strong = false,
    wrong = false,
    children,
}: {
    icon: ReactNode;
    strong?: boolean;
    wrong?: boolean;
    children: ReactNode;
}) {
    return (
        <span
            className={cn(
                'flex min-w-0 items-center gap-1.5',
                strong ? 'font-semibold' : 'text-muted-foreground',
                wrong && 'text-red-600 dark:text-red-400',
            )}
        >
            {icon}
            <span className="min-w-0">{children}</span>
        </span>
    );
}
