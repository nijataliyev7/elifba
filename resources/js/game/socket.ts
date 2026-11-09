/**
 * Echo for the live pages only (docs/architecture.md §4): the board and the
 * remote import this module, nothing else does, so no other page loads
 * pusher-js. The connection is built from `VITE_REVERB_*` alone (host,
 * port, scheme), so `composer dev`, `composer dev:lan`, HTTPS in
 * development and production behind the proxy all work unchanged.
 *
 * `/broadcasting/auth` gets the board's request headers (`authHeaders()`
 * in `http.ts`), built at each (re)subscription: the teacher's session (the
 * XSRF cookie, read each time), or a guest board's bearer, which wins over
 * any session in the same browser (the channel's guards: board, web).
 */
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { authenticate } from '@/actions/Illuminate/Broadcasting/BroadcastController';
import type { BoardAuth } from './http';
import { authHeaders, SESSION_AUTH } from './http';
import type { LiveChannel, SocketState } from './live';

export type LiveEcho = Echo<'reverb'>;

export function connectEcho(auth: BoardAuth = SESSION_AUTH): LiveEcho {
    const env = import.meta.env;
    const scheme = env.VITE_REVERB_SCHEME ?? 'https';
    const port = Number(
        env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80),
    );

    return new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: env.VITE_REVERB_APP_KEY,
        wsHost: env.VITE_REVERB_HOST,
        wsPort: port,
        wssPort: port,
        forceTLS: scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        channelAuthorization: {
            transport: 'ajax',
            endpoint: authenticate.url(),
            headersProvider: () => authHeaders(auth),
        },
    });
}

function socketState(
    status: ReturnType<LiveEcho['connectionStatus']>,
): SocketState {
    switch (status) {
        case 'connected':
            return 'connected';
        case 'connecting':
        case 'reconnecting':
            return 'connecting';
        default:
            return 'disconnected';
    }
}

/** The game's private channel, `private-game.{id}`, for `live.ts`. */
export function gameChannel(echo: LiveEcho, gameId: string): LiveChannel {
    const name = `game.${gameId}`;
    const channel = echo.private(name);
    const offs: (() => void)[] = [];

    return {
        // Echo adds `client-` on the wire and listens for it (§4's names).
        whisper: (event, payload) => {
            channel.whisper(event, payload);
        },
        onWhisper: (event, listener) => {
            channel.listenForWhisper(event, listener);
        },
        onStatus: (listener) => {
            channel.listen('.session.status', listener);
        },
        onSubscribed: (listener) => {
            channel.subscribed(listener);
        },
        onSocket: (listener) => {
            listener(socketState(echo.connectionStatus()));
            offs.push(
                echo.connector.onConnectionChange((status) =>
                    listener(socketState(status)),
                ),
            );
        },
        close: () => {
            for (const off of offs) {
                off();
            }

            echo.leave(name);
        },
    };
}
