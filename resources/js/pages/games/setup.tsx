import { Head, setLayoutProps } from '@inertiajs/react';
import { GameCatalog } from '@/components/games/game-catalog';
import { useTranslation } from '@/hooks/use-translation';
import { index as classesIndex, show as showClass } from '@/routes/classes';
import {
    create as createClassGame,
    mode as classMode,
    store as storeClassGame,
} from '@/routes/classes/games';
import { index as childrenIndex, show as showChild } from '@/routes/children';
import {
    create as createChildGame,
    mode as childMode,
    store as storeChildGame,
} from '@/routes/children/games';
import type { GameSetupProps } from '@/types';

/**
 * «Yeni oyun» (docs/architecture.md §2, "Game setup"; docs/frontend.md
 * rule 13) for a class or for a parent's child (ADR 011): the catalog of
 * ready games (`GameCatalog`), started through `StartGame`; a started game
 * opens on the board. «Dəyiş» leads to the learned letters on the class's
 * or the child's page.
 */
export default function GameSetup({
    schoolClass,
    child,
    ...setup
}: GameSetupProps) {
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs:
            child !== null
                ? [
                      { title: 'children.index.title', href: childrenIndex() },
                      {
                          title: 'children.index.title',
                          label: child.display_name,
                          href: showChild(child.id),
                      },
                      {
                          title: 'games.setup.title',
                          href: createChildGame(child.id),
                      },
                  ]
                : [
                      { title: 'nav.classes', href: classesIndex() },
                      {
                          title: 'classes.show.breadcrumb',
                          label: schoolClass?.name,
                          href: showClass(schoolClass?.id ?? ''),
                      },
                      {
                          title: 'games.setup.title',
                          href: createClassGame(schoolClass?.id ?? ''),
                      },
                  ],
    });

    return (
        <>
            <Head title={t('games.setup.title')} />

            <div className="mx-auto flex w-full max-w-[1072px] flex-col gap-3 p-4 md:p-6">
                <h1 className="text-2xl font-bold tracking-tight">
                    {child !== null
                        ? t('games.setup.child_title', {
                              name: child.display_name,
                          })
                        : t('games.setup.title')}
                </h1>

                <GameCatalog
                    {...setup}
                    home={child !== null}
                    name={child?.display_name ?? schoolClass?.name ?? ''}
                    bleed="-mx-4 px-4 scroll-px-4 md:-mx-6 md:px-6 md:scroll-px-6"
                    lettersHref={
                        child !== null
                            ? `${showChild.url(child.id)}#learned-letters`
                            : `${showClass.url(schoolClass?.id ?? '')}#learned-letters`
                    }
                    modeUrl={
                        child !== null
                            ? childMode.url(child.id)
                            : classMode.url(schoolClass?.id ?? '')
                    }
                    form={
                        child !== null
                            ? storeChildGame.form(child.id)
                            : storeClassGame.form(schoolClass?.id ?? '')
                    }
                />
            </div>
        </>
    );
}
