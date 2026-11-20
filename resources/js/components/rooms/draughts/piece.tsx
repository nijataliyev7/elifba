import { Crown } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { RoomMatchColor } from '@/rooms/contract';

/**
 * A draughts piece (docs/frontend.md rule 15 "Board games"): a disc in the
 * board's own colours, a white and a black piece told apart by fill
 * **and** by ring, a king by `Crown` — never colour alone. It fills its
 * box; the caller gives the size. Decoration where the words beside it say
 * what it is (`aria-hidden`).
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
    const white = color === 'white';

    return (
        <span
            aria-hidden
            className={cn(
                'relative inline-grid shrink-0 place-items-center rounded-full',
                white
                    ? 'bg-draughts-white text-draughts-black shadow-[inset_0_0_0_2px_var(--draughts-white-ring),inset_0_0_0_5px_var(--draughts-white),inset_0_0_0_6px_var(--draughts-white-ring),0_1px_2px_rgb(0_0_0/0.3)]'
                    : 'bg-draughts-black text-draughts-white shadow-[inset_0_0_0_2px_var(--draughts-black-ring),inset_0_0_0_5px_var(--draughts-black),inset_0_0_0_6px_var(--draughts-black-ring),0_1px_2px_rgb(0_0_0/0.4)]',
                className,
            )}
        >
            {king && <Crown className="size-[52%]" strokeWidth={2.5} />}
        </span>
    );
}

/** A small piece beside a name: no inner rings, a plain ring in its colour's tone. */
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
            className={cn(
                'inline-block shrink-0 rounded-full',
                color === 'white'
                    ? 'bg-draughts-white shadow-[inset_0_0_0_1.5px_var(--draughts-white-ring)]'
                    : 'bg-draughts-black shadow-[inset_0_0_0_1.5px_var(--draughts-black-ring)]',
                className ?? 'size-4',
            )}
        />
    );
}
