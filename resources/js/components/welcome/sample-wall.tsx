import { Smartphone, Star } from 'lucide-react';
import type { CSSProperties } from 'react';
import { useEffect, useRef, useState } from 'react';
import { boardColours, textOn } from '@/game/palette';
import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { Sample, SampleRound } from '@/types/welcome';

/** How long a solved round stays before the next one comes in. */
const NEXT_ROUND_MS = 1100;

/** How long a pressed phone button looks pressed. */
const PRESS_MS = 300;

/** The one motion nobody triggered: the phone taps the answer once. */
const AUTOPLAY_DELAY_MS = 1300;

const RAYS = [0, 45, 90, 135, 180, 225, 270, 315];

type Props = {
    sample: Sample;
};

/**
 * The welcome page's wall (frontend.md rule 10): real `find` rounds from
 * `SampleRounds`, drawn simply in the pack's colours (`game/palette.ts`),
 * always light like the board. A visitor taps an item on the wall, or the
 * same item on the teacher's phone beside it (below it on a phone). It
 * plays the first round by itself once, unless the reader asked for less
 * motion. The prompt and the items are content: no interface text is drawn
 * on the wall; what happened is said to screen readers only.
 */
export function SampleWall({ sample }: Props) {
    const { t } = useTranslation();
    const reduced = useReducedMotion();
    const colours = boardColours(sample.pack.palette);
    const [index, setIndex] = useState(0);
    const [hit, setHit] = useState<string | null>(null);
    const [miss, setMiss] = useState<string | null>(null);
    const [pressed, setPressed] = useState<string | null>(null);
    const [said, setSaid] = useState('');
    const touched = useRef(false);
    const wall = useRef<HTMLDivElement>(null);
    const round = sample.rounds[index];
    const total = sample.rounds.length;

    // A solved round makes way for the next one; the last for the first.
    useEffect(() => {
        if (hit === null) {
            return;
        }

        const timer = window.setTimeout(() => {
            setHit(null);
            setMiss(null);
            setSaid('');
            setIndex((current) => (current + 1) % total);
        }, NEXT_ROUND_MS);

        return () => window.clearTimeout(timer);
    }, [hit, total]);

    useEffect(() => {
        if (pressed === null) {
            return;
        }

        const timer = window.setTimeout(() => setPressed(null), PRESS_MS);

        return () => window.clearTimeout(timer);
    }, [pressed]);

    // Once the wall is in view, the phone taps the first round's answer,
    // unless the visitor got there first or asked for less motion.
    useEffect(() => {
        const element = wall.current;
        const target = sample.rounds[0]?.solution[0];

        if (reduced || element === null || target === undefined) {
            return;
        }

        const timers: number[] = [];
        const observer = new IntersectionObserver(
            ([entry]) => {
                if (!entry?.isIntersecting) {
                    return;
                }

                observer.disconnect();
                timers.push(
                    window.setTimeout(() => {
                        if (touched.current) {
                            return;
                        }

                        setPressed(target);
                        timers.push(
                            window.setTimeout(() => {
                                if (!touched.current) {
                                    setHit(target);
                                }
                            }, PRESS_MS),
                        );
                    }, AUTOPLAY_DELAY_MS),
                );
            },
            { threshold: 0.6 },
        );

        observer.observe(element);

        return () => {
            observer.disconnect();
            timers.forEach((timer) => window.clearTimeout(timer));
        };
    }, [reduced, sample]);

    const answer = (id: string, fromPhone: boolean): void => {
        touched.current = true;

        if (fromPhone) {
            setPressed(id);
        }

        if (hit !== null) {
            return;
        }

        if (round.solution.includes(id)) {
            setMiss(null);
            setHit(id);
            setSaid(t('welcome.wall.hit'));
        } else {
            setMiss(id);
            setSaid(t('welcome.wall.miss'));
        }
    };

    const colourOf = (position: number): string =>
        sample.pack.palette[position % sample.pack.palette.length] ??
        colours.accent;

    return (
        <figure aria-label={t('welcome.wall.label')} className="min-w-0">
            <div className="relative lg:mr-24 xl:mr-28">
                <div
                    ref={wall}
                    className="@container relative aspect-video w-full overflow-hidden rounded-2xl ring-1 ring-black/5 dark:shadow-[0_0_140px_-40px_var(--wall-glow)] dark:ring-white/10"
                    style={
                        {
                            backgroundColor: colours.background,
                            color: colours.text,
                            '--wall-glow': colours.accent,
                        } as CSSProperties
                    }
                >
                    <div className="absolute inset-x-[4.5cqw] top-[4.5cqw] flex items-start gap-[2.5cqw]">
                        {round.show !== null && (
                            <span
                                aria-hidden
                                className="flex size-[10cqw] shrink-0 items-center justify-center rounded-[1.8cqw] border-[0.7cqw] bg-white font-board text-[6cqw] leading-none font-bold"
                                style={{ borderColor: colours.accent }}
                            >
                                {round.show}
                            </span>
                        )}
                        <p
                            lang="az"
                            className="min-w-0 self-center font-board text-[4.6cqw] leading-tight font-bold"
                        >
                            {round.prompt}
                        </p>
                        <span className="ml-auto shrink-0 pt-[0.6cqw] text-[2.1cqw] font-semibold tabular-nums">
                            {t('games.board.round', {
                                index: index + 1,
                                total,
                            })}
                        </span>
                    </div>

                    <div
                        key={index}
                        className="absolute top-[21cqw] right-[5cqw] bottom-[3cqw] left-[5cqw] flex items-center justify-around lg:right-[12cqw]"
                    >
                        {round.items.map((item, position) => (
                            <WallItem
                                key={item.id}
                                text={item.text}
                                look={sample.pack.item}
                                colour={colourOf(position)}
                                lowered={position % 2 === 1}
                                order={position}
                                state={
                                    item.id === hit
                                        ? 'hit'
                                        : item.id === miss
                                          ? 'miss'
                                          : 'idle'
                                }
                                reduced={reduced}
                                onTap={() => answer(item.id, false)}
                                onSettled={() => setMiss(null)}
                            />
                        ))}
                    </div>
                </div>

                <Phone
                    round={round}
                    pressed={pressed}
                    onPress={(id) => answer(id, true)}
                    className="absolute top-[42%] -right-24 hidden w-36 lg:block xl:-right-28 xl:w-40"
                />
            </div>

            <RemoteRow
                round={round}
                pressed={pressed}
                onPress={(id) => answer(id, true)}
                className="mt-4 lg:hidden"
            />

            <figcaption className="mt-4 max-w-prose text-sm text-pretty text-muted-foreground lg:mt-5 lg:pr-44">
                {t('welcome.wall.hint')}{' '}
                {t('welcome.wall.today', { pack: sample.pack.title })}
            </figcaption>

            <p aria-live="polite" className="sr-only">
                {said}
            </p>
        </figure>
    );
}

