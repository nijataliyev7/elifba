import { describe, expect, it } from 'vite-plus/test';
import {
    pairingOnLoad,
    playersHome,
    playersLabel,
    playersNewGame,
} from '@/lib/game-players';

/*
 * Who a game is for, on the board and the phone (architecture §2 "Game
 * setup", §4 "At home", ADR 011): a class or a parent's child.
 */

const forClass = {
    schoolClass: { id: '01JCLASS', name: '1A' },
    child: null,
};
const forChild = {
    schoolClass: null,
    child: { id: '01JCHILD', display_name: 'Aysel' },
};

describe('game players', () => {
    it('names the class or the child', () => {
        expect(playersLabel(forClass)).toEqual({
            key: 'games.board.class',
            values: { name: '1A' },
        });
        expect(playersLabel(forChild)).toEqual({
            key: 'games.board.child',
            values: { name: 'Aysel' },
        });
    });

    it("leads back to the class's or the child's page and a new game there, by id only", () => {
        expect(playersHome(forClass).href.url).toBe('/classes/01JCLASS');
        expect(playersHome(forChild)).toMatchObject({
            href: { url: '/children/01JCHILD' },
            label: 'games.board.back_to_child',
        });
        expect(playersNewGame(forChild).url).toBe(
            '/children/01JCHILD/games/create',
        );
    });

    it('does not open the pairing card on a home board', () => {
        expect(pairingOnLoad(forClass)).toBe(true);
        expect(pairingOnLoad(forChild)).toBe(false);
    });
});
