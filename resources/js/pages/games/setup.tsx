import { Head, setLayoutProps } from '@inertiajs/react';
import { GameSetupForm } from '@/components/games/game-setup-form';
import Heading from '@/components/heading';
import { useTranslation } from '@/hooks/use-translation';
import { index, show } from '@/routes/classes';
import { create, store } from '@/routes/classes/games';
import type { GameSetupProps } from '@/types';

/**
 * The game setup for a class (docs/architecture.md §2, "Game setup"): the
 * shared setup form (`GameSetupForm`), sent to `StartGame`; a started game
 * opens on the board.
 */
export default function GameSetup({ schoolClass, ...setup }: GameSetupProps) {
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            { title: 'nav.classes', href: index() },
            {
                title: 'classes.show.breadcrumb',
                label: schoolClass.name,
                href: show(schoolClass.id),
            },
            { title: 'games.setup.title', href: create(schoolClass.id) },
        ],
    });

    return (
        <>
            <Head title={t('games.setup.title')} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 p-4 md:p-6">
                <Heading
                    title={t('games.setup.title')}
                    description={t('games.setup.description')}
                />

                <GameSetupForm {...setup} form={store.form(schoolClass.id)} />
            </div>
        </>
    );
}
