import { offerTo, slideOf } from '@/components/rooms/draughts/board';
import { finalRows, finalTier, finalTimeline } from '@/components/rooms/final';
import { momentKey, REVEAL_MS, roomMoment } from '@/components/rooms/motion';
import { standingGroups } from '@/components/rooms/standing-groups';
import { TAUNT_REACTION_MS } from '@/components/rooms/taunts';
import type {
    RoomLastMove,
    RoomMatch,
    RoomMatchResult,
    RoomState,
} from '@/rooms/contract';
import type { RoomSoundName, RoomSpeaker } from '@/rooms/sound';

/*
 * When a room game sounds (ADR 025 "Sound", ADR 028, docs/frontend.md rule
 * 15 "Sound"), the part that is pure: from two consecutive things a page
 * heard — a state and the estimated server clock — the cues to play now.
 * A cue plays when its moment begins and the page saw it begin, once:
 * the state fetched again (`room.changed`, the poll, a timetable fetch)
 * is the same moment and plays nothing, and the moment a page opened on —
 * a reload, a late join, the screen opened mid-game — is silent, as it
 * stands still (`useRoomMotion()`).
 *
 * The screen plays the room's moments: the countdown, one file that ends
 * at `opens_ms`; `go` as round 0 opens; the study and the question of a
 * later round; the clock, one file that ends at `bell_ms`, in an answer's
 * last `CLOCK_MS`; the round closing (the reveal opening) and its ranking;
 * in the final a hit as the third and the second places open, a roll
 * ending where the first opens, then a fanfare and the cheer — the
 * winner's first name and «Əla oynadın!» (ADR 030); and between games a
 * `join` when a player appears. It has no viewer, so nothing it plays
 * depends on anyone's answer. A phone plays the viewer's own: the answer
 * sent (`answered`, the same for every answer) and, from the reveal only,
 * its verdict (`reveal.mine`). Its taps play where they are made
 * (`LiveRound`), not here. In a room without a screen (`room.screen`
 * false, ADR 028 and ADR 030 as amended 2026-11-19) a phone carries the
 * room: it plays the screen's cues as well as its own, the cheer
 * included, its final at a phone's pace (`carriesRoom()`).
 *
 * A match (ADR 034 decision 31) is the room's too: its countdown ending at
 * `starts_ms`, `go` as the clock starts, then for each move the page saw
 * arrive `move` — or `capture` — as the piece lands and `crown` after it
 * when a man was crowned; the clock in the side to move's last
 * `CLOCK_MS`, ending at its flag and stopped by the move; at its end
 * `close` and the final of its own two places (`finalRows()`). A phone
 * plays `offer` when a draw offer arrives at its viewer; never `sent`,
 * `right` or `wrong`.
 *
 * A taunt is said (ADR 029 as amended 2026-11-19, decisions 47 and 48) by
 * the devices that draw its reaction: the target's phone, for a taunt of
 * its `received`, and the screen, for one of its `shown` — once, as the
 * page saw it arrive, and never for a page opened on it, a state fetched
 * again or a reaction already over. No other phone has the taunt, the
 * thrower's included, and a phone that carries the room carries its
 * moments, never another player's taunt. A device that hides taunts says
 * none. A taunt's cue is the taunt's own, not its moment's: the round's
 * close never cuts it (`sameMoment()` reads no taunt).
 */

/** What a page heard at one moment: the latest state and the server's clock as estimated. */
export type RoomEar = { state: RoomState | null; now: number | null };

/**
 * A cue: `at` ms from now it starts — or, with `ends`, it ends there (the
 * countdown, the clock, the roll). `cheer` is the room's one voice (ADR
 * 030; a carrying phone's too, as amended 2026-11-19), as a home board
 * ends a game: from `at`, the first place's first
 * names (`players`, by `player_id`, none when more than `MAX_NAMES` share
 * it), then «Əla oynadın!», or «Əla oynadınız!» when the place is shared
 * (`many`).
 */
export type RoomCue =
    | { sound: RoomSoundName; at: number; ends?: boolean }
    | { sound: 'cheer'; at: number; players: string[]; many: boolean }
    /** A taunt's voice: its id and key, said now; `until`, ms from now, is when its reaction ends. */
    | { sound: 'taunt'; at: number; id: string; key: string; until: number };

/** What the page itself decided: whether this device hides taunts (its «Mənə atılanları gizlət», the screen's «Atmacalar»). */
export type CueOptions = { tauntsHidden?: boolean };

/**
 * A moment heard later than this after it began is not announced: a page
 * that was asleep, or shown old props, joins the moment silently. The
 * longest a state may lag without a socket is a poll (3 s).
 */
export const STALE_MS = 4000;