type WallItemProps = {
    text: string;
    /** The pack's item style: cards are square, balloons have a string. */
    look: string;
    colour: string;
    lowered: boolean;
    order: number;
    state: 'idle' | 'hit' | 'miss';
    reduced: boolean;
    onTap: () => void;
    onSettled: () => void;
};

function WallItem({
    text,
    look,
    colour,
    lowered,
    order,
    state,
    reduced,
    onTap,
    onSettled,
}: WallItemProps) {
    const card = look === 'card';
    const balloon = look === 'balloon';

    return (
        <div className={lowered ? 'translate-y-[3cqw]' : '-translate-y-[1cqw]'}>
            <div
                className={cn(
                    'relative flex flex-col items-center',
                    !reduced && 'animate-welcome-in',
                )}
                style={{ animationDelay: `${order * 60}ms` }}
            >
                <button
                    type="button"
                    lang="az"
                    onClick={onTap}
                    onAnimationEnd={state === 'miss' ? onSettled : undefined}
                    disabled={state === 'hit'}
                    className={cn(
                        'relative z-10 flex cursor-pointer items-center justify-center font-board text-[8.5cqw] leading-none font-bold outline-offset-4 focus-visible:outline-[0.5cqw] focus-visible:outline-current',
                        card
                            ? 'size-[15cqw] rounded-[2.4cqw]'
                            : balloon
                              ? 'h-[17cqw] w-[14.5cqw] rounded-[50%]'
                              : 'size-[15.5cqw] rounded-full',
                        state === 'hit' &&
                            (reduced ? 'opacity-0' : 'animate-welcome-burst'),
                        state === 'miss' && !reduced && 'animate-welcome-miss',
                    )}
                    style={{
                        backgroundColor: colour,
                        color: textOn(colour),
                        boxShadow: 'inset 0 -0.8cqw 0 rgb(0 0 0 / 0.12)',
                    }}
                >
                    {text}
                </button>

                {balloon && (
                    <svg
                        aria-hidden
                        viewBox="0 0 10 40"
                        className={cn(
                            'h-[7cqw] w-[2cqw] text-[#6B7280]',
                            state === 'hit' && 'opacity-0',
                        )}
                    >
                        <path
                            d="M5 0 L3 12 L7 24 L4 40"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="1.2"
                        />
                    </svg>
                )}

                {state === 'hit' && !reduced && (
                    <span
                        aria-hidden
                        className={cn(
                            'pointer-events-none absolute left-1/2',
                            balloon ? 'top-[8.5cqw]' : 'top-[7.6cqw]',
                        )}
                    >
                        {RAYS.map((angle) => (
                            <span
                                key={angle}
                                className="absolute bottom-0 -left-[0.5cqw] h-[4cqw] w-[1cqw] origin-bottom animate-welcome-ray rounded-full"
                                style={
                                    {
                                        backgroundColor: colour,
                                        '--ray-angle': `${angle}deg`,
                                    } as CSSProperties
                                }
                            />
                        ))}
                    </span>
                )}
            </div>
        </div>
    );
}

