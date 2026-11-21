import { Form, Head, Link, usePage } from '@inertiajs/react';
import { LogIn, UserRound } from 'lucide-react';
import InputError from '@/components/input-error';
import { RoomActionBar } from '@/components/rooms/room-action-bar';
import { RoomCodeField } from '@/components/rooms/room-code-field';
import { spacedCode } from '@/components/rooms/room-code';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { edit as editProfile } from '@/routes/profile';
import { entrance as entranceRoute, index } from '@/routes/rooms';
import { store as enter } from '@/routes/rooms/entrance';
import { store } from '@/routes/rooms/join';

type Props = {
    /** The code of an open room; null at `/oyun`, where it is typed. */
    code: string | null;
    /** The account's own name, as the room will show it. */
    name: string;
    /** At `/oyun`, its own address (`APP_URL/oyun`): the camera takes this origin too. */
    entrance: string | null;
};

/**
 * The entrance (docs/frontend.md rule 15 "Games first", "The entrance";
 * architecture §12 "Rooms and joining", ADR 020 decision 4, ADR 026
 * decisions 6 and 15): at `/oyun` the code field with its camera, focused,
 * and «Qoşul», which joins with that tap (ADR 033 as amended 2026-11-21);
 * at `/oyun/{code}` the code, the account's own name as the
 * game will show it, and «Qoşul» at the bottom of the screen. A locked or
 * a full room is said here and nothing else about it. From I2 (ADR 033) a
 * player who opened a room's link by hand never sees this page: the link
 * joins at once.
 */
export default function RoomsJoin({ code, name, entrance }: Props) {
    const { t } = useTranslation();
    // A link or a QR that opens nothing arrives here by a full page load:
    // its refusal is in the page's errors, not in the form's own.
    const { errors: arrived } = usePage<{ errors: Record<string, string> }>()
        .props;

    if (code === null) {
        return (
            <>
                <Head title={t('rooms.join.title')} />
                <div className="mx-auto flex w-full max-w-md flex-col gap-5 px-4 pt-4 pb-10 md:px-6">
                    <header className="flex flex-col gap-1">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('rooms.join.title')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('rooms.join.line')}
                        </p>
                    </header>
                    <Form
                        {...enter.form()}
                        options={{ preserveState: 'errors' }}
                        className="flex flex-col gap-3"
                    >
                        {({ errors, processing }) => {
                            const refusal = errors.code ?? arrived.code;

                            return (
                                <>
                                    <Label htmlFor="code">
                                        {t('rooms.join.code')}
                                    </Label>
                                    <RoomCodeField
                                        id="code"
                                        invalid={refusal !== undefined}
                                        entrance={entrance}
                                        large
                                        autoFocus
                                    />
                                    <InputError message={refusal} />
                                    <Button
                                        type="submit"
                                        className="h-13 text-base"
                                        disabled={processing}
                                    >
                                        {processing ? <Spinner /> : <LogIn />}
                                        {t('rooms.join.submit')}
                                    </Button>
                                </>
                            );
                        }}
                    </Form>
                </div>
            </>
        );
    }

    return (
        <Form {...store.form(code)} className="flex flex-1 flex-col">
            {({ errors, processing }) => (
                <>
                    <Head title={t('rooms.join.title')} />
                    <div className="mx-auto flex w-full max-w-md flex-col gap-5 px-4 pt-4 pb-6 md:px-6">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('rooms.join.title')}
                        </h1>

                        <section className="flex flex-col items-center gap-1 rounded-2xl border bg-card px-4 py-5">
                            <span className="text-sm text-muted-foreground">
                                {t('rooms.join.code')}
                            </span>
                            <span className="text-5xl font-bold tracking-wider tabular-nums">
                                {spacedCode(code)}
                            </span>
                        </section>

                        <section className="flex flex-col gap-2">
                            <span className="text-sm text-muted-foreground">
                                {t('rooms.join.as')}
                            </span>
                            <span className="flex min-h-14 items-center gap-3 rounded-xl border bg-card py-1 pr-1 pl-3">
                                <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted">
                                    <UserRound aria-hidden className="size-5" />
                                </span>
                                <span className="min-w-0 flex-1 truncate text-lg font-semibold">
                                    {name}
                                </span>
                                <Button
                                    asChild
                                    variant="ghost"
                                    className="h-11 shrink-0 text-muted-foreground"
                                >
                                    <Link href={editProfile()}>
                                        {t('rooms.join.change_name')}
                                    </Link>
                                </Button>
                            </span>
                        </section>

                        {/* A locked or full room met by a link opened by hand arrives in the page's errors (ADR 033 decision 7). */}
                        <InputError message={errors.code ?? arrived.code} />
                    </div>

                    <RoomActionBar>
                        <Button
                            type="submit"
                            className="h-14 w-full text-base"
                            disabled={processing}
                        >
                            {processing ? <Spinner /> : <LogIn />}
                            {t('rooms.join.submit')}
                        </Button>
                    </RoomActionBar>
                </>
            )}
        </Form>
    );
}

RoomsJoin.layout = {
    breadcrumbs: [
        { title: 'nav.rooms', href: index() },
        { title: 'rooms.join.title', href: entranceRoute() },
    ],
};
