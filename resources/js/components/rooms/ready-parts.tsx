import { Check, Hand, Hourglass } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { readyTally } from '@/components/rooms/ready';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { update as setReady } from '@/routes/rooms/ready';
import type { RoomPlayer } from '@/rooms/contract';
import { roomRequest } from '@/rooms/http';

/** A player's «Hazıram» as the phone shows and changes it (`useRoomReady()`). */
export type RoomReady = {
    /** The state's mark, or the tap not yet in the state. */
    on: boolean;
    failed: boolean;
    toggle: () => void;
};

/**
 * A player's «Hazıram» between games (docs/frontend.md rule 15 "Ready for
 * the next game", ADR 035 decision 7): a tap sends `rooms.ready.update`
 * `{on}` at once and shows the asked value until the state's `ready`
 * moves — the state then says it, on this phone and on the host's and the
 * screen's, as `useTauntsSwitch()` does for the host's switch. Once the
 * state has moved the tap is spent, so a later change the server makes —
 * a tap on another device of the same account — is drawn as it is. A
 * refusal or a failure leaves the state's value standing; a tap while one
 * is in flight does nothing.
 */
export function useRoomReady({
    roomId,
    ready,
    onChanged,
}: {
    roomId: string;
    /** The viewer's mark, from the state. */
    ready: boolean;
    /** After a write: the page asks for the state now rather than wait for the signal. */
    onChanged: () => void;
}): RoomReady {
    const [tap, setTap] = useState<{ on: boolean; over: boolean } | null>(null);
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);

    if (tap !== null && tap.over !== ready) {
        setTap(null);
    }

    const shown = tap !== null && tap.over === ready ? tap.on : ready;

    const toggle = () => {
        if (busy) {
            return;
        }

        setTap({ on: !shown, over: ready });
        setBusy(true);
        setFailed(false);
        void roomRequest('PUT', setReady.url(roomId), { on: !shown })
            .then((reply) => {
                if (reply.status !== 204) {
                    setFailed(true);
                    setTap(null);
                }
            })
            .catch(() => {
                setFailed(true);
                setTap(null);
            })
            .finally(() => {
                setBusy(false);
                onChanged();
            });
    };

    return { on: shown, failed, toggle };
}

/**
 * The call (ADR 035 decisions 14–18 as amended 2026-11-21, docs/frontend.md
 * rule 15 "Ready for the next game" → "The call"): a player's bar as one
 * raised card in the room's right colour — a round badge with `Hand`, the
 * question («Oynamağa hazırsınız?» before the room's first game,
 * «Yenidən oynamağa hazırsınız?» after one) with the waiting line under
 * it, and the toggle, 56px: filled «Hazıram» with `Hand`; pressed,
 * `outline` «Hazırsınız» with `Check`, the badge's `Check` and «Aparıcı
 * hazır olduğunuzu görür.». A tap on the pressed one takes the mark back.
 *
 * `entrance`, on a page that saw the game end: the ms after which the card
 * comes — until then the bar holds the waiting line alone — and rises,
 * its hand waving (the phone's chime is `roomCues()`'s, at the same
 * `readyCallAt()`). Null: the page opened on it, so it stands at once and
 * still. Not pressed, its halo pulses; under reduced motion nothing moves.
 */
