import { Bot, Flag, Timer } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';
import {
    halfCentre,
    layoutChain,
    PHONE_CHAIN,
    SCREEN_CHAIN,
    upright,
} from '@/components/rooms/domino/chain';
import type { ChainLayout, ChainTile } from '@/components/rooms/domino/chain';
import { dominoSeatName, sideTone } from '@/components/rooms/domino/names';
import {
    othersInTurn,
    partnerOf,
    seatPlaces,
} from '@/components/rooms/domino/places';
import type { SeatPlace } from '@/components/rooms/domino/places';
import {
    chipLines,
    flies,
    flightFrames,
    flyReady,
    markBelow,
    PHONE_CHIP_PX,
    stepKey,
} from '@/components/rooms/domino/stage';
import { Stepped, StepMark } from '@/components/rooms/domino/stepped';
import type { PlayPath } from '@/components/rooms/domino/taps';
import {
    BackSvg,
    TileFace,
    TileOutline,
    tileNumbers,
} from '@/components/rooms/domino/tile';
import {
    PASS_SHOWN_MS,
    stepCounts,
    turnSteps,
    viewerSeat,
} from '@/components/rooms/domino/turn';
import type { SteppedCount } from '@/components/rooms/domino/turn';
import { reducedMotion } from '@/components/rooms/motion-dom';
import { secondsUntil } from '@/components/rooms/use-server-now';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type {
    RoomComputerLevel,
    RoomDomino,
    RoomDominoEnd,
    RoomDominoPlaced,
    RoomPlayer,
    RoomTable,
} from '@/rooms/contract';

/** A play the page saw arrive and draws moving: its seat, its tile and its moment's key. */
export type DominoArrival = { seat: number; tile: string | null; key: string };

/** The last seconds of a turn, when the seat's ring turns the wrong colour with `Timer`. */
const URGENT_S = 5;

/** How long «Bazar :count×» stands on a chip after its step begins. */
const DRAW_SHOWN_MS = 1600;

/** The band of a phone's table its chips take at the top and at the bottom: 6px, a 36px chip and 4px. */
const PHONE_CHIP_ROW =
    PHONE_CHIP_PX.edge + PHONE_CHIP_PX.height + PHONE_CHIP_PX.gap;

/** The size of a box, measured when it is drawn and when the window changes it. */
function useBoxSize(box: RefObject<HTMLElement | null>): {
    width: number;
    height: number;
} {
    const [size, setSize] = useState({ width: 0, height: 0 });

    useLayoutEffect(() => {
        const element = box.current;

        if (element === null) {
            return;
        }

        const measure = () =>
            setSize((last) =>
                last.width === element.clientWidth &&
                last.height === element.clientHeight
                    ? last
                    : {
                          width: element.clientWidth,
                          height: element.clientHeight,
                      },
            );

        measure();

        if (typeof ResizeObserver === 'undefined') {
            return;
        }

        const observer = new ResizeObserver(measure);

        observer.observe(element);

        return () => observer.disconnect();
    }, [box]);

    return size;
}

/**
 * The line with the viewer's posted tile at its end, lying as the line's
 * end makes it lie, until the state answers (docs/frontend.md rule 15
 * "Domino" → "Taps"). How a tile at an end lies is the drawing's, not a
 * judgement: the server answers whether it fits.
 */
export function withPending(
    domino: Pick<RoomDomino, 'line' | 'ends'>,
    pending: PlayPath | null,
): RoomDominoPlaced[] {
    if (pending === null) {
        return domino.line;
    }

    const [tile, end] = pending;
    const [a, b] = tileNumbers(tile);

    if (end === undefined) {
        return [{ tile, left: a, right: b }];
    }

    const number = domino.ends.find((one) => one.end === end)?.number ?? a;
    const other = a === number ? b : a;

    return end === 'left'
        ? [{ tile, left: other, right: number }, ...domino.line]
        : [...domino.line, { tile, left: number, right: other }];
}

