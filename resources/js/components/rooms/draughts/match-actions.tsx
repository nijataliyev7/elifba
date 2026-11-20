import { Flag, Handshake } from 'lucide-react';
import { useId, useState } from 'react';
import { MoveListButton } from '@/components/rooms/draughts/move-list';
import type { MatchActs } from '@/components/rooms/draughts/use-match-acts';
import { RoomConfirm } from '@/components/rooms/room-confirm';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomMatch, RoomSide } from '@/rooms/contract';

/**
 * A match's action bar on a phone (docs/frontend.md rule 15 "Board games"
 * → "The action bar"), inside `RoomActionBar framed`: for a seat three
 * 44px buttons — «Gedişlər» with the number of the last move, «Heç-heçə»
 * (enabled only while the state's `can_offer`, its line saying why not)
 * and «Təslim ol», which asks first; a watcher has «Gedişlər» alone. For
 * the seat a draw offer is made to, the bar is the offer's card instead
 * (`DrawOffer`): never over the board.
 */
export function MatchActions({
    match,
    seat,
    offerFrom,
    acts,
}: {
    match: RoomMatch;
    seat: RoomSide | null;
    /** The name of the seat whose offer stands, when it is made to the viewer. */
    offerFrom: string | null;
    acts: MatchActs;
}) {
    const { t } = useTranslation();
    const why = useId();
    // An offer standing when the page opened is drawn still; one the page saw arrive enters (ADR 025).
    const [openedOn] = useState(() => (offerFrom !== null ? match.ply : null));

    if (seat === null) {
        return <MoveListButton moves={match.moves} className="w-full" />;
    }

    if (offerFrom !== null) {
        return (
            <DrawOffer
                name={offerFrom}
                acts={acts}
                enters={openedOn !== match.ply}
            />
        );
    }

    const live = match.phase !== 'over';

    return (
        <div className="flex flex-col gap-1">
            <div className="grid grid-cols-3 gap-2">
                <MoveListButton moves={match.moves} className="min-w-0 px-2" />
                <Button
                    type="button"
                    variant="outline"
                    className="h-11 min-w-0 px-2"
                    disabled={!match.can_offer || acts.busy}
                    aria-describedby={match.can_offer ? undefined : why}
                    onClick={() => void acts.act('offer')}
                >
                    <Handshake />
                    {t('rooms.match.offer.make')}
                </Button>
                <RoomConfirm
                    trigger={
                        <Button
                            type="button"
                            variant="ghost"
                            className="h-11 min-w-0 px-2 text-red-600 hover:text-red-600 dark:text-red-400 dark:hover:text-red-400"
                            disabled={!live || acts.busy}
                        >
                            <Flag />
                            {t('rooms.match.resign')}
                        </Button>
                    }
                    title={t('rooms.match.resign_title')}
                    description={t('rooms.match.resign_description')}
                    confirmLabel={t('rooms.match.resign')}
                    destructive
                    onConfirm={() => acts.act('resign')}
                />
            </div>
            {!match.can_offer && (
                <p id={why} className="sr-only">
                    {t('rooms.match.offer.when')}
                </p>
            )}
        </div>
    );
}

/**
 * A draw offer made to the viewer's seat (`DrawOffer`, rule 15 "A draw
 * offer"): the bar's card in place of its buttons, never over the board —
 * `Handshake` with «:name heç-heçə təklif edir», «Rədd et» (44px,
 * outline) and «Qəbul et» (52px, filled), in one row so the match still
 * fits 375 × 667. Making a move declines it too, and the card goes with
 * the state.
 */
export function DrawOffer({
    name,
    acts,
    enters,
}: {
    name: string;
    acts: MatchActs;
    /** The page saw the offer arrive. */
    enters: boolean;
}) {
    const { t } = useTranslation();

    return (
        <div
            role="group"
            aria-labelledby="draw-offer"
            className={cn(
                'flex items-center gap-2',
                enters && 'motion-safe:animate-room-enter',
            )}
        >
            <Handshake aria-hidden className="size-5 shrink-0" />
            <p
                id="draw-offer"
                className="line-clamp-2 min-w-0 flex-1 text-sm leading-5 font-semibold"
            >
                {t('rooms.match.offer.from', { name })}
            </p>
            <Button
                type="button"
                variant="outline"
                className="h-11 shrink-0 px-2.5"
                disabled={acts.busy}
                onClick={() => void acts.act('decline')}
            >
                {t('rooms.match.offer.decline')}
            </Button>
            <Button
                type="button"
                className="h-13 shrink-0 px-3 text-base"
                disabled={acts.busy}
                onClick={() => void acts.act('accept')}
            >
                {acts.busy && <Spinner />}
                {t('rooms.match.offer.accept')}
            </Button>
        </div>
    );
}
