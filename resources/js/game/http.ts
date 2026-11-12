/**
 * The board's HTTP calls (docs/architecture.md §4): the claim, the state,
 * `/sync` and status changes, as JSON. Responses are parsed with the
 * contract's zod schemas, so nothing malformed reaches the engine. Laravel
 * accepts same-origin `fetch` by its `Sec-Fetch-Site` header; the XSRF
 * cookie goes along for browsers that do not send it.
 *
 * Two board modes, one protocol: a teacher board sends the session cookie;
 * a guest board (`/lovhe`) sends `Authorization: Bearer {board_id}.{secret}`
 * as well, and the bearer wins over any session in the same browser
 * (`auth:board,web`). The token lives in the tab's `sessionStorage` and in
 * memory only: it never goes into a URL, an error or a log. A guest board
 * hears of every 401 (the board is closed or expired) and 404 (the board no
 * longer shows this game) through `onRefused`, so it can ask what it shows.
 */
import { z } from 'zod';
import {
    joinCode as joinCodeRoute,
    state as stateRoute,
    sync as syncRoute,
} from '@/routes/games';
import { claim as claimRoute } from '@/routes/games/board';
import { update as statusRoute } from '@/routes/games/status';
import {
    BoardReplaced,
    GameState,
    GameStatus,
    GameStatusResponse,
    PairingResponse,
    SyncResponse,
} from './contract';
import type { Pairing, SyncRequest } from './contract';
import type { SendResult } from './outbox';

export type BoardHttp = {
    claim: () => Promise<GameStatus>;
    state: () => Promise<GameState>;
    sync: (request: SyncRequest) => Promise<SendResult>;
    status: (
        status: 'live' | 'paused' | 'finished',
    ) => Promise<StatusChangeResult>;
};

export type StatusChangeResult =
    | { kind: 'ok'; game: GameStatus }
    | { kind: 'refused'; game: GameStatus }
    | { kind: 'failed' };

export type JoinCodeResult =
    | { kind: 'ok'; pairing: Pairing }
    /** The game has ended: there is nothing to pair with. */
    | { kind: 'game_over' }
    | { kind: 'failed' };

/** How a board's requests are authenticated. */
export type BoardAuth =
    | { kind: 'session' }
    | {
          kind: 'bearer';
          token: string;
          /** A board request answered 401 (board gone) or 404 (game no longer shown). */
          onRefused?: (status: 401 | 404) => void;
      };

export const SESSION_AUTH: BoardAuth = { kind: 'session' };

export class HttpError extends Error {
    constructor(readonly status: number) {
        super(`HTTP ${status}`);
    }
}

const InvalidTransition = z.strictObject({
    error: z.literal('invalid_transition'),
    game: GameStatus,
});

function xsrfToken(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = /(?:^|;\s*)XSRF-TOKEN=([^;]*)/.exec(document.cookie);

    return match ? decodeURIComponent(match[1]) : null;
}

/**
 * The headers of a board's requests, and of `/broadcasting/auth` for its
 * channel (`socket.ts`): JSON, the XSRF cookie's token, and the bearer of a
 * guest board.
 */
export function authHeaders(
    auth: BoardAuth = SESSION_AUTH,
): Record<string, string> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const xsrf = xsrfToken();

    if (xsrf !== null) {
        headers['X-XSRF-TOKEN'] = xsrf;
    }

    if (auth.kind === 'bearer') {
        headers.Authorization = `Bearer ${auth.token}`;
    }

    return headers;
}

export async function request(
    method: 'GET' | 'POST' | 'PATCH' | 'DELETE',
    url: string,
    body?: unknown,
    auth: BoardAuth = SESSION_AUTH,
): Promise<Response> {
    const headers = authHeaders(auth);

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const init: RequestInit = {
        method,
        headers,
        credentials: 'same-origin',
        cache: 'no-store',
    };

    if (body !== undefined) {
        init.body = JSON.stringify(body);
    }

    const response = await fetch(url, init);

    if (
        auth.kind === 'bearer' &&
        (response.status === 401 || response.status === 404)
    ) {
        auth.onRefused?.(response.status);
    }

    return response;
}