/** Where the chain's box stands in the table, in px. */
function chainBox(
    width: number,
    height: number,
    places: SeatPlace[],
    big: boolean,
    chips = true,
): { left: number; top: number; width: number; height: number } {
    if (!chips) {
        const pad = Math.min(width, height) * 0.05;

        return {
            left: pad,
            top: pad,
            width: Math.max(0, width - 2 * pad),
            height: Math.max(0, height - 2 * pad),
        };
    }

    if (!big) {
        // A phone's chips are two lines (36px) 6px from the table's top and bottom edges.
        return {
            left: 4,
            top: PHONE_CHIP_ROW,
            width: Math.max(0, width - 8),
            height: Math.max(0, height - 2 * PHONE_CHIP_ROW),
        };
    }

    const chip = Math.max(56, height * 0.13);
    const sides = places.includes('left') || places.includes('right');
    // A side seat's chip is at most 15vw wide, 1.4vw from the edge.
    const side = sides ? Math.min(width * 0.19, 360) : width * 0.03;
    const top = places.includes('top') ? chip + height * 0.03 : height * 0.04;
    const bottom = chip + height * 0.03;

    return {
        left: side,
        top,
        width: Math.max(0, width - 2 * side),
        height: Math.max(0, height - top - bottom),
    };
}

/**
 * The table (docs/frontend.md rule 15 "Domino" → "The table", ADR 038
 * decisions 45, 47, 52 and 86–89), one part on the phone and the screen
 * (`big`): the felt; the line as a snake from its top left
 * (`layoutChain()`), the last tile framed, the two ends' numbers as
 * badges and — while the viewer's lifted tile fits both — a dashed target
 * at each end, a 44px button at least; the seats round it as chips, the
 * viewer's own at the bottom; and the boneyard where the table has one.
 *
 * It draws the state. A play the page saw arrive (`arrival`) lifts, flies
 * from its seat's chip and settles («oturma», 510 ms), and the steps after
 * it — a seat's draw, a pass — show on the chips in their windows of the
 * turn's timeline (`turnSteps()`), as the counts change (`stepCounts()`);
 * a page opened on a position, a state more than one ply ahead and every
 * state fetched again draw it still, as does `prefers-reduced-motion`.
 * The viewer's own posted tile stands at its end in its side's colour
 * until the state answers (`pending`).
 */
