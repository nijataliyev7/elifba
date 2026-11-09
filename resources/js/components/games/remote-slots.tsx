import type { Round } from '@/game/contract';
import { slotTexts } from '@/game/slots';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

type Props = {
    round: Round;
    /** The round's progress on the board (`client-state`). */
    hitItemIds: readonly string[];
};

/**
 * What a build round asks and how far the board has built it, above the
 * phone's tiles (docs/architecture.md §4): the word's picture or the
 * syllable to build, then the slots filled so far, the next one marked,
 * and the fixed `.`, `?` or `!` after the last. Content, in `font-board`.
 */
export function RemoteSlots({ round, hitItemIds }: Props) {
    const { t } = useTranslation();
    const texts = slotTexts(round, hitItemIds);
    const filled = texts.filter((text) => text !== null).length;
    const next = texts.indexOf(null);

    return (
        <div className="flex flex-col items-center gap-3" lang="az">
            {round.prompt.image !== null && (
                <img
                    src={round.prompt.image}
                    alt={t('games.remote.picture')}
                    className="size-24 rounded-2xl border bg-white object-contain p-2"
                />
            )}
            {round.prompt.show !== null && (
                <span className="rounded-2xl border-4 border-primary bg-card px-4 py-1 font-board text-5xl leading-tight font-bold">
                    {round.prompt.show}
                </span>
            )}
            <div className="flex max-w-full min-w-0 items-end gap-1">
                <ol
                    className="flex min-w-0 flex-wrap justify-center gap-2"
                    aria-label={t('games.remote.slots', {
                        filled,
                        count: texts.length,
                    })}
                >
                    {texts.map((text, index) => (
                        <li
                            key={index}
                            className={cn(
                                'flex h-14 min-w-14 items-center justify-center rounded-xl border-2 px-2 font-board text-3xl font-bold',
                                text === null
                                    ? 'border-dashed border-muted-foreground/40 bg-muted/40'
                                    : 'border-emerald-500 bg-emerald-50 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-100',
                                index === next &&
                                    'border-solid border-primary ring-2 ring-primary/40',
                            )}
                        >
                            {text ?? (
                                <span className="sr-only">
                                    {t('games.remote.slot_empty')}
                                </span>
                            )}
                        </li>
                    ))}
                </ol>
                {round.prompt.tail !== null && (
                    <span
                        className="font-board text-4xl leading-none font-bold"
                        aria-hidden
                    >
                        {round.prompt.tail}
                    </span>
                )}
            </div>
        </div>
    );
}
