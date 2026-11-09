import { Link } from '@inertiajs/react';
import { Star } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { Tally } from '@/game/engine';
import { useTranslation } from '@/hooks/use-translation';
import { show } from '@/routes/classes';
import { create } from '@/routes/classes/games';

type Props = {
    classId: string;
    /** What the board offers next; by default the teacher's links (a guest board has its own). */
    actions?: ReactNode;
    /** Stars as the server returned them (`PointsRule`, PHP only). */
    points: number;
    tally: Tally | null;
    /** Answers not saved yet: the stars may still grow. */
    pending: number;
    accent: string;
};

/**
 * The end of a game: the stars it earned and how the rounds went. Leaving
 * waits until every answer is saved, because nothing else would send them.
 */
export function BoardResult({
    classId,
    actions,
    points,
    tally,
    pending,
    accent,
}: Props) {
    const { t } = useTranslation();
    const saving = pending > 0;

    return (
        <div className="flex w-full max-w-xl flex-col items-center gap-8 rounded-3xl bg-white p-10 text-center shadow-xl">
            <h1 className="text-4xl font-bold">
                {t('games.board.result.title')}
            </h1>

            <p className="flex items-center gap-4 text-6xl font-bold tabular-nums">
                <Star
                    aria-hidden
                    className="size-20"
                    style={{ color: accent, fill: accent }}
                />
                <span>{t('games.board.result.stars', { count: points })}</span>
            </p>

            {tally !== null && (
                <dl className="grid w-full grid-cols-3 gap-3 text-center">
                    {(
                        [
                            ['solved', tally.solved],
                            ['skipped', tally.skipped],
                            ['misses', tally.misses],
                        ] as const
                    ).map(([key, value]) => (
                        <div
                            key={key}
                            className="rounded-2xl bg-neutral-50 p-3"
                        >
                            <dt className="text-sm text-neutral-500">
                                {t(`games.board.result.${key}`)}
                            </dt>
                            <dd className="text-3xl font-bold tabular-nums">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}

            {saving && (
                <p
                    className="flex items-center gap-2 text-neutral-600"
                    aria-live="polite"
                >
                    <Spinner />
                    {t('games.board.result.saving', { count: pending })}
                </p>
            )}

            {actions ?? (
                <div className="flex flex-wrap justify-center gap-3">
                    <Button
                        asChild
                        size="lg"
                        variant="outline"
                        className={
                            saving ? 'pointer-events-none opacity-50' : ''
                        }
                    >
                        <Link href={show(classId)} aria-disabled={saving}>
                            {t('games.board.back_to_class')}
                        </Link>
                    </Button>
                    <Button
                        asChild
                        size="lg"
                        className={
                            saving ? 'pointer-events-none opacity-50' : ''
                        }
                    >
                        <Link href={create(classId)} aria-disabled={saving}>
                            {t('games.board.new_game')}
                        </Link>
                    </Button>
                </div>
            )}
        </div>
    );
}