export function DominoTable({
    domino,
    table,
    players,
    computer,
    viewer,
    now,
    big = false,
    targets = [],
    onTarget,
    pending = null,
    arrival = null,
    still = false,
    className,
}: {
    domino: RoomDomino;
    table: RoomTable;
    players: RoomPlayer[];
    computer: RoomComputerLevel | null;
    /** The phone's viewer; null for the screen. */
    viewer: string | null;
    now: number | null;
    big?: boolean;
    /** The ends a lifted tile may take: their targets are lit. */
    targets?: RoomDominoEnd[];
    onTarget?: (end: RoomDominoEnd) => void;
    pending?: PlayPath | null;
    /** The act the page saw arrive and draws moving; null: everything still. */
    arrival?: DominoArrival | null;
    /** The table as a game ended: no turn, no step. */
    still?: boolean;
    className?: string;
}) {
    const { t } = useTranslation();
    const box = useRef<HTMLDivElement>(null);
    const chipRefs = useRef<(HTMLElement | null)[]>([]);
    const size = useBoxSize(box);
    const own = viewerSeat(domino, viewer);
    const count = domino.seats.length;
    const places = seatPlaces(count, own);
    // The screen's table as a game ended is small beside its final: the line alone, the sheet says who held what.
    const chips = !(big && still);
    const area = chainBox(
        size.width,
        size.height,
        chips ? places : [],
        big,
        chips,
    );
    const line = withPending(domino, pending);
    const layout =
        area.width > 0 && area.height > 0
            ? layoutChain(line, {
                  width: area.width,
                  height: area.height,
                  ...(big ? SCREEN_CHAIN : PHONE_CHAIN),
              })
            : null;
    const moving = arrival !== null && !still;
    const steps = moving ? turnSteps(domino).steps : [];
    const counts = stepCounts(domino);
    const [landed, setLanded] = useState<string | null>(null);
    // Drawn while it flies, never once it landed: the line's own tile shows then (ADR 038 "As built (Y7)").
    const flying =
        moving &&
        arrival.tile !== null &&
        !reducedMotion() &&
        flies(arrival.key, landed)
            ? arrival
            : null;
    const hidden = flying?.tile ?? null;
    const moment = moving ? arrival.key : '';
    const last =
        domino.last?.kind === 'play' && pending === null
            ? domino.last.tile
            : null;
    const partner = own === null ? null : partnerOf(table, own);
    const boneyard = count < 4;

    const chip = (seat: number) => {
        const one = domino.seats[seat];
        const mover = !still && domino.phase === 'play' && domino.turn === seat;
        const seconds =
            mover && domino.flag_ms !== null
                ? secondsUntil(domino.flag_ms, now)
                : null;

        return (
            <SeatChip
                key={seat}
                ref={(element) => {
                    chipRefs.current[seat] = element;
                }}
                name={dominoSeatName(t, players, domino, computer, seat, !big)}
                fullName={dominoSeatName(t, players, domino, computer, seat)}
                computer={one.standing === 'computer'}
                playedFor={one.standing === 'away' || one.standing === 'left'}
                partner={seat === partner}
                tone={sideTone(domino, viewer, one.side) ?? 'right'}
                seconds={seconds}
                counts={counts.seats[seat] ?? []}
                moving={moving}
                moment={moment}
                below={markBelow(big, places[seat], seat === (own ?? 0))}
                steps={steps.filter(
                    (step) =>
                        (step.kind === 'draw' || step.kind === 'pass') &&
                        step.seat === seat,
                )}
                backs={big ? 7 : 1}
                upright={
                    big && (places[seat] === 'left' || places[seat] === 'right')
                }
                big={big}
            />
        );
    };

    return (
        <div
            ref={box}
            role="group"
            aria-label={t('rooms.domino.table_label')}
            data-hand={domino.hand?.number}
            className={cn(
                'relative min-h-0 overflow-hidden text-domino-ink shadow-[inset_0_1px_3px_rgb(0_0_0/0.35)] domino-felt',
                big ? 'rounded-[2.4vh]' : 'rounded-xl',
                className,
            )}
        >
            {layout !== null && (
                <div
                    className="absolute"
                    style={{
                        left: area.left,
                        top: area.top,
                        width: area.width,
                        height: area.height,
                    }}
                >
                    <ChainSvg
                        layout={layout}
                        width={area.width}
                        height={area.height}
                        ends={domino.ends}
                        last={last}
                        pending={pending?.[0] ?? null}
                        hidden={hidden}
                        targets={targets}
                        badgePx={big ? 40 : 20}
                        label={
                            domino.line.length === 0
                                ? t('rooms.domino.line_empty')
                                : t('rooms.domino.line', {
                                      count: domino.line.length,
                                      left: domino.ends[0]?.number ?? 0,
                                      right: domino.ends[1]?.number ?? 0,
                                  })
                        }
                    />
                    {onTarget !== undefined &&
                        layout.targets
                            .filter((target) => targets.includes(target.end))
                            .map((target) => {
                                const [hx, hy] = upright(target.angle)
                                    ? [0.5, 1]
                                    : [1, 0.5];
                                const x =
                                    (target.x - layout.viewBox[0]) *
                                    layout.unit;
                                const y =
                                    (target.y - layout.viewBox[1]) *
                                    layout.unit;
                                const w = Math.max(44, hx * 2 * layout.unit);
                                const h = Math.max(44, hy * 2 * layout.unit);
                                const number =
                                    domino.ends.find(
                                        (end) => end.end === target.end,
                                    )?.number ?? 0;

                                return (
                                    <button
                                        key={target.end}
                                        type="button"
                                        data-target={target.end}
                                        aria-label={t(
                                            target.end === 'left'
                                                ? 'rooms.domino.left_end'
                                                : 'rooms.domino.right_end',
                                            { number },
                                        )}
                                        onClick={() => onTarget(target.end)}
                                        className="absolute z-10 rounded-lg outline-none focus-visible:ring-[3px] focus-visible:ring-white/80"
                                        style={{
                                            left: x - w / 2,
                                            top: y - h / 2,
                                            width: w,
                                            height: h,
                                        }}
                                    />
                                );
                            })}
                </div>
            )}

            {!chips ? null : big ? (
                places.map((place, seat) => (
                    <div
                        key={seat}
                        className={cn(
                            'absolute z-[5] flex',
                            place === 'bottom' &&
                                'inset-x-0 bottom-[1.6vh] justify-center',
                            place === 'top' &&
                                'inset-x-0 top-[1.6vh] justify-center',
                            place === 'left' &&
                                'inset-y-0 left-[1.4vw] items-center',
                            place === 'right' &&
                                'inset-y-0 right-[1.4vw] items-center',
                        )}
                    >
                        {chip(seat)}
                    </div>
                ))
            ) : (
                <>
                    <div className="absolute inset-x-1.5 top-1.5 z-[5] flex justify-center gap-1.5">
                        {othersInTurn(count, own).map(chip)}
                    </div>
                    <div className="absolute inset-x-1.5 bottom-1.5 z-[5] flex items-end justify-between gap-1.5">
                        {chip(own ?? 0)}
                        {boneyard && (
                            <Boneyard
                                domino={domino}
                                counts={counts.pile}
                                moving={moving}
                                moment={moment}
                            />
                        )}
                    </div>
                </>
            )}
            {big && chips && boneyard && (
                <div className="absolute right-[1.4vw] bottom-[1.6vh] z-[5]">
                    <Boneyard
                        domino={domino}
                        counts={counts.pile}
                        moving={moving}
                        moment={moment}
                        big
                    />
                </div>
            )}

            {flying !== null && layout !== null && (
                <Fly
                    key={flying.key}
                    chips={chipRefs}
                    seat={flying.seat}
                    box={box}
                    measured={size}
                    layout={layout}
                    area={area}
                    tile={layout.tiles.find((one) => one.tile === flying.tile)}
                    onLanded={() => setLanded(flying.key)}
                />
            )}
        </div>
    );
}

