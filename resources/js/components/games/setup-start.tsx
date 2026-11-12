import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

type Props = {
    /** The chosen theme's cover. */
    cover: string;
    /** «:level · :mechanic». */
    game: string;
    /** The theme and the extras that apply, joined. */
    details: string;
    /** Who plays («1A sinfi», «Oynayır: Aysel»), for the card. */
    players?: string;
    label: string;
    processing: boolean;
    /** The setup page: above the tab bar, and a card from `lg`; else the guest board's bar at the bottom. */
    page: boolean;
};

/**
 * What starts and the button that starts it (docs/frontend.md rule 13): a
 * bar stuck to the bottom below `lg` (above the tab bar on the setup page,
 * over the safe area on the guest board), and on the setup page from `lg`
 * a card stuck to the top of the second column.
 */
export function SetupStart({
    cover,
    game,
    details,
    players,
    label,
    processing,
    page,
}: Props) {
    const { t } = useTranslation();
    const button = (
        <Button
            type="submit"
            size="lg"
            disabled={processing}
            className="h-12 w-full text-base"
        >
            {processing && <Spinner />}
            {label}
        </Button>
    );

    return (
        <>
            <div
                className={cn(
                    'sticky z-10 -mx-4 mt-8 flex flex-col gap-3 border-t bg-background/95 px-4 pt-3 backdrop-blur-sm',
                    page
                        ? 'bottom-[calc(4rem+env(safe-area-inset-bottom))] pb-3 md:-mx-6 md:px-6 lg:hidden'
                        : 'bottom-0 pb-[calc(0.75rem+env(safe-area-inset-bottom))]',
                )}
            >
                <div className="flex min-w-0 items-center gap-3">
                    <img
                        src={cover}
                        alt=""
                        width={40}
                        height={40}
                        className="size-10 shrink-0 object-contain"
                    />
                    <div className="min-w-0 flex-1">
                        <p className="truncate font-semibold">{game}</p>
                        <p className="truncate text-sm text-muted-foreground">
                            {details}
                        </p>
                    </div>
                </div>
                {button}
            </div>

            {page && (
                <aside
                    aria-labelledby="setup-starting"
                    className="sticky top-[4.5rem] hidden flex-col gap-4 rounded-xl border bg-card p-5 shadow-xs lg:flex"
                >
                    <h2
                        id="setup-starting"
                        className="text-sm font-medium text-muted-foreground"
                    >
                        {t('games.setup.starting')}
                    </h2>
                    <img
                        src={cover}
                        alt=""
                        width={64}
                        height={64}
                        className="size-16 object-contain"
                    />
                    <div className="grid gap-1">
                        <p className="font-semibold">{game}</p>
                        <p className="text-sm text-muted-foreground">
                            {details}
                        </p>
                        {players !== undefined && (
                            <p className="text-sm">{players}</p>
                        )}
                    </div>
                    {button}
                </aside>
            )}
        </>
    );
}
