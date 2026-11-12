import { Lock } from 'lucide-react';
import { useId } from 'react';
import { GameScene } from '@/components/games/game-scene';
import { useTranslation } from '@/hooks/use-translation';
import { cardLine } from '@/lib/setup-choices';
import type { CatalogCard, Line } from '@/lib/setup-choices';
import { cn } from '@/lib/utils';

/** A line as text: a translation key with its values, or the server's own words. */
export function useLine(): (line: Line) => string {
    const { t } = useTranslation();

    return (line) => ('text' in line ? line.text : t(line.key, line.values));
}

/**
 * A card of the catalog (docs/frontend.md rule 13): a game in a theme, as a
 * store shows it — its scene, its name (from the server, «Qatarla cümlə
 * qur») and one muted line. No border and no box around the text. A locked
 * card is greyed with a lock and says why in its line; it stays focusable
 * and opens its game page, which says the rest.
 */
export function GameCard({
    card,
    onOpen,
}: {
    card: CatalogCard;
    onOpen: () => void;
}) {
    const say = useLine();
    const id = useId();
    const locked = !card.startable;

    return (
        <button
            type="button"
            onClick={onOpen}
            aria-label={card.card.title}
            aria-describedby={id}
            aria-disabled={locked || undefined}
            className="group flex w-[152px] shrink-0 snap-start flex-col gap-[7px] rounded-[22px] text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-offset-2 focus-visible:ring-offset-background @min-[700px]:w-[186px]"
        >
            <span className="relative block">
                <GameScene
                    pack={card.pack}
                    unit={card.card.unit}
                    mechanic={card.card.mechanic}
                    sample={card.card.sample}
                    className={cn(
                        'rounded-[22px] motion-safe:transition-[transform,box-shadow] motion-safe:duration-150',
                        locked
                            ? 'opacity-45 grayscale'
                            : '[@media(hover:hover)]:group-hover:shadow-lg motion-safe:[@media(hover:hover)]:group-hover:-translate-y-0.5',
                    )}
                />
                {locked && (
                    <span className="absolute top-[9px] right-[9px] grid size-[30px] place-items-center rounded-full bg-white/90 text-[#2b2f2c] shadow-sm">
                        <Lock aria-hidden className="size-4" />
                    </span>
                )}
            </span>
            <span
                className={cn(
                    'px-0.5 text-[14.5px] leading-tight font-semibold text-balance',
                    locked && 'text-muted-foreground',
                )}
            >
                {card.card.title}
            </span>
            <span
                id={id}
                className="-mt-[3px] flex items-start gap-1 px-0.5 text-[12.5px] leading-snug text-muted-foreground"
            >
                {locked && (
                    <Lock aria-hidden className="mt-0.5 size-3 shrink-0" />
                )}
                {say(cardLine(card))}
            </span>
        </button>
    );
}
