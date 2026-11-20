import { ArrowRight, Laugh } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { createPortal } from 'react-dom';
import {
    readTauntsHidden,
    shownTaunts,
    TAUNT_SENT_MS,
    tauntMoment,
    tauntPops,
    TAUNTS_UNSEEN,
    trayView,
    writeTauntsHidden,
} from '@/components/rooms/taunts';
import type {
    TauntDevice,
    TauntPop,
    TauntsSeen,
} from '@/components/rooms/taunts';
import { reducedMotion } from '@/components/rooms/motion-dom';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { store as throwTaunt } from '@/routes/rooms/taunts';
import { eventId } from '@/rooms/answer';
import type { ServerClock } from '@/rooms/clock';
import type {
    RoomPlayer,
    RoomState,
    RoomTauntReceived,
    RoomTauntShown,
} from '@/rooms/contract';
import { roomRequest } from '@/rooms/http';
import { deviceStorage } from '@/rooms/sound';
import type { TauntVoiceUrls } from '@/rooms/sound';
import { sendTaunt } from '@/rooms/taunt';

/*
 * Taunts on the pages (docs/frontend.md rule 15 "Taunts", ADR 029 as
 * amended 2026-11-19, T3: C «Rəqibə toxun», 1 «Partlayış», 85 %): the
 * strip's rival side as the throw button with its badge, the fan of
 * twelve under the strip, «Atıldı · −1 xal», the reaction in the middle of
 * the target's viewport and of the screen, the reveal's «−:count» and the
 * devices' own choice to hide them. They read the state's `taunts`,
 * `players`, the strip's rival and the prop `taunts` — never a verdict, a
 * solution or anyone's result before the reveal. Violet (`--taunt*`),
 * always with the emoji, the words and the icon. A taunt's voice is the
 * page's sound (`useRoomSound()`, `roomCues()`), not these parts'.
 */

/** The prop `taunts` of `rooms/show` and `rooms/screen`: each key with its emoji's URL and its clip's (or null), in the fan's order. */
export type TauntList = { key: string; url: string; voice: string | null }[];

/** The clips of a list, by key, for the page's sound: the taunts with a clip only. */
export function tauntVoices(list: TauntList | null): TauntVoiceUrls {
    return Object.fromEntries(
        (list ?? []).flatMap((taunt) =>
            taunt.voice === null ? [] : [[taunt.key, taunt.voice]],
        ),
    );
}

function emojiOf(list: TauntList | null, key: string): string | null {
    return list?.find((taunt) => taunt.key === key)?.url ?? null;
}

/** A taunt's emoji: a Fluent file of the list, beside its words, never alone. */
function TauntEmoji({
    url,
    className,
}: {
    url: string | null;
    className: string;
}) {
    return url === null ? (
        <span aria-hidden className={cn('shrink-0', className)} />
    ) : (
        <img
            src={url}
            alt=""
            aria-hidden
            draggable={false}
            className={cn('shrink-0 select-none', className)}
        />
    );
}

/** A player's name as the state gives it, or «Oyunçu». */
function useNameOf(players: RoomPlayer[]): (id: string) => string {
    const { t } = useTranslation();

    return (id) =>
        players.find((player) => player.id === id)?.name ??
        t('rooms.show.nameless');
}

/** This device's choice to hide taunts, read once and remembered in `localStorage` (never sent). */
export function useTauntsHidden(
    device: TauntDevice,
): [boolean, (hidden: boolean) => void] {
    const [hidden, setHidden] = useState(() =>
        readTauntsHidden(deviceStorage(), device),
    );

    return [
        hidden,
        (next) => {
            writeTauntsHidden(deviceStorage(), device, next);
            setHidden(next);
        },
    ];
}

/**
 * The reactions a page draws now and how each moves: the state's list at
 * the server's clock as the page reads it every 200 ms (`useServerNow()`,
 * the page's `now`), and for each reaction drawn, a pop when the page saw
 * it arrive (`tauntMoment()`) or null — one it opened on, or one of a
 * state fetched again — to draw it still. A pop's length is fixed the
 * first time it is drawn (`tauntPops()`), before the browser paints it.
 */
