import { describe, expect, it } from 'vite-plus/test';
import type { RoomPlayer } from '@/rooms/contract';
import { readyTally } from './ready';

function player(id: string, extra: Partial<RoomPlayer> = {}): RoomPlayer {
    return {
        id,
        name: id,
        present: true,
        host: false,
        voice: null,
        ready: false,
        ...extra,
    };
}

describe('readyTally', () => {
    it('counts the present players who are not the host, and the ready among them', () => {
        expect(
            readyTally([
                player('host', { host: true }),
                player('a', { ready: true }),
                player('b'),
                player('c', { ready: true }),
            ]),
        ).toEqual({ ready: 2, total: 3 });
    });

    it('leaves out an absent player and the host, whatever the state says of them', () => {
        expect(
            readyTally([
                player('host', { host: true, ready: true }),
                player('gone', { present: false, ready: true }),
                player('a'),
            ]),
        ).toEqual({ ready: 0, total: 1 });
    });

    it('is nothing in a room of the host alone', () => {
        expect(readyTally([player('host', { host: true })])).toEqual({
            ready: 0,
            total: 0,
        });
        expect(readyTally([])).toEqual({ ready: 0, total: 0 });
    });
});