/** The frame round the last tile played, or the one being placed, in the tile's own units. */
function TileFrame({ pending }: { pending: boolean }) {
    return (
        <>
            <rect
                x="-0.09"
                y="-0.09"
                width="1.18"
                height="2.18"
                rx="0.2"
                fill="none"
                style={{ stroke: 'var(--domino-target)' }}
                strokeOpacity="0.7"
                strokeWidth="0.14"
            />
            <rect
                x="-0.08"
                y="-0.08"
                width="1.16"
                height="2.16"
                rx="0.19"
                fill="none"
                style={{
                    stroke: pending ? 'var(--rival-left)' : 'var(--primary)',
                }}
                strokeWidth="0.07"
            />
        </>
    );
}

/** The line as one SVG: the tiles with their shadow, the last one framed, the lit targets and the two ends' badges. */
export function ChainSvg({
    layout,
    width,
    height,
    ends,
    last,
    pending,
    hidden,
    targets,
    badgePx,
    label,
}: {
    layout: ChainLayout;
    width: number;
    height: number;
    ends: RoomDomino['ends'];
    last: string | null;
    pending: string | null;
    hidden: string | null;
    targets: RoomDominoEnd[];
    badgePx: number;
    label: string;
}) {
    const r = Math.max(0.36, badgePx / 2 / layout.unit);

    return (
        <svg
            viewBox={layout.viewBox.join(' ')}
            width={width}
            height={height}
            role="img"
            aria-label={label}
            className="block"
        >
            {/*
                The shadow in a layer of its own, cast from the tiles' outlines
                and their frames, under every tile: Safari (WebKit) left out
                pips of tiles drawn inside a filtered group (ADR 038 "As built (Y8)").
            */}
            <g filter="url(#dm-shadow)" aria-hidden>
                {layout.tiles.map((tile) => (
                    <g
                        key={tile.tile}
                        opacity={tile.tile === hidden ? 0 : 1}
                        transform={`translate(${tile.x} ${tile.y}) rotate(${tile.angle}) translate(-0.5 -1)`}
                    >
                        <TileOutline />
                        {(tile.tile === last || tile.tile === pending) && (
                            <TileFrame pending={tile.tile === pending} />
                        )}
                    </g>
                ))}
            </g>
            <g>
                {layout.tiles.map((tile) => (
                    <g
                        key={tile.tile}
                        data-tile={tile.tile}
                        opacity={tile.tile === hidden ? 0 : 1}
                        transform={`translate(${tile.x} ${tile.y}) rotate(${tile.angle}) translate(-0.5 -1)`}
                    >
                        <TileFace near={tile.near} far={tile.far} />
                        {(tile.tile === last || tile.tile === pending) && (
                            <TileFrame pending={tile.tile === pending} />
                        )}
                    </g>
                ))}
            </g>
            {layout.targets.map((target) => {
                const number =
                    ends.find((end) => end.end === target.end)?.number ?? null;
                const [bx, by] = halfCentre(target, 'near');
                const lit = targets.includes(target.end);

                return (
                    <g key={target.end} data-end={target.end}>
                        {lit && (
                            <g
                                transform={`translate(${target.x} ${target.y}) rotate(${target.angle}) translate(-0.5 -1)`}
                            >
                                <rect
                                    x="0.04"
                                    y="0.04"
                                    width="0.92"
                                    height="1.92"
                                    rx="0.13"
                                    style={{
                                        fill: 'var(--domino-target)',
                                        stroke: 'var(--domino-target)',
                                    }}
                                    fillOpacity="0.14"
                                    strokeWidth="0.05"
                                    strokeDasharray="0.14 0.09"
                                />
                            </g>
                        )}
                        {number !== null && (
                            <g aria-hidden>
                                <circle
                                    cx={bx}
                                    cy={by}
                                    r={r}
                                    style={{
                                        fill: 'var(--card)',
                                        stroke: lit
                                            ? 'var(--primary)'
                                            : 'var(--border)',
                                    }}
                                    strokeWidth={r * (lit ? 0.2 : 0.12)}
                                />
                                <text
                                    x={bx}
                                    y={by}
                                    fontSize={r * 1.25}
                                    fontWeight="700"
                                    textAnchor="middle"
                                    dominantBaseline="central"
                                    style={{ fill: 'var(--foreground)' }}
                                >
                                    {number}
                                </text>
                            </g>
                        )}
                    </g>
                );
            })}
        </svg>
    );
}

