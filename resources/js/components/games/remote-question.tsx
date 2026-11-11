import type { Round } from '@/game/contract';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    round: Round;
};

/**
 * What a `count` round asks, above the phone's number buttons
 * (docs/architecture.md §2 "The count builder", ADR 012): the picture the
 * board draws and how many times it draws it, «× N», so the adult knows
 * the answer without walking to the board. Nothing shows for a round with
 * no picture to count.
 */
export function RemoteQuestion({ round }: Props) {
    const { t } = useTranslation();
    const { image, count } = round.prompt;

    if (image === null || count === null) {
        return null;
    }

    return (
        <div
            className="flex items-center justify-center gap-3"
            role="img"
            aria-label={t('games.remote.count', { count })}
        >
            <img
                src={image}
                alt=""
                className="size-20 rounded-2xl border bg-white object-contain p-2"
            />
            <span
                className="font-board text-5xl leading-none font-bold"
                aria-hidden
            >
                × {count}
            </span>
        </div>
    );
}
