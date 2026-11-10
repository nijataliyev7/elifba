import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { useTranslation } from '@/hooks/use-translation';

/**
 * The settings pages' frame. Moving between them is the shell's job
 * (docs/frontend.md §1): the sidebar's Settings group from `lg`, the pill
 * strip below it.
 */
export default function SettingsLayout({ children }: PropsWithChildren) {
    const { t } = useTranslation();

    return (
        <div className="p-4 md:p-6">
            <Heading
                title={t('settings.title')}
                description={t('settings.description')}
            />

            <section className="max-w-xl space-y-12">{children}</section>
        </div>
    );
}