export async function json(response: Response): Promise<unknown> {
    try {
        return (await response.json()) as unknown;
    } catch {
        return null;
    }
}

export function retryAfter(response: Response): number | undefined {
    const seconds = Number(response.headers.get('Retry-After'));

    return Number.isFinite(seconds) && seconds > 0 ? seconds * 1000 : undefined;
}

export function boardHttp(
    gameId: string,
    auth: BoardAuth = SESSION_AUTH,
): BoardHttp {
    return {
        async claim() {
            const response = await request(
                'POST',
                claimRoute.url(gameId),
                undefined,
                auth,
            );

            if (!response.ok) {
                throw new HttpError(response.status);
            }

            return GameStatusResponse.parse(await json(response)).game;
        },

        async state() {
            const response = await request(
                'GET',
                stateRoute.url(gameId),
                undefined,
                auth,
            );

            if (!response.ok) {
                throw new HttpError(response.status);
            }

            return GameState.parse(await json(response));
        },

        async sync(body) {
            let response: Response;

            try {
                response = await request(
                    'POST',
                    syncRoute.url(gameId),
                    body,
                    auth,
                );
            } catch {
                return { kind: 'retry' };
            }

            if (response.ok) {
                const parsed = SyncResponse.safeParse(await json(response));

                return parsed.success
                    ? { kind: 'ok', response: parsed.data }
                    : { kind: 'retry' };
            }

            if (response.status === 409) {
                const parsed = BoardReplaced.safeParse(await json(response));

                return parsed.success
                    ? { kind: 'replaced', game: parsed.data.game }
                    : { kind: 'retry' };
            }

            if (response.status === 422) {
                return { kind: 'invalid' };
            }

            return { kind: 'retry', afterMs: retryAfter(response) };
        },

        async status(status) {
            let response: Response;

            try {
                response = await request(
                    'PATCH',
                    statusRoute.url(gameId),
                    { status },
                    auth,
                );
            } catch {
                return { kind: 'failed' };
            }

            const body = await json(response);

            if (response.ok) {
                const parsed = GameStatusResponse.safeParse(body);

                return parsed.success
                    ? { kind: 'ok', game: parsed.data.game }
                    : { kind: 'failed' };
            }

            const refused = InvalidTransition.safeParse(body);

            return refused.success
                ? { kind: 'refused', game: refused.data.game }
                : { kind: 'failed' };
        },
    };
}

/**
 * The clip of a player's first name (§3 "Voice", ADR 016 decision 8): the
 * `name_audio` path of the board's props, `/games/{game}/players/{player}/voice`
 * — ids only, never a name or a hash — fetched with the board's own
 * headers (a guest board's bearer in `Authorization`) and never cached.
 * Null when there is none: a 404 here means "no clip", not a board that
 * lost its game, so `onRefused` hears only a 401.
 */
export async function nameClip(
    url: string,
    auth: BoardAuth = SESSION_AUTH,
): Promise<ArrayBuffer | null> {
    let response: Response;

    try {
        response = await fetch(url, {
            method: 'GET',
            headers: { ...authHeaders(auth), Accept: 'audio/mpeg' },
            credentials: 'same-origin',
            cache: 'no-store',
        });
    } catch {
        return null;
    }

    if (auth.kind === 'bearer' && response.status === 401) {
        auth.onRefused?.(401);
    }

    if (!response.ok) {
        return null;
    }

    try {
        return await response.arrayBuffer();
    } catch {
        return null;
    }
}

/**
 * The teacher board's pairing card: `POST /games/{game}/join-code` keeps a
 * code that is still valid and replaces an expired one (§4, "Pairing").
 */
export async function issueJoinCode(gameId: string): Promise<JoinCodeResult> {
    let response: Response;

    try {
        response = await request('POST', joinCodeRoute.url(gameId));
    } catch {
        return { kind: 'failed' };
    }

    if (response.status === 409) {
        return { kind: 'game_over' };
    }

    const parsed = PairingResponse.safeParse(await json(response));

    return response.ok && parsed.success
        ? { kind: 'ok', pairing: parsed.data.pairing }
        : { kind: 'failed' };
}