export function ReadyCall({
    ready,
    again,
    waiting,
    entrance,
}: {
    ready: RoomReady;
    /** A game has been played in the room. */
    again: boolean;
    /** The waiting line: «Aparıcı başladanda oyun başlayacaq.» or «Aparıcı növbəti oyunu seçir.». */
    waiting: string;
    entrance: number | null;
}) {
    const { t } = useTranslation();
    const [come, setCome] = useState(entrance === null);

    useEffect(() => {
        if (entrance === null) {
            return;
        }

        const timer = window.setTimeout(() => setCome(true), entrance);

        return () => window.clearTimeout(timer);
    }, [entrance]);

    const line = (
        <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
            <Hourglass aria-hidden className="size-3.5 shrink-0" />
            {waiting}
        </span>
    );

    if (!come) {
        return (
            <p className="flex min-h-11 items-center justify-center">{line}</p>
        );
    }

    const rises = entrance !== null;

    return (
        <div
            data-ready-call={ready.on ? 'pressed' : 'waiting'}
            className={cn(
                'flex flex-col gap-3 rounded-2xl border-2 p-3 shadow-[0_-6px_24px_rgb(0_0_0/0.10)] dark:shadow-[0_-6px_24px_rgb(0_0_0/0.5)]',
                ready.on
                    ? 'border-emerald-500/35 bg-emerald-50/60 dark:border-emerald-400/30 dark:bg-emerald-950/30'
                    : 'border-emerald-500 bg-emerald-50 dark:border-emerald-400 dark:bg-emerald-950/50',
                rises && 'motion-safe:animate-room-call-rise',
            )}
        >
            <div className="flex items-center gap-3">
                <span
                    aria-hidden
                    className="grid size-11 shrink-0 place-items-center rounded-full bg-emerald-700 text-white"
                >
                    {ready.on ? (
                        <Check className="size-6" />
                    ) : (
                        <Hand
                            className={cn(
                                'size-6 origin-[60%_90%]',
                                rises && 'motion-safe:animate-room-call-wave',
                            )}
                        />
                    )}
                </span>
                <span className="flex min-w-0 flex-col gap-0.5">
                    <span className="text-[1.0625rem] leading-snug font-semibold">
                        {ready.on
                            ? t('rooms.ready.seen')
                            : t(
                                  again
                                      ? 'rooms.ready.ask_again'
                                      : 'rooms.ready.ask',
                              )}
                    </span>
                    {line}
                </span>
            </div>
            <Button
                type="button"
                variant={ready.on ? 'outline' : 'default'}
                aria-pressed={ready.on}
                className={cn(
                    'h-14 w-full rounded-xl text-lg font-semibold',
                    ready.on
                        ? 'border-emerald-600/50 dark:border-emerald-400/40'
                        : 'bg-emerald-700 text-white hover:bg-emerald-800 motion-safe:animate-room-call-halo dark:bg-emerald-700 dark:hover:bg-emerald-600',
                )}
                onClick={ready.toggle}
            >
                {ready.on ? (
                    <Check className="size-5" />
                ) : (
                    <Hand className="size-5" />
                )}
                {t(ready.on ? 'rooms.ready.pressed' : 'rooms.ready.button')}
            </Button>
            {ready.failed && (
                <InputError
                    className="text-center"
                    message={t('rooms.ready.failed')}
                />
            )}
        </div>
    );
}

/** `Check` and «Hazır» after a ready player's name on a chip — the word and the icon, never a colour alone. */
export function ReadyMark({ big = false }: { big?: boolean }) {
    const { t } = useTranslation();

    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center font-medium text-emerald-700 dark:text-emerald-300',
                big
                    ? 'ml-[1vh] gap-[0.4vh] align-middle text-[0.75em] font-semibold'
                    : 'gap-1 text-xs',
            )}
        >
            <Check aria-hidden className={big ? 'size-[1em]' : 'size-3.5'} />
            {t('rooms.ready.mark')}
        </span>
    );
}

/**
 * «:ready / :total hazırdır» with `Check`, muted, by the start: `:total`
 * the present players who are not the host (`readyTally()`); nothing while
 * there is none.
 */
export function ReadyCount({
    players,
    className,
}: {
    players: readonly RoomPlayer[];
    className?: string;
}) {
    const { t } = useTranslation();
    const { ready, total } = readyTally(players);

    if (total === 0) {
        return null;
    }

    return (
        <p
            className={cn(
                'flex items-center justify-center gap-1.5 text-sm text-muted-foreground tabular-nums',
                className,
            )}
        >
            <Check
                aria-hidden
                className="size-[1.1em] shrink-0 text-emerald-700 dark:text-emerald-300"
            />
            {t('rooms.ready.count', { ready, total })}
        </p>
    );
}
