import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import type { BoardProps } from '@/types';
import type { GuestBoard } from './contract';
import type { GuestBoardHttp, OpenResult, PollResult } from './guest-board';
import {
    GUEST_BOARD_KEY,
    GuestBoardClient,
    guestBoardHttp,
    PLAYING_POLL_MS,
    WAITING_POLL_MS,
} from './guest-board';
import type { BoardAuth } from './http';
import type { StorageLike } from './outbox';

/*
 * The guest board's tab (docs/architecture.md §4, "Guest board"): the
 * bearer stays in `sessionStorage` and memory, a reload continues, a 401
 * opens a new board, every game gets its own board after the old one
 * settles, and closing leaves nothing. Fake http and Vitest's fake timers.
 */

const BOARD_A = '01jz8c3m4n5p6q7r8s9t0v1w2a';
const BOARD_B = '01jz8c3m4n5p6q7r8s9t0v1w2b';
const GAME_1 = '01jz8c3m4n5p6q7r8s9t0v1w21';
const GAME_2 = '01jz8c3m4n5p6q7r8s9t0v1w22';
const SECRET_A = 'a'.repeat(43);
const SECRET_B = 'b'.repeat(43);

function memoryStorage(initial: Record<string, string> = {}) {
    const data = new Map(Object.entries(initial));
    const storage: StorageLike = {
        getItem: (key) => data.get(key) ?? null,
        setItem: (key, value) => void data.set(key, value),
        removeItem: (key) => void data.delete(key),
    };

    return { storage, data };
}

function unclaimed(id: string): GuestBoard {
    return {
        id,
        expires_at: '2026-11-10T08:00:00Z',
        pairing: {
            code: '482913',
            expires_at: '2026-11-09T20:10:00Z',
            url: 'https://elifba.test/pult/482913',
            entry_url: 'https://elifba.test/pult',
        },
        teacher: null,
        game: null,
    };
}

function showing(
    id: string,
    game: string | null,
    status: 'live' | 'paused' | 'finished' = 'live',
): GuestBoard {
    return {
        ...unclaimed(id),
        pairing: null,
        teacher: { name: 'Gülnarə Məmmədova' },
        game: game === null ? null : { id: game, status },
    };
}

function props(game: string): BoardProps {
    return {
        game: {
            id: game,
            status: 'live',
            board_epoch: 0,
            mechanic: 'find',
            level: 'letters',
            letter_case: 'upper',
            rounds: 6,
            points: [],
        },
        schoolClass: { id: 'c', name: '1A' },
        pack: {
            key: 'balloons',
            title: 'Şarlar',
            style: {
                item: 'balloon',
                slot: 'card',
                effect: 'burst',
                palette: ['#ff0000'],
                backdrop: null,
                sounds: { hit: null, miss: null, solved: null },
                figures: [],
            },
            pictures: {},
        },
        students: [],
    };
}

function opened(
    id: string,
    secret: string,
): Extract<OpenResult, { kind: 'ok' }> {
    return { kind: 'ok', board: unclaimed(id), token: `${id}.${secret}` };
}

function fakeHttp() {
    const opens: OpenResult[] = [];
    const polls: PollResult[] = [];
    const tokens: string[] = [];
    const http = {
        open: vi.fn(async () => opens.shift() ?? { kind: 'failed' as const }),
        poll: vi.fn(async (_id: string, auth: BoardAuth) => {
            tokens.push(auth.kind === 'bearer' ? auth.token : '');

            return polls.shift() ?? { kind: 'failed' as const };
        }),
        close: vi.fn(async () => true),
    } satisfies GuestBoardHttp;

    return { http, opens, polls, tokens };
}