/**
 * A play's flight («oturma», ADR 038 decision 89): the tile lifts at its
 * seat's chip (90 ms, its shadow growing), flies to its place turning to
 * lie as the line has it (280 ms) and settles (140 ms); then it is gone
 * and the line's own tile shows. It starts once the table was measured at
 * the size it has, so it lands where the line is drawn. The tile and its
 * shadow — a layer of its own under it — move in transform and opacity
 * only (`flightFrames()`), and never under reduced motion, where the tile
 * is at its end at once.
 */
function Fly({
    chips,
    seat,
    box,
    measured,
    layout,
    area,
    tile,
    onLanded,
}: {
    chips: RefObject<(HTMLElement | null)[]>;
    seat: number;
    box: RefObject<HTMLDivElement | null>;
    measured: { width: number; height: number };
    layout: ChainLayout;
    area: { left: number; top: number };
    tile: ChainTile | undefined;
    onLanded: () => void;
}) {
    const element = useRef<HTMLDivElement>(null);
    const shadow = useRef<HTMLDivElement>(null);
    const flown = useRef(false);
    const unit = layout.unit;

    // Once, as its moment arrives: a state drawn again does not fly it again.
    useLayoutEffect(() => {
        const fly = element.current;
        const under = shadow.current;
        const table = box.current;
        const from = chips.current[seat] ?? null;

        if (flown.current) {
            return;
        }

        if (
            table !== null &&
            typeof ResizeObserver !== 'undefined' &&
            !flyReady(measured, {
                width: table.clientWidth,
                height: table.clientHeight,
            })
        ) {
            // The table changed size in this moment: fly once the line is laid out for it.
            return;
        }

        flown.current = true;

        if (
            fly === null ||
            under === null ||
            tile === undefined ||
            table === null
        ) {
            onLanded();

            return;
        }

        const frame = table.getBoundingClientRect();
        const start = from?.getBoundingClientRect();
        const origin: [number, number] =
            start === undefined
                ? [frame.width / 2, frame.height]
                : [
                      start.left + start.width / 2 - frame.left,
                      start.top + start.height / 2 - frame.top,
                  ];
        const to: [number, number] = [
            area.left + (tile.x - layout.viewBox[0]) * unit,
            area.top + (tile.y - layout.viewBox[1]) * unit,
        ];
        const frames = flightFrames(origin, to, tile.angle, unit);
        const timing: KeyframeAnimationOptions = {
            duration: frames.duration,
            easing: 'ease-in-out',
            fill: 'both',
        };

        under.animate(frames.shadow, timing);
        fly.animate(frames.tile, timing).finished.then(onLanded, onLanded);
    }, [chips, seat, box, measured, layout, area, tile, unit, onLanded]);

    if (tile === undefined) {
        return null;
    }

    return (
        <div
            ref={element}
            aria-hidden
            data-flying={tile.tile}
            className="pointer-events-none absolute top-0 left-0 z-20 origin-center opacity-0"
            style={{ width: unit, height: unit * 2 }}
        >
            <div
                ref={shadow}
                className="absolute inset-0 rounded-[18%] bg-black opacity-0 blur-[3px]"
            />
            <svg viewBox="0 0 1 2" className="relative block size-full">
                <TileFace near={tile.near} far={tile.far} />
            </svg>
        </div>
    );
}

