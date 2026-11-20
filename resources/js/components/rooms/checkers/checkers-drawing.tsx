import { cn } from '@/lib/utils';

/**
 * Checkers' drawing on «Oyunlar» (ADR 034): a corner of the board with a
 * white and a black piece, drawn in `em` like `GameDrawing`, so the
 * caller's font size is its side.
 */
export function CheckersDrawing({
    framed = false,
    className,
}: {
    framed?: boolean;
    className?: string;
}) {
    const pieces: Record<number, 'w' | 'b'> = {
        1: 'b',
        3: 'b',
        5: 'w',
        7: 'w',
    };

    return (
        <span
            aria-hidden
            className={cn(
                'inline-flex size-[1em] shrink-0 items-center justify-center',
                framed && 'rounded-[0.24em] bg-muted',
                className,
            )}
        >
            <span className="grid size-[0.62em] grid-cols-3 overflow-hidden rounded-[0.06em]">
                {Array.from({ length: 9 }, (_, index) => {
                    const dark =
                        (Math.floor(index / 3) + (index % 3)) % 2 === 1;
                    const piece = pieces[index];

                    return (
                        <span
                            key={index}
                            className={cn(
                                'flex items-center justify-center',
                                dark ? 'bg-amber-800' : 'bg-amber-100',
                            )}
                        >
                            {piece !== undefined && dark && (
                                <span
                                    className={cn(
                                        'size-[70%] rounded-full border-[0.02em]',
                                        piece === 'w'
                                            ? 'border-stone-300 bg-stone-50'
                                            : 'border-neutral-700 bg-neutral-900',
                                    )}
                                />
                            )}
                        </span>
                    );
                })}
            </span>
        </span>
    );
}
