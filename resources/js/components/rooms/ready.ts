import type { RoomPlayer } from '@/rooms/contract';

/**
 * «:ready / :total hazırdır» (ADR 035 decision 8, docs/frontend.md rule 15
 * "Ready for the next game"): `total` is the present players who are not
 * the host — the ones who can say «Hazıram» — and `ready` those of them
 * whose mark the state says counts. The page counts what the server
 * derived; it never decides a mark itself.
 */
export function readyTally(players: readonly RoomPlayer[]): {
    ready: number;
    total: number;
} {
    const able = players.filter((player) => player.present && !player.host);

    return {
        ready: able.filter((player) => player.ready).length,
        total: able.length,
    };
}