function tokenOf(client: GuestBoardClient): string | null {
    const auth = client.getView().auth;

    return auth?.kind === 'bearer' ? auth.token : null;
}

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('a new tab', () => {
    it('opens a board, keeps {board_id, secret} in sessionStorage only, and polls every 2 s while it waits', async () => {
        const { http, opens, polls, tokens } = fakeHttp();
        const { storage, data } = memoryStorage();
        const client = new GuestBoardClient({ http, storage });
        opens.push(opened(BOARD_A, SECRET_A));
        polls.push({ kind: 'ok', board: unclaimed(BOARD_A), props: null });

        client.start();
        await vi.advanceTimersByTimeAsync(0);

        expect(client.getView().phase).toBe('waiting');
        expect(client.getView().board?.pairing?.code).toBe('482913');
        expect([...data.entries()]).toEqual([
            [
                GUEST_BOARD_KEY,
                JSON.stringify({ board_id: BOARD_A, secret: SECRET_A }),
            ],
        ]);
        expect(tokenOf(client)).toBe(`${BOARD_A}.${SECRET_A}`);
        expect(http.poll).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(WAITING_POLL_MS);

        expect(http.poll).toHaveBeenCalledWith(BOARD_A, expect.anything());
        expect(tokens).toEqual([`${BOARD_A}.${SECRET_A}`]);

        await vi.advanceTimersByTimeAsync(WAITING_POLL_MS);

        expect(http.poll).toHaveBeenCalledTimes(2);
    });

    it("refuses a token that is not the answered board's, and retries opening", async () => {
        const { http, opens } = fakeHttp();
        const { storage, data } = memoryStorage();
        const client = new GuestBoardClient({ http, storage });
        opens.push(
            { ...opened(BOARD_A, SECRET_A), token: `${BOARD_B}.${SECRET_A}` },
            opened(BOARD_A, SECRET_A),
        );

        client.start();
        await vi.advanceTimersByTimeAsync(0);

        expect(client.getView().notice).toBe('open_failed');
        expect(client.getView().phase).toBe('opening');
        expect(data.size).toBe(0);

        await vi.advanceTimersByTimeAsync(1000);

        expect(http.open).toHaveBeenCalledTimes(2);
        expect(client.getView().phase).toBe('waiting');
        expect(client.getView().notice).toBeNull();
    });
});

describe('a reload of the tab', () => {
    it('continues with the stored board and asks at once what it shows', async () => {
        const { http, polls, tokens } = fakeHttp();
        const { storage } = memoryStorage({
            [GUEST_BOARD_KEY]: JSON.stringify({
                board_id: BOARD_A,
                secret: SECRET_A,
            }),
        });
        const client = new GuestBoardClient({ http, storage });
        const shown = props(GAME_1);
        polls.push({
            kind: 'ok',
            board: showing(BOARD_A, GAME_1),
            props: shown,
        });

        client.start();
        await vi.advanceTimersByTimeAsync(0);

        expect(http.open).not.toHaveBeenCalled();
        expect(tokens).toEqual([`${BOARD_A}.${SECRET_A}`]);
        expect(client.getView().phase).toBe('claimed');
        expect(client.getView().props).toBe(shown);
    });

    it('ignores a stored value that is not a board and opens a new one', async () => {
        const { http, opens } = fakeHttp();
        const { storage } = memoryStorage({
            [GUEST_BOARD_KEY]: '{"board_id":"x","secret":"y"}',
        });
        opens.push(opened(BOARD_A, SECRET_A));

        new GuestBoardClient({ http, storage }).start();
        await vi.advanceTimersByTimeAsync(0);

        expect(http.open).toHaveBeenCalledTimes(1);
        expect(http.poll).not.toHaveBeenCalled();
    });
});

