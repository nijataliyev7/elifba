import { show as showClass } from '@/routes/classes';
import { create as createClassGame } from '@/routes/classes/games';
import { show as showChild } from '@/routes/children';
import { create as createChildGame } from '@/routes/children/games';
import type { RouteDefinition } from '@/wayfinder';

/**
 * Who a game is for, as the server sends it (`Play\Data\GamePlayers`,
 * architecture §2 "Game setup", ADR 011): a class or a parent's child,
 * exactly one of them.
 */
export type GamePlayers = {
    schoolClass: { id: string; name: string } | null;
    child: { id: string; display_name: string } | null;
};

/** "1A sinfi" or "Oynayır: Aysel", as a translation key and its values. */
export function playersLabel(players: GamePlayers): {
    key: string;
    values: { name: string };
} {
    return players.child !== null
        ? {
              key: 'games.board.child',
              values: { name: players.child.display_name },
          }
        : {
              key: 'games.board.class',
              values: { name: players.schoolClass?.name ?? '' },
          };
}

/** The page the game belongs to: the class's or the child's, and its label key. */
export function playersHome(players: GamePlayers): {
    href: RouteDefinition<'get'>;
    label: string;
} {
    return players.child !== null
        ? {
              href: showChild(players.child.id),
              label: 'games.board.back_to_child',
          }
        : {
              href: showClass(players.schoolClass?.id ?? ''),
              label: 'games.board.back_to_class',
          };
}

/** A new game for the same class or child. */
export function playersNewGame(players: GamePlayers): RouteDefinition<'get'> {
    return players.child !== null
        ? createChildGame(players.child.id)
        : createClassGame(players.schoolClass?.id ?? '');
}

/**
 * Whether a board opens its pairing card by itself (architecture §4 "At
 * home"): a class's board does, until a phone says hello; a home board
 * waits for «Pultu qoş», since the child usually plays by touch on the
 * one device.
 */
export function pairingOnLoad(players: GamePlayers): boolean {
    return players.child === null;
}
