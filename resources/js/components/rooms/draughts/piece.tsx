import { Crown } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { RoomMatchColor } from '@/rooms/contract';

/**
 * A draughts piece (docs/frontend.md rule 15 "Board games" → "The board",
 * ADR 034 decision 30 as amended 2026-11-21): turned wood in the board's
 * own tokens (`draughts-piece`) — boxwood and ebony, a white and a black
 * piece told apart by fill **and** by groove (dark on white, light on
 * black), a king by `Crown`, engraved (drawn twice, a light line under it)
 * — never colour alone. It fills its box; the caller gives the size.
 * Decoration where the words beside it say what it is (`aria-hidden`).
 */
export function DraughtsPiece({
    color,
    king = false,
    className,
}: {
    color: RoomMatchColor;
    king?: boolean;
    className?: string;
}) {
    return (
        <span
            aria-hidden
            data-color={color}
            data-king={king ? '' : undefined}
            className={cn(
                'relative inline-grid shrink-0 place-items-center rounded-full draughts-piece',
                className,
            )}
        >
            {king && (
                <>
                    <Crown
                        className="col-start-1 row-start-1 size-[52%] translate-y-[0.9px] text-(--engrave)"
                        strokeWidth={2.6}
                    />
                    <Crown
                        className="col-start-1 row-start-1 size-[52%]"
                        strokeWidth={2.6}
                    />
                </>
            )}
        </span>
    );
}

/** A small piece beside a name: its fill and one groove (`draughts-mark`). */
export function PieceMark({
    color,
    className,
}: {
    color: RoomMatchColor;
    className?: string;
}) {
    return (
        <span
            aria-hidden
            data-color={color}
            className={cn(
                'inline-block shrink-0 rounded-full draughts-mark',
                className ?? 'size-4',
            )}
        />
    );
}
