import { PieceMark } from '@/components/rooms/draughts/piece';
import { cn } from '@/lib/utils';
import type { RoomMechanic } from '@/rooms/contract';

/**
 * A game's drawing (docs/frontend.md rule 15 "Games first", "A game's
 * drawing"): a small picture of its round in the tokens — a row of tiles,
 * a grid with lit cells, a rising row with a dashed place, three alike and
 * one apart, tiles under dashed places, four lines with one stronger, two
 * domino tiles end to end on the felt. No
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
        // A corner of the board — its own wood, a square a tile of the texture — with a white and a black piece (ADR 034 decision 27).
        const pieces: Record<number, 'white' | 'black'> = {
            1: 'black',
            5: 'white',
            7: 'white',
        };

        return (
            <span className="grid size-[0.6em] grid-cols-3 overflow-hidden rounded-[0.05em] bg-(image:--draughts-wood) bg-size-[266.667%_266.667%]">
                {Array.from({ length: 9 }, (_, index) => {
                    const piece = pieces[index];

                    return (
                        <span key={index} className="grid place-items-center">
                            {piece !== undefined && (
                                <PieceMark
                                    color={piece}
                                    className="size-[72%]"
                                />
                            )}
                        </span>
                    );
                })}
            </span>
        );
    }

    if (mechanic === 'domino_101') {
        // Two tiles end to end on the table's felt (ADR 038, docs/frontend.md rule 15 "Domino"): ivory, a divider, their pips.
        const tile = (
            x: number,
            near: [number, number][],
            far: [number, number][],
        ) => (
            <g transform={`translate(${x} 4.05)`}>
                <rect
                    width="3.7"
                    height="1.9"
                    rx="0.35"
                    style={{
                        fill: 'var(--domino-face)',
                        stroke: 'var(--domino-edge)',
                    }}
                    strokeWidth="0.12"
                />
                <line
                    x1="1.85"
                    y1="0.3"
                    x2="1.85"
                    y2="1.6"
                    style={{ stroke: 'var(--domino-divider)' }}
                    strokeWidth="0.12"
                />
                {[
                    ...near,
                    ...far.map(([px, py]): [number, number] => [px + 1.85, py]),
                ].map(([px, py]) => (
                    <circle
                        key={`${px}:${py}`}
                        cx={px}
                        cy={py}
                        r="0.2"
                        style={{ fill: 'var(--domino-pip)' }}
                    />
                ))}
            </g>
        );

        return (
            <svg viewBox="0 0 10 10" className="size-[0.72em]">
                <rect
                    width="10"
                    height="10"
                    rx="1.6"
                    style={{ fill: 'var(--domino-felt)' }}
                />
                {tile(
                    1.1,
                    [
                        [0.5, 0.5],
                        [1.35, 1.4],
                    ],
                    [[0.93, 0.95]],
                )}
                {tile(
                    5.2,
                    [[0.93, 0.95]],
                    [
                        [0.5, 0.5],
                        [0.93, 0.95],
                        [1.35, 1.4],
                    ],
                )}
            </svg>
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