/**
 * The clock runs the last 5 s of every answer (ADR 028 decision 3): one
 * file that ends at `bell_ms`. Its last 3 s are the most urgent, as the
 * time bar turns red (`URGENT_MS` stays the bar's).
 */
export const CLOCK_MS = 5000;

/**
 * The clock is heard this much ahead of its start, more than the page's
 * listening step (200 ms), so the player schedules it to the sample.
 */
export const LEAD_MS = 500;

/**
 * The fanfare's length (`scripts/room-sounds/README.md`, ADR 028): the
 * cheer follows it (ADR 030). A fanfare of another length changes
 * this number in the same change.
 */
export const FANFARE_MS = 2300;

/**
 * The most who share the first place and are named, one after another, in
 * the state's order; when more share it, nobody is named and the cheer is
 * «Əla oynadınız!» alone (ADR 030 decision 4).
 */
export const MAX_NAMES = 3;

/**
 * What one ear means for a speaker: the moment (the game, the round and
 * its phase) with when it began and its cues; the beats within it — the
 * clock, the viewer's answer sent — each with its own cues; and, between
 * games on the screen, the players the state lists.
 */
type Heard = {
    moment: string;
    began: number | null;
    cues: RoomCue[];
    beats: Partial<Record<'clock' | 'sent' | 'offer', RoomCue[]>>;
    players: string[] | null;
};

const now0 = (sound: RoomSoundName): RoomCue => ({ sound, at: 0 });

/**
 * The cheer after the fanfare: who holds the final's first place, in the
 * order of the final's rows (the server's, never re-sorted: a race's
 * ranking, a match's own standing — ADR 034 decision 26) — none named when
 * more than `MAX_NAMES` share it — and whether it is shared.
 */
function cheer(state: RoomState, at: number): RoomCue {
    const first = finalRows(state)
        .filter((row) => row.place === 1)
        .map((row) => row.player_id);

    return {
        sound: 'cheer',
        at,
        players: first.length > MAX_NAMES ? [] : first,
        many: first.length > 1,
    };
}

/**
 * The final's cues: a hit as the third and the second open, the roll
 * ending where the first opens, the fanfare, and after it the cheer — at
 * the pace of the final the page shows: the screen's, or a phone's, which
 * is quicker (`finalTimeline()`).
 */
function finalCues(state: RoomState, pace: RoomSpeaker): RoomCue[] {
    const groups = standingGroups(finalRows(state), []);
    const timeline = finalTimeline(groups, pace);

    if (timeline.winnerAt === null) {
        return [];
    }

    const places = groups
        .map((group, index) => ({
            tier: finalTier(group.place),
            at: timeline.opensAt[index],
        }))
        .filter(({ tier }) => tier === 'second' || tier === 'third')
        .sort((a, b) => a.at - b.at)
        .map(({ at }): RoomCue => ({ sound: 'place', at }));

    return [
        ...places,
        { sound: 'roll', at: timeline.winnerAt, ends: true },
        { sound: 'fanfare', at: timeline.winnerAt },
        cheer(state, timeline.winnerAt + FANFARE_MS),
    ];
}

/** The endings a move made: the move that ended the game is heard before its close. */
const MOVE_ENDINGS: ReadonlySet<RoomMatchResult['ending']> = new Set([
    'no_moves',
    'repetition',
    'three_kings',
    'main_road',
    'balance',
    'kings_only',
]);

/**
 * A move's cues: `move`, or `capture` when it took a piece, as the piece
 * lands (the board's slide, rule 15 "Motion"), then `crown` when a man was
 * crowned.
 */
function moveCues(last: RoomLastMove): RoomCue[] {
    const lands = slideOf(last).ms;

    return [
        { sound: last.captured.length > 0 ? 'capture' : 'move', at: lands },
        ...(last.crowned ? [{ sound: 'crown' as const, at: lands + 120 }] : []),
    ];
}

/** A match's end: the move that made it, then `close` (ADR 034 decision 31); a race's end has the final alone. */
function endCues(match: RoomMatch | null): RoomCue[] {
    if (match === null || match.phase !== 'over') {
        return [];
    }

    if (match.last !== null && MOVE_ENDINGS.has(match.result.ending)) {
        const moved = moveCues(match.last);

        return [
            ...moved,
            {
                sound: 'close',
                at: Math.max(...moved.map((cue) => cue.at)) + 300,
            },
        ];
    }

    return [now0('close')];
}

/**
 * A live match's moments, as the room's speaker plays them: the countdown
 * ending at `starts_ms`; `go` as the clock starts; each later ply's move
 * as it arrives; and, in the side to move's last `CLOCK_MS`, the clock
 * ending at its flag — a beat of the ply, stopped by the next move.
 */