/**
 * A seat at the table as a chip: its side's mark, its name — `Bot` for the
 * computer's, and beside a player's whose seat is played for —, «para» on
 * the viewer's partner, its tiles as backs with their count («×5»), and
 * while it is that seat's turn a ring in its side's colour with the
 * seconds left, the wrong colour with `Timer` in the last five. A step of
 * the turn shows on it in its window: «Bazar :count×», «PAS» with `Flag` —
 * under a chip along the table's top edge (`below`), so the edge never
 * cuts it, and keyed by its moment, so a seat that passes again shows it
 * again. On a phone the chip is two lines — the name over the tiles and
 * the seconds — so three chips across 375px keep their names.
 */
function SeatChip({
    ref,
    name,
    fullName,
    computer,
    playedFor,
    partner,
    tone,
    seconds,
    counts,
    moving,
    moment,
    below,
    steps,
    backs,
    upright: stacked,
    big,
}: {
    ref: (element: HTMLElement | null) => void;
    name: string;
    fullName: string;
    computer: boolean;
    playedFor: boolean;
    partner: boolean;
    tone: 'left' | 'right';
    seconds: number | null;
    counts: SteppedCount;
    moving: boolean;
    /** The moment the steps belong to (`stepKey()`). */
    moment: string;
    /** A step's mark stands under the chip, not over it. */
    below: boolean;
    steps: ReturnType<typeof turnSteps>['steps'];
    /** How many backs it draws at most beside the count. */
    backs: number;
    /** A screen's side seat: its name over its tiles, so it stays narrow. */
    upright: boolean;
    big: boolean;
}) {
    const { t } = useTranslation();
    const tiles = counts[counts.length - 1]?.value ?? 0;
    const urgent = seconds !== null && seconds <= URGENT_S;
    const icon = big ? 'size-[2.4vh] shrink-0' : 'size-3.5 shrink-0';

    const mark = (
        <span
            aria-hidden
            className={cn(
                'shrink-0 rounded-full',
                big ? 'size-[1.2vh]' : 'size-2',
                tone === 'left' ? 'bg-rival-left' : 'bg-rival-right',
            )}
        />
    );
    const named = (
        <span
            className={cn(
                'min-w-0 truncate font-semibold',
                stacked && 'max-w-full basis-[calc(100%-3vh)] text-center',
            )}
            aria-hidden
        >
            {computer ? t('rooms.computer.name') : name}
        </span>
    );
    const bot = (computer || playedFor) && <Bot aria-hidden className={icon} />;
    const playedForWords = playedFor && (
        <span className="sr-only">
            {t('rooms.domino.played_for', { name: fullName })}
        </span>
    );
    const partnerBadge = partner && (
        <span
            className={cn(
                'shrink-0 text-muted-foreground',
                big ? 'text-[1.8vh]' : 'text-[10px]',
            )}
        >
            {t('rooms.domino.partner_badge')}
        </span>
    );
    const held = (
        <span aria-hidden className="flex shrink-0 items-center gap-px">
            {Array.from({ length: Math.min(tiles, backs) }, (_, index) => (
                <BackSvg
                    key={index}
                    className={big ? 'h-[2.6vh] w-[1.3vh]' : 'h-3 w-1.5'}
                />
            ))}
            <span
                className={cn(
                    'ml-0.5 text-muted-foreground tabular-nums',
                    big ? 'text-[2vh]' : 'text-[11px]',
                )}
            >
                <Stepped
                    counts={counts}
                    moving={moving}
                    moment={moment}
                    render={(value) => `×${value}`}
                />
            </span>
        </span>
    );
    const time = seconds !== null && (
        <span
            className={cn(
                'flex shrink-0 items-center gap-0.5 font-bold tabular-nums',
                tone === 'left'
                    ? 'text-rival-left-text'
                    : 'text-rival-right-text',
                urgent && 'text-red-600 dark:text-red-400',
            )}
        >
            {urgent && <Timer aria-hidden className={icon} />}
            {seconds}
        </span>
    );
    const marks = steps.map((step) => (
        <StepMark
            key={stepKey(moment, step)}
            step={`${step.kind}:${step.seat}`}
            at={step.at}
            length={step.kind === 'pass' ? PASS_SHOWN_MS : DRAW_SHOWN_MS}
            className={cn(
                'absolute left-1/2 z-10 -translate-x-1/2 whitespace-nowrap',
                below
                    ? big
                        ? 'top-full mt-[0.8vh]'
                        : 'top-full mt-1'
                    : big
                      ? '-top-[3.4vh]'
                      : '-top-5',
            )}
        >
            <span
                className={cn(
                    'inline-flex items-center gap-1 rounded-full font-bold text-white shadow',
                    big
                        ? 'px-[1.2vh] py-[0.3vh] text-[2.2vh]'
                        : 'px-2 py-0.5 text-[11px]',
                    step.kind === 'pass'
                        ? 'bg-neutral-800 dark:bg-neutral-700'
                        : 'bg-neutral-700 dark:bg-neutral-600',
                )}
            >
                {step.kind === 'pass' ? (
                    <>
                        <Flag aria-hidden className={icon} />
                        {t('rooms.domino.pass')}
                    </>
                ) : (
                    t('rooms.domino.drew', {
                        count: step.kind === 'draw' ? step.count : 0,
                    })
                )}
            </span>
        </StepMark>
    ));
    const label = t('rooms.domino.seat_tiles', {
        name: fullName,
        count: tiles,
    });
    const ring =
        seconds !== null &&
        cn(
            tone === 'left'
                ? 'ring-[3px] ring-rival-left'
                : 'ring-[3px] ring-rival-right',
            urgent && 'ring-red-600 dark:ring-red-400',
        );

    if (!big && chipLines(big, stacked) === 2) {
        return (
            <span
                ref={ref}
                role="group"
                data-seat-chip
                data-turn={seconds !== null ? '' : undefined}
                aria-label={label}
                className={cn(
                    'relative flex h-9 max-w-[8.5rem] min-w-0 flex-col items-center justify-center rounded-xl bg-card/95 px-2 text-xs leading-4 text-foreground shadow-[0_2px_6px_rgb(0_0_0/0.3)]',
                    ring,
                )}
            >
                <span className="flex max-w-full min-w-0 items-center gap-1">
                    {mark}
                    {bot}
                    {named}
                </span>
                <span className="flex items-center gap-1">
                    {partnerBadge}
                    {held}
                    {time}
                </span>
                {playedForWords}
                {marks}
            </span>
        );
    }

    return (
        <span
            ref={ref}
            role="group"
            data-seat-chip
            data-turn={seconds !== null ? '' : undefined}
            aria-label={label}
            className={cn(
                'relative flex min-w-0 items-center bg-card/95 text-foreground shadow-[0_2px_6px_rgb(0_0_0/0.3)]',
                stacked
                    ? 'max-w-[15vw] flex-wrap justify-center gap-x-[1vh] gap-y-[0.4vh] rounded-[2vh] px-[1.6vh] py-[1vh] text-[max(1rem,2.6vh)]'
                    : 'h-[max(2.75rem,6vh)] gap-[1vh] rounded-full px-[1.6vh] text-[max(1rem,2.6vh)]',
                ring,
            )}
        >
            {mark}
            {bot}
            {named}
            {playedForWords}
            {partnerBadge}
            {held}
            {time}
            {marks}
        </span>
    );
}

