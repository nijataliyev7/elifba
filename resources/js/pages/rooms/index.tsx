import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import type { GameSetupOptions } from '@/components/rooms/catalog';
import { CheckersDrawing } from '@/components/rooms/checkers/checkers-drawing';
import { GameDrawing } from '@/components/rooms/game-drawing';
import { RoomActionBar } from '@/components/rooms/room-action-bar';
import { RoomCodeField } from '@/components/rooms/room-code-field';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { entrance as entranceRoute, index, show, store } from '@/routes/rooms';
import type { RoomMechanic } from '@/rooms/contract';

/** An open room the account hosts or is in: the game of its choice and whether a game is running. */
type OpenRoom = {
    id: string;
    code: string | null;
    host: boolean;
    players: number;
    mechanic: RoomMechanic;
    /** The board game the room was opened for (ADR 034), else null. */
    board: 'checkers' | null;
    running: boolean;
};

type Props = {
    /** The games a room plays, with what each level holds (`RoomGameSetupData::options()`). */
    catalog: GameSetupOptions;
    open: OpenRoom[];
    /** The code field's address, `{APP_URL}/oyun`: the scanner takes this origin too. */
    entrance: string;
};

/**
 * «Oyunlar», the player area's home (docs/frontend.md rule 15 "Games
 * first"; architecture §12 "Games first", ADR 026 decision 13): a card for
 * each open room the account is in («Oyuna qayıt»), the six games as a
 * grid of tiles — a tap chooses one, and the room opens behind it, or the
 * host's open room takes it — and at the bottom, above the tab bar, the
 * code field with its camera and «Qoşul». Nothing else: the past games
 * are their own page.
 */