describe('what the board shows', () => {
    async function claimedOn(game: string) {
        const fake = fakeHttp();
        const { storage, data } = memoryStorage();
        const client = new GuestBoardClient({ http: fake.http, storage });
        const shown = props(game);
        fake.opens.push(opened(BOARD_A, SECRET_A));
        fake.polls.push({
            kind: 'ok',
            board: showing(BOARD_A, game),
            props: shown,
        });

        client.start();
        await vi.advanceTimersByTimeAsync(WAITING_POLL_MS);

        return { ...fake, client, data, shown };
    }

    it('keeps the first props of a game, so a poll never rebuilds the board, and polls every 10 s while it is live', async () => {
        const { client, polls, http, shown } = await claimedOn(GAME_1);
        polls.push({
            kind: 'ok',
            board: showing(BOARD_A, GAME_1),
            props: props(GAME_1),
        });

        expect(client.getView().props).toBe(shown);
        expect(client.getView().board?.teacher?.name).toBe('Gülnarə Məmmədova');

        await vi.advanceTimersByTimeAsync(WAITING_POLL_MS);
        expect(http.poll).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(PLAYING_POLL_MS - WAITING_POLL_MS);
        expect(http.poll).toHaveBeenCalledTimes(2);
        expect(client.getView().props).toBe(shown);
    });

    it('waits every 2 s for the next game once a game is over', async () => {
        const { client, polls, http } = await claimedOn(GAME_1);
        polls.push({
            kind: 'ok',
            board: showing(BOARD_A, GAME_1, 'finished'),
            props: props(GAME_1),
        });

        await vi.advanceTimersByTimeAsync(PLAYING_POLL_MS);
        expect(http.poll).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(WAITING_POLL_MS);
        expect(http.poll).toHaveBeenCalledTimes(3);
        expect(client.getView().props?.game.id).toBe(GAME_1);
    });

    it('lets the old board settle before the next game takes over', async () => {
        const { client, polls } = await claimedOn(GAME_1);
        let settled!: () => void;
        const settle = vi.fn(
            () => new Promise<void>((resolve) => (settled = resolve)),
        );
        const next = props(GAME_2);
        client.setSettle(settle);
        polls.push({
            kind: 'ok',
            board: showing(BOARD_A, GAME_2),
            props: next,
        });

        await vi.advanceTimersByTimeAsync(PLAYING_POLL_MS);

        expect(settle).toHaveBeenCalledTimes(1);
        expect(client.getView().props?.game.id).toBe(GAME_1);

        settled();
        await vi.advanceTimersByTimeAsync(0);

        expect(client.getView().props).toBe(next);
    });

    it('takes a game that is gone off the board', async () => {
        const { client, polls } = await claimedOn(GAME_1);
        polls.push({
            kind: 'ok',
            board: showing(BOARD_A, null),
            props: null,
        });

        await vi.advanceTimersByTimeAsync(PLAYING_POLL_MS);

        expect(client.getView().phase).toBe('claimed');
        expect(client.getView().props).toBeNull();
    });

    it('asks at once when a board request is refused, at most once a second', async () => {
        const { client, http } = await claimedOn(GAME_1);
        const auth = client.getView().auth;

        if (auth?.kind !== 'bearer') {
            throw new Error('no bearer');
        }

        await vi.advanceTimersByTimeAsync(400);
        auth.onRefused?.(404);
        auth.onRefused?.(404);
        await vi.advanceTimersByTimeAsync(599);
        expect(http.poll).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(1);
        expect(http.poll).toHaveBeenCalledTimes(2);
    });

    it('forgets the bearer on a 401 (closed from the phone, expired) and opens a new board', async () => {
        const { client, polls, opens, data } = await claimedOn(GAME_1);
        polls.push({ kind: 'unauthorized' });
        opens.push(opened(BOARD_B, SECRET_B));

        await vi.advanceTimersByTimeAsync(PLAYING_POLL_MS);

        expect(client.getView().phase).toBe('waiting');
        expect(client.getView().props).toBeNull();
        expect(client.getView().board?.id).toBe(BOARD_B);
        expect(tokenOf(client)).toBe(`${BOARD_B}.${SECRET_B}`);
        expect(data.get(GUEST_BOARD_KEY)).toBe(
            JSON.stringify({ board_id: BOARD_B, secret: SECRET_B }),
        );
    });
});

describe('closing', () => {
    it('settles the board, closes it with its bearer and keeps nothing', async () => {
        const { http, opens } = fakeHttp();
        const { storage, data } = memoryStorage();
        const client = new GuestBoardClient({ http, storage });
        const settle = vi.fn(async () => {});
        client.setSettle(settle);
        opens.push(opened(BOARD_A, SECRET_A));
        client.start();
        await vi.advanceTimersByTimeAsync(0);

        await expect(client.close()).resolves.toBe(true);

        expect(settle).toHaveBeenCalled();
        expect(http.close).toHaveBeenCalledWith(
            BOARD_A,
            expect.objectContaining({ token: `${BOARD_A}.${SECRET_A}` }),
        );
        expect(client.getView()).toMatchObject({
            phase: 'closed',
            board: null,
            props: null,
            auth: null,
        });
        expect(data.size).toBe(0);

        await vi.advanceTimersByTimeAsync(PLAYING_POLL_MS);
        expect(http.poll).not.toHaveBeenCalled();

        opens.push(opened(BOARD_B, SECRET_B));
        client.reopen();
        await vi.advanceTimersByTimeAsync(0);
        expect(client.getView().board?.id).toBe(BOARD_B);
    });

    it('keeps the board and says so when closing fails', async () => {
        const { http, opens } = fakeHttp();
        const { storage, data } = memoryStorage();
        const client = new GuestBoardClient({ http, storage });
        opens.push(opened(BOARD_A, SECRET_A));
        client.start();
        await vi.advanceTimersByTimeAsync(0);
        http.close.mockResolvedValueOnce(false);

        await expect(client.close()).resolves.toBe(false);

        expect(client.getView().notice).toBe('close_failed');
        expect(client.getView().phase).toBe('waiting');
        expect(data.has(GUEST_BOARD_KEY)).toBe(true);
    });

    it('«Mənim deyil — yeni kod» closes the board and shows a new code', async () => {
        const { http, opens } = fakeHttp();
        const { storage } = memoryStorage();
        const client = new GuestBoardClient({ http, storage });
        opens.push(opened(BOARD_A, SECRET_A), opened(BOARD_B, SECRET_B));
        client.start();
        await vi.advanceTimersByTimeAsync(0);

        await client.notMine();
        await vi.advanceTimersByTimeAsync(0);

        expect(http.close).toHaveBeenCalledWith(BOARD_A, expect.anything());
        expect(client.getView().phase).toBe('waiting');
        expect(client.getView().board?.id).toBe(BOARD_B);
    });
});

