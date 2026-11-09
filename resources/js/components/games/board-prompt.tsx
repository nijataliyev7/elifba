import type { Round } from '@/game/contract';

type Props = {
    round: Round | null;
    /** The pack's main colour, framing the target. */
    accent: string;
};

/**
 * The round's prompt above the canvas: what to find, big, and the sentence
 * that asks for it. Both are content (always Azerbaijani, cased by the
 * server) and set in Andika, the face made for beginning readers.
 */
export function BoardPrompt({ round, accent }: Props) {
    if (round === null) {
        return <div className="min-w-0 flex-1" />;
    }

    return (
        <div
            className="flex min-w-0 flex-1 items-center gap-6"
            lang="az"
            aria-live="polite"
        >
            {round.prompt.show !== null && (
                <span
                    className="flex h-24 min-w-24 shrink-0 items-center justify-center rounded-3xl border-[6px] bg-white px-5 font-board text-6xl leading-none font-bold xl:h-32 xl:min-w-32 xl:text-8xl"
                    style={{ borderColor: accent }}
                >
                    {round.prompt.show}
                </span>
            )}
            <p className="min-w-0 font-board text-3xl leading-tight font-bold xl:text-5xl">
                {round.prompt.text}
            </p>
        </div>
    );
}
