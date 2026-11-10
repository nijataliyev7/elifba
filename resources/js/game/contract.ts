/**
 * The round contract (docs/architecture.md §2, ADR 003): what the server,
 * the board and the phone exchange. The schemas are the source; every
 * TypeScript type is inferred from them, never written by hand. The PHP
 * side is `app/Domain/Play/Data/*`; both are pinned to
 * `tests/Fixtures/contract/*.json` (Pest round-trips them, Vitest parses
 * them strictly), so a key added on one side fails until the fixtures and
 * the other side change too. JSON keys are snake_case.
 *
 * The client events of §4 (protocol v1) are here too: what the phone and
 * the board whisper to each other over Reverb. No PHP sees them (except
 * `session.status`, which the server broadcasts), so they have no DTO and
 * no fixture; every receiver parses them with these schemas and drops what
 * fails. They carry ids only, never a name.
 */
import { z } from 'zod';

const id = z.string().min(1);
const nullableText = z.string().nullable();
/** An event id: a ULID, as `/sync` requires (`BoardEventsData::rules()`). */
const eventId = z.ulid();

export const Mechanic = z.enum([
    'find',
    'pop',
    'build_syllable',
    'build_word',
    'build_sentence',
]);
export const Unit = z.enum(['letter', 'syllable', 'word', 'sentence']);
export const Effect = z.enum(['burst', 'sparkle']);
export const SessionStatus = z.enum([
    'live',
    'paused',
    'finished',
    'abandoned',
]);

export const Round = z.strictObject({
    id,
    index: z.int().min(0),
    total: z.int().min(1),
    mechanic: Mechanic,
    unit: Unit,
    pack: z.string(),
    level: z.string(),
    prompt: z.strictObject({
        text: z.string(),
        show: nullableText,
        image: nullableText,
        audio: nullableText,
        tail: nullableText,
    }),
    items: z.array(
        z.strictObject({
            id,
            text: z.string(),
            image: nullableText,
            content_key: z.string(),
        }),
    ),
    slots: z.int().min(0),
    solution: z.strictObject({
        order: z.enum(['any', 'sequence']),
        item_ids: z.array(id).min(1),
    }),
    effect: Effect,
});

export const Hit = z.strictObject({
    event_id: eventId,
    round_id: id,
    item_id: id,
    source: z.enum(['remote', 'board']),
    student_id: id.nullable(),
    client_at: z.iso.datetime().nullable(),
});

export const Skip = z.strictObject({
    event_id: eventId,
    round_id: id,
    client_at: z.iso.datetime().nullable(),
});

export const BoardEvent = z.discriminatedUnion('type', [
    Hit.extend({ type: z.literal('hit') }),
    Skip.extend({ type: z.literal('skip') }),
]);

export const AnswerStatus = z.enum([
    'correct',
    'miss',
    'skipped',
    'duplicate',
    'stale',
]);

export const AnswerResult = z.strictObject({
    event_id: id,
    status: AnswerStatus,
    round_solved: z.boolean(),
    points: z.int().nullable(),
});

/** A game's status and the epoch of the board allowed to write (§4). */
export const GameStatus = z.strictObject({
    status: SessionStatus,
    board_epoch: z.int().min(0),
});

export const RoundProgress = z.strictObject({
    round_id: id,
    hit_item_ids: z.array(id),
    misses: z.int().min(0),
    solved: z.boolean(),
    skipped: z.boolean(),
});

/** `GET /games/{game}/state`; also the base of the board's `client-state` (A7). */
export const GameState = z.strictObject({
    game: z.strictObject({
        id,
        status: SessionStatus,
        board_epoch: z.int().min(0),
        pack: z.string(),
        mechanic: Mechanic,
    }),
    rounds: z.array(Round),
    progress: z.array(RoundProgress),
});

/** The body of `POST /games/{game}/sync`: the board's outbox, in order. */
export const SyncRequest = z.strictObject({
    board_epoch: z.int().min(0),
    events: z.array(BoardEvent).min(1).max(50),
});

/** The answer of `POST /games/{game}/sync`: one result per event, in order. */
export const SyncResponse = z.strictObject({
    results: z.array(AnswerResult),
    game: GameStatus,
});

/** `POST /games/{game}/sync` with an old epoch: 409, and this board stops. */
export const BoardReplaced = z.strictObject({
    error: z.literal('board_replaced'),
    game: GameStatus,
});

/** `POST /games/{game}/board/claim` and `PATCH /games/{game}/status`. */
export const GameStatusResponse = z.strictObject({
    game: GameStatus,
});

/** `{APP_URL}/pult/{code}` and the code a board shows (`POST /games/{game}/join-code`). */
export const Pairing = z.strictObject({
    code: z.string().regex(/^\d{6}$/),
    expires_at: z.iso.datetime(),
    url: z.string().min(1),
    entry_url: z.string().min(1),
});

export const PairingResponse = z.strictObject({ pairing: Pairing });

