import {
    FILES,
    isDark,
    piecesBySquare,
    RANKS,
    squareAt,
} from '@/components/rooms/draughts/board';
import {
    MovingPiece,
    PlacedPiece,
    pendingPosition,
    Taken,
} from '@/components/rooms/draughts/board-pieces';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomLastMove, RoomPiece } from '@/rooms/contract';

export type { Arrival } from '@/components/rooms/draughts/board-pieces';
import type { Arrival } from '@/components/rooms/draughts/board-pieces';

/** The screen's rim, in % of the board (its `p-[5.2%]`), and a square's share of what is inside it. */
const RIM = 5.2;
const SQUARE = (100 - 2 * RIM) / 8;

/**
 * The board of a match (docs/frontend.md rule 15 "Board games" → "The
 * board", ADR 034 decisions 5 and 30 as amended 2026-11-21): eight by
 * eight in the board's own wood — the squares one texture
 * (`draughts-wood`) under 64 transparent squares, in a walnut rim
 * (`draughts-rim`): 3px on a phone, where the files and ranks stand in
 * the edge squares, and 5.2 % of the board on the screen, where they stand
 * in the rim. A square's name is drawn at its column and row only (turned
 * round for a seat that plays black), each dark square a button named by
 * its square and what stands on it, the last move's squares tinted (14 %)
 * and framed 2px in `primary` — the frame ≥ 3:1 against the darkest
 * walnut under the tint, which a tint alone never reaches. It draws
 * the state's `position` and offers what the page gives it — the lifted
 * piece, the squares the begun path may land on next — and reports a tap:
 * it computes no move.
 *
 * A move the page saw arrive (`arrival`) slides its piece along the path,
 * a leg at a time, the taken pieces fading once it has landed and a man
 * crowned on the way turning into a king where it crowned. Anything else
 * — a page opened on a position, a state more than one ply ahead, reduced
 * motion — is drawn still.
 */