function useReactions<T extends { id: string; sent_ms: number }>(
    list: T[] | null | undefined,
    now: number | null,
    hidden: boolean,
): { shown: T[]; pops: ReadonlyMap<string, TauntPop | null> } {
    const seen = useRef<TauntsSeen>(TAUNTS_UNSEEN);
    const arrived = useRef<Set<string>>(new Set());
    const [pops, setPops] = useState<ReadonlyMap<string, TauntPop | null>>(
        () => new Map(),
    );
    const ids = (list ?? []).map((taunt) => taunt.id).join(',');
    const shown = shownTaunts(list, now, hidden);

    useLayoutEffect(() => {
        const moment = tauntMoment(
            seen.current,
            ids === '' ? [] : ids.split(','),
        );

        seen.current = moment.seen;
        moment.arrived.forEach((id) => arrived.current.add(id));
    }, [ids]);

    useLayoutEffect(() => {
        const fresh = shown.filter((taunt) => !pops.has(taunt.id));

        if (fresh.length === 0 || now === null) {
            return;
        }

        const moving = tauntPops(
            fresh.filter((taunt) => arrived.current.has(taunt.id)),
            now,
        );

        setPops((before) => {
            const next = new Map(before);

            fresh.forEach((taunt) =>
                next.set(taunt.id, moving.get(taunt.id) ?? null),
            );

            return next;
        });
    }, [shown, pops, now]);

    return { shown, pops };
}

/** A reaction's motion («Partlayış»): its length and delay, or nothing for one drawn still. */
function popStyle(pop: TauntPop | null | undefined): CSSProperties | undefined {
    return pop === null || pop === undefined
        ? undefined
        : {
              animationDuration: `${pop.duration}ms`,
              animationDelay: `${pop.delay}ms`,
          };
}

/** The layer over the whole viewport a reaction is drawn in: above everything, taking no tap. */
function ReactionLayer({
    className,
    children,
}: {
    className: string;
    children: ReactNode;
}) {
    if (typeof document === 'undefined') {
        return null;
    }

    return createPortal(
        <div
            role="status"
            className={cn(
                'pointer-events-none fixed inset-0 z-[70] flex items-center justify-center select-none',
                className,
            )}
        >
            {children}
        </div>,
        document.body,
    );
}

/**
 * A received taunt on the target's phone (ADR 029 decisions 39–41, 43): a
 * reaction in the middle of the whole viewport, over the game frame's top
 * bar and the round (ADR 032), with no card — the emoji, the thrower's name and the
 * phrase in the page's locale, at `--taunt-reaction-opacity`, the words
 * haloed in the page's background. It takes no tap (`pointer-events:
 * none`), moves nothing, and stays until `sent_ms + TAUNT_REACTION_MS` by
 * the estimated server clock; two stand one above the other, the older
 * first, each smaller. A live region that is always there announces it.
 */
export function TauntReaction({
    received,
    players,
    list,
    now,
    hidden,
}: {
    received: RoomTauntReceived[] | null | undefined;
    players: RoomPlayer[];
    list: TauntList | null;
    /** The server's clock as the page estimates it (`useServerNow()`). */
    now: number | null;
    hidden: boolean;
}) {
    const { t } = useTranslation();
    const nameOf = useNameOf(players);
    const { shown, pops } = useReactions(received, now, hidden);
    const two = shown.length > 1;

    return (
        <ReactionLayer
            className={cn('flex-col px-4.5', two ? 'gap-4.5' : 'gap-6.5')}
        >
            {shown.map((taunt) => (
                <div
                    key={taunt.id}
                    className="flex max-w-full flex-col items-center opacity-(--taunt-reaction-opacity)"
                >
                    <div
                        className={cn(
                            'flex max-w-full flex-col items-center gap-0.5 text-center',
                            pops.get(taunt.id) &&
                                'motion-safe:animate-room-taunt-pop',
                        )}
                        style={popStyle(pops.get(taunt.id))}
                    >
                        <TauntEmoji
                            url={emojiOf(list, taunt.taunt)}
                            className={cn(
                                'mb-1.5 drop-shadow-[0_6px_14px_rgb(0_0_0/0.18)]',
                                two ? 'size-23' : 'size-34',
                            )}
                        />
                        <span className="max-w-75 truncate text-[0.9375rem] font-bold text-(--taunt-text) taunt-halo">
                            {nameOf(taunt.from)}
                        </span>
                        <span
                            className={cn(
                                'max-w-80 leading-[1.12] font-extrabold tracking-[-0.01em] text-balance text-foreground taunt-halo',
                                two ? 'text-[1.4375rem]' : 'text-[1.875rem]',
                            )}
                        >
                            {t(`rooms.taunts.say.${taunt.taunt}`)}
                        </span>
                    </div>
                </div>
            ))}
        </ReactionLayer>
    );
}