function hearMatch(match: RoomMatch, now: number | null, quiet: Heard): Heard {
    if (match.phase === 'countdown') {
        return now === null
            ? quiet
            : {
                  ...quiet,
                  cues: [
                      {
                          sound: 'countdown',
                          at: match.starts_ms - now,
                          ends: true,
                      },
                  ],
              };
    }

    if (match.phase !== 'play') {
        return quiet;
    }

    const left = now === null ? null : match.flag_ms - now;
    const clock = left !== null && left > 0 && left <= CLOCK_MS + LEAD_MS;

    return {
        ...quiet,
        began: match.since_ms,
        cues:
            match.ply === 0 || match.last === null
                ? [now0('go')]
                : moveCues(match.last),
        beats:
            clock && left !== null
                ? { clock: [{ sound: 'clock', at: left, ends: true }] }
                : {},
    };
}

/** The room's moments, as the screen plays them — or a carrying phone, at its final's pace. */
function hearScreen(
    state: RoomState | null,
    now: number | null,
    pace: RoomSpeaker = 'screen',
): Heard {
    const moment = momentKey(roomMoment(state), 'phase');
    const quiet: Heard = {
        moment,
        began: null,
        cues: [],
        beats: {},
        players: null,
    };

    if (state === null) {
        return quiet;
    }

    // Between games: before the first, and while the last one's final stands.
    const players = state.players.map((player) => player.id);

    if (state.game === null) {
        return { ...quiet, players };
    }

    if (state.game.phase === 'over') {
        return {
            ...quiet,
            cues: [...endCues(state.match), ...finalCues(state, pace)],
            players,
        };
    }

    const round = state.round;

    if (round === null) {
        return state.match === null
            ? quiet
            : hearMatch(state.match, now, quiet);
    }

    switch (round.phase) {
        case 'countdown':
            return now === null
                ? quiet
                : {
                      ...quiet,
                      cues: [
                          {
                              sound: 'countdown',
                              at: round.opens_ms - now,
                              ends: true,
                          },
                      ],
                  };
        case 'study':
            // Round 0's opening is the game's start.
            return {
                ...quiet,
                began: round.opens_ms,
                cues: [now0(round.index === 0 ? 'go' : 'study')],
            };
        case 'answer': {
            const opening = round.answer_from_ms === round.opens_ms;
            const left = now === null ? null : round.bell_ms - now;
            const clock =
                left !== null && left > 0 && left <= CLOCK_MS + LEAD_MS;

            return {
                ...quiet,
                began: round.answer_from_ms,
                cues: [now0(round.index === 0 && opening ? 'go' : 'question')],
                beats:
                    clock && left !== null
                        ? { clock: [{ sound: 'clock', at: left, ends: true }] }
                        : {},
            };
        }
        case 'reveal':
            return {
                ...quiet,
                began: round.closes_ms,
                cues: [
                    now0('close'),
                    { sound: 'ranking', at: REVEAL_MS.ranking },
                ],
            };
    }
}

function hearPhone(state: RoomState | null): Heard {
    const moment = momentKey(roomMoment(state), 'phase');
    const quiet: Heard = {
        moment,
        began: null,
        cues: [],
        beats: {},
        players: null,
    };
    const match = state?.game?.phase === 'live' ? state.match : null;

    if (match !== null && match.phase === 'play') {
        // A draw offer made to the viewer's seat: the side to move, whose offer it is not.
        return {
            ...quiet,
            began: match.since_ms,
            beats:
                offerTo(match, state?.me.player_id ?? null) !== null
                    ? { offer: [now0('offer')] }
                    : {},
        };
    }

    const round = state?.game?.phase === 'live' ? state.round : null;

    if (state === null || round === null) {
        return quiet;
    }

    if (round.phase === 'answer') {
        // The answer is in: one sound for every answer, from `answered` only.
        return {
            ...quiet,
            began: round.answer_from_ms,
            beats: round.answered ? { sent: [now0('sent')] } : {},
        };
    }

    if (round.phase === 'reveal' && state.me.player_id !== null) {
        // From the reveal only: the viewer's own verdict; no answer, no sound.
        const mine = round.reveal.mine;

        return {
            ...quiet,
            began: round.closes_ms,
            cues: mine === null ? [] : [now0(mine.correct ? 'right' : 'wrong')],
        };
    }

    return quiet;
}

/**
 * Whether a phone carries the room (ADR 028 and ADR 030 as amended
 * 2026-11-19): an open room whose big screen is not on (`room.screen`), so
 * the phone plays the screen's cues as well as its own — the cheer
 * included — at its own level, and its final at a phone's pace.
 */
export function carriesRoom(state: RoomState | null): boolean {
    return state !== null && state.room.status === 'open' && !state.room.screen;
}

