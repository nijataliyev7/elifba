import { Lightbulb, Lock, Shuffle, SquarePlus, Undo2 } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { Mark } from '@/components/rooms/mechanics/choice';
import type { AskTools } from '@/components/rooms/mechanics/views';
import {
    anagramMine,
    canShuffle,
    lettersOf,
    placesOf,
    priceKey,
    slotText,
    tileRows,
    trayOrder,
    trayOrders,
} from '@/components/rooms/mechanics/word-layout';
import type { AnagramPlace } from '@/components/rooms/mechanics/word-layout';
import { REVEAL_MS, stagger } from '@/components/rooms/motion';
import { press, reducedMotion } from '@/components/rooms/motion-dom';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomAsk, RoomLetters, RoomMine } from '@/rooms/contract';

/*
 * «Anaqram» (docs/architecture.md §12 "Room games" and "«Anaqram»: a clue,
 * a shuffle and a bought letter (ADR 037)", docs/frontend.md rule 15): the
 * word's clue on a card, the word's places in one bar, and its letters as
 * tiles as the server sent them — cased there (Az::upper, so «İ» and «I»
 * stay two letters in every locale), in an order the server made, never
 * one that spells a word the letters accept. Each tap moves a tile into
 * the next place and leaves a hollow where it stood.
 *
 * Under the tiles, a player who may still answer has three tools:
 * «Qarışdır» lays the tiles in the next of the orders the server sent
 * (the page never shuffles, and cannot tell a word); «Hərf al» buys the
 * word's next letter at the price the state words, and the page waits for
 * its state, which locks the bought tiles in the first places — inverted,
 * with a lock — returns the viewer's own taps and lays the tiles left in
 * the buyer's own orders; «Geri» takes back the viewer's last own tap,
 * never a bought letter. The tap that fills the last place sends the
 * word, as does the purchase of the last letter (the round's). Nothing
 * here knows the word before the reveal, and right or wrong is the
 * server's word (`mine.correct`).
 *
 * The screen shows the clue large, the empty places and the tiles in the
 * first order: never anyone's taps or bought letters.
 *
 * No case is changed here: no `uppercase` class, no `toUpperCase()`.
 *
 * Motion (ADR 025): the places and the tiles come in with the round; a tap
 * presses its tile and pops its letter into the place; a shuffle and a
 * purchase slide the tiles to their new places (the viewer's own taps,
 * `useSlide()`), and a bought letter pops into its place; nothing moves
 * differently for a right or a wrong answer, and nothing moves under
 * reduced motion.
 */

/** A tile on a phone: 56px, five to a row at most; on the screen up to 13vh. */
const TILE = { phone: 56, gap: 8 } as const;
const BIG_TILE = 'min(13vh,9vw)';
const BIG_GAP = '1.6vh';
/** How long after a tap of «Qarışdır» or «Hərf al» the tiles' new order still slides from the old. */
const SLIDE_WINDOW_MS = 3000;
const SLIDE_MS = 280;
const EASE = 'cubic-bezier(0.2, 0.8, 0.2, 1)';

/** The tray's box, as wide as a row of its tiles, so they wrap into even rows. */
function trayWidth(count: number, big: boolean): CSSProperties {
    const { perRow } = tileRows(count, big ? 6 : 5);

    return {
        maxWidth: big
            ? `calc(${perRow} * ${BIG_TILE} + ${Math.max(0, perRow - 1)} * ${BIG_GAP})`
            : `${perRow * TILE.phone + Math.max(0, perRow - 1) * TILE.gap}px`,
    };
}

/** Where each tile (`[data-tile]`) is drawn now, from the tray's corner — mid-slide too. */
function tilePlaces(root: HTMLElement): Map<string, { x: number; y: number }> {
    const corner = root.getBoundingClientRect();

    return new Map(
        Array.from(root.querySelectorAll<HTMLElement>('[data-tile]')).map(
            (tile) => {
                const box = tile.getBoundingClientRect();

                return [
                    tile.dataset.tile ?? '',
                    { x: box.left - corner.left, y: box.top - corner.top },
                ];
            },
        ),
    );
}

