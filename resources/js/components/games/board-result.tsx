import { Form, Link } from '@inertiajs/react';
import { LogOut, Plus, RotateCcw, Star } from 'lucide-react';
import type { ReactNode } from 'react';
import { studentAvatars } from '@/components/games/student-grid';
import { StudentAvatar } from '@/components/students/student-avatar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { Tally } from '@/game/engine';
import { useTranslation } from '@/hooks/use-translation';
import { playersHome, playersNewGame } from '@/lib/game-players';
import type { GamePlayers } from '@/lib/game-players';
import { cn } from '@/lib/utils';
import { replay } from '@/routes/games';
import type { BoardChild } from '@/types';

type Props = {
    /** The game just played: «Yenidən oyna» starts it again. */
    gameId: string;
    /** The class or the child the game was for: where the links lead. */
    players: GamePlayers;
    /** A home game's child, with their stars before this game; null for a class. */
    child?: BoardChild | null;
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
 * Smaller below `lg`, so it fits a phone held sideways (docs/frontend.md §6).
 *
 * At home (architecture §5 "A child's stars") the child's avatar heads it
 * and the child's own total follows: the stars before this game
 * (`BoardProps.child.stars`) plus this game's, as `/sync` returned them.
 * Never a place or anyone else's stars.
 *
 * What comes next, most likely first: «Yenidən oyna» (the same game again,
 * straight to its board; `POST /games/{game}/replay`, architecture §2) in
 * the pack's accent with `RotateCcw`, then «Yeni oyun» (the setup page) as
 * an outline with `Plus`; leaving for the class's or the child's page is
 * set apart below a rule, with `LogOut`. A guest board passes its own
 * `actions` instead.
 */
export function BoardResult({
    gameId,
    players,
    child = null,
    actions,
    points,
    tally,
    pending,
    accent,
}: Props) {
    const { t } = useTranslation();
    const saving = pending > 0;
    const home = playersHome(players);

    return (
        <div className="flex w-full max-w-xl flex-col items-center gap-8 rounded-3xl bg-white p-10 text-center shadow-xl max-lg:gap-4 max-lg:p-5">
            {child !== null && (
                <StudentAvatar
                    avatar={child.avatar.key}
                    avatars={studentAvatars([child])}
                    initial={child.display_name.slice(0, 1)}
                    className="-mb-4 size-24 text-3xl max-lg:hidden"
                />
            )}
            <h1 className="text-4xl font-bold max-lg:text-2xl">
                {t('games.board.result.title')}
            </h1>

            <p className="flex items-center gap-4 text-6xl font-bold tabular-nums max-lg:gap-3 max-lg:text-4xl">
                <Star
                    aria-hidden
                    className="size-20 max-lg:size-12"
                    style={{ color: accent, fill: accent }}
                />
                <span>{t('games.board.result.stars', { count: points })}</span>
            </p>

            {child !== null && (
                <p
                    className="-mt-4 flex items-center justify-center gap-2 text-2xl font-semibold text-neutral-700 max-lg:-mt-2 max-lg:text-lg"
                    data-test="child-total"
                >
                    {/* Below `lg` the avatar stands here, so a phone held sideways keeps the buttons in view. */}
                    <StudentAvatar
                        avatar={child.avatar.key}
                        avatars={studentAvatars([child])}
                        initial={child.display_name.slice(0, 1)}
                        className="hidden size-10 text-base max-lg:inline-flex"
                    />
                    {t('games.board.result.child_total', {
                        name: child.display_name,
                        count: child.stars + points,
                    })}
                </p>
            )}

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
                            className="rounded-2xl bg-neutral-50 p-3 max-lg:p-2"
                        >
                            <dt className="text-sm text-neutral-500">
                                {t(`games.board.result.${key}`)}
                            </dt>
                            <dd className="text-3xl font-bold tabular-nums max-lg:text-2xl">
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
                <div className="flex w-full flex-col items-center gap-6 max-lg:gap-3">
                    <div className="flex flex-wrap justify-center gap-3">
                        <Form {...replay.form(gameId)}>
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    size="lg"
                                    className="h-14 rounded-xl px-8 text-xl text-white max-lg:h-11 max-lg:px-5 max-lg:text-base"
                                    style={{ backgroundColor: accent }}
                                    disabled={saving || processing}
                                    data-test="replay"
                                >
                                    {processing ? (
                                        <Spinner />
                                    ) : (
                                        <RotateCcw className="size-6 max-lg:size-5" />
                                    )}
                                    {t('games.board.replay')}
                                </Button>
                            )}
                        </Form>
                        <Button
                            asChild
                            size="lg"
                            variant="outline"
                            className={cn(
                                'h-14 rounded-xl px-6 text-lg max-lg:h-11 max-lg:px-4 max-lg:text-base',
                                saving && 'pointer-events-none opacity-50',
                            )}
                        >
                            <Link
                                href={playersNewGame(players)}
                                aria-disabled={saving}
                            >
                                <Plus className="size-5" />
                                {t('games.board.new_game')}
                            </Link>
                        </Button>
                    </div>
                    <div className="flex w-full justify-center border-t border-neutral-200 pt-5 max-lg:pt-3">
                        <Button
                            asChild
                            variant="ghost"
                            size="lg"
                            className={cn(
                                'text-base text-neutral-700 hover:text-neutral-900 max-lg:h-11',
                                saving && 'pointer-events-none opacity-50',
                            )}
                        >
                            <Link href={home.href} aria-disabled={saving}>
                                <LogOut />
                                {t(home.label)}
                            </Link>
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
