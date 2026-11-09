import { Head, Link, setLayoutProps } from '@inertiajs/react';
import confetti from 'canvas-confetti';
import { ListOrdered, Play, Trophy } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { PeriodNav } from '@/components/rankings/period-nav';
import { PodiumStage } from '@/components/rankings/podium-stage';
import { SharePanel } from '@/components/share/share-panel';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index, podium as podiumRoute, rankings, show } from '@/routes/classes';
import { create as createGame } from '@/routes/classes/games';
import type { PodiumProps } from '@/types';

/** Confetti in the medal colours and the card's palette. */
const CONFETTI_COLORS = ['#FCEA2B', '#F1B31C', '#EA5A47', '#61B2E4', '#B1CC33'];

/**
 * A class's winners of the day or the week (architecture §5, §6): places
 * 1–3 for the projector and the phone, with confetti once when the page
 * opens, and the share card for WhatsApp status. The server derives the
 * podium and shapes the card; the card receives nothing but `card`.
 */
export default function Podium({
    schoolClass,
    period,
    date,
    label,
    current,
    previous,
    next,
    podium,
    avatars,
    card,
}: PodiumProps) {
    const { t } = useTranslation();
    const celebrated = useRef(false);
    const query = current ? { period } : { period, date };

    setLayoutProps({
        breadcrumbs: [
            { title: 'nav.classes', href: index() },
            {
                title: 'classes.show.breadcrumb',
                label: schoolClass.name,
                href: show(schoolClass.id),
            },
            { title: 'podium.title', href: podiumRoute(schoolClass.id) },
        ],
    });

    // Once per visit, and only when someone is on the podium; with "reduce
    // motion" on, the library draws nothing.
    useEffect(() => {
        if (podium.length === 0 || celebrated.current) {
            return;
        }

        celebrated.current = true;
        void confetti({
            particleCount: 160,
            spread: 100,
            startVelocity: 45,
            origin: { y: 0.55 },
            colors: CONFETTI_COLORS,
            disableForReducedMotion: true,
        });
    }, [podium.length]);

    return (
        <>
            <Head title={`${t('podium.title')} · ${schoolClass.name}`} />

            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-1">
                        <div className="flex min-w-0 flex-wrap items-center gap-2">
                            <h1 className="truncate text-2xl font-semibold tracking-tight">
                                {t('podium.title')}
                            </h1>
                            <span className="truncate text-2xl text-muted-foreground">
                                {schoolClass.name}
                            </span>
                            {schoolClass.archived && (
                                <Badge variant="secondary">
                                    {t('classes.archived.badge')}
                                </Badge>
                            )}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {t('podium.description')}
                        </p>
                    </div>
                    <Button variant="outline" asChild>
                        <Link
                            href={rankings(schoolClass.id, { query })}
                            data-test="rankings"
                        >
                            <ListOrdered />
                            {t('rankings.title')}
                        </Link>
                    </Button>
                </div>

                <div className="w-full max-w-3xl">
                    <PeriodNav
                        classId={schoolClass.id}
                        route={podiumRoute}
                        period={period}
                        date={date}
                        label={label}
                        current={current}
                        previous={previous}
                        next={next}
                    />
                </div>

                {card === null || podium.length === 0 ? (
                    <div className="flex flex-col items-center gap-4 rounded-xl border border-dashed p-8 text-center">
                        <Trophy
                            aria-hidden
                            className="size-12 text-muted-foreground"
                        />
                        <div className="space-y-1">
                            <p className="text-lg font-medium">
                                {t('podium.empty.title')}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {t(`rankings.empty.${period}`)}
                            </p>
                        </div>
                        {!schoolClass.archived && current && (
                            <Button asChild>
                                <Link href={createGame(schoolClass.id)}>
                                    <Play />
                                    {t('classes.show.start_game')}
                                </Link>
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="grid grid-cols-1 items-start gap-8 lg:grid-cols-[minmax(0,1fr)_18rem]">
                        <PodiumStage podium={podium} avatars={avatars} />
                        <SharePanel key={card.file_name} card={card} />
                    </div>
                )}
            </div>
        </>
    );
}
