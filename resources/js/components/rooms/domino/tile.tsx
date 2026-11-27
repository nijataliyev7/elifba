import { cn } from '@/lib/utils';

/*
 * A domino tile as the owner chose it («fil», ADR 038 decision 86): our own
 * SVG, never an image of the app studied — a 1 × 2 face with rounded
 * corners in ivory, a lip of light inside its edge, a divider with a brass
 * pin at its middle and black pips at Y3's places; its back a plain ivory
 * with a mark. Every colour is a `--domino-*` token through the gradients
 * of `DominoDefs`, which a page draws once.
 */

/** Where a half's pips stand, in the half's own 1 × 1 square. */
const PIPS: Record<number, [number, number][]> = {
    0: [],
    1: [[0.5, 0.5]],
    2: [
        [0.27, 0.27],
        [0.73, 0.73],
    ],
    3: [
        [0.27, 0.27],
        [0.5, 0.5],
        [0.73, 0.73],
    ],
    4: [
        [0.27, 0.27],
        [0.73, 0.27],
        [0.27, 0.73],
        [0.73, 0.73],
    ],
    5: [
        [0.27, 0.27],
        [0.73, 0.27],
        [0.5, 0.5],
        [0.27, 0.73],
        [0.73, 0.73],
    ],
    6: [
        [0.28, 0.24],
        [0.28, 0.5],
        [0.28, 0.76],
        [0.72, 0.24],
        [0.72, 0.5],
        [0.72, 0.76],
    ],
};

const stop = (color: string) => ({ stopColor: `var(${color})` });

/**
 * The gradients and the shadow every tile reads, by id: drawn once on a
 * page that draws tiles, in an SVG of no size — never `display: none`,
 * which would leave the gradients unpainted.
 */
export function DominoDefs() {
    return (
        <svg
            width="0"
            height="0"
            aria-hidden
            focusable="false"
            className="pointer-events-none absolute"
        >
            <defs>
                <linearGradient id="dm-face" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" style={stop('--domino-face')} />
                    <stop offset="0.55" style={stop('--domino-face-mid')} />
                    <stop offset="1" style={stop('--domino-face-end')} />
                </linearGradient>
                <linearGradient id="dm-back" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" style={stop('--domino-back')} />
                    <stop offset="1" style={stop('--domino-back-end')} />
                </linearGradient>
                <radialGradient id="dm-pip" cx="0.42" cy="0.38" r="0.7">
                    <stop offset="0" style={stop('--domino-pip')} />
                    <stop offset="0.7" style={stop('--domino-pip-mid')} />
                    <stop offset="1" style={stop('--domino-pip-end')} />
                </radialGradient>
                <radialGradient id="dm-pin" cx="0.38" cy="0.34" r="0.7">
                    <stop offset="0" style={stop('--domino-pin')} />
                    <stop offset="0.45" style={stop('--domino-pin-mid')} />
                    <stop offset="1" style={stop('--domino-pin-end')} />
                </radialGradient>
                <filter
                    id="dm-shadow"
                    x="-20%"
                    y="-20%"
                    width="140%"
                    height="140%"
                >
                    <feDropShadow
                        dx="0"
                        dy="0.05"
                        stdDeviation="0.045"
                        style={{ floodColor: 'var(--domino-shadow)' }}
                        floodOpacity="1"
                    />
                </filter>
            </defs>
        </svg>
    );
}

/** A tile's two numbers from its name, the larger first: `6-5` → [6, 5]. */
export function tileNumbers(tile: string): [number, number] {
    return [Number(tile[0]), Number(tile[2])];
}

/**
 * An upright tile's face in its own units — 1 wide, 2 long, `near` in the
 * upper half and `far` in the lower — for a `<g>` that places and turns it.
 */
export function TileFace({ near, far }: { near: number; far: number }) {
    return (
        <>
            <TileOutline />
            <rect
                x="0.075"
                y="0.075"
                width="0.85"
                height="1.85"
                rx="0.1"
                fill="none"
                style={{ stroke: 'var(--domino-lip)' }}
                strokeWidth="0.022"
            />
            <line
                x1="0.15"
                y1="1"
                x2="0.85"
                y2="1"
                style={{ stroke: 'var(--domino-divider)' }}
                strokeWidth="0.032"
                strokeLinecap="round"
            />
            <circle
                cx="0.5"
                cy="1"
                r="0.062"
                fill="url(#dm-pin)"
                style={{ stroke: 'var(--domino-pin-edge)' }}
                strokeWidth="0.012"
            />
            {(
                [
                    [near, 0],
                    [far, 1],
                ] as const
            ).flatMap(([value, top]) =>
                (PIPS[value] ?? []).map(([x, y]) => (
                    <circle
                        key={`${top}:${x}:${y}`}
                        cx={x}
                        cy={top + y}
                        r="0.085"
                        fill="url(#dm-pip)"
                    />
                )),
            )}
        </>
    );
}

/**
 * An upright tile's face without its marks — the shape a shadow is cast
 * from. A line draws its shadow from these alone, in a layer under the
 * tiles: Safari (WebKit) left out pips of tiles drawn inside a filtered
 * group (ADR 038 "As built (Y8)").
 */
export function TileOutline() {
    return (
        <rect
            x="0.03"
            y="0.03"
            width="0.94"
            height="1.94"
            rx="0.13"
            fill="url(#dm-face)"
            style={{ stroke: 'var(--domino-edge)' }}
            strokeWidth="0.03"
        />
    );
}

/** A tile's back, upright, in its own units. */
export function TileBack() {
    return (
        <>
            <rect
                x="0.03"
                y="0.03"
                width="0.94"
                height="1.94"
                rx="0.13"
                fill="url(#dm-back)"
                style={{ stroke: 'var(--domino-edge)' }}
                strokeWidth="0.03"
            />
            <rect
                x="0.27"
                y="0.62"
                width="0.46"
                height="0.76"
                rx="0.1"
                fill="none"
                style={{ stroke: 'var(--domino-back-mark)' }}
                strokeWidth="0.04"
            />
        </>
    );
}

/**
 * One tile on its own, upright — its larger number on top, as the hand
 * holds it — or lying with it on the left. Decoration: whoever draws it
 * names it in words.
 */
export function TileSvg({
    tile,
    lying = false,
    className,
}: {
    tile: string;
    lying?: boolean;
    className?: string;
}) {
    const [near, far] = tileNumbers(tile);

    return (
        <svg
            viewBox={lying ? '0 0 2 1' : '0 0 1 2'}
            aria-hidden
            className={cn('block', className)}
        >
            <g transform={lying ? 'translate(0 1) rotate(-90)' : undefined}>
                <TileFace near={near} far={far} />
            </g>
        </svg>
    );
}

/** A tile's back on its own, upright. */
export function BackSvg({ className }: { className?: string }) {
    return (
        <svg viewBox="0 0 1 2" aria-hidden className={cn('block', className)}>
            <TileBack />
        </svg>
    );
}