/**
 * The screen's taunts (ADR 029 decision 42): each of the state's `shown`
 * drawn the same way over the whole screen, centred — the emoji,
 * «:from → :to» and the phrase in the screen's locale, at
 * `--taunt-reaction-opacity` with the halo, for the same time; two side by
 * side, the older on the left. It takes no tap and writes nothing.
 */
export function ScreenTauntReaction({
    shown: list,
    players,
    taunts,
    now,
    hidden,
}: {
    shown: RoomTauntShown[] | null | undefined;
    players: RoomPlayer[];
    taunts: TauntList | null;
    /** The server's clock as the page estimates it (`useServerNow()`). */
    now: number | null;
    hidden: boolean;
}) {
    const { t } = useTranslation();
    const nameOf = useNameOf(players);
    const { shown, pops } = useReactions(list, now, hidden);
    const two = shown.length > 1;

    return (
        <ReactionLayer className="flex-row gap-[8vh] p-[6vh]">
            {shown.map((taunt) => (
                <div
                    key={taunt.id}
                    className="flex min-w-0 flex-col items-center opacity-(--taunt-reaction-opacity)"
                >
                    <div
                        className={cn(
                            'flex min-w-0 flex-col items-center gap-[0.4vh] text-center',
                            pops.get(taunt.id) &&
                                'motion-safe:animate-room-taunt-pop',
                        )}
                        style={popStyle(pops.get(taunt.id))}
                    >
                        <TauntEmoji
                            url={emojiOf(taunts, taunt.taunt)}
                            className={cn(
                                'mb-[1vh] drop-shadow-[0_1vh_2vh_rgb(0_0_0/0.18)]',
                                two ? 'size-[19vh]' : 'size-[26vh]',
                            )}
                        />
                        <span className="flex max-w-full min-w-0 items-center gap-[0.6vh] text-[max(0.9375rem,3.2vh)] font-bold text-(--taunt-text) taunt-halo">
                            <span className="truncate">
                                {nameOf(taunt.from)}
                            </span>
                            <ArrowRight
                                aria-label="→"
                                className="size-[0.9em] shrink-0"
                            />
                            <span className="truncate">{nameOf(taunt.to)}</span>
                        </span>
                        <span
                            className={cn(
                                'leading-[1.12] font-extrabold tracking-[-0.01em] text-balance text-foreground taunt-halo',
                                two
                                    ? 'max-w-[58vh] text-[max(1.4375rem,5vh)]'
                                    : 'max-w-[80vh] text-[max(1.875rem,6.4vh)]',
                            )}
                        >
                            {t(`rooms.taunts.say.${taunt.taunt}`)}
                        </span>
                    </div>
                </div>
            ))}
        </ReactionLayer>
    );
}

/**
 * The screen's «Atmacalar» in its top bar, before the sound (ADR 029
 * decision 21): a toggle (`aria-pressed`: taunts are shown) that hides
 * them on this screen only, remembered in `localStorage`.
 */