/** A carrying phone hears as the screen does, at its own final's pace, and as itself. */
function hearBoth(state: RoomState | null, now: number | null): Heard {
    const room = hearScreen(state, now, 'phone');
    const own = hearPhone(state);

    return {
        moment: room.moment,
        began: room.began ?? own.began,
        cues: [...room.cues, ...own.cues],
        beats: { ...room.beats, ...own.beats },
        players: room.players,
    };
}

/** What `ear` means for `speaker`. */
function hear(ear: RoomEar, speaker: RoomSpeaker): Heard {
    if (speaker === 'screen') {
        return hearScreen(ear.state, ear.now);
    }

    return carriesRoom(ear.state)
        ? hearBoth(ear.state, ear.now)
        : hearPhone(ear.state);
}

/** The cues of every beat in `heard`, or only of those `before` did not have. */
function beatCues(heard: Heard, before: Heard | null): RoomCue[] {
    return (Object.keys(heard.beats) as (keyof Heard['beats'])[])
        .filter((beat) => before === null || before.beats[beat] === undefined)
        .flatMap((beat) => heard.beats[beat] ?? []);
}

/**
 * Whether a state's taunts are drawn at all: an open room whose host has
 * taunts on, during a live game. Outside it a taunt's voice stops.
 */
export function tauntsLive(state: RoomState | null): boolean {
    return (
        state !== null &&
        state.room.status === 'open' &&
        state.room.taunts &&
        state.game !== null &&
        state.game.phase === 'live' &&
        state.taunts !== null
    );
}

/** The taunts `speaker` draws as reactions: the target's own on a phone, the room's on the screen. */
function tauntsDrawn(
    state: RoomState | null,
    speaker: RoomSpeaker,
): { id: string; taunt: string; sent_ms: number }[] {
    if (state === null || !tauntsLive(state) || state.taunts === null) {
        return [];
    }

    return speaker === 'screen'
        ? (state.taunts.shown ?? [])
        : state.taunts.received;
}

/**
 * A taunt's voice for each taunt the page saw arrive between two ears —
 * older first, and none whose reaction is already over by the server's
 * clock — or none while this device hides taunts.
 */
function tauntCues(
    previous: RoomEar,
    current: RoomEar,
    speaker: RoomSpeaker,
    hidden: boolean,
): RoomCue[] {
    const now = current.now;

    if (hidden || now === null) {
        return [];
    }

    const known = new Set(
        tauntsDrawn(previous.state, speaker).map(({ id }) => id),
    );

    return tauntsDrawn(current.state, speaker)
        .filter(
            (taunt) =>
                !known.has(taunt.id) && taunt.sent_ms + TAUNT_REACTION_MS > now,
        )
        .sort((a, b) => a.sent_ms - b.sent_ms || (a.id < b.id ? -1 : 1))
        .map((taunt): RoomCue => ({
            sound: 'taunt',
            at: 0,
            id: taunt.id,
            key: taunt.taunt,
            until: taunt.sent_ms + TAUNT_REACTION_MS - now,
        }));
}

/**
 * The cues to play now, from what the page heard before (`previous`, null
 * for the first thing it heard) and what it hears now: a moment's cues —
 * with the beats it began on, if any — when a new moment began recently; a
 * beat's when a new beat came within the same moment; `join` when a player
 * appeared between games; a taunt's voice for each taunt the page saw
 * arrive (unless `options.tauntsHidden`); and nothing when nothing began.
 */
export function roomCues(
    previous: RoomEar | null,
    current: RoomEar,
    speaker: RoomSpeaker,
    options: CueOptions = {},
): RoomCue[] {
    if (previous === null) {
        return [];
    }

    const taunts = tauntCues(
        previous,
        current,
        speaker,
        options.tauntsHidden ?? false,
    );
    const before = hear(previous, speaker);
    const heard = hear(current, speaker);

    if (heard.moment !== before.moment) {
        const late =
            heard.began !== null &&
            current.now !== null &&
            current.now - heard.began > STALE_MS;

        return late
            ? taunts
            : [...heard.cues, ...beatCues(heard, null), ...taunts];
    }

    const cues = beatCues(heard, before);

    if (heard.players !== null && before.players !== null) {
        const known = new Set(before.players);

        if (heard.players.some((id) => !known.has(id))) {
            cues.push(now0('join'));
        }
    }

    return [...cues, ...taunts];
}

/** Whether two ears are one moment for `speaker`: every cue of a moment stops when it ends. */
export function sameMoment(
    previous: RoomEar,
    current: RoomEar,
    speaker: RoomSpeaker,
): boolean {
    return hear(previous, speaker).moment === hear(current, speaker).moment;
}