/**
 * The boneyard (docs/frontend.md rule 15 "Domino" → "The table"): a pile
 * of backs and «Bazar · :count», its count going down as the steps draw;
 * when nobody may draw it says why in one line — «Son 2 daş çəkilmir», or
 * «Uclara yalnız qoşa düşə bilər — bazar bağlıdır» (R13).
 */
function Boneyard({
    domino,
    counts,
    moving,
    moment,
    big = false,
}: {
    domino: RoomDomino;
    counts: SteppedCount;
    moving: boolean;
    moment: string;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const left = domino.boneyard.tiles;
    const why = domino.boneyard.closed
        ? t('rooms.domino.closed')
        : left <= domino.boneyard.reserve && domino.hand !== null
          ? t('rooms.domino.reserve', { count: domino.boneyard.reserve })
          : null;

    return (
        <span
            data-boneyard={left}
            className={cn(
                'flex max-w-[11rem] flex-col items-end rounded-xl bg-black/25 text-domino-ink',
                big
                    ? 'max-w-[22vw] gap-[0.4vh] px-[1.4vh] py-[0.8vh] text-[max(0.9375rem,2.4vh)]'
                    : 'gap-0.5 px-2 py-1 text-xs',
            )}
        >
            <span className="flex items-center gap-1.5">
                <span aria-hidden className="flex gap-px">
                    {Array.from({ length: Math.min(left, 4) }, (_, index) => (
                        <BackSvg
                            key={index}
                            className={big ? 'h-[3vh] w-[1.5vh]' : 'h-4 w-2'}
                        />
                    ))}
                </span>
                <span className="font-semibold tabular-nums">
                    <Stepped
                        counts={counts}
                        moving={moving}
                        moment={moment}
                        render={(value) =>
                            t('rooms.domino.boneyard', { count: value })
                        }
                    />
                </span>
            </span>
            {why !== null && (
                <span
                    className={cn(
                        'text-right leading-tight opacity-90',
                        big ? 'text-[1.8vh]' : 'text-[10px]',
                    )}
                >
                    {why}
                </span>
            )}
        </span>
    );
}