/**
 * The tray's tiles slide from where they were drawn to their new order
 * (FLIP) — only after the viewer's own tap of «Qarışdır» or «Hərf al»
 * (`mark()`), within a few seconds of it, and never under reduced motion.
 * A new order the viewer did not ask for is drawn still.
 */
function useSlide(order: string) {
    const ref = useRef<HTMLUListElement>(null);
    const from = useRef<{
        at: number;
        places: Map<string, { x: number; y: number }>;
    } | null>(null);

    useLayoutEffect(() => {
        const root = ref.current;
        const marked = from.current;

        from.current = null;

        if (
            root === null ||
            marked === null ||
            performance.now() - marked.at > SLIDE_WINDOW_MS ||
            typeof root.animate !== 'function' ||
            reducedMotion()
        ) {
            return;
        }

        const tiles = Array.from(
            root.querySelectorAll<HTMLElement>('[data-tile]'),
        );

        for (const tile of tiles) {
            for (const running of tile.getAnimations()) {
                running.cancel();
            }
        }

        const now = tilePlaces(root);

        for (const tile of tiles) {
            const id = tile.dataset.tile ?? '';
            const before = marked.places.get(id);
            const after = now.get(id);

            if (before === undefined || after === undefined) {
                continue;
            }

            const dx = before.x - after.x;
            const dy = before.y - after.y;

            if (Math.abs(dx) < 0.5 && Math.abs(dy) < 0.5) {
                continue;
            }

            tile.animate(
                [
                    { transform: `translate(${dx}px, ${dy}px)` },
                    { transform: 'none' },
                ],
                { duration: SLIDE_MS, easing: EASE },
            );
        }
    }, [order]);

    return {
        ref,
        /** Called just before the viewer's tap changes the order. */
        mark: () => {
            const root = ref.current;

            from.current =
                root === null || reducedMotion()
                    ? null
                    : { at: performance.now(), places: tilePlaces(root) };
        },
    };
}

/** The word's clue (`ask.clue`), as the server wrote it: content, never translated, never cased here. */
function Clue({ clue, big }: { clue: string; big: boolean }) {
    const { t } = useTranslation();

    return (
        <p
            data-clue
            className={cn(
                'flex w-full items-center border bg-card text-left shadow-xs room-motion:animate-room-enter',
                big
                    ? 'max-w-[72vw] gap-[2vh] rounded-[2.2vh] px-[2.8vh] py-[2vh]'
                    : 'gap-2.5 rounded-xl px-3 py-2',
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'flex shrink-0 items-center justify-center rounded-full bg-muted text-foreground/70',
                    big ? 'size-[7vh]' : 'size-7',
                )}
            >
                <Lightbulb className={big ? 'size-[4vh]' : 'size-4'} />
            </span>
            <span
                className={cn(
                    'min-w-0 font-medium text-pretty text-foreground/80',
                    big
                        ? 'text-[4.4vh] leading-tight'
                        : 'text-[15px] leading-snug',
                )}
            >
                <span className="sr-only">{t('rooms.anagram.clue')} </span>
                {clue}
            </span>
        </p>
    );
}

/**
 * The word's places in one recessed bar: a letter the viewer tapped
 * stands in it as a tile, a bought one as the tile inverted with a lock,
 * and the next empty place has a dashed border in `primary`. A letter
 * pops into its place when it comes — not one the page found there.
 */
