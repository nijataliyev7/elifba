import { PLACE_COLORS } from './place-colors';

/*
 * The share card's festive decoration (architecture §6, §8): static shapes
 * drawn here in code — a bunting garland across the top, confetti along the
 * edges and an empty podium at the bottom — in OpenMoji's palette with
 * black outlines, as Elifba's own art is drawn. No text, letter or digit.
 * It fills WhatsApp's covered bands (the top 250 px and the bottom 300 px)
 * and the 72 px margins, never the content.
 */

const WIDTH = 1080;
const HEIGHT = 1920;
const OUTLINE = '#000000';
const COLORS = [
    '#EA5A47',
    '#FCEA2B',
    '#B1CC33',
    '#61B2E4',
    '#F4AA41',
    '#B399C8',
    '#FFA7C0',
    '#92D3F5',
];

type Point = { x: number; y: number };

/** A point of the quadratic Bézier from `a` through control `c` to `b`. */
function bezier(a: Point, c: Point, b: Point, t: number): Point {
    const u = 1 - t;

    return {
        x: u * u * a.x + 2 * u * t * c.x + t * t * b.x,
        y: u * u * a.y + 2 * u * t * c.y + t * t * b.y,
    };
}

/** A five-pointed star around (x, y). */
function star(x: number, y: number, r: number): string {
    return Array.from({ length: 10 }, (_, i) => {
        const radius = i % 2 === 0 ? r : r * 0.45;
        const angle = -Math.PI / 2 + (i * Math.PI) / 5;

        return `${(x + radius * Math.cos(angle)).toFixed(1)},${(y + radius * Math.sin(angle)).toFixed(1)}`;
    }).join(' ');
}

/** A small deterministic generator, so every card is drawn the same. */
function random(seed: number): () => number {
    let state = seed;

    return () => {
        state = (state * 1664525 + 1013904223) % 4294967296;

        return state / 4294967296;
    };
}

const GARLAND = {
    a: { x: -40, y: 54 },
    c: { x: 540, y: 250 },
    b: { x: 1120, y: 54 },
};
const FLAGS = Array.from({ length: 13 }, (_, i) => {
    const t = (i + 0.5) / 13;
    const top = bezier(GARLAND.a, GARLAND.c, GARLAND.b, t);

    return { ...top, color: COLORS[i % COLORS.length] };
});

type Piece = {
    x: number;
    y: number;
    kind: 'rect' | 'circle' | 'star';
    size: number;
    angle: number;
    color: string;
};

/** Confetti: in the covered bands and in the side margins only. */
const CONFETTI: Piece[] = (() => {
    const next = random(20261111);
    const pieces: Piece[] = [];
    const areas = [
        { x: [40, 1040], y: [150, 236], count: 10 },
        { x: [14, 58], y: [280, 1600], count: 14 },
        { x: [1022, 1066], y: [280, 1600], count: 14 },
        { x: [40, 1040], y: [1640, 1700], count: 12 },
    ];

    for (const area of areas) {
        for (let i = 0; i < area.count; i++) {
            pieces.push({
                x: area.x[0] + next() * (area.x[1] - area.x[0]),
                y: area.y[0] + next() * (area.y[1] - area.y[0]),
                kind: (['rect', 'circle', 'star'] as const)[
                    Math.floor(next() * 3)
                ],
                size: 9 + next() * 9,
                angle: Math.floor(next() * 180),
                color: COLORS[Math.floor(next() * COLORS.length)],
            });
        }
    }

    return pieces;
})();

/** The empty podium at the bottom: 2nd, 1st, 3rd, with a star above each. */
const STEPS = [
    { x: 150, top: 1782, width: 260, place: 2 },
    { x: 410, top: 1716, width: 260, place: 1 },
    { x: 670, top: 1822, width: 260, place: 3 },
];

export function CardDecoration() {
    const string = `M ${GARLAND.a.x} ${GARLAND.a.y} Q ${GARLAND.c.x} ${GARLAND.c.y} ${GARLAND.b.x} ${GARLAND.b.y}`;

    return (
        <svg
            aria-hidden
            className="absolute inset-0"
            width={WIDTH}
            height={HEIGHT}
            viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
            xmlns="http://www.w3.org/2000/svg"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            {/* Bunting */}
            <path d={string} fill="none" stroke={OUTLINE} strokeWidth={4} />
            {FLAGS.map(({ x, y, color }) => (
                <polygon
                    key={`${x}-${y}`}
                    points={`${x - 30},${y - 4} ${x + 30},${y - 4} ${x},${y + 70}`}
                    fill={color}
                    stroke={OUTLINE}
                    strokeWidth={4}
                />
            ))}

            {/* Confetti */}
            {CONFETTI.map((piece, i) => {
                const common = {
                    fill: piece.color,
                    stroke: OUTLINE,
                    strokeWidth: 2.5,
                };

                switch (piece.kind) {
                    case 'circle':
                        return (
                            <circle
                                key={i}
                                cx={piece.x}
                                cy={piece.y}
                                r={piece.size / 2}
                                {...common}
                            />
                        );
                    case 'star':
                        return (
                            <polygon
                                key={i}
                                points={star(
                                    piece.x,
                                    piece.y,
                                    piece.size * 0.8,
                                )}
                                {...common}
                            />
                        );
                    default:
                        return (
                            <rect
                                key={i}
                                x={piece.x - piece.size / 2}
                                y={piece.y - piece.size / 4}
                                width={piece.size}
                                height={piece.size / 2}
                                transform={`rotate(${piece.angle} ${piece.x} ${piece.y})`}
                                {...common}
                            />
                        );
                }
            })}

            {/* Podium */}
            {STEPS.map(({ x, top, width, place }) => (
                <g key={place}>
                    <rect
                        x={x}
                        y={top}
                        width={width}
                        height={HEIGHT - top + 12}
                        rx={18}
                        fill={PLACE_COLORS[place].fill}
                        stroke={OUTLINE}
                        strokeWidth={5}
                    />
                    <polygon
                        points={star(x + width / 2, top - 44, 30)}
                        fill={PLACE_COLORS[place].ring}
                        stroke={OUTLINE}
                        strokeWidth={4}
                    />
                </g>
            ))}
        </svg>
    );
}
