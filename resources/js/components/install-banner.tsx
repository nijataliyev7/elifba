import { usePage } from '@inertiajs/react';
import { useId } from 'react';
import type { KeyboardEvent } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { useInstallOffer } from '@/hooks/use-install-offer';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * Elifba's own offer to install the app (docs/frontend.md rule 11), shown
 * when Chrome offers installation and only on the teacher's pages. A card
 * floating at the bottom (bottom right from `sm`), so nothing moves when it
 * comes or goes; in the teacher shell below `lg` it sits above the bottom
 * tab bar. «Quraşdır» opens Chrome's install dialog, «İndi yox» or Esc hides
 * it for two weeks in this browser.
 */
export function InstallBanner({
    aboveTabBar = false,
}: {
    /** The teacher shell: below `lg` the bottom tab bar takes the edge. */
    aboveTabBar?: boolean;
}) {
    const { component } = usePage();
    const { t } = useTranslation();
    const { visible, install, dismiss } = useInstallOffer(component);
    const titleId = useId();
    const bodyId = useId();

    if (!visible) {
        return null;
    }

    const onKeyDown = (event: KeyboardEvent<HTMLElement>): void => {
        if (event.key === 'Escape') {
            event.stopPropagation();
            dismiss();
        }
    };

    return (
        <section
            aria-labelledby={titleId}
            aria-describedby={bodyId}
            onKeyDown={onKeyDown}
            data-test="install-banner"
            className={cn(
                'pointer-events-none fixed inset-x-0 z-40 flex justify-center sm:justify-end',
                'pr-[max(1rem,env(safe-area-inset-right))] pl-[max(1rem,env(safe-area-inset-left))] sm:pr-[max(1.5rem,env(safe-area-inset-right))]',
                aboveTabBar
                    ? 'bottom-[calc(4.75rem+env(safe-area-inset-bottom))] lg:bottom-6'
                    : 'bottom-[calc(1rem+env(safe-area-inset-bottom))] sm:bottom-6',
            )}
        >
            <div
                className={cn(
                    'pointer-events-auto flex w-full max-w-md items-start gap-4 rounded-2xl border bg-popover p-4 text-popover-foreground shadow-xl shadow-black/10 sm:w-96 dark:shadow-black/40',
                    'motion-safe:animate-in motion-safe:duration-300 motion-safe:fade-in-0 motion-safe:slide-in-from-bottom-4',
                )}
            >
                <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-green-700 text-white shadow-sm">
                    <AppLogoIcon className="size-8 fill-current" />
                </span>

                <div className="min-w-0 flex-1">
                    <h2
                        id={titleId}
                        className="text-[0.9375rem] leading-snug font-semibold tracking-tight"
                    >
                        {t('install.title')}
                    </h2>
                    <p
                        id={bodyId}
                        className="mt-1 text-sm leading-snug text-pretty text-muted-foreground"
                    >
                        {t('install.body')}
                    </p>

                    <div className="mt-3.5 flex flex-wrap items-center gap-2">
                        <Button
                            size="sm"
                            onClick={() => void install()}
                            data-test="install-banner-install"
                        >
                            {t('install.action')}
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={dismiss}
                            data-test="install-banner-dismiss"
                        >
                            {t('install.later')}
                        </Button>
                    </div>
                </div>
            </div>
        </section>
    );
}
