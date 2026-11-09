import { afterEach, describe, expect, it, vi } from 'vite-plus/test';

/*
 * The Echo factory (docs/architecture.md §4, "Channel and authorization"):
 * `/broadcasting/auth` gets the board's own headers at each
 * (re)subscription — the session's for a teacher board and the remote, the
 * bearer for a guest board. Echo and pusher-js are stubbed: no socket opens.
 */

const echo = vi.hoisted(() => ({ options: [] as Record<string, unknown>[] }));

vi.mock('laravel-echo', () => ({
    default: vi.fn(function (options: Record<string, unknown>) {
        echo.options.push(options);
    }),
}));

vi.mock('pusher-js', () => ({ default: class {} }));

const { connectEcho } = await import('./socket');

type ChannelAuthorization = {
    transport: string;
    endpoint: string;
    headersProvider: () => Record<string, string>;
};

function authorizationOf(index: number): ChannelAuthorization {
    return echo.options[index].channelAuthorization as ChannelAuthorization;
}

afterEach(() => {
    echo.options.length = 0;
    vi.unstubAllGlobals();
});

describe('connectEcho', () => {
    it("authorizes a guest board's channel with its bearer", () => {
        connectEcho({ kind: 'bearer', token: 'board.secret' });

        const authorization = authorizationOf(0);

        expect(authorization.endpoint).toBe('/broadcasting/auth');
        expect(authorization.transport).toBe('ajax');
        expect(authorization.headersProvider()).toMatchObject({
            Accept: 'application/json',
            Authorization: 'Bearer board.secret',
        });
    });

    it('authorizes with the session by default, reading the XSRF cookie at each subscription', () => {
        connectEcho();

        const provider = authorizationOf(0).headersProvider;

        expect(provider()).not.toHaveProperty('Authorization');

        vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=fresh' });

        expect(provider()).toMatchObject({ 'X-XSRF-TOKEN': 'fresh' });
    });
});