export function ScreenTauntsToggle({
    hidden,
    onChange,
}: {
    hidden: boolean;
    onChange: (hidden: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <button
            type="button"
            aria-pressed={!hidden}
            onClick={() => onChange(!hidden)}
            className={cn(
                'inline-flex h-11 min-w-11 items-center justify-center gap-2 rounded-md border bg-background px-3 text-sm font-medium shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                hidden
                    ? 'text-muted-foreground'
                    : 'border-(--taunt) text-(--taunt-text)',
            )}
        >
            <Laugh aria-hidden className="size-4" />
            {t('rooms.taunts.switch')}
        </button>
    );
}

/**
 * «−:count» with the taunt's icon (ADR 029 decision 6): the taunts counted
 * at this close, beside a round's result in «Sıralama» and in the race
 * lanes; «Atmacalara :count xal» to a screen reader.
 */
export function SpentMark({
    count,
    className,
}: {
    count: number;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <span
            aria-label={t('rooms.taunts.spent_label', { count })}
            className={cn(
                'inline-flex items-center gap-0.5 font-bold whitespace-nowrap text-(--taunt-text) tabular-nums',
                className,
            )}
        >
            <Laugh aria-hidden className="size-[0.95em]" />
            <span aria-hidden>−{count}</span>
        </span>
    );
}

/** «Atmaca: −:count xal» under the thrower's own verdict in the reveal. */
export function SpentLine({ count }: { count: number }) {
    const { t } = useTranslation();

    return (
        <span className="flex items-center gap-1 font-semibold text-(--taunt-text)">
            <Laugh aria-hidden className="size-3.5" />
            {t('rooms.taunts.spent', { count })}
        </span>
    );
}

/** The players' costs counted at this close, by row (the state's `reveal.spent`). */
export function spentOf(state: RoomState): Map<string, number> {
    const reveal =
        state.round !== null && state.round.phase === 'reveal'
            ? state.round.reveal
            : null;

    return new Map(
        (reveal?.spent ?? []).map((spent) => [spent.player_id, spent.count]),
    );
}

type Refusal = 'late' | 'off' | 'cooling' | 'no_points';

/**
 * The throw (ADR 029 decisions 36–38): the strip's rival side — its right
 * half, the rival's name, number and bar end — is one button laid over the
 * strip, with a 28px badge on the strip's bottom-right corner; it opens
 * the fan under the strip, the strip's width, over the thrower's own round
 * head and question — «Kimə: :name», the price, the balance or the
 * reason, the twelve taunts as a 4 × 3 grid and «Mənə atılanları gizlət».
 * The badge is muted with no points or before the first close (a tap still
 * opens the fan and says why) and is the cooldown's ring while it runs. A
 * tap throws at the strip's rival; the fan stays open until the receipt
 * and says a refusal in itself; on `accepted` «Atıldı · −1 xal» lies over
 * the strip's gap row for 2 s and «−1» rises once from the badge (not
 * under reduced motion), and the page asks for the state. The button
 * again, a tap outside, Escape or a new phase of the round closes the fan.
 * Drawn inside the strip's own `relative` box; the strip under it never
 * moves.
 */
export function TauntThrow({
    roomId,
    state,
    rival,
    list,
    clock,
    now,
    phase,
    hidden,
    onHiddenChange,
    onThrown,
    noPoints = 'rooms.taunts.no_points',
}: {
    roomId: string;
    state: RoomState;
    /** The strip's rival on the right: whom the button throws at. */
    rival: { id: string; name: string | null } | null;
    list: TauntList | null;
    /** The page's estimate of the server's clock: a taunt's event id. */
    clock: ServerClock;
    /** The server's clock as the page reads it every 200 ms (`useServerNow()`). */
    now: number | null;
    /** The round's index and phase: a new one closes the fan. */
    phase: string;
    hidden: boolean;
    onHiddenChange: (hidden: boolean) => void;
    /** After a receipt: the page asks for the state now. */
    onThrown: () => void;
    /** What the fan says at a balance of 0: a match's «Bu oyunda atmaca haqqınız qalmayıb» (ADR 034 decision 22). */
    noPoints?: 'rooms.taunts.no_points' | 'rooms.taunts.none_left';
}) {
    const { t } = useTranslation();
    // The fan is open in the phase it was opened in only.
    const [openIn, setOpenIn] = useState<string | null>(null);
    const open = openIn === phase;
    const [sending, setSending] = useState(false);
    const [refusal, setRefusal] = useState<Refusal | null>(null);
    const [sent, setSent] = useState<{ key: string; at: number } | null>(null);
    const button = useRef<HTMLButtonElement>(null);
    const fan = useRef<HTMLDivElement>(null);
    const view = trayView(state, rival?.id ?? null, now);
    const cooling = view.kind === 'tray' ? view.cooling : null;
    const readyMs = state.taunts?.ready_ms ?? null;
    // The cooldown's length as the page first saw it: the ring fills from there.
    const [cool, setCool] = useState<{ ready: number; total: number } | null>(
        null,
    );

    useEffect(() => {
        if (readyMs !== null && now !== null && cool?.ready !== readyMs) {
            setCool({ ready: readyMs, total: Math.max(1, readyMs - now) });
        }
    }, [readyMs, now, cool]);

    useEffect(() => {
        if (sent === null) {
            return;
        }

        const timer = window.setTimeout(() => setSent(null), TAUNT_SENT_MS);

        return () => window.clearTimeout(timer);
    }, [sent]);

    // A tap outside, or Escape, closes it.
    useEffect(() => {
        if (!open) {
            return;
        }

        const outside = (event: PointerEvent) => {
            const target = event.target as Node | null;

            if (
                target !== null &&
                !fan.current?.contains(target) &&
                !button.current?.contains(target)
            ) {
                setOpenIn(null);
            }
        };
        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpenIn(null);
                button.current?.focus();
            }
        };

        document.addEventListener('pointerdown', outside);
        document.addEventListener('keydown', escape);

        return () => {
            document.removeEventListener('pointerdown', outside);
            document.removeEventListener('keydown', escape);
        };
    }, [open]);

    if (view.kind === 'none' || rival === null) {
        return null;
    }

    const name = rival.name ?? t('rooms.show.nameless');
    const left =
        readyMs === null || now === null ? 0 : Math.max(0, readyMs - now);
    const fraction =
        cool === null || cool.ready !== readyMs
            ? 1
            : Math.min(1, left / cool.total);
    const canThrow = view.reason === null && !sending;
    const muted = view.reason === 'first_round' || view.reason === 'no_points';
    const line: ReactNode =
        refusal !== null && refusal !== 'cooling' && refusal !== 'no_points'
            ? t(`rooms.taunts.${refusal}`)
            : view.reason === 'first_round'
              ? t('rooms.taunts.first_round')
              : view.reason === 'no_points'
                ? t(noPoints)
                : null;

    const send = (key: string) => {
        if (!canThrow) {
            return;
        }

        setSending(true);
        setRefusal(null);
        void sendTaunt({
            taunt: {
                event_id: eventId(clock.now() ?? 0),
                target_id: rival.id,
                taunt: key,
            },
            post: (taunt) => roomRequest('POST', throwTaunt.url(roomId), taunt),
            wait: (ms) =>
                new Promise((resolve) => window.setTimeout(resolve, ms)),
        })
            .then((outcome) => {
                if (outcome.kind !== 'receipt') {
                    setRefusal('late');

                    return;
                }

                const status = outcome.receipt.status;

                if (status === 'accepted' || status === 'duplicate') {
                    setOpenIn(null);
                    setSent({ key, at: Date.now() });
                } else {
                    setRefusal(status);
                }
            })
            .finally(() => {
                setSending(false);
                onThrown();
            });
    };

    return (
        <>
            <button
                ref={button}
                type="button"
                aria-label={t('rooms.taunts.at', { name })}
                aria-expanded={open}
                onClick={() => {
                    setRefusal(null);
                    setOpenIn(open ? null : phase);
                }}
                className="absolute inset-y-0 right-0 z-[5] min-h-11 w-1/2 rounded-r-[14px] outline-0 -outline-offset-2 outline-(--taunt) outline-dashed hover:outline-2 focus-visible:outline-2 aria-expanded:outline-2"
            >
                <span
                    aria-hidden
                    className={cn(
                        'absolute -right-1.5 -bottom-2 grid size-7 place-items-center rounded-full ring-[3px] ring-background',
                        cooling !== null
                            ? 'bg-card'
                            : muted
                              ? 'bg-muted text-muted-foreground'
                              : 'bg-(--taunt) text-white',
                    )}
                >
                    {cooling !== null ? (
                        <span
                            className="grid size-7 place-items-center rounded-full text-xs font-extrabold text-(--taunt-text) tabular-nums"
                            style={{
                                background: `radial-gradient(circle, var(--card) 0 9.5px, transparent 10px), conic-gradient(var(--taunt) ${(1 - fraction) * 360}deg, var(--border) 0)`,
                            }}
                        >
                            {cooling}
                        </span>
                    ) : (
                        <Laugh className="size-4" />
                    )}
                    {sent !== null && !reducedMotion() && (
                        <span className="pointer-events-none absolute right-0 bottom-full text-[0.9375rem] font-extrabold text-(--taunt-text) opacity-0 motion-safe:animate-room-taunt-rise">
                            −1
                        </span>
                    )}
                </span>
            </button>

            {sent !== null && (
                <span className="pointer-events-none absolute inset-x-0 bottom-1.5 z-20 flex justify-center">
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-(--taunt) py-0.5 pr-2.5 pl-1 text-[0.78125rem] font-bold text-white ring-2 ring-card">
                        <TauntEmoji
                            url={emojiOf(list, sent.key)}
                            className="size-4.5"
                        />
                        {t('rooms.taunts.sent')}
                    </span>
                </span>
            )}

            {open && (
                <div
                    ref={fan}
                    className="absolute top-[calc(100%+6px)] right-0 left-0 z-30 flex flex-col gap-2 rounded-[20px] rounded-tr-lg border bg-card px-2.5 pt-2.5 pb-1 shadow-[0_16px_40px_rgb(0_0_0/0.24)] dark:shadow-[0_16px_40px_rgb(0_0_0/0.65)]"
                >
                    <div className="flex items-center justify-between gap-2 text-[0.84375rem]">
                        <span className="truncate">
                            {t('rooms.taunts.to', { name })}
                        </span>
                        <span className="inline-flex shrink-0 items-center gap-1 text-[0.78125rem] font-bold text-(--taunt-text)">
                            <Laugh aria-hidden className="size-3.5" />
                            {t('rooms.taunts.price')}
                        </span>
                    </div>
                    {line !== null ? (
                        <p className="rounded-[10px] bg-muted px-2.5 py-2 text-[0.78125rem] font-semibold">
                            {line}
                        </p>
                    ) : (
                        <p className="flex flex-wrap justify-between gap-x-2 text-[0.78125rem] text-muted-foreground">
                            <span>
                                {t('rooms.taunts.balance', {
                                    count: view.balance,
                                })}
                            </span>
                            {cooling !== null && (
                                <span className="font-semibold text-(--taunt-text)">
                                    {t('rooms.taunts.cooling', {
                                        seconds: cooling,
                                    })}
                                </span>
                            )}
                        </p>
                    )}
                    <div className="grid grid-cols-4 gap-1">
                        {(list ?? []).map((taunt) => (
                            <button
                                key={taunt.key}
                                type="button"
                                disabled={!canThrow}
                                onClick={() => send(taunt.key)}
                                className="flex min-h-16 flex-col items-center justify-start gap-0.75 rounded-[14px] bg-(--taunt-tint) px-0.75 pt-1.5 pb-1.25 text-center text-[0.6875rem] leading-[1.15] font-semibold wrap-anywhere outline-none hover:bg-(--taunt-tint-strong) hover:outline-[1.5px] hover:outline-(--taunt) focus-visible:bg-(--taunt-tint-strong) focus-visible:outline-[1.5px] focus-visible:outline-(--taunt) disabled:cursor-default disabled:opacity-45 disabled:grayscale-[0.7] disabled:hover:bg-(--taunt-tint) disabled:hover:outline-0"
                            >
                                <TauntEmoji
                                    url={taunt.url}
                                    className="size-8.5"
                                />
                                {t(`rooms.taunts.say.${taunt.key}`)}
                            </button>
                        ))}
                    </div>
                    <button
                        type="button"
                        role="switch"
                        aria-checked={hidden}
                        onClick={() => onHiddenChange(!hidden)}
                        className="flex min-h-11 items-center gap-2.5 text-left text-[0.8125rem] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <span
                            aria-hidden
                            className={cn(
                                'relative h-5 w-9 shrink-0 rounded-full transition-colors motion-reduce:transition-none',
                                hidden ? 'bg-primary' : 'bg-input',
                            )}
                        >
                            <span
                                className={cn(
                                    'absolute top-0.5 left-0.5 size-4 rounded-full bg-background shadow-sm transition-transform motion-reduce:transition-none',
                                    hidden && 'translate-x-4',
                                )}
                            />
                        </span>
                        {t('rooms.taunts.hide')}
                    </button>
                </div>
            )}
        </>
    );
}
