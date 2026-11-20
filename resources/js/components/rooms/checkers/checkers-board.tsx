import { Crown } from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { isKing, sideOf } from '@/components/rooms/checkers/match';
import type { Side } from '@/components/rooms/checkers/match';

/**
 * The 8 × 8 board of a checkers match (ADR 034 decision 8): white's view,
 * or black's turned round for the player with black. Only the dark squares
 * are played. The squares the viewer may tap are the ones the page offers
 * from the server's legal moves (`interactive`); the last move and the
 * squares tapped so far are marked. Nothing here judges a move.
 */
export function CheckersBoard({
    board,
    flipped,
    lastPath,
    selected,
    targets,
    starts,
    onTap,
    fill = false,
}: {
    board: string;
    /** Black's view: row 7 at the top. */
    flipped: boolean;
    lastPath: number[] | null;
    /** The squares tapped so far in this move. */
    selected: number[];
    /** Where the next tap may go. */
    targets: Set<number>;
    /** The squares whose piece has a legal move, before a first tap. */
    starts: Set<number>;
    onTap: ((square: number) => void) | null;
    /** The big screen: the board fills the box its caller sized. */
    fill?: boolean;
}) {
    const { t } = useTranslation();
    const last = new Set(lastPath ?? []);
    const squares = Array.from({ length: 64 }, (_, index) =>
        flipped ? 63 - index : index,
    );

    return (
        <div
            role="grid"
            aria-label={t('rooms.match.board')}
            className={cn(
                'mx-auto grid aspect-square w-full grid-cols-8 overflow-hidden rounded-xl border-4 border-amber-900/80 shadow-sm select-none dark:border-amber-950',
                fill ? 'h-full' : 'max-w-[min(100%,calc(100svh-21.25rem))]',
            )}
        >
            {squares.map((square) => {
                const row = Math.floor(square / 8);
                const col = square % 8;
                const dark = (row + col) % 2 === 1;
                const piece = board[square];
                const side = sideOf(piece);
                const isTarget = targets.has(square);
                const isStart = starts.has(square);
                const isSelected = selected.includes(square);
                const tappable = onTap !== null && (isTarget || isStart);

                return (
                    <button
                        key={square}
                        type="button"
                        role="gridcell"
                        tabIndex={tappable ? 0 : -1}
                        disabled={!tappable}
                        onClick={() => onTap?.(square)}
                        aria-label={squareLabel(square, piece, t)}
                        className={cn(
                            'relative flex aspect-square items-center justify-center outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                            dark
                                ? 'bg-amber-800 dark:bg-amber-900'
                                : 'bg-amber-100 dark:bg-amber-200/80',
                            dark &&
                                last.has(square) &&
                                'bg-amber-600 dark:bg-amber-700',
                            isSelected && 'bg-sky-600 dark:bg-sky-700',
                            tappable ? 'cursor-pointer' : 'cursor-default',
                        )}
                    >
                        {side !== null && (
                            <Piece
                                side={side}
                                king={isKing(piece)}
                                movable={isStart && selected.length === 0}
                            />
                        )}
                        {isTarget && side === null && (
                            <span
                                aria-hidden
                                className="size-[34%] rounded-full bg-sky-300/90 ring-2 ring-sky-100/70"
                            />
                        )}
                    </button>
                );
            })}
        </div>
    );
}

function Piece({
    side,
    king,
    movable,
}: {
    side: Side;
    king: boolean;
    movable: boolean;
}) {
    return (
        <span
            aria-hidden
            className={cn(
                'flex size-[78%] items-center justify-center rounded-full border-[3px] shadow-[inset_0_-3px_0_rgba(0,0,0,0.25)] transition-transform motion-reduce:transition-none',
                side === 'w'
                    ? 'border-stone-300 bg-stone-50 text-amber-600'
                    : 'border-neutral-700 bg-neutral-900 text-amber-400',
                movable &&
                    'ring-2 ring-sky-300 ring-offset-1 ring-offset-transparent',
            )}
        >
            {king && <Crown className="size-[55%]" strokeWidth={2.5} />}
        </span>
    );
}

function squareLabel(
    square: number,
    piece: string,
    t: (key: string) => string,
): string {
    const name = `${'abcdefgh'[square % 8]}${8 - Math.floor(square / 8)}`;
    const side = sideOf(piece);

    if (side === null) {
        return name;
    }

    return `${name}, ${t(`rooms.match.piece.${side}${isKing(piece) ? '_king' : ''}`)}`;
}
