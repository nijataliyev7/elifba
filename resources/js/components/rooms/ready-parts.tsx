import { Check, Hand } from 'lucide-react';
import { useState } from 'react';
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
 * The question and the toggle in a player's bar: «Oynamağa hazırsınız?»
 * before the room's first game, «Yenidən oynamağa hazırsınız?» after one;
 * «Hazıram» with `Hand`, pressed «Hazırsınız» with `Check`, 52px and the
 * bar's width. A tap on the pressed one takes the mark back.
 */
export function ReadyToggle({
    ready,
    again,
}: {
    ready: RoomReady;
    /** A game has been played in the room. */
    again: boolean;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col gap-2">
            <p className="text-center text-sm font-medium">
                {t(again ? 'rooms.ready.ask_again' : 'rooms.ready.ask')}
            </p>
            <Button
                type="button"
                variant={ready.on ? 'default' : 'outline'}
                aria-pressed={ready.on}
                className="h-13 w-full text-base"
                onClick={ready.toggle}
            >
                {ready.on ? <Check /> : <Hand />}
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