function WordBar({
    places,
    big,
    label,
    still,
}: {
    places: AnagramPlace[];
    big: boolean;
    label: string;
    /** The tiles the page found in place when it was drawn: they stand still. */
    still?: ReadonlySet<string>;
}) {
    const { t } = useTranslation();
    const count = places.length;

    return (
        <ol
            aria-label={label}
            data-word-bar
            className={cn(
                'mx-auto grid w-fit max-w-full justify-center anagram-bar room-motion:animate-room-enter',
                big
                    ? 'gap-[1vh] rounded-[2.4vh] p-[1.2vh]'
                    : 'gap-1.5 rounded-2xl p-1.5',
            )}
            style={{
                gridTemplateColumns: big
                    ? `repeat(${count}, minmax(0, min(8.5vh, 5.6vw)))`
                    : `repeat(${count}, minmax(0, 2.75rem))`,
            }}
        >
            {places.map((place, index) => (
                <li
                    key={index}
                    data-place={place.kind}
                    className={cn(
                        'relative flex items-center justify-center font-semibold select-none',
                        big
                            ? 'h-[10vh] rounded-[1.2vh] text-[min(6vh,4vw)]'
                            : cn('h-12 rounded-lg', slotText(count)),
                        place.kind === 'empty' &&
                            (place.next && !big
                                ? 'border-2 border-dashed border-primary motion-safe:animate-room-breathe'
                                : 'border border-dashed border-foreground/15'),
                    )}
                >
                    {place.kind !== 'empty' && (
                        <span
                            key={place.id}
                            className={cn(
                                'absolute inset-0 flex items-center justify-center',
                                big ? 'rounded-[1.2vh]' : 'rounded-lg',
                                place.kind === 'bought'
                                    ? 'anagram-bought'
                                    : 'anagram-tile',
                                !still?.has(place.id) &&
                                    'motion-safe:animate-room-pop',
                            )}
                        >
                            {place.text}
                            {place.kind === 'bought' && (
                                <>
                                    <Lock
                                        aria-hidden
                                        strokeWidth={2.5}
                                        className="absolute top-1 right-1 size-2.5 opacity-75"
                                    />
                                    <span className="sr-only">
                                        {t('rooms.anagram.bought')}
                                    </span>
                                </>
                            )}
                        </span>
                    )}
                </li>
            ))}
        </ol>
    );
}

/** A tool under the tiles: an icon and its word, one height for the three. */
function Tool({
    tool,
    icon,
    label,
    onClick,
    disabled,
    children,
}: {
    tool: 'shuffle' | 'buy' | 'undo';
    icon: ReactNode;
    label: string;
    onClick: () => void;
    disabled: boolean;
    children?: ReactNode;
}) {
    return (
        <button
            type="button"
            data-tool={tool}
            disabled={disabled}
            onClick={onClick}
            className="relative inline-flex h-11 min-w-0 flex-1 items-center justify-center gap-1.5 rounded-xl border bg-card px-2 text-sm font-medium shadow-xs transition-[color,background-color,opacity] outline-none select-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 motion-reduce:transition-none [&_svg]:size-4 [&_svg]:shrink-0"
        >
            {icon}
            <span className="truncate">{label}</span>
            {children}
        </button>
    );
}