describe('guestBoardHttp', () => {
    function stubFetch(...responses: Response[]) {
        const calls: { url: string; init: RequestInit }[] = [];

        vi.stubGlobal(
            'fetch',
            vi.fn(async (url: string, init: RequestInit) => {
                calls.push({ url, init });

                return responses.shift() ?? new Response(null, { status: 500 });
            }),
        );

        return calls;
    }

    function json(status: number, body: unknown): Response {
        return new Response(JSON.stringify(body), { status });
    }

    const auth: BoardAuth = {
        kind: 'bearer',
        token: `${BOARD_A}.${SECRET_A}`,
        onRefused: vi.fn(),
    };

    it('opens a board and reads its token from the 201', async () => {
        const calls = stubFetch(
            json(201, {
                board: unclaimed(BOARD_A),
                token: `${BOARD_A}.${SECRET_A}`,
            }),
        );

        await expect(guestBoardHttp().open()).resolves.toEqual(
            opened(BOARD_A, SECRET_A),
        );
        expect(calls[0].url).toBe('/guest-boards');
        expect(calls[0].init.method).toBe('POST');
    });

    it('polls with the bearer in the header; a 401 is its answer, not a refusal', async () => {
        const calls = stubFetch(
            json(200, {
                board: showing(BOARD_A, GAME_1),
                props: props(GAME_1),
            }),
            json(401, { message: 'Unauthenticated.' }),
        );
        const http = guestBoardHttp();

        await expect(http.poll(BOARD_A, auth)).resolves.toEqual({
            kind: 'ok',
            board: showing(BOARD_A, GAME_1),
            props: props(GAME_1),
        });
        await expect(http.poll(BOARD_A, auth)).resolves.toEqual({
            kind: 'unauthorized',
        });

        expect(calls[0].url).toBe(`/guest-boards/${BOARD_A}`);
        expect(calls[0].url).not.toContain(SECRET_A);
        expect(
            (calls[0].init.headers as Record<string, string>).Authorization,
        ).toBe(`Bearer ${BOARD_A}.${SECRET_A}`);
        expect(auth.kind === 'bearer' && auth.onRefused).not.toHaveBeenCalled();
    });

    it("treats a poll whose props are not a board's as a failure", async () => {
        stubFetch(
            json(200, {
                board: showing(BOARD_A, GAME_1),
                props: { game: 'nope' },
            }),
        );

        await expect(guestBoardHttp().poll(BOARD_A, auth)).resolves.toEqual({
            kind: 'failed',
            afterMs: undefined,
        });
    });

    it('closes with DELETE; a board already gone counts as closed', async () => {
        const calls = stubFetch(
            new Response(null, { status: 204 }),
            json(401, {}),
            json(500, {}),
        );
        const http = guestBoardHttp();

        await expect(http.close(BOARD_A, auth)).resolves.toBe(true);
        await expect(http.close(BOARD_A, auth)).resolves.toBe(true);
        await expect(http.close(BOARD_A, auth)).resolves.toBe(false);
        expect(calls[0].init.method).toBe('DELETE');
    });
});