type RemoteProps = {
    round: SampleRound;
    pressed: string | null;
    onPress: (id: string) => void;
    className?: string;
};

/** The teacher's phone beside the wall (from `lg`): the remote, drawn small. */
function Phone({ round, pressed, onPress, className }: RemoteProps) {
    const { t } = useTranslation();

    return (
        <div
            role="group"
            aria-label={t('welcome.wall.phone')}
            className={className}
        >
            <div className="rounded-[1.75rem] border-[6px] border-zinc-900 bg-background p-2.5 pt-4 shadow-2xl shadow-black/20 dark:border-zinc-600 dark:shadow-black/60">
                <p
                    lang="az"
                    className="min-h-9 text-center font-board text-[13px] leading-tight"
                >
                    {round.prompt}
                </p>
                <div className="mt-2 grid grid-cols-2 gap-1.5">
                    {round.items.map((item) => (
                        <RemoteButton
                            key={item.id}
                            text={item.text}
                            answer={round.solution.includes(item.id)}
                            pressed={pressed === item.id}
                            onPress={() => onPress(item.id)}
                        />
                    ))}
                </div>
            </div>
        </div>
    );
}

/** Below `lg` the visitor's own phone is the remote: the same buttons in a row. */
function RemoteRow({ round, pressed, onPress, className }: RemoteProps) {
    const { t } = useTranslation();

    return (
        <div
            role="group"
            aria-label={t('welcome.wall.phone')}
            className={className}
        >
            <p className="mb-2 flex items-center gap-1.5 text-sm text-muted-foreground">
                <Smartphone aria-hidden className="size-4" />
                {t('welcome.wall.phone')}
            </p>
            <div className="grid grid-cols-4 gap-2">
                {round.items.map((item) => (
                    <RemoteButton
                        key={item.id}
                        text={item.text}
                        answer={round.solution.includes(item.id)}
                        pressed={pressed === item.id}
                        onPress={() => onPress(item.id)}
                    />
                ))}
            </div>
        </div>
    );
}

type RemoteButtonProps = {
    text: string;
    answer: boolean;
    pressed: boolean;
    onPress: () => void;
};

/**
 * Like the remote's item button (at least 64px, frontend.md rule 4): the
 * answer is marked for the teacher.
 */
function RemoteButton({ text, answer, pressed, onPress }: RemoteButtonProps) {
    const { t } = useTranslation();

    return (
        <button
            type="button"
            lang="az"
            onClick={onPress}
            className={cn(
                'relative flex h-16 cursor-pointer items-center justify-center rounded-xl border bg-card font-board text-3xl leading-none font-bold outline-offset-2 focus-visible:outline-2 focus-visible:outline-ring',
                answer ? 'border-2 border-foreground' : 'border-border',
                pressed &&
                    'animate-welcome-press bg-muted motion-reduce:animate-none',
            )}
        >
            {text}
            {answer && (
                <span className="absolute top-1 right-1 flex items-center rounded-full bg-foreground p-0.5 text-background">
                    <Star aria-hidden className="size-2.5 fill-current" />
                    <span className="sr-only">{t('games.remote.answer')}</span>
                </span>
            )}
        </button>
    );
}