/** The phone's question: the clue, the word's places, the tray and the tools. */
function PhoneAsk({
    ask,
    picked,
    disabled,
    onPick,
    letters,
    tools,
}: {
    ask: RoomAsk;
    picked: string[];
    disabled: boolean;
    onPick: (id: string) => void;
    letters: RoomLetters | null;
    tools: AskTools | null;
}) {
    const { t } = useTranslation();
    const bought = letters?.bought ?? [];
    const own = picked.slice(bought.length);
    const orders = trayOrders(ask, letters);
    // The page's own count of «Qarışdır», from the first order again after each purchase.
    const [shuffled, setShuffled] = useState({ bought: 0, turns: 0 });
    const turns = shuffled.bought === bought.length ? shuffled.turns : 0;
    const order = trayOrder(orders, turns);
    const slide = useSlide(order.join(' '));
    // Letters bought before the page was drawn stand still in their places.
    const [still] = useState(() => new Set(bought));
    const texts = new Map(ask.items.map((item) => [item.id, item.text ?? '']));
    const price = priceKey(letters);

    return (
        <div className="flex w-full flex-col items-center gap-3">
            {ask.clue !== null && <Clue clue={ask.clue} big={false} />}

            <WordBar
                places={placesOf(ask, bought, own)}
                big={false}
                label={t('rooms.game.yours')}
                still={still}
            />

            {order.length > 0 && (
                <ul
                    ref={slide.ref}
                    className="flex w-full flex-wrap justify-center gap-x-2 gap-y-2.5 pb-1"
                    style={trayWidth(order.length, false)}
                >
                    {order.map((id, index) => {
                        const used = own.includes(id);

                        return (
                            <li key={id} data-tile={id}>
                                <button
                                    type="button"
                                    disabled={disabled || used}
                                    aria-pressed={used}
                                    aria-label={texts.get(id)}
                                    onClick={(event) => {
                                        press(event.currentTarget);
                                        onPick(id);
                                    }}
                                    style={{
                                        animationDelay: `${stagger(index)}ms`,
                                    }}
                                    className={cn(
                                        'flex size-14 items-center justify-center rounded-xl text-3xl font-semibold outline-none select-none focus-visible:ring-[3px] focus-visible:ring-ring/50 room-motion:animate-room-pop',
                                        used ? 'anagram-gap' : 'anagram-tile',
                                        disabled && !used && 'opacity-60',
                                    )}
                                >
                                    {/* A moved letter leaves its hollow: it now stands in the word above. */}
                                    <span className={cn(used && 'invisible')}>
                                        {texts.get(id)}
                                    </span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}

            {tools !== null && (
                <div className="flex w-full gap-2 pb-2.5">
                    {canShuffle(orders) && (
                        <Tool
                            tool="shuffle"
                            icon={<Shuffle aria-hidden />}
                            label={t('rooms.anagram.shuffle')}
                            disabled={disabled}
                            onClick={() => {
                                tools.tapped();
                                slide.mark();
                                setShuffled({
                                    bought: bought.length,
                                    turns: turns + 1,
                                });
                            }}
                        />
                    )}
                    {letters !== null && (
                        <Tool
                            tool="buy"
                            icon={
                                tools.buying ? (
                                    <Spinner />
                                ) : (
                                    <SquarePlus aria-hidden />
                                )
                            }
                            label={t('rooms.anagram.buy')}
                            disabled={
                                disabled || tools.buying || price === null
                            }
                            onClick={() => {
                                tools.tapped();
                                slide.mark();
                                tools.buy();
                            }}
                        >
                            {price !== null && (
                                <span
                                    data-price={letters.price}
                                    className="absolute -bottom-2.5 left-1/2 -translate-x-1/2 rounded-full bg-primary px-2 py-0.5 text-[11px] leading-4 font-semibold whitespace-nowrap text-primary-foreground ring-2 ring-background"
                                >
                                    {t(price)}
                                </span>
                            )}
                        </Tool>
                    )}
                    <Tool
                        tool="undo"
                        icon={<Undo2 aria-hidden />}
                        label={t('rooms.game.undo')}
                        disabled={disabled || tools.undo === null}
                        onClick={() => tools.undo?.()}
                    />
                </div>
            )}
        </div>
    );
}

/**
 * The screen's question: the clue large, the word's places empty and the
 * tiles in the first order. The room sees what the word is about, how long
 * it is and its letters — never anyone's taps or bought letters.
 */
function ScreenAsk({ ask }: { ask: RoomAsk }) {
    const { t } = useTranslation();

    return (
        <div className="flex w-full flex-col items-center gap-[4.5vh]">
            {ask.clue !== null && <Clue clue={ask.clue} big />}
            <WordBar
                places={placesOf(ask, [], [])}
                big
                label={t('rooms.game.word')}
            />
            <ul
                className="flex flex-wrap justify-center gap-x-[1.6vh] gap-y-[2vh]"
                style={trayWidth(ask.items.length, true)}
            >
                {ask.items.map((item, index) => (
                    <li
                        key={item.id}
                        style={{ animationDelay: `${stagger(index)}ms` }}
                        className="flex size-[min(13vh,9vw)] items-center justify-center rounded-[min(2.4vh,1.7vw)] text-[min(8vh,5.5vw)] font-semibold anagram-tile room-motion:animate-room-pop"
                    >
                        {item.text ?? ''}
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * The question of an anagram: on a phone the clue, the places, the tiles
 * and the tools (`tools`, for a player who may still answer); on the
 * screen (`big`) the clue, the empty places and the first order.
 */
export function AnagramAsk({
    ask,
    picked,
    disabled,
    onPick,
    big = false,
    letters = null,
    tools = null,
}: {
    ask: RoomAsk;
    picked: string[];
    disabled: boolean;
    onPick: (id: string) => void;
    big?: boolean;
    letters?: RoomLetters | null;
    tools?: AskTools | null;
}) {
    if (big) {
        return <ScreenAsk ask={ask} />;
    }

    return (
        <PhoneAsk
            ask={ask}
            picked={picked}
            disabled={disabled}
            onPick={onPick}
            letters={letters}
            tools={tools}
        />
    );
}

/** A word in coloured cells, read by screen readers as one word, with its mark under it. */
function Word({
    kind,
    letters,
    tone,
    big,
    label,
    mark,
    after,
}: {
    /** For checks (`data-word`): the round's word or the viewer's. */
    kind: 'word' | 'yours';
    letters: string[];
    tone: 'right' | 'wrong';
    big: boolean;
    label: string;
    mark: ReactNode;
    /** In a reveal the page saw begin: when its first letter comes in. */
    after: number;
}) {
    return (
        <div
            data-word={kind}
            data-tone={tone}
            className={cn(
                'flex w-full flex-col items-center gap-1.5',
                big && '@container',
            )}
        >
            <span
                className={cn(
                    'text-sm text-muted-foreground',
                    big && 'text-[2.6vh]',
                )}
            >
                {label}
            </span>
            <span className="sr-only">{letters.join('')}</span>
            <span
                aria-hidden
                className={cn(
                    'grid w-full justify-center',
                    big ? 'gap-[1vh]' : 'gap-1.5',
                )}
                style={{
                    gridTemplateColumns: big
                        ? `repeat(${letters.length}, minmax(0, 11vh))`
                        : `repeat(${letters.length}, minmax(0, 2.75rem))`,
                    // On the screen the cells share the reveal's column; a letter fills about 0.6 of its cell.
                    fontSize: big
                        ? `min(7vh, calc(100cqw / ${Math.max(1, letters.length)} * 0.6))`
                        : undefined,
                }}
            >
                {letters.map((letter, index) => (
                    <span
                        key={index}
                        style={{
                            animationDelay: `${after + stagger(index, 60, 480)}ms`,
                        }}
                        className={cn(
                            'flex items-center justify-center border font-semibold room-motion:animate-room-pop',
                            big
                                ? 'h-[12vh] rounded-[1.4vh] border-[0.3vh]'
                                : cn(
                                      'h-12 rounded-lg',
                                      slotText(letters.length),
                                  ),
                            tone === 'right'
                                ? 'border-emerald-600 bg-emerald-600/10 dark:border-emerald-400'
                                : 'border-red-600 bg-destructive/10 dark:border-red-400',
                        )}
                    >
                        {letter}
                    </span>
                ))}
            </span>
            {mark}
        </div>
    );
}

/**
 * The round's word in green cells (from `reveal.solution`, as the tiles
 * write it), and the viewer's own word by the server's verdict: the same
 * word marked «Düzgün · sizin»; another word the letters spell, green
 * under it, «Sizin · bu söz də düzgündür»; a wrong one red, «Sizin».
 */
export function AnagramSolution({
    ask,
    solution,
    mine,
    word,
    big = false,
}: {
    ask: RoomAsk;
    solution: string[];
    mine: RoomMine | null;
    word?: string | null;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const theirs = anagramMine(ask, solution, mine);
    const right = lettersOf(ask, solution);
    const letters = right.some((letter) => letter !== '')
        ? right
        : word
          ? [word]
          : [];

    return (
        <div
            className={cn(
                'flex flex-col items-center',
                big ? 'gap-[4vh]' : 'gap-4',
            )}
        >
            <Word
                kind="word"
                letters={letters}
                tone="right"
                big={big}
                label={t('rooms.game.word')}
                after={REVEAL_MS.solution}
                mark={
                    theirs === null ? null : (
                        <Mark
                            mark={{
                                right: true,
                                yours: theirs === 'same',
                                tone: 'right',
                            }}
                            big={big}
                            keepLine={false}
                        />
                    )
                }
            />
            {mine !== null && (theirs === 'other' || theirs === 'wrong') && (
                <Word
                    kind="yours"
                    letters={lettersOf(ask, mine.picked)}
                    tone={theirs === 'other' ? 'right' : 'wrong'}
                    big={big}
                    label={t('rooms.game.yours')}
                    after={REVEAL_MS.highlight}
                    mark={
                        <Mark
                            mark={{
                                right: false,
                                yours: true,
                                tone: theirs === 'other' ? 'right' : 'wrong',
                            }}
                            big={big}
                            keepLine={false}
                            words={
                                theirs === 'other'
                                    ? t('rooms.game.mark_also_right')
                                    : undefined
                            }
                        />
                    }
                />
            )}
        </div>
    );
}
