import { Volume2 } from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import type { Round } from '@/game/contract';

type Props = {
    round: Round | null;
    /** The pack's main colour, framing the target. */
    accent: string;
    /** 🔊: says the prompt again; null when the board says no prompts (§3 "Voice"). */
    onRepeat: (() => void) | null;
};

/**
 * The round's prompt above the canvas: what to find, big, and the sentence
 * that asks for it. Both are content (always Azerbaijani, cased by the
 * server) and set in Andika, the face made for beginning readers.
 * Smaller below `lg`, where the canvas needs the room (docs/frontend.md §6).
 * A prompt the voice says (`prompt.audio`) has a 🔊 button that says it
 * again, for a child who missed it.
 */
export function BoardPrompt({ round, accent, onRepeat }: Props) {
    const { t } = useTranslation();

    if (round === null) {
        return <div className="min-w-0 flex-1" />;
    }

    return (
        <div
            className="flex min-w-0 flex-1 items-center gap-6 max-lg:gap-3"
            lang="az"
            aria-live="polite"
        >
            {round.prompt.show !== null && (
                <span
                    className="flex h-24 min-w-24 shrink-0 items-center justify-center rounded-3xl border-[6px] bg-white px-5 font-board text-6xl leading-none font-bold max-lg:h-14 max-lg:min-w-14 max-lg:rounded-2xl max-lg:border-4 max-lg:px-3 max-lg:text-4xl xl:h-32 xl:min-w-32 xl:text-8xl"
                    style={{ borderColor: accent }}
                >
                    {round.prompt.show}
                </span>
            )}
            <p className="min-w-0 font-board text-3xl leading-tight font-bold max-lg:text-xl xl:text-5xl">
                {round.prompt.text}
            </p>
            {onRepeat !== null && round.prompt.audio !== null && (
                <button
                    type="button"
                    onClick={onRepeat}
                    aria-label={t('games.board.say_prompt')}
                    title={t('games.board.say_prompt')}
                    className="flex size-16 shrink-0 items-center justify-center rounded-full border-4 bg-white/90 transition-transform active:scale-95 max-lg:size-11 max-lg:border-2 xl:size-20"
                    style={{ borderColor: accent }}
                >
                    <Volume2 className="size-8 max-lg:size-5 xl:size-10" />
                </button>
            )}
        </div>
    );
}