export function DraughtsBoard({
    position,
    last,
    flipped,
    arrival = null,
    taps = [],
    marks = new Set<string>(),
    pending = null,
    onTap,
    labels = 'inside',
    className,
}: {
    position: RoomPiece[];
    last: RoomLastMove | null;
    flipped: boolean;
    arrival?: Arrival | null;
    /** The squares of the path begun by the viewer's taps: the lifted piece first. */
    taps?: string[];
    /** The squares the begun path may land on next. */
    marks?: Set<string>;
    /** The viewer's own move posted and not yet in the state: its piece stands on its last square. */
    pending?: string[] | null;
    onTap?: (square: string) => void;
    /** The files and ranks: inside the edge squares (a phone, a 3px rim), or in the rim (the screen, 5.2 %). */
    labels?: 'inside' | 'outside';
    className?: string;
}) {
    const { t } = useTranslation();
    const drawn = pendingPosition(position, pending);
    const bySquare = piecesBySquare(drawn);
    const marked = new Set(last?.path ?? []);
    const trail = new Set(taps.slice(1));
    const lifted = taps[0] ?? null;
    const moving =
        arrival !== null
            ? arrival.last.path[arrival.last.path.length - 1]
            : null;

    const squares = [];

    for (let row = 0; row < 8; row++) {
        for (let col = 0; col < 8; col++) {
            const square = squareAt(col, row, flipped);
            const dark = isDark(square);
            const piece = bySquare.get(square);
            const name = !dark
                ? null
                : `${square}, ${t(
                      piece === undefined
                          ? 'rooms.match.piece.empty'
                          : `rooms.match.piece.${piece.color}${piece.king ? '_king' : ''}`,
                  )}`;
            const rank =
                labels === 'inside' && col === 0 ? square.slice(1) : null;
            const file =
                labels === 'inside' && row === 7 ? square.slice(0, 1) : null;
            const body = (
                <>
                    {(marked.has(square) || trail.has(square)) && (
                        <span
                            aria-hidden
                            className={cn(
                                'absolute inset-0',
                                trail.has(square)
                                    ? 'bg-primary/35'
                                    : 'bg-primary/14 shadow-[inset_0_0_0_2px_var(--primary)]',
                            )}
                        />
                    )}
                    {marks.has(square) && (
                        <span
                            aria-hidden
                            className="absolute top-1/2 left-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full bg-primary"
                        />
                    )}
                    {rank !== null && (
                        <span
                            aria-hidden
                            className={cn(
                                'absolute top-0.5 left-0.5 text-[9px] leading-none',
                                dark
                                    ? 'text-draughts-label-dark'
                                    : 'text-draughts-label',
                            )}
                        >
                            {rank}
                        </span>
                    )}
                    {file !== null && (
                        <span
                            aria-hidden
                            className={cn(
                                'absolute right-0.5 bottom-0.5 text-[9px] leading-none',
                                dark
                                    ? 'text-draughts-label-dark'
                                    : 'text-draughts-label',
                            )}
                        >
                            {file}
                        </span>
                    )}
                </>
            );

            squares.push(
                dark ? (
                    <button
                        key={square}
                        type="button"
                        aria-label={name ?? undefined}
                        aria-pressed={lifted === square ? true : undefined}
                        disabled={onTap === undefined}
                        onClick={() => onTap?.(square)}
                        className="relative outline-none focus-visible:z-10 focus-visible:ring-[3px] focus-visible:ring-ring disabled:cursor-default"
                    >
                        {body}
                    </button>
                ) : (
                    <span key={square} aria-hidden className="relative">
                        {body}
                    </span>
                ),
            );
        }
    }

    // The files under the board and the ranks at its left, in the screen's rim.
    const files = flipped ? [...FILES].reverse() : FILES;
    const ranks = flipped ? RANKS : [...RANKS].reverse();
    const coordinate =
        'absolute grid place-items-center text-[max(0.75rem,1.9vh)] leading-none font-semibold text-draughts-coord tabular-nums';

    return (
        <div
            role="group"
            aria-label={t('rooms.match.board')}
            className={cn(
                'relative aspect-square',
                labels === 'inside' && 'w-full',
                className,
            )}
        >
            <div
                className={cn(
                    'absolute inset-0 rounded-[7px] draughts-rim',
                    labels === 'inside' ? 'p-[3px]' : 'p-[5.2%]',
                )}
            >
                <div className="relative size-full overflow-hidden rounded-[2px] draughts-wood">
                    <div className="grid size-full grid-cols-8 grid-rows-8">
                        {squares}
                    </div>
                    <div className="pointer-events-none absolute inset-0">
                        {drawn.map((piece) =>
                            piece.square === moving && arrival !== null ? (
                                <MovingPiece
                                    key={`move:${arrival.key}`}
                                    piece={piece}
                                    arrival={arrival}
                                    flipped={flipped}
                                />
                            ) : (
                                <PlacedPiece
                                    key={piece.square}
                                    piece={piece}
                                    flipped={flipped}
                                    lifted={lifted === piece.square}
                                />
                            ),
                        )}
                        {arrival !== null && (
                            <Taken
                                key={`taken:${arrival.key}`}
                                arrival={arrival}
                                flipped={flipped}
                            />
                        )}
                    </div>
                </div>
            </div>
            {labels === 'outside' && (
                <div aria-hidden>
                    {files.map((file, index) => (
                        <span
                            key={file}
                            className={coordinate}
                            style={{
                                left: `${RIM + index * SQUARE}%`,
                                width: `${SQUARE}%`,
                                bottom: 0,
                                height: `${RIM}%`,
                            }}
                        >
                            {file}
                        </span>
                    ))}
                    {ranks.map((rank, index) => (
                        <span
                            key={rank}
                            className={coordinate}
                            style={{
                                top: `${RIM + index * SQUARE}%`,
                                height: `${SQUARE}%`,
                                left: 0,
                                width: `${RIM}%`,
                            }}
                        >
                            {rank}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}
