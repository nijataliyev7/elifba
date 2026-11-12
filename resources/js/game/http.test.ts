import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import type { BoardAuth } from './http';
import {
    authHeaders,
    boardHttp,
    HttpError,
    nameClip,
    SESSION_AUTH,
} from './http';

/*
 * The board's HTTP in its two modes (docs/architecture.md §4): a teacher
 * board sends the session cookie; a guest board also sends its bearer in
 * the `Authorization` header — never in the URL — and hears of every 401
 * and 404 through `onRefused`.
 */

const GAME = '01jz8c3m4n5p6q7r8s9t0v1w2x';
const TOKEN = `01jz8c3m4n5p6q7r8s9t0v1w2y.${'s'.repeat(43)}`;

type Call = { url: string; init: RequestInit };

function stubFetch(...responses: Response[]): Call[] {
    const calls: Call[] = [];

    vi.stubGlobal(
        'fetch',
        vi.fn(async (url: string, init: RequestInit) => {
            calls.push({ url, init });
            const next = responses.shift();

            if (next === undefined) {
                throw new TypeError('network down');
            }

            return next;
        }),
    );

    return calls;
}

function reply(status: number, body?: unknown): Response {
    return new Response(body === undefined ? null : JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function headersOf(call: Call): Record<string, string> {
    return call.init.headers as Record<string, string>;
}

function bearer(onRefused?: (status: 401 | 404) => void): BoardAuth {
    return { kind: 'bearer', token: TOKEN, onRefused };
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('authHeaders', () => {
    it('sends JSON headers, and the bearer only for a guest board', () => {
        expect(authHeaders(SESSION_AUTH)).toEqual({
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        });
        expect(authHeaders(bearer())).toEqual({
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            Authorization: `Bearer ${TOKEN}`,
        });
    });

    it("adds the XSRF cookie's token when the page has one", () => {
        vi.stubGlobal('document', { cookie: 'a=1; XSRF-TOKEN=abc%3D%3D; b=2' });

        expect(authHeaders(bearer())['X-XSRF-TOKEN']).toBe('abc==');
    });
});

describe('boardHttp in bearer mode', () => {
    it('sends the bearer in the header, never in the URL, with the session cookie alongside', async () => {
        const calls = stubFetch(
            reply(200, { game: { status: 'live', board_epoch: 3 } }),
        );

        await expect(boardHttp(GAME, bearer()).claim()).resolves.toEqual({
            status: 'live',
            board_epoch: 3,
        });

        expect(calls).toHaveLength(1);
        expect(calls[0].url).toBe(`/games/${GAME}/board/claim`);
        expect(calls[0].url).not.toContain(TOKEN.split('.')[1]);
        expect(calls[0].init.method).toBe('POST');
        expect(calls[0].init.credentials).toBe('same-origin');
        expect(headersOf(calls[0]).Authorization).toBe(`Bearer ${TOKEN}`);
    });

    it('sends no bearer for a teacher board', async () => {
        const calls = stubFetch(
            reply(200, { game: { status: 'live', board_epoch: 1 } }),
        );

        await boardHttp(GAME).claim();

        expect(headersOf(calls[0])).not.toHaveProperty('Authorization');
    });

    it('tells the guest board of a 401 (board gone) and still fails the call', async () => {
        const onRefused = vi.fn();
        stubFetch(reply(401, { message: 'Unauthenticated.' }));

        await expect(
            boardHttp(GAME, bearer(onRefused)).state(),
        ).rejects.toEqual(new HttpError(401));
        expect(onRefused).toHaveBeenCalledWith(401);
    });

    it('tells the guest board of a 404 (game no longer shown) and keeps the outbox for a retry', async () => {
        const onRefused = vi.fn();
        stubFetch(reply(404, { message: 'Not Found' }));

        await expect(
            boardHttp(GAME, bearer(onRefused)).sync({
                board_epoch: 1,
                events: [],
            }),
        ).resolves.toEqual({ kind: 'retry', afterMs: undefined });
        expect(onRefused).toHaveBeenCalledWith(404);
    });

    it('does not report a 409, a 422 or a status refusal as a lost board', async () => {
        const onRefused = vi.fn();
        const http = boardHttp(GAME, bearer(onRefused));
        stubFetch(
            reply(409, {
                error: 'board_replaced',
                game: { status: 'live', board_epoch: 4 },
            }),
            reply(422, { message: 'invalid' }),
            reply(409, {
                error: 'invalid_transition',
                game: { status: 'finished', board_epoch: 4 },
            }),
        );

        await expect(
            http.sync({ board_epoch: 1, events: [] }),
        ).resolves.toEqual({
            kind: 'replaced',
            game: { status: 'live', board_epoch: 4 },
        });
        await expect(
            http.sync({ board_epoch: 1, events: [] }),
        ).resolves.toEqual({ kind: 'invalid' });
        await expect(http.status('live')).resolves.toEqual({
            kind: 'refused',
            game: { status: 'finished', board_epoch: 4 },
        });
        expect(onRefused).not.toHaveBeenCalled();
    });

    it('patches the status with the bearer', async () => {
        const calls = stubFetch(
            reply(200, { game: { status: 'paused', board_epoch: 2 } }),
        );

        await expect(
            boardHttp(GAME, bearer()).status('paused'),
        ).resolves.toEqual({
            kind: 'ok',
            game: { status: 'paused', board_epoch: 2 },
        });
        expect(calls[0].init.method).toBe('PATCH');
        expect(calls[0].init.body).toBe('{"status":"paused"}');
        expect(headersOf(calls[0]).Authorization).toBe(`Bearer ${TOKEN}`);
    });
});

/*
 * A player's name clip (ADR 016 decision 8): fetched by the ids-only path
 * of the props with the board's own headers, never cached; a 404 is "no
 * clip", not a board that lost its game.
 */
describe('nameClip', () => {
    const PLAYER = '01jz8c3m4n5p6q7r8s9t0v1w2z';
    const PATH = `/games/${GAME}/players/${PLAYER}/voice`;

    function audio(bytes: number): Response {
        return new Response(new Uint8Array(bytes), {
            status: 200,
            headers: { 'Content-Type': 'audio/mpeg' },
        });
    }

    it('fetches the clip with the bearer in the header, never in the URL, and never cached', async () => {
        const calls = stubFetch(audio(16));

        const clip = await nameClip(PATH, bearer());

        expect(clip?.byteLength).toBe(16);
        expect(calls).toHaveLength(1);
        expect(calls[0].url).toBe(PATH);
        expect(calls[0].url).not.toContain(TOKEN.split('.')[1]);
        expect(calls[0].init.method).toBe('GET');
        expect(calls[0].init.cache).toBe('no-store');
        expect(calls[0].init.credentials).toBe('same-origin');
        expect(headersOf(calls[0])).toMatchObject({
            Accept: 'audio/mpeg',
            Authorization: `Bearer ${TOKEN}`,
        });
    });

    it("sends a teacher board's session and no bearer", async () => {
        const calls = stubFetch(audio(4));

        await nameClip(PATH);

        expect(headersOf(calls[0])).not.toHaveProperty('Authorization');
    });

    it('has no clip after a 404 and does not take it for a lost game', async () => {
        const onRefused = vi.fn();
        stubFetch(reply(404, { message: 'Not Found' }));

        await expect(nameClip(PATH, bearer(onRefused))).resolves.toBeNull();
        expect(onRefused).not.toHaveBeenCalled();
    });

    it('tells the guest board of a 401 (board gone)', async () => {
        const onRefused = vi.fn();
        stubFetch(reply(401, { message: 'Unauthenticated.' }));

        await expect(nameClip(PATH, bearer(onRefused))).resolves.toBeNull();
        expect(onRefused).toHaveBeenCalledWith(401);
    });

    it('has no clip when the network is down', async () => {
        stubFetch();

        await expect(nameClip(PATH, bearer())).resolves.toBeNull();
    });
});
