import { MousePointerClick, Search } from 'lucide-react';
import type { ComponentType, CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { SetupPack, SetupUnit } from '@/types';

type Props = {
    pack: SetupPack;
    unit: SetupUnit;
    mechanic: string;
    /** The card's `sample`, as the board would show it (none at a picture level or when it cannot start). */
    sample: string[];
    /** 4:3 for «Hazır oyun» and the game page; 1:1 on a card. */
    wide?: boolean;
    className?: string;
};

/** The board's ink: text on the white faces. */
const INK = '#1d241f';

/**
 * The game before it is played (docs/frontend.md rule 13): drawn like the
 * board and always light, from the pack's palette and its cover only — a
 * sky from `palette[0]`, a sun in `palette[2]`, a hill in `palette[1]` (a
 * shorter palette repeats its first) — with the card's sample on white
 * faces in `font-board`. Sizes are container query units, so one scene
 * draws a 152px card, «Hazır oyun» and the game page. It is decoration:
 * `aria-hidden`, the card names the game.
 */
export function GameScene({
    pack,
    unit,
    mechanic,
    sample,
    wide = false,
    className,
}: Props) {
    const [p0 = '#3B82C4', p1 = p0, p2 = p0] = pack.palette;
    const style = {
        '--p0': p0,
        '--p1': p1,
        '--p2': p2,
        '--mark': `color-mix(in srgb, ${p0} 62%, ${INK})`,
        background: `linear-gradient(180deg, color-mix(in srgb, ${p0} 16%, #fff) 0%, color-mix(in srgb, ${p0} 32%, #fff) 100%)`,
        boxShadow: `inset 0 0 0 1px color-mix(in srgb, ${p0} 30%, transparent)`,
    } as CSSProperties;

    return (
        <div
            aria-hidden
            style={style}
            className={cn(
                '@container relative isolate block w-full overflow-hidden',
                wide ? 'aspect-[4/3]' : 'aspect-square',
                className,
            )}
        >
            <span
                className="absolute -top-[12%] -right-[9%] -z-10 aspect-square w-[44%] rounded-full"
                style={{
                    background: 'color-mix(in srgb, var(--p2) 60%, #fff)',
                }}
            />
            <span
                className="absolute -right-1/4 -bottom-[40%] -left-1/4 -z-10 h-[64%] rounded-[50%]"
                style={{
                    background: 'color-mix(in srgb, var(--p1) 42%, #fff)',
                }}
            />
            <Layout
                cover={pack.cover}
                unit={unit}
                mechanic={mechanic}
                sample={sample}
                wide={wide}
            />
        </div>
    );
}

function Layout({
    cover,
    unit,
    mechanic,
    sample,
    wide,
}: {
    cover: string;
    unit: SetupUnit;
    mechanic: string;
    sample: string[];
    wide: boolean;
}) {
    const find = mechanic === 'find';

    if (unit === 'picture') {
        return (
            <>
                <Cover
                    src={cover}
                    style={
                        wide
                            ? { width: '42%', left: '29%', top: '13%' }
                            : { width: '56%', left: '22%', top: '16%' }
                    }
                />
                {find && (
                    <span
                        className="absolute aspect-square rounded-full"
                        style={{
                            ...(wide
                                ? { width: '54%', left: '23%', top: '6%' }
                                : { width: '70%', left: '15%', top: '9%' }),
                            border: '1.4cqw solid var(--mark)',
                        }}
                    />
                )}
                {/* At the picture's corner, clear of the game page's close button. */}
                <Badge
                    icon={find ? Search : MousePointerClick}
                    wide={wide}
                    style={
                        wide
                            ? { left: '64%', top: '8%' }
                            : { left: '70%', top: '11%' }
                    }
                />
            </>
        );
    }

    if (mechanic === 'count') {
        return (
            <>
                <div className="absolute top-[11%] right-[9%] left-[9%] flex justify-between">
                    {[0, 1, 2].map((index) => (
                        <Cover
                            key={index}
                            src={cover}
                            className="static"
                            style={{ width: '26%' }}
                        />
                    ))}
                </div>
                <div className="absolute right-[8%] bottom-[9%] left-[8%] flex justify-between">
                    {padded(sample, 3).map((text, index) => (
                        <Face
                            key={index}
                            round
                            wide={wide}
                            ringed={index === 1}
                            className="static"
                        >
                            {text}
                        </Face>
                    ))}
                </div>
            </>
        );
    }

    if (mechanic === 'pop' || mechanic === 'find') {
        const spots = wide
            ? [
                  { left: '52%', top: '9%' },
                  { left: '66%', top: '38%' },
                  { left: '50%', top: '67%' },
              ]
            : [
                  { left: '49%', top: '8%' },
                  { left: '59%', top: '37%' },
                  { left: '47%', top: '66%' },
              ];
        const badge = find
            ? wide
                ? { left: 'calc(66% + 11cqw)', top: '33%' }
                : { left: 'calc(59% + 14cqw)', top: '31%' }
            : wide
              ? { left: 'calc(52% + 12cqw)', top: '3%' }
              : { left: 'calc(49% + 15cqw)', top: '3%' };

        return (
            <>
                <Cover
                    src={cover}
                    style={
                        wide
                            ? { width: '34%', left: '9%', bottom: '8%' }
                            : { width: '40%', left: '5%', bottom: '8%' }
                    }
                />
                {padded(sample, 3).map((text, index) => (
                    <Face
                        key={index}
                        round
                        wide={wide}
                        ringed={find && index === 1}
                        longest={longest(sample)}
                        style={spots[index]}
                    >
                        {text}
                    </Face>
                ))}
                <Badge
                    icon={find ? Search : MousePointerClick}
                    wide={wide}
                    style={badge}
                />
            </>
        );
    }

    // A build game: the cover asks, the first tile is in place, the rest wait.
    const sentence = mechanic === 'build_sentence';
    const slots = Math.max(1, sample.length - 1);

    return (
        <>
            <Cover
                src={cover}
                style={
                    wide
                        ? { width: '30%', left: '35%', top: '9%' }
                        : { width: '40%', left: '30%', top: '9%' }
                }
            />
            <div className="absolute right-[5%] bottom-[10%] left-[5%] flex justify-center gap-[2.5cqw]">
                <Face wide={wide} small={sentence} className="static">
                    {sample[0] ?? ''}
                </Face>
                {Array.from({ length: slots }, (_, index) => (
                    <Face
                        key={index}
                        wide={wide}
                        small={sentence}
                        empty
                        className="static"
                    />
                ))}
            </div>
        </>
    );
}

/** Three faces even when the sample is shorter (a locked card has none). */
function padded(sample: string[], count: number): string[] {
    return Array.from({ length: count }, (_, index) => sample[index] ?? '');
}

function longest(sample: string[]): number {
    return Math.max(0, ...sample.map((text) => text.length));
}

function Cover({
    src,
    style,
    className,
}: {
    src: string;
    style: CSSProperties;
    className?: string;
}) {
    return (
        <img
            src={src}
            alt=""
            draggable={false}
            className={cn('absolute object-contain', className)}
            style={{
                filter: 'drop-shadow(0 3px 5px rgba(0, 0, 0, 0.18))',
                ...style,
            }}
        />
    );
}

/**
 * A white face with the board's text: a round bubble (`pop`, `find`,
 * `count`) or a tile (a build game); `empty` is a dashed slot.
 */
function Face({
    children,
    round = false,
    wide,
    ringed = false,
    empty = false,
    small = false,
    longest: length = 0,
    style,
    className,
}: {
    children?: ReactNode;
    round?: boolean;
    wide: boolean;
    ringed?: boolean;
    empty?: boolean;
    small?: boolean;
    longest?: number;
    style?: CSSProperties;
    className?: string;
}) {
    // A word on a bubble is smaller than a letter, so it stays inside the scene.
    const shrink = length > 4 ? 0.62 : length > 2 ? 0.8 : 1;
    const size = round
        ? wide
            ? 17
            : 21
        : small
          ? wide
              ? 13
              : 16
          : wide
            ? 15
            : 19;
    const font = round ? size * 0.5 * shrink : size * (small ? 0.47 : 0.53);

    return (
        <span
            lang="az"
            className={cn(
                'absolute grid place-items-center font-board leading-none font-bold whitespace-nowrap',
                className,
            )}
            style={{
                minWidth: `${size}cqw`,
                height: `${size}cqw`,
                padding: `0 ${round ? 4 : 3}cqw`,
                borderRadius: round ? '999px' : '4cqw',
                fontSize: `${font}cqw`,
                color: INK,
                ...(empty
                    ? {
                          background: 'rgba(255, 255, 255, 0.35)',
                          border: '0.9cqw dashed rgba(29, 36, 31, 0.5)',
                      }
                    : {
                          background: '#fff',
                          boxShadow:
                              '0 1.2cqw 0 color-mix(in srgb, var(--p0) 65%, #000)',
                      }),
                ...(ringed
                    ? {
                          outline: '1.3cqw solid var(--mark)',
                          outlineOffset: '1.1cqw',
                      }
                    : {}),
                ...style,
            }}
        >
            {children}
        </span>
    );
}

function Badge({
    icon: Icon,
    wide,
    style,
}: {
    icon: ComponentType<{ className?: string }>;
    wide: boolean;
    style: CSSProperties;
}) {
    return (
        <span
            className="absolute z-10 grid place-items-center rounded-full text-white"
            style={{
                width: wide ? '10cqw' : '13cqw',
                height: wide ? '10cqw' : '13cqw',
                background: 'var(--mark)',
                ...style,
            }}
        >
            <Icon className="size-[62%]" />
        </span>
    );
}
