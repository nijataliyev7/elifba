import { useState } from 'react';
import type { UIEvent } from 'react';
import { HAND_ACROSS } from '@/components/rooms/domino/places';
import { handPlaces, offEdge } from '@/components/rooms/domino/stage';
import { TileSvg } from '@/components/rooms/domino/tile';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/** A hand's tile: 46px wide and 92 tall with 5px between, never under 44 wide. */
const TILE_PX = 46;
const MIN_PX = 44;
const GAP_PX = 5;

/**
 * The viewer's own tiles (docs/frontend.md rule 15 "Domino" → "The hand",
 * ADR 038 decisions 45, 46 and 86): upright in a row at the bottom, seven
 * across at 375px, in the order they were dealt and drawn — never sorted —
 * with a second row above the first from eight, and beyond fourteen two
 * rows that scroll sideways together with a count of what is off the edge.
 * Each is a button named by its tile and what it may do. In the viewer's
 * own turn the tiles `mine.legal` names are lit — raised 6px and strong —
 * and the others dimmed to 55 % and no buttons; outside it every tile
 * stands level and takes no tap. The lifted tile has a `primary` ring.
 *
 * A hand the page saw open deals its tiles in (`dealt`, in that moment
 * alone); the tiles drawn after the latest act (`drawn`) arrive at their
 * step (`drawAt`) on a page that saw it — standing still under reduced
 * motion and on a page opened on them. The tiles are one list on a grid
 * (`handPlaces()`), so a tile that moves between the rows — a hand of
 * eight drawing, the viewer's own play — keeps its node and never enters
 * again. One row is 108px: 10 above the tiles, 92, 6 under them.
 */
export function DominoHand({
    tiles,
    lit,
    turn,
    picked,
    onTap,
    drawn = [],
    drawAt = null,
    dealt = false,
    dimmed = false,
}: {
    tiles: string[];
    /** The tiles `mine.legal` offers. */
    lit: Set<string>;
    /** The viewer's own turn: lit tiles are buttons. */
    turn: boolean;
    picked: string | null;
    onTap: (tile: string) => void;
    drawn?: string[];
    /** When the viewer's draw step begins, ms into its moment; null: drawn still. */
    drawAt?: number | null;
    /** The moment the hand opened, on a page that saw it: the tiles deal in. */
    dealt?: boolean;
    /** Under the away card: every tile dimmed. */
    dimmed?: boolean;
}) {
    const { t } = useTranslation();
    const { places, across } = handPlaces(tiles);
    const scrolls = across > HAND_ACROSS;
    const [shownAcross, setShownAcross] = useState<number | null>(null);
    const fresh = new Set(drawn);
    const off = scrolls ? offEdge(tiles, shownAcross ?? HAND_ACROSS) : 0;

    const onScroll = (event: UIEvent<HTMLDivElement>) => {
        const element = event.currentTarget;
        const seen = Math.floor(
            (element.scrollLeft + element.clientWidth + GAP_PX) /
                (TILE_PX + GAP_PX),
        );

        setShownAcross(Math.min(across, seen));
    };

    return (
        <div
            role="group"
            aria-label={t('rooms.domino.hand_label')}
            className="relative"
        >
            <div
                onScroll={scrolls ? onScroll : undefined}
                className={cn(
                    'px-2 pt-2.5 pb-1.5',
                    scrolls && '[scrollbar-width:thin] overflow-x-auto',
                )}
            >
                {/* One list on a grid of half-tile columns: a tile that moves between the rows keeps its node. */}
                <div
                    className={cn(
                        'grid gap-y-1.5',
                        scrolls ? 'w-max justify-start' : 'justify-center',
                    )}
                    style={{
                        gridTemplateColumns: `repeat(${2 * across}, minmax(${(MIN_PX + GAP_PX) / 2}px, ${(TILE_PX + GAP_PX) / 2}px))`,
                    }}
                >
                    {places.map(({ tile: name, row, column }, index) => {
                        const lights = turn && lit.has(name);
                        const arriving = drawAt !== null && fresh.has(name);

                        return (
                            <button
                                key={name}
                                type="button"
                                data-tile={name}
                                data-lit={lights ? '' : undefined}
                                aria-label={
                                    lights
                                        ? t('rooms.domino.tile', { tile: name })
                                        : name
                                }
                                aria-disabled={lights ? undefined : true}
                                aria-pressed={
                                    lights ? picked === name : undefined
                                }
                                onClick={lights ? () => onTap(name) : undefined}
                                className={cn(
                                    'relative aspect-[1/2] justify-self-center rounded-[7px] transition-[transform,opacity] duration-150 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/60 motion-reduce:transition-none',
                                    lights &&
                                        '-translate-y-1.5 shadow-[0_6px_10px_rgb(0_0_0/0.25)]',
                                    turn &&
                                        !lights &&
                                        'cursor-default opacity-55',
                                    !turn && 'cursor-default',
                                    dimmed && 'opacity-55',
                                    picked === name &&
                                        'ring-[3px] ring-primary ring-offset-2 ring-offset-background',
                                    arriving &&
                                        'motion-safe:animate-room-enter',
                                    !arriving &&
                                        dealt &&
                                        'motion-safe:animate-room-enter',
                                )}
                                style={{
                                    gridRow: row,
                                    gridColumn: `${column} / span 2`,
                                    width: `calc(100% - ${GAP_PX}px)`,
                                    ...(arriving
                                        ? { animationDelay: `${drawAt}ms` }
                                        : dealt
                                          ? {
                                                animationDelay: `${index * 45}ms`,
                                            }
                                          : {}),
                                }}
                            >
                                <TileSvg
                                    tile={name}
                                    className="size-full drop-shadow-[0_1px_1px_rgb(0_0_0/0.25)]"
                                />
                            </button>
                        );
                    })}
                </div>
            </div>
            {off > 0 && (
                <span className="pointer-events-none absolute top-1 right-2 rounded-full bg-foreground/80 px-2 py-0.5 text-[11px] font-semibold text-background tabular-nums">
                    {t('rooms.domino.off_edge', { count: off })}
                </span>
            )}
        </div>
    );
}
