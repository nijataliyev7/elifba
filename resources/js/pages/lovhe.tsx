import { Head } from '@inertiajs/react';
import { CheckCircle2, RefreshCw } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import type { GuestBoardChrome } from '@/components/games/board-screen';
import { BoardScreen } from '@/components/games/board-screen';
import { GuestBoardActions } from '@/components/games/guest-board-actions';
import { PairingCard } from '@/components/games/pairing-card';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { BoardController } from '@/game/board';
import type { GuestBoardView } from '@/game/guest-board';
import { GuestBoardClient, guestBoardHttp } from '@/game/guest-board';
import type { StorageLike } from '@/game/outbox';
import { forceLightTheme } from '@/hooks/use-appearance';
import { useTranslation } from '@/hooks/use-translation';

/** How long the next game waits for the old board's outbox (one `/sync`). */
const SETTLE_MS = 5000;

/**
 * `/lovhe`, the guest board on a shared school computer
 * (docs/architecture.md §4, "Guest board"): no login, no app shell. The
 * page opens a board, shows its code and QR, and once a teacher's phone
 * claims it and sends a game, draws the same board as a teacher board
 * (`BoardScreen`) with the board's bearer. After a game it shows the result
 * and waits for the next one, without a new code. The bearer lives only in
 * this tab (`GuestBoardClient`): a reload continues, a new tab starts over.
 */
export default function Lovhe() {
    const { t } = useTranslation();
    const [client, setClient] = useState<GuestBoardClient | null>(null);
    const view = useGuestBoardView(client);
    const board = useRef<BoardController | null>(null);
    const [mount, setMount] = useState(0);

    useEffect(() => forceLightTheme(), []);

    // Deferred a tick so React's development double mount never opens two boards.
    useEffect(() => {
        const created = new GuestBoardClient({
            http: guestBoardHttp(),
            storage: sessionStore(),
        });

        created.setSettle(async () => {
            const current = board.current;

            if (current !== null) {
                await Promise.race([current.settle(), delay(SETTLE_MS)]);
            }
        });

        const handle = window.setTimeout(() => {
            setClient(created);
            created.start();
        }, 0);

        return () => {
            window.clearTimeout(handle);
            created.dispose();
        };
    }, []);

    const onController = useCallback((controller: BoardController | null) => {
        board.current = controller;
    }, []);

    const teacherName = view.board?.teacher?.name ?? null;
    const chrome = useMemo<GuestBoardChrome | null>(
        () =>
            client === null
                ? null
                : {
                      teacherName,
                      busy: view.busy,
                      failed: view.notice === 'close_failed',
                      onNotMine: () => void client.notMine(),
                      onClose: () => void client.close(),
                      onReopen: () => setMount((n) => n + 1),
                      onController,
                  },
        [client, teacherName, view.busy, view.notice, onController],
    );

    return (
        <>
            <Head title={t('lovhe.title')} />

            {view.props !== null && view.auth !== null && chrome !== null ? (
                <BoardScreen
                    key={`${view.props.game.id}.${mount}`}
                    {...view.props}
                    auth={view.auth}
                    guest={chrome}
                />
            ) : (
                <Screen>
                    <Waiting
                        view={view}
                        chrome={chrome}
                        onNewCode={() => client?.reopen()}
                    />
                </Screen>
            )}
        </>
    );
}

/** Everything before a game: opening, the code, a claimed board without a game, closed. */
function Waiting({
    view,
    chrome,
    onNewCode,
}: {
    view: GuestBoardView;
    chrome: GuestBoardChrome | null;
    onNewCode: () => void;
}) {
    const { t } = useTranslation();

    if (view.phase === 'closed') {
        return (
            <div className="flex max-w-xl flex-col items-center gap-6 text-center max-lg:gap-4">
                <CheckCircle2
                    className="size-16 text-emerald-600 max-lg:size-12"
                    aria-hidden
                />
                <h1 className="text-4xl font-bold max-lg:text-2xl">
                    {t('lovhe.closed.title')}
                </h1>
                <p className="text-xl text-neutral-600 max-lg:text-base">
                    {t('lovhe.closed.description')}
                </p>
                <Button
                    type="button"
                    size="lg"
                    className="h-14 px-8 text-lg"
                    onClick={onNewCode}
                >
                    <RefreshCw />
                    {t('lovhe.closed.new_code')}
                </Button>
            </div>
        );
    }

    if (view.phase === 'waiting' && view.board?.pairing) {
        return (
            <div className="flex flex-col items-center gap-8 text-center max-lg:gap-4">
                <h1 className="text-5xl font-bold max-lg:text-3xl">
                    {t('lovhe.title')}
                </h1>
                <PairingCard pairing={view.board.pairing} failed={false} />
                <p className="max-w-2xl text-xl text-neutral-600 max-lg:text-base">
                    {t('lovhe.waiting.hint')}
                </p>
            </div>
        );
    }

    if (view.phase === 'claimed' && chrome !== null) {
        return (
            <div className="flex flex-col items-center gap-8 text-center max-lg:gap-4">
                <p
                    className="flex items-center gap-3 text-2xl text-neutral-700 max-lg:text-lg"
                    role="status"
                >
                    <Spinner className="size-6" />
                    {t('lovhe.waiting_game')}
                </p>
                <GuestBoardActions {...chrome} />
            </div>
        );
    }

    return (
        <p className="flex items-center gap-3 text-2xl" role="status">
            <Spinner className="size-6" />
            {view.notice === 'open_failed'
                ? t('lovhe.open_failed')
                : t('lovhe.opening')}
        </p>
    );
}

/** The page never scrolls; content too tall for a phone held sideways scrolls inside (docs/frontend.md §6). */
function Screen({ children }: { children: ReactNode }) {
    return (
        <main className="fixed inset-0 flex flex-col items-center justify-center-safe gap-8 overflow-y-auto bg-white p-6 text-neutral-900 max-lg:p-3 [&>*]:shrink-0">
            {children}
        </main>
    );
}

const INITIAL_VIEW: GuestBoardView = {
    phase: 'opening',
    board: null,
    props: null,
    auth: null,
    busy: false,
    notice: null,
};

const noSubscription = () => () => {};

function useGuestBoardView(client: GuestBoardClient | null): GuestBoardView {
    return useSyncExternalStore(
        client?.subscribe ?? noSubscription,
        client?.getView ?? (() => INITIAL_VIEW),
        () => INITIAL_VIEW,
    );
}

function sessionStore(): StorageLike | null {
    try {
        return window.sessionStorage;
    } catch {
        return null;
    }
}

function delay(ms: number): Promise<void> {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
}
