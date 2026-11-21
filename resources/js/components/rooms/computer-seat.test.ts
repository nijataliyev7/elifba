import { createElement } from 'react';
import type { ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import az from '../../../../lang/az.json';
import en from '../../../../lang/en.json';
import { choiceParts } from '@/components/rooms/catalog';
import { LiveMatch } from '@/components/rooms/draughts/live-match';
import { MatchEnd } from '@/components/rooms/draughts/match-end';
import { ScreenMatch } from '@/components/rooms/draughts/screen-match';
import {
    SeatNext,
    SeatPair,
    SeatRows,
} from '@/components/rooms/draughts/seat-rows';
import { ComputerLevelSegments } from '@/components/rooms/game-settings';
import type { RoomChoice } from '@/components/rooms/game-settings';
import { ChoiceRow } from '@/components/rooms/start-parts';
import { serverClock } from '@/rooms/clock';
import { RoomState } from '@/rooms/contract';
import type { RoomBoardGame, RoomMatch } from '@/rooms/contract';

/*
 * The computer's seat as the pages draw it (docs/frontend.md rule 15 "The
 * computer's seat", ADR 036 decision 15), read from the markup: wherever a
 * side or a final's row has no player it is `Bot` and «Kompüter» with its
 * level — a word and an icon — on the start screen, in a match on the phone
 * and the screen, and at its end; no throw is offered toward it; and a
 * match of two players draws none of it.
 */

const shared: { props: Record<string, unknown> } = { props: {} };

vi.mock('@inertiajs/react', () => ({
    usePage: () => shared,
    Head: () => null,
    Link: ({ children }: { children?: ReactNode }) =>
        createElement('a', null, children),
    Form: ({ children }: { children?: ReactNode }) =>
        createElement('form', null, children),
    router: { reload: () => undefined },
}));

type Dictionary = Record<string, string>;

const fixtures = import.meta.glob<unknown>(
    '../../../../tests/Fixtures/rooms/state.*.json',
    { eager: true, import: 'default' },
);

function state(name: string): RoomState {
    return RoomState.parse(
        fixtures[`../../../../tests/Fixtures/rooms/state.${name}.json`],
    );
}

/** A text as React writes it into the markup. */
function escaped(text: string): string {
    return text
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#x27;');
}

/** How often `part` stands in `html`. */
function count(html: string, part: string): number {
    return html.split(part).length - 1;
}

/** The icon `Bot`, as lucide writes it. */
const BOT = 'lucide-bot';

const still = { phase: false, round: false, strip: false };

/** A live board game of a state, with its match. */
function live(source: RoomState): { game: RoomBoardGame; match: RoomMatch } {
    if (source.game?.kind !== 'board' || source.match === null) {
        throw new Error('A board game with its match.');
    }

    return { game: source.game, match: source.match };
}

/** The match against the computer, over: the computer (black) won, or the player did. */
function over(source: RoomState, winner: 'white' | 'black'): RoomState {
    const { game, match } = live(source);
    const [white, black] = match.sides;
    const rows = [
        {
            player_id: white.player_id,
            points: winner === 'white' ? 3 : 0,
            place: winner === 'white' ? 1 : 2,
        },
        {
            player_id: black.player_id,
            points: 0,
            place: winner === 'black' ? 1 : 2,
        },
    ];

    return RoomState.parse({
        ...source,
        game: { ...game, phase: 'over' },
        setup: { ...source.setup, played: true },
        taunts: null,
        match: {
            ...match,
            phase: 'over',
            turn: null,
            flag_ms: null,
            legal: null,
            offer: null,
            can_offer: false,
            result: {
                outcome: winner,
                ending: 'no_moves',
                standing: winner === 'black' ? rows.reverse() : rows,
                spent: [],
            },
        },
    });
}

describe.each([
    ['az', az as Dictionary],
    ['en', en as Dictionary],
])('in %s', (locale, translations) => {
    const computer = state('match.computer');
    const chosen = state('match.computer.chosen');
    const two = state('match.play');
    const twoChosen = state('match.chosen');
    const level = translations['rooms.computer.level.2'];
    const name = translations['rooms.computer.name'];
    /** «Kompüter (Orta)». */
    const named = escaped(
        translations['rooms.computer.named'].replace(':level', level),
    );
    /** «5+3». */
    const clock = translations['rooms.clock.level']
        .replace(':minutes', '5')
        .replace(':seconds', '3');
    /** What the strip's throw button is named by, before its target. */
    const throwAt = escaped(
        translations['rooms.taunts.at'].split(':name')[0].trim(),
    );
    const t = (key: string, replace: Record<string, string | number> = {}) =>
        (translations[key] ?? key).replace(/:([a-z_]+)/g, (holder, word) =>
            word in replace ? String(replace[word]) : holder,
        );

    beforeEach(() => {
        shared.props = { locale, translations };
    });

    /** The host's choice as the hook gives it, with nothing in flight. */
    const choiceOf = (source: RoomState): RoomChoice => {
        if (source.setup === null) {
            throw new Error('An open room has its choice.');
        }

        return {
            shown: source.setup,
            pending: null,
            failed: false,
            choose: () => undefined,
            pick: () => undefined,
        };
    };

    const phone = (source: RoomState) =>
        renderToStaticMarkup(
            createElement(LiveMatch, {
                ...live(source),
                roomId: source.room.id,
                state: source,
                clock: serverClock(),
                placeLabels: [],
                refresh: () => undefined,
                onGone: () => undefined,
                motion: still,
                sound: null,
                icons: null,
                taunts: [],
            }),
        );

    const screen = (source: RoomState) =>
        renderToStaticMarkup(
            createElement(ScreenMatch, {
                ...live(source),
                state: source,
                clock: serverClock(),
                placeLabels: [],
                motion: still,
                icons: {
                    clash: '/c.svg',
                    leader: '/l.svg',
                    overtake: '/o.svg',
                },
                taunts: [],
                tauntsHidden: false,
            }),
        );

    it('seats the computer on the start screen with its level, and offers its three levels', () => {
        const seats = choiceParts(t, choiceOf(chosen).shown).seats;
        const rows = renderToStaticMarkup(
            createElement(SeatRows, {
                seats,
                players: chosen.players,
                choice: choiceOf(chosen),
            }),
        );

        // The player's row and the computer's: the word, its level and the icon.
        expect(rows).toContain(escaped('Rəşad Quliyev'));
        expect(rows).toContain(named);
        expect(count(rows, BOT)).toBe(1);
        // «Yerləri dəyiş» gives the one player the computer's colour.
        expect(rows).not.toContain('disabled=""');

        const levels = renderToStaticMarkup(
            createElement(ComputerLevelSegments, {
                level: 2,
                choice: choiceOf(chosen),
            }),
        );

        for (const one of [1, 2, 3]) {
            expect(levels).toContain(
                `>${escaped(translations[`rooms.computer.level.${one}`])}</button>`,
            );
        }

        // One is pressed: the choice's level.
        expect(
            [
                ...levels.matchAll(
                    /aria-pressed="true"[^>]*>([^<]*)<\/button>/g,
                ),
            ].map((found) => found[1]),
        ).toEqual([escaped(level)]);
        expect(levels).toContain(
            escaped(translations['rooms.computer.level.label']),
        );
    });

    it("says the computer in the choice's line, beside the seats and for the next game", () => {
        if (chosen.setup === null) {
            throw new Error('An open room has its choice.');
        }

        const row = renderToStaticMarkup(
            createElement(ChoiceRow, { choice: chosen.setup }),
        );

        expect(row).toContain(`${escaped(clock)} · ${named}`);

        const seats = choiceParts(t, chosen.setup).seats;
        const pair = renderToStaticMarkup(
            createElement(SeatPair, { seats, players: chosen.players }),
        );

        expect(pair).toContain(named);
        expect(count(pair, BOT)).toBe(1);

        // The colours change with every game: the computer plays white in the next.
        const next = renderToStaticMarkup(
            createElement(SeatNext, {
                seats: { ...seats, white: null, black: seats.white },
                players: chosen.players,
            }),
        );

        expect(next).toContain(
            escaped(
                t('rooms.seats.next', {
                    name: t('rooms.computer.named', { level }),
                }),
            ),
        );
    });

    it('draws none of it in a choice or a match of two players', () => {
        const seats = choiceParts(t, choiceOf(twoChosen).shown).seats;
        const rows = renderToStaticMarkup(
            createElement(SeatRows, {
                seats,
                players: twoChosen.players,
                choice: choiceOf(twoChosen),
            }),
        );

        expect(rows).not.toContain(BOT);
        expect(rows).not.toContain(escaped(name));

        const match = phone(two);

        expect(match).not.toContain(BOT);
        expect(match).not.toContain(escaped(name));
        // And there the other seat is the throw's button, as ever.
        expect(match).toContain(throwAt);
    });

    it('draws the computer on a phone in a match, and offers no throw toward it', () => {
        const match = phone(computer);

        // The strip's side: the icon and «Kompüter», its level read out.
        expect(match).toContain(BOT);
        expect(match).toContain(
            `<span aria-hidden="true">${escaped(name)}</span>`,
        );
        expect(match).toContain(`<span class="sr-only">${named}</span>`);
        expect(match).toContain(escaped(translations['rooms.rivalry.you']));
        // Nobody sits there: the rival's half is no button.
        expect(match).not.toContain(throwAt);
        expect(match).not.toContain(
            escaped(translations['rooms.show.nameless']),
        );
        // It is the player's turn in the fixture.
        expect(match).toContain(escaped(translations['rooms.match.your_turn']));

        // While the computer thinks, the line says so beside its icon.
        const { match: played } = live(computer);
        const thinking = phone({
            ...computer,
            match: { ...played, turn: 'black', legal: null },
        } as RoomState);

        expect(thinking).toContain(
            escaped(
                t('rooms.match.their_turn', {
                    name: t('rooms.computer.named', { level }),
                }),
            ),
        );
        expect(count(thinking, BOT)).toBe(count(match, BOT) + 1);
    });

    it('draws the computer with its level on the screen, in the strip and the head', () => {
        const match = screen(computer);

        expect(match).toContain(BOT);
        expect(match).toContain(
            `${escaped(translations['rooms.mechanics.draughts.name'])} · ${escaped(clock)} · ${named}`,
        );
        // The strip's side says the level there: no short form.
        expect(match).not.toContain(
            `<span aria-hidden="true">${escaped(name)}</span>`,
        );
        expect(match).not.toContain(
            escaped(translations['rooms.show.nameless']),
        );
    });

    it("says who won and draws the computer's row of the final, nobody's own", () => {
        const end = (source: RoomState, big: boolean) => {
            const ended = live(source);

            if (ended.match.phase !== 'over') {
                throw new Error('A match over.');
            }

            return renderToStaticMarkup(
                createElement(MatchEnd, {
                    state: source,
                    game: ended.game,
                    match: ended.match,
                    placeLabels: ['1.', '2.'],
                    motion: still,
                    big,
                }),
            );
        };
        const you = `>${escaped(translations['rooms.show.you'])}</span>`;
        const lost = end(over(computer, 'black'), false);

        expect(lost).toContain(
            escaped(
                t('rooms.match.won', {
                    name: t('rooms.computer.named', { level }),
                }),
            ),
        );
        expect(lost).toContain('data-player="computer"');
        expect(count(lost, BOT)).toBe(1);
        // «siz» stands on the player's row alone — on a phone once, on the screen never.
        expect(count(lost, you)).toBe(1);
        expect(count(end(over(computer, 'black'), true), you)).toBe(0);
        expect(lost).not.toContain(
            escaped(translations['rooms.show.nameless']),
        );

        const won = end(over(computer, 'white'), false);

        expect(won).toContain(
            escaped(t('rooms.match.won', { name: 'Rəşad Quliyev' })),
        );
        expect(won).toContain(named);
        expect(count(won, BOT)).toBe(1);
    });
});
