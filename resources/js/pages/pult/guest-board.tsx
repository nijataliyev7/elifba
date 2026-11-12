import { Form, Head, Link } from '@inertiajs/react';
import { ChevronLeft, MonitorUp } from 'lucide-react';
import { useState } from 'react';
import { GameCatalog } from '@/components/games/game-catalog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { playersLabel } from '@/lib/game-players';
import { mechanicName } from '@/lib/setup-choices';
import { pult } from '@/routes';
import { mode as childMode } from '@/routes/children/games';
import { mode as classMode } from '@/routes/classes/games';
import { update } from '@/routes/guest-boards/game';
import { claim } from '@/routes/pult';
import type { GuestBoardChoicesProps } from '@/types';

/** A class or a parent's child to start a new game for. */
type Players = { field: 'class' | 'child'; id: string; name: string };

/**
 * What a guest board shows, chosen on the owner's phone
 * (docs/architecture.md §4, "Guest board"; docs/frontend.md rule 13): one
 * of the account's live games, named as the setup names them, or a new one
 * — «Kim oynayır?» (a child or a class, ADR 011), then the setup page's
 * catalog for them, with «Lövhədə başla». For an open
 * board (`/pult/{code}`) it claims the board; for a board the teacher
 * claimed (`/guest-boards/{board}/game`) it sends the next game without a
 * new code. Either way the phone then opens the game's remote.
 */
export default function PultGuestBoard({
    code,
    board,
    games,
    classes,
    children,
    setup,
}: GuestBoardChoicesProps) {
    const { t } = useTranslation();
    const target =
        code !== null ? claim.form(code) : update.form(board?.id ?? '');
    const choices: Players[] = [
        ...children.map((child): Players => ({
            field: 'child',
            id: child.id,
            name: child.display_name,
        })),
        ...classes.map((item): Players => ({
            field: 'class',
            id: item.id,
            name: item.name,
        })),
    ];
    const [players, setPlayers] = useState<Players | null>(choices[0] ?? null);
    const chosen =
        players === null
            ? undefined
            : players.field === 'child'
              ? setup.children[players.id]
              : setup.classes[players.id];
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
                                                {t(
                                                    playersLabel(game).key,
                                                    playersLabel(game).values,
                                                )}
                                            </span>
                                            <span className="block truncate text-sm text-muted-foreground">
                                                {[
                                                    t(
                                                        'games.setup.summary.game',
                                                        {
                                                            level: game.level
                                                                .title,
                                                            mechanic: t(
                                                                mechanicName(
                                                                    game.mechanic,
                                                                    game.level
                                                                        .unit,
                                                                ),
                                                            ),
                                                        },
                                                    ),
                                                    t(
                                                        `pult.status.${game.status}`,
                                                    ),
                                                ].join(' · ')}
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

                <section className="flex flex-col gap-6">
                    <h2 className="font-semibold">
                        {t('pult.guest_board.new_game')}
                    </h2>

                    {choices.length === 0 ? (
                        <p className="text-muted-foreground">
                            {t('pult.guest_board.no_classes')}
                        </p>
                    ) : (
                        <fieldset className="grid gap-2">
                            <legend className="mb-2 text-base font-semibold">
                                {t('pult.guest_board.players')}
                            </legend>
                            <div className="flex flex-wrap gap-2">
                                {choices.map((item) => (
                                    <Button
                                        key={item.id}
                                        type="button"
                                        variant={
                                            item.id === players?.id
                                                ? 'default'
                                                : 'outline'
                                        }
                                        aria-pressed={item.id === players?.id}
                                        className="h-11 max-w-full"
                                        onClick={() => setPlayers(item)}
                                    >
                                        <span className="truncate">
                                            {item.name}
                                        </span>
                                    </Button>
                                ))}
                            </div>
                        </fieldset>
                    )}

                    {players !== null && chosen !== undefined && (
                        <GameCatalog
                            key={players.id}
                            packs={setup.packs}
                            levels={setup.levels}
                            games={setup.games}
                            letterCases={setup.letterCases}
                            wordMaxLength={setup.wordMaxLength}
                            {...chosen}
                            form={target}
                            modeUrl={
                                players.field === 'child'
                                    ? childMode.url(players.id)
                                    : classMode.url(players.id)
                            }
                            hidden={{ [players.field]: players.id }}
                            home={players.field === 'child'}
                            name={players.name}
                            bleed="-mx-4 px-4 scroll-px-4"
                            submitLabel={t('pult.guest_board.start')}
                        />
                    )}
                </section>
            </main>
        </>
    );
}
