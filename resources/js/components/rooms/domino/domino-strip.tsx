import { Hourglass, Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { computerOf } from '@/components/rooms/computer';
import { dominoSideName } from '@/components/rooms/domino/names';
import { sideKey } from '@/components/rooms/domino/sides';
import { GameStrip } from '@/components/rooms/rivalry-strip';
import type { Rivalry, RivalryIcons } from '@/components/rooms/rivalry-strip';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomDomino, RoomDominoGame, RoomState } from '@/rooms/contract';

/** The tries a closed account has before its waiting points burn (R22). */
const TRIES = 3;

/**
 * A domino game's strip (docs/frontend.md rule 15 "Domino" → "The strip",
 * ADR 038 decision 48): the room's one strip (`GameStrip`) over the rows
 * `stripRows()` gives — the sides and their balances in the server's
 * order — named as a domino page names them («Siz», at pairs «Biz» and
 * «Onlar», a pair by its two first names), «101» between the two names,
 * «Bank: :count» in the gap's row while the bank holds points, and under a
 * side whose account is closed, where a place label would stand, `Lock`
 * «Hesab bağlıdır» — or `Hourglass` «Gözləmədə :count» with its tries
 * («2/3») while points wait. It moves only at a hand's end the page saw
 * (`moves`). `children` lies over it: a seat's throw.
 */
export function DominoStrip({
    rivalry,
    state,
    game,
    domino,
    viewer,
    placeLabels,
    icons,
    moves,
    big = false,
    children,
}: {
    rivalry: Rivalry & { key: string };
    state: RoomState;
    game: RoomDominoGame;
    domino: RoomDomino;
    viewer: string | null;
    placeLabels: string[];
    icons: RivalryIcons | null;
    moves: boolean;
    big?: boolean;
    children?: ReactNode;
}) {
    const { t } = useTranslation();
    const level = computerOf(game);
    const pairs = domino.sides.some((side) => side.seats.length > 1);
    const notes: Record<string, ReactNode> = {};
    const icon = big ? 'size-[2.2vh] shrink-0' : 'size-3 shrink-0';

    for (const side of domino.sides) {
        if (side.open) {
            continue;
        }

        notes[sideKey(domino, side)] = (
            <span
                data-account={side.waiting > 0 ? 'waiting' : 'closed'}
                className={cn(
                    'inline-flex min-w-0 items-center gap-0.5 truncate font-medium whitespace-nowrap text-muted-foreground',
                    big ? 'text-[2vh]' : 'text-[10.5px]',
                )}
            >
                {side.waiting > 0 ? (
                    <>
                        <Hourglass aria-hidden className={icon} />
                        {t('rooms.rivalry.waiting', {
                            count: side.waiting,
                        })}
                        <span className="tabular-nums">
                            {` · ${side.tries}/${TRIES}`}
                        </span>
                    </>
                ) : (
                    <>
                        <Lock aria-hidden className={icon} />
                        {t('rooms.rivalry.closed')}
                    </>
                )}
            </span>
        );
    }

    return (
        <GameStrip
            rivalry={rivalry}
            state={state}
            placeLabels={placeLabels}
            icons={icons}
            moves={moves}
            ribbonInGap
            big={big}
            naming={{
                nameOf: (key) =>
                    dominoSideName(
                        t,
                        state.players,
                        domino,
                        level,
                        viewer,
                        key,
                    ),
                you: t(pairs ? 'rooms.rivalry.us' : 'rooms.rivalry.you'),
                target: game.target,
                bank: domino.bank,
                notes,
            }}
        >
            {children}
        </GameStrip>
    );
}
