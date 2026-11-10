import { Form, Head, Link } from '@inertiajs/react';
import { ChevronLeft, MonitorUp } from 'lucide-react';
import { useState } from 'react';
import { GameSetupForm } from '@/components/games/game-setup-form';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { pult } from '@/routes';
import { update } from '@/routes/guest-boards/game';
import { claim } from '@/routes/pult';
import type { GuestBoardChoicesProps } from '@/types';

/**
 * What a guest board shows, chosen on the teacher's phone
 * (docs/architecture.md §4, "Guest board"): one of the teacher's live games,
 * or a new one set up with the same form as the setup page. For an open
 * board (`/pult/{code}`) it claims the board; for a board the teacher
 * claimed (`/guest-boards/{board}/game`) it sends the next game without a
 * new code. Either way the phone then opens the game's remote.
 */
export default function PultGuestBoard({
    code,
    board,
    games,
    classes,
    setup,
}: GuestBoardChoicesProps) {
    const { t } = useTranslation();
    const target =
        code !== null ? claim.form(code) : update.form(board?.id ?? '');
    const [classId, setClassId] = useState(classes[0]?.id ?? null);
    const chosen = classId === null ? undefined : setup.classes[classId];
    const title =
        code !== null
            ? t('pult.guest_board.title')
            : t('pult.guest_board.next_title');

    return (
        <>
            <Head title={title} />

            <main className="mx-auto flex min-h-svh w-full max-w-md flex-col gap-8 bg-background p-4 text-foreground">
                <header className="flex items-center gap-2 pt-2">
                    <Button
                        asChild
                        variant="ghost"
                        size="icon"
                        className="size-11 shrink-0"
                    >
                        <Link
                            href={pult({ query: { list: 1 } })}
                            aria-label={t('common.back')}
                        >
                            <ChevronLeft className="size-6" />
                        </Link>
                    </Button>
                    <span className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-primary text-primary-foreground">
                        <MonitorUp className="size-6" aria-hidden />
                    </span>
                    <div className="min-w-0">
                        <h1 className="text-xl font-semibold">{title}</h1>
                        {code !== null && (
                            <p className="text-sm text-muted-foreground tabular-nums">
                                {t('pult.guest_board.code', {
                                    code: `${code.slice(0, 3)} ${code.slice(3)}`,
                                })}
                            </p>
                        )}
                    </div>
                </header>

                <section className="flex flex-col gap-3">
                    <h2 className="font-semibold">{t('pult.games')}</h2>
                    {games.length === 0 && (
                        <p className="text-muted-foreground">
                            {t('pult.no_games')}
                        </p>
                    )}
                    {games.map((game) => (
                        <Form key={game.id} {...target}>
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="game"
                                        value={game.id}
                                    />
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="flex min-h-16 w-full min-w-0 items-center gap-3 rounded-2xl border-2 bg-card px-4 py-3 text-left transition-colors hover:bg-accent disabled:opacity-60"
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-semibold">
                                                {t('games.board.class', {
                                                    name: game.schoolClass.name,
                                                })}
                                            </span>
                                            <span className="block truncate text-sm text-muted-foreground">
                                                {t(
                                                    `games.mechanics.${game.mechanic}`,
                                                )}
                                                {' · '}
                                                {t(
                                                    `pult.status.${game.status}`,
                                                )}
                                            </span>
                                        </span>
                                        {processing ? (
                                            <Spinner />
                                        ) : (
                                            <MonitorUp
                                                className="size-5 shrink-0 text-muted-foreground"
                                                aria-hidden
                                            />
                                        )}
                                    </button>
                                    <InputError message={errors.game} />
                                </>
                            )}
                        </Form>
                    ))}
                </section>

                <section className="flex flex-col gap-4 pb-8">
                    <h2 className="font-semibold">
                        {t('pult.guest_board.new_game')}
                    </h2>

                    {classes.length === 0 ? (
                        <p className="text-muted-foreground">
                            {t('pult.guest_board.no_classes')}
                        </p>
                    ) : (
                        <fieldset className="grid gap-2">
                            <legend className="mb-2 text-sm font-medium">
                                {t('pult.guest_board.class')}
                            </legend>
                            <div className="flex flex-wrap gap-2">
                                {classes.map((item) => (
                                    <Button
                                        key={item.id}
                                        type="button"
                                        variant={
                                            item.id === classId
                                                ? 'default'
                                                : 'outline'
                                        }
                                        aria-pressed={item.id === classId}
                                        className="h-11 max-w-full"
                                        onClick={() => setClassId(item.id)}
                                    >
                                        <span className="truncate">
                                            {item.name}
                                        </span>
                                    </Button>
                                ))}
                            </div>
                        </fieldset>
                    )}

                    {classId !== null && chosen !== undefined && (
                        <GameSetupForm
                            key={classId}
                            packs={setup.packs}
                            levels={setup.levels}
                            letterCases={setup.letterCases}
                            rounds={setup.rounds}
                            wordMaxLength={setup.wordMaxLength}
                            options={chosen.options}
                            letters={chosen.letters}
                            form={target}
                            hidden={{ class: classId }}
                            submitLabel={t('pult.guest_board.start')}
                        />
                    )}
                </section>
            </main>
        </>
    );
}
