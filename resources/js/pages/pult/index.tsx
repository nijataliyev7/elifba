import { Form, Head, Link } from '@inertiajs/react';
import { ChevronRight, Smartphone } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { pult } from '@/routes';
import { remote } from '@/routes/games';

type LiveGame = {
    id: string;
    status: 'live' | 'paused';
    mechanic: string;
    schoolClass: { id: string; name: string };
};

type Props = { games: LiveGame[] };

/**
 * `/pult` on the teacher's phone (docs/architecture.md §4): type the code
 * the board shows, or open one of the teacher's live games. With exactly
 * one live game the server opens its remote at once; `?list=1` (the
 * remote's back link) stays here.
 */
export default function PultIndex({ games }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('pult.title')} />

            <main className="mx-auto flex min-h-svh w-full max-w-md flex-col gap-8 bg-background p-4 text-foreground">
                <header className="flex items-center gap-3 pt-4">
                    <span className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-primary text-primary-foreground">
                        <Smartphone className="size-6" aria-hidden />
                    </span>
                    <div className="min-w-0">
                        <h1 className="text-2xl font-semibold">
                            {t('pult.title')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('pult.intro')}
                        </p>
                    </div>
                </header>

                <Form {...pult.form()} className="flex flex-col gap-3">
                    {({ errors, processing }) => (
                        <>
                            <Label htmlFor="code">{t('pult.code')}</Label>
                            <Input
                                id="code"
                                name="code"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                placeholder="482 913"
                                aria-invalid={errors.code !== undefined}
                                className="h-16 text-center text-3xl font-bold tracking-[0.3em] tabular-nums"
                            />
                            <InputError message={errors.code} />
                            <Button
                                type="submit"
                                className="h-14 text-base"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {t('pult.open')}
                            </Button>
                        </>
                    )}
                </Form>

                <section className="flex flex-col gap-3">
                    <h2 className="font-semibold">{t('pult.games')}</h2>
                    {games.length === 0 && (
                        <p className="text-muted-foreground">
                            {t('pult.no_games')}
                        </p>
                    )}
                    {games.map((game) => (
                        <Link
                            key={game.id}
                            href={remote(game.id)}
                            className="flex min-h-16 min-w-0 items-center gap-3 rounded-2xl border-2 bg-card px-4 py-3 transition-colors hover:bg-accent"
                        >
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-semibold">
                                    {t('games.board.class', {
                                        name: game.schoolClass.name,
                                    })}
                                </span>
                                <span className="block truncate text-sm text-muted-foreground">
                                    {t(`games.mechanics.${game.mechanic}`)}
                                    {' · '}
                                    {t(`pult.status.${game.status}`)}
                                </span>
                            </span>
                            <ChevronRight
                                className="size-5 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                        </Link>
                    ))}
                </section>
            </main>
        </>
    );
}