export default function RoomsIndex({ catalog, open, entrance }: Props) {
    const { t } = useTranslation();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [choosing, setChoosing] = useState<RoomMechanic | 'checkers' | null>(
        null,
    );

    const choose = (mechanic: RoomMechanic | 'checkers') => {
        router.post(
            store.url(),
            mechanic === 'checkers' ? { board: mechanic } : { mechanic },
            {
                onStart: () => setChoosing(mechanic),
                onFinish: () => setChoosing(null),
            },
        );
    };

    return (
        <div className="flex flex-1 flex-col">
            <Head title={t('rooms.index.title')} />

            <div className="mx-auto flex w-full max-w-xl flex-col gap-2.5 px-4 pt-2.5 pb-4 md:px-6">
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('rooms.index.title')}
                </h1>

                {open.length === 0 ? (
                    <p className="-mt-2 text-[13px] leading-snug text-muted-foreground">
                        {t('rooms.index.description')}
                    </p>
                ) : (
                    open.map((room) => (
                        <OpenRoomCard key={room.id} room={room} />
                    ))
                )}

                <ul
                    aria-label={t('rooms.index.all')}
                    className="grid grid-cols-2 gap-x-3 gap-y-2"
                >
                    {catalog.mechanics.map((game) => (
                        <li key={game.mechanic}>
                            <button
                                type="button"
                                disabled={choosing !== null}
                                onClick={() => choose(game.mechanic)}
                                className={cn(
                                    'group flex w-full flex-col gap-0.5 rounded-[18px] text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                    choosing !== null &&
                                        choosing !== game.mechanic &&
                                        'opacity-50',
                                )}
                            >
                                <span className="relative mb-0.5 flex aspect-[2/1] w-full items-center justify-center rounded-[18px] bg-muted transition-colors group-hover:bg-accent motion-reduce:transition-none">
                                    <GameDrawing
                                        mechanic={game.mechanic}
                                        framed={false}
                                        className="text-[56px]"
                                    />
                                    {choosing === game.mechanic && (
                                        <span className="absolute top-2 right-2">
                                            <Spinner />
                                        </span>
                                    )}
                                </span>
                                <span className="text-[14.5px] leading-[18px] font-semibold">
                                    {t(`rooms.mechanics.${game.mechanic}.name`)}
                                </span>
                                <span className="text-xs leading-4 text-muted-foreground">
                                    {t(`rooms.setup.families.${game.family}`)}
                                </span>
                            </button>
                        </li>
                    ))}
                    <li>
                        <button
                            type="button"
                            disabled={choosing !== null}
                            onClick={() => choose('checkers')}
                            className={cn(
                                'group flex w-full flex-col gap-0.5 rounded-[18px] text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                choosing !== null &&
                                    choosing !== 'checkers' &&
                                    'opacity-50',
                            )}
                        >
                            <span className="relative mb-0.5 flex aspect-[2/1] w-full items-center justify-center rounded-[18px] bg-muted transition-colors group-hover:bg-accent motion-reduce:transition-none">
                                <CheckersDrawing className="text-[56px]" />
                                {choosing === 'checkers' && (
                                    <span className="absolute top-2 right-2">
                                        <Spinner />
                                    </span>
                                )}
                            </span>
                            <span className="text-[14.5px] leading-[18px] font-semibold">
                                {t('rooms.boards.checkers.name')}
                            </span>
                            <span className="text-xs leading-4 text-muted-foreground">
                                {t('rooms.boards.family')}
                            </span>
                        </button>
                    </li>
                </ul>
                <InputError message={errors.mechanic ?? errors.board} />
            </div>

            <RoomActionBar>
                <Form
                    {...entranceRoute.form()}
                    options={{ preserveState: 'errors' }}
                    className="flex flex-col gap-2"
                >
                    {({ errors: codeErrors, processing }) => {
                        const refusal = codeErrors.code ?? errors.code;

                        return (
                            <>
                                <InputError message={refusal} />
                                <div className="flex items-center gap-2">
                                    <label htmlFor="code" className="sr-only">
                                        {t('rooms.join.code')}
                                    </label>
                                    <RoomCodeField
                                        id="code"
                                        invalid={refusal !== undefined}
                                        entrance={entrance}
                                    />
                                    <Button
                                        type="submit"
                                        className="h-13 shrink-0 px-5 text-base"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        {t('rooms.join.submit')}
                                    </Button>
                                </div>
                            </>
                        );
                    }}
                </Form>
            </RoomActionBar>
        </div>
    );
}

/** An open room the account is in: its game, whether it runs, the players, and the way back. */
function OpenRoomCard({ room }: { room: OpenRoom }) {
    const { t } = useTranslation();

    return (
        <section
            aria-label={t('rooms.index.open_room')}
            className="flex flex-col gap-3 rounded-2xl border border-primary bg-card p-3"
        >
            <div className="flex items-center gap-3">
                {room.board === 'checkers' ? (
                    <CheckersDrawing framed className="text-[48px]" />
                ) : (
                    <GameDrawing
                        mechanic={room.mechanic}
                        className="text-[48px]"
                    />
                )}
                <span className="flex min-w-0 flex-1 flex-col">
                    <span className="text-xs font-semibold text-muted-foreground">
                        {room.running
                            ? t('rooms.index.live')
                            : t('rooms.index.open_room')}
                    </span>
                    <span className="truncate font-semibold">
                        {room.board === 'checkers'
                            ? t('rooms.boards.checkers.name')
                            : t(`rooms.mechanics.${room.mechanic}.name`)}
                    </span>
                    <span className="truncate text-sm text-muted-foreground">
                        {t('rooms.index.players', { count: room.players })}
                        {room.host && ` · ${t('rooms.index.hosted')}`}
                    </span>
                </span>
            </div>
            <Button asChild className="h-13 text-base">
                <Link href={show(room.id)}>
                    {t('rooms.index.go')}
                    <ChevronRight />
                </Link>
            </Button>
        </section>
    );
}

RoomsIndex.layout = {
    breadcrumbs: [{ title: 'nav.rooms', href: index() }],
};
