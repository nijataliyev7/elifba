import { createElement } from 'react';
import type { ComponentType, ReactNode } from 'react';
import { LiveMatch, MatchTitle } from '@/components/rooms/draughts/live-match';
import { MatchEnd } from '@/components/rooms/draughts/match-end';
import { ScreenMatch } from '@/components/rooms/draughts/screen-match';
import { LiveRound, RoundTitle } from '@/components/rooms/live-round';
import { RaceEnd } from '@/components/rooms/race-end';
import type { RivalryIcons } from '@/components/rooms/rivalry-strip';
import { ScreenRound } from '@/components/rooms/screen-round';
import type { TauntList } from '@/components/rooms/taunt-parts';
import type { RoomMotion } from '@/components/rooms/use-motion';
import type { RoomSound } from '@/components/rooms/use-room-sound';
import type { ServerClock } from '@/rooms/clock';
import type {
    RoomGame,
    RoomMatch,
    RoomRaceGame,
    RoomRound,
    RoomState,
} from '@/rooms/contract';

/*
 * A room game's views by its kind (docs/frontend.md rule 15 "Board games",
 * ADR 034 decision 27): `GAME_VIEWS` is the one table of what a page draws
 * for a race and for a board game — the live view's title in the game
 * frame's top bar, the live view on a phone and on the screen, and its end
 * — and `liveGame()` the one place a page asks which kind is live. The
 * pages draw through `GameTitle`, `PhoneGame`, `ScreenGame` and `GameEnd`
 * and never compare a kind or a mechanic themselves.
 */

type BoardGame = Extract<RoomGame, { kind: 'board' }>;

/** A live game with the part of the state it is drawn from: a race's round, a board game's match. */
export type LiveGame =
    | { kind: 'race'; state: RoomState; game: RoomRaceGame; round: RoomRound }
    | { kind: 'board'; state: RoomState; game: BoardGame; match: RoomMatch };

/** The state's live game, or null outside one. */
export function liveGame(state: RoomState | null): LiveGame | null {
    const game = state?.game ?? null;

    if (state === null || game === null || game.phase !== 'live') {
        return null;
    }

    if (game.kind === 'race' && state.round !== null) {
        return { kind: 'race', state, game, round: state.round };
    }

    if (game.kind === 'board' && state.match !== null) {
        return { kind: 'board', state, game, match: state.match };
    }

    return null;
}

/** What a phone's live view needs beside its game: the page's own parts. */
export type PhoneParts = {
    roomId: string;
    clock: ServerClock;
    placeLabels: string[];
    refresh: () => void;
    onGone: () => void;
    motion: RoomMotion;
    sound: RoomSound | null;
    icons: RivalryIcons | null;
    taunts: TauntList | null;
    tauntsHidden: boolean;
    onTauntsHiddenChange: (hidden: boolean) => void;
    /** Under the game: the host's «Oyunu bitir». */
    footer: ReactNode;
};

/** What the screen's live view needs beside its game. */
export type ScreenParts = {
    clock: ServerClock;
    placeLabels: string[];
    motion: RoomMotion;
    icons: RivalryIcons;
    taunts: TauntList;
    tauntsHidden: boolean;
};

type Of<K extends LiveGame['kind']> = Extract<LiveGame, { kind: K }>;

type Views<K extends LiveGame['kind']> = {
    Title: ComponentType<{ live: Of<K>; motion: RoomMotion }>;
    Phone: ComponentType<PhoneParts & { live: Of<K> }>;
    Screen: ComponentType<ScreenParts & { live: Of<K> }>;
    /** What the host's «Oyunu bitir» says will happen. */
    stop: string;
};

export const GAME_VIEWS: { race: Views<'race'>; board: Views<'board'> } = {
    race: {
        Title: ({ live, motion }) =>
            createElement(RoundTitle, {
                game: live.game,
                round: live.round,
                motion: motion.round,
            }),
        Phone: ({ live, footer, ...parts }) =>
            createElement(
                'div',
                {
                    className:
                        'mx-auto flex w-full max-w-xl flex-col gap-5 px-4 pt-4 pb-6 md:px-6',
                },
                createElement(LiveRound, {
                    ...parts,
                    state: live.state,
                    game: live.game,
                    round: live.round,
                }),
                footer,
            ),
        Screen: ({ live, ...parts }) =>
            createElement(ScreenRound, {
                ...parts,
                state: live.state,
                game: live.game,
                round: live.round,
            }),
        stop: 'rooms.game.end_description',
    },
    board: {
        Title: ({ live, motion }) =>
            createElement(MatchTitle, {
                game: live.game,
                match: live.match,
                motion: motion.round,
            }),
        Phone: ({ live, ...parts }) =>
            createElement(LiveMatch, {
                ...parts,
                state: live.state,
                game: live.game,
                match: live.match,
            }),
        Screen: ({ live, ...parts }) =>
            createElement(ScreenMatch, {
                ...parts,
                state: live.state,
                game: live.game,
                match: live.match,
            }),
        stop: 'rooms.match.stop_description',
    },
};

/** The live game's title in the game frame's top bar. */
export function GameTitle({
    live,
    motion,
}: {
    live: LiveGame;
    motion: RoomMotion;
}) {
    return live.kind === 'race'
        ? createElement(GAME_VIEWS.race.Title, { live, motion })
        : createElement(GAME_VIEWS.board.Title, { live, motion });
}

/** The live game on a phone: the game frame's children. */
export function PhoneGame({ live, ...parts }: PhoneParts & { live: LiveGame }) {
    return live.kind === 'race'
        ? createElement(GAME_VIEWS.race.Phone, { ...parts, live })
        : createElement(GAME_VIEWS.board.Phone, { ...parts, live });
}

/** The live game on the screen. */
export function ScreenGame({
    live,
    ...parts
}: ScreenParts & { live: LiveGame }) {
    return live.kind === 'race'
        ? createElement(GAME_VIEWS.race.Screen, { ...parts, live })
        : createElement(GAME_VIEWS.board.Screen, { ...parts, live });
}

/** What «Oyunu bitir» says: a race keeps its closed rounds' points; a match ends without a result. */
export function stopLine(live: LiveGame): string {
    return GAME_VIEWS[live.kind].stop;
}

/**
 * A game's end, once it is over: a race's final of the room's standing, a
 * match's own final with its result, its board and the room's standing
 * under it (`MatchEnd`). `big` is the screen's.
 */
export function GameEnd({
    state,
    game,
    placeLabels,
    motion,
    big = false,
}: {
    state: RoomState;
    game: RoomGame;
    placeLabels: string[];
    motion: RoomMotion;
    big?: boolean;
}) {
    if (game.kind === 'board' && state.match?.phase === 'over') {
        return createElement(MatchEnd, {
            state,
            game,
            match: state.match,
            placeLabels,
            motion,
            big,
        });
    }

    return createElement(RaceEnd, { state, game, placeLabels, motion, big });
}
