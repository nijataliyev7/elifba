import { cn } from '@/lib/utils';
import type { RoomMechanic } from '@/rooms/contract';

/**
 * A game's drawing (docs/frontend.md rule 15 "Games first", "A game's
 * drawing"): a small picture of its round in the tokens — a row of tiles,
 * a grid with lit cells, a rising row with a dashed place, three alike and
 * one apart, tiles under dashed places, four lines with one stronger. No
 * letter, word or number, since content is data. Drawn in `em`: the font
 * size the caller gives (`text-[48px]`, `text-[6vh]`) is its side, so one
 * component serves 32px to 110px. `framed` puts it on its own `bg-muted`
 * tile; without it, it stands on the caller's scene.
 */
export function GameDrawing({
    mechanic,
    framed = true,
    className,
}: {
    mechanic: RoomMechanic;
    framed?: boolean;
    className?: string;
}) {
    return (
        <span
            aria-hidden
            className={cn(
                'inline-flex size-[1em] shrink-0 items-center justify-center',
                framed && 'rounded-[0.24em] bg-muted',
                className,
            )}
        >
            <Glyph mechanic={mechanic} />
        </span>
    );
}

function Glyph({ mechanic }: { mechanic: RoomMechanic }) {
    if (mechanic === 'recall_cells') {
        const lit = new Set([1, 3, 5, 8]);

        return (
            <span className="grid size-[0.6em] grid-cols-3 gap-[0.05em]">
                {Array.from({ length: 9 }, (_, index) => (
                    <span
                        key={index}
                        className={cn(
                            'rounded-[0.03em]',
                            lit.has(index)
                                ? 'bg-amber-400'
                                : 'bg-foreground/15',
                        )}
                    />
                ))}
            </span>
        );
    }

    if (mechanic === 'next_in_sequence') {
        // A rising row and the dashed place of the next one.
        return (
            <span className="flex h-[0.56em] items-end gap-[0.06em]">
                <span className="h-[0.16em] w-[0.12em] rounded-[0.025em] bg-primary/40" />
                <span className="h-[0.28em] w-[0.12em] rounded-[0.025em] bg-primary/60" />
                <span className="h-[0.4em] w-[0.12em] rounded-[0.025em] bg-primary/85" />
                <span className="h-[0.54em] w-[0.12em] rounded-[0.025em] border-[max(1px,0.02em)] border-dashed border-primary" />
            </span>
        );
    }

    if (mechanic === 'odd_one_out') {
        // Three alike and one that is not.
        return (
            <span className="grid size-[0.52em] grid-cols-2 place-items-center gap-[0.06em]">
                <span className="size-[0.2em] rounded-full bg-primary" />
                <span className="size-[0.2em] rounded-full bg-primary" />
                <span className="size-[0.2em] rounded-full bg-primary" />
                <span className="size-[0.17em] rotate-45 rounded-[0.025em] bg-amber-400" />
            </span>
        );
    }

    if (mechanic === 'anagram') {
        // Tiles to order under the empty places they fill.
        return (
            <span className="flex flex-col items-center gap-[0.07em]">
                <span className="flex gap-[0.035em]">
                    {[0, 1, 2, 3].map((index) => (
                        <span
                            key={index}
                            className="size-[0.13em] rounded-[0.025em] border-[max(1px,0.015em)] border-dashed border-primary/60"
                        />
                    ))}
                </span>
                <span className="flex gap-[0.035em]">
                    {[1, 0.85, 1, 0.85].map((opacity, index) => (
                        <span
                            key={index}
                            className="size-[0.13em] rounded-[0.025em] bg-primary"
                            style={{ opacity }}
                        />
                    ))}
                </span>
            </span>
        );
    }

    if (mechanic === 'draughts') {
        // A corner of the board with a white and a black piece (ADR 034 decision 27).
        const pieces: Record<number, 'white' | 'black'> = {
            1: 'black',
            5: 'white',
            7: 'white',
        };

        return (
            <span className="grid size-[0.6em] grid-cols-3 overflow-hidden rounded-[0.05em]">
                {Array.from({ length: 9 }, (_, index) => {
                    const dark =
                        (Math.floor(index / 3) + (index % 3)) % 2 === 1;
                    const piece = pieces[index];

                    return (
                        <span
                            key={index}
                            className={cn(
                                'grid place-items-center',
                                dark ? 'bg-draughts-dark' : 'bg-draughts-light',
                            )}
                        >
                            {piece !== undefined && (
                                <span
                                    className={cn(
                                        'size-[72%] rounded-full',
                                        piece === 'white'
                                            ? 'bg-draughts-white shadow-[inset_0_0_0_max(1px,0.012em)_var(--draughts-white-ring)]'
                                            : 'bg-draughts-black shadow-[inset_0_0_0_max(1px,0.012em)_var(--draughts-black-ring)]',
                                    )}
                                />
                            )}
                        </span>
                    );
                })}
            </span>
        );
    }

    if (mechanic === 'spelling') {
        // Four spellings, one of them stronger.
        return (
            <span className="flex w-[0.56em] flex-col gap-[0.07em]">
                {[0.35, 1, 0.35, 0.35].map((opacity, index) => (
                    <span
                        key={index}
                        className="h-[0.07em] rounded-full bg-primary"
                        style={{ opacity }}
                    />
                ))}
            </span>
        );
    }

    // `recall_order`: a row of tiles, the last one not yet seen.
    return (
        <span className="flex items-center gap-[0.05em]">
            <span className="size-[0.16em] rounded-[0.03em] bg-primary" />
            <span className="size-[0.16em] rounded-[0.03em] bg-primary/80" />
            <span className="size-[0.16em] rounded-[0.03em] bg-primary/35" />
        </span>
    );
}
