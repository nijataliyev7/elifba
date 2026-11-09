import { Check, Star } from 'lucide-react';
import type { Round } from '@/game/contract';
import type { Tap } from '@/game/live';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

type Props = {
    round: Round;
    /** The round's progress on the board (`client-state`). */
    hitItemIds: readonly string[];
    taps: readonly Tap[];
    disabled: boolean;
    onTap: (itemId: string) => void;
};

/**
 * The round's items as big buttons on the phone (docs/architecture.md §4):
 * the answer is marked for the teacher, an item the board already burst is
 * done, and a tap shows its state until the board's ack — pending, then
 * correct, miss or not delivered. Item text is content, in `font-board`.
 */
export function RemoteItems({
    round,
    hitItemIds,
    taps,
    disabled,
    onTap,
}: Props) {
    const { t } = useTranslation();
    const sequence = round.solution.order === 'sequence';
    const answers = new Set(
        sequence
            ? [round.solution.item_ids[hitItemIds.length]].filter(Boolean)
            : round.solution.item_ids.filter((id) => !hitItemIds.includes(id)),
    );
    const lastTap = new Map<string, Tap>();

    for (const tap of taps) {
        if (tap.roundId === round.id) {
            lastTap.set(tap.itemId, tap);
        }
    }

    return (
        <div
            className={cn(
                'grid gap-3',
                round.items.length > 6 ? 'grid-cols-3' : 'grid-cols-2',
            )}
        >
            {round.items.map((item) => {
                const done = !sequence && hitItemIds.includes(item.id);
                const answer = answers.has(item.id);
                const tap = lastTap.get(item.id);
                const status = tap?.status;

                return (
                    <button
                        key={item.id}
                        type="button"
                        disabled={disabled || done}
                        onClick={() => onTap(item.id)}
                        aria-pressed={status === 'pending' ? true : undefined}
                        className={cn(
                            'relative flex min-h-20 min-w-0 touch-manipulation flex-col items-center justify-center gap-1 rounded-2xl border-2 bg-card p-2 font-board text-4xl font-bold shadow-xs transition-[transform,colors] select-none active:scale-95 disabled:opacity-50',
                            answer && 'border-primary ring-2 ring-primary/40',
                            !answer && 'border-border',
                            done &&
                                'border-emerald-500 bg-emerald-50 text-emerald-900 opacity-100 disabled:opacity-100 dark:bg-emerald-950 dark:text-emerald-100',
                            status === 'pending' && 'animate-pulse',
                            status === 'correct' &&
                                'border-emerald-500 bg-emerald-100 dark:bg-emerald-900',
                            status === 'miss' &&
                                'border-destructive bg-destructive/10',
                            status === 'lost' &&
                                'border-dashed border-destructive',
                        )}
                    >
                        {answer && (
                            <span className="absolute top-1 right-1 flex items-center gap-0.5 rounded-full bg-primary px-1.5 py-0.5 font-sans text-[0.625rem] font-semibold tracking-wide text-primary-foreground uppercase">
                                <Star className="size-3" aria-hidden />
                                {t('games.remote.answer')}
                            </span>
                        )}
                        {done && (
                            <Check
                                className="absolute top-1 left-1 size-5 text-emerald-600"
                                aria-hidden
                            />
                        )}
                        {item.image !== null && (
                            <img
                                src={item.image}
                                alt=""
                                className="size-12 object-contain"
                            />
                        )}
                        <span className="max-w-full truncate">{item.text}</span>
                        {status === 'lost' && (
                            <span className="font-sans text-xs font-medium text-destructive">
                                {t('games.remote.tap_lost')}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