/**
 * A guest board as its own tab sees it (`GuestBoardData`, §4): its code
 * while unclaimed, then the claiming teacher's name and the game it shows.
 */
export const GuestBoard = z.strictObject({
    id: z.ulid(),
    expires_at: z.iso.datetime(),
    pairing: Pairing.nullable(),
    teacher: z.strictObject({ name: z.string() }).nullable(),
    game: z.strictObject({ id: z.ulid(), status: SessionStatus }).nullable(),
});

/** `POST /guest-boards`: the only answer that carries the secret (`{board_id}.{secret}`). */
export const OpenedGuestBoard = z.strictObject({
    board: GuestBoard,
    token: z.string().regex(/^[0-9A-Za-z]{26}\.[A-Za-z0-9_-]{43}$/),
});

// Client events (§4, protocol v1). Wire names are `client-{name}`; Echo's
// `whisper(name)` adds the prefix and `listenForWhisper(name)` hears it.

const v1 = z.literal(1);

/** remote → board: a remote (re)joined; the board answers with its state. */
export const ClientHello = z.strictObject({
    v: v1,
    device_id: z.string().min(1).max(64),
});

/** remote → board: the teacher tapped an item. */
export const ClientHit = z.strictObject({
    v: v1,
    event_id: eventId,
    round_id: id,
    item_id: id,
    client_at: z.iso.datetime(),
});

/** remote → board: who is at the board (null: the whole class, no points). */
export const ClientStudent = z.strictObject({
    v: v1,
    student_id: id.nullable(),
});

export const ControlAction = z.enum([
    'pause',
    'resume',
    'skip',
    'end',
    /** Show the pairing card on the board again. */
    'pairing',
]);

/** remote → board: the teacher's buttons. */
export const ClientControl = z.strictObject({
    v: v1,
    action: ControlAction,
});

/** remote → board: every 5 s; the board says "remote disconnected" after 10 s without one. */
export const ClientPing = z.strictObject({
    v: v1,
    t: z.number().finite(),
});

/**
 * board → remote: a snapshot, sent on hello, after every change and every
 * 5 s. `round_id` is the round on the canvas, null while the board is not
 * playing (loading, waiting for «Başla», over). `last_points` is what the
 * server gave the round solved last.
 */
export const ClientState = z.strictObject({
    v: v1,
    board_epoch: z.int().min(0),
    status: SessionStatus,
    round_id: id.nullable(),
    round_index: z.int().min(0).nullable(),
    student_id: id.nullable(),
    hit_item_ids: z.array(id),
    misses: z.int().min(0),
    last_points: z.int().min(0).nullable(),
});

export const AckStatus = z.enum(['correct', 'miss', 'duplicate', 'stale']);

/** board → remote: what one `client-hit` came to. */
export const ClientAck = z.strictObject({
    v: v1,
    event_id: eventId,
    status: AckStatus,
});

/** server → both: `session.status`, a status changed through HTTP or the scheduler. */
export const SessionStatusEvent = z.strictObject({
    v: v1,
    status: SessionStatus,
});

export type Mechanic = z.infer<typeof Mechanic>;
export type Unit = z.infer<typeof Unit>;
export type Effect = z.infer<typeof Effect>;
export type SessionStatus = z.infer<typeof SessionStatus>;
export type Round = z.infer<typeof Round>;
export type RoundItem = Round['items'][number];
export type Hit = z.infer<typeof Hit>;
export type Skip = z.infer<typeof Skip>;
export type BoardEvent = z.infer<typeof BoardEvent>;
export type AnswerStatus = z.infer<typeof AnswerStatus>;
export type AnswerResult = z.infer<typeof AnswerResult>;
export type GameStatus = z.infer<typeof GameStatus>;
export type RoundProgress = z.infer<typeof RoundProgress>;
export type GameState = z.infer<typeof GameState>;
export type SyncRequest = z.infer<typeof SyncRequest>;
export type SyncResponse = z.infer<typeof SyncResponse>;
export type BoardReplaced = z.infer<typeof BoardReplaced>;
export type GameStatusResponse = z.infer<typeof GameStatusResponse>;
export type Pairing = z.infer<typeof Pairing>;
export type GuestBoard = z.infer<typeof GuestBoard>;
export type OpenedGuestBoard = z.infer<typeof OpenedGuestBoard>;
export type ClientHello = z.infer<typeof ClientHello>;
export type ClientHit = z.infer<typeof ClientHit>;
export type ClientStudent = z.infer<typeof ClientStudent>;
export type ControlAction = z.infer<typeof ControlAction>;
export type ClientControl = z.infer<typeof ClientControl>;
export type ClientPing = z.infer<typeof ClientPing>;
export type ClientState = z.infer<typeof ClientState>;
export type AckStatus = z.infer<typeof AckStatus>;
export type ClientAck = z.infer<typeof ClientAck>;
export type SessionStatusEvent = z.infer<typeof SessionStatusEvent>;
