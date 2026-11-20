<?php

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Actions\CloseRoom;
use App\Domain\Rooms\Data\RoomActData;
use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAct;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomTaunt;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomRanking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Draughts;
use Tests\Support\Rooms;

/*
 * A board game in a room (docs/architecture.md §12 "Board games", ADR 034;
 * the contributor's pull request #9, re-homed): a match of draughts is a
 * room game like a race — chosen with its two seats, started through
 * `StartRoomGame`, ended through `EndRoomGame`, the state's `game` with its
 * `match`, ranked and taunted through the room's one machinery. A seat
 * posts its own acts (`POST /rooms/{room}/acts`, `RecordRoomAct`); the
 * server alone judges them, times them and replays the match from them
 * (`DraughtsMatch`): the position, the clocks, the offer and the outcome
 * are never stored.
 */

beforeEach(function (): void {
    Rooms::on();
    $this->travelTo('2026-11-24 10:00:00');
    $this->resad = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->aygun = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
    $this->tural = User::factory()->player()->create(['name' => 'Tural Əliyev']);
    $this->room = Rooms::open($this->resad);
    $this->resadRow = $this->room->rowOf($this->resad);
    $this->aygunRow = Rooms::join($this->room, $this->aygun);
    $this->turalRow = Rooms::join($this->room, $this->tural);
});

/**
 * Aygün (white) against Rəşad (black) in the test's room, Tural watching;
 * the clock started at difficulty `$difficulty`.
 */
function matchStarted(int $difficulty = 2): RoomGame
{
    $game = Rooms::match(test()->room, test()->aygunRow, test()->resadRow, $difficulty);
    test()->travelTo(RoomClock::at($game->started_ms));

    return $game;
}

/**
 * `$account` posts an act through the endpoint, in the room's latest game
 * unless the body names another.
 *
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function matchAct(User $account, Room $room, array $body): TestResponse
{
    return test()->actingAs($account)->postJson(route('rooms.acts.store', $room), [...$body, 'game_id' => $body['game_id'] ?? $room->latestGame()?->id]);
}

/**
 * `$account` throws a taunt at `$target` through the endpoint.
 *
 * @return TestResponse<Response>
 */
function matchTaunt(User $account, Room $room, RoomPlayer $target): TestResponse
{
    return test()->actingAs($account)->postJson(route('rooms.taunts.store', $room), [
        'event_id' => Str::lower((string) Str::ulid()),
        'target_id' => $target->id,
        'taunt' => 'turtle',
    ]);
}

/**
 * The state `$account` reads now, through the endpoint; the screen's with
 * `$screen`. It round-trips through the DTOs.
 *
 * @return array<string, mixed>
 */
function matchState(User $account, Room $room, bool $screen = false): array
{
    $state = test()->actingAs($account)
        ->getJson(route('rooms.state', $screen ? [$room, 'for' => 'screen'] : $room))
        ->assertOk()
        ->json();

    expect(RoomStateData::fromArray($state)->toArray())->toBe($state);

    return $state;
}

/**
 * Each row's points and place in a state's ranking, by the row's id.
 *
 * @param  array<string, mixed>  $state
 * @return array<string, array{0: int, 1: int|null}>
 */
function matchRanking(array $state): array
{
    $rows = [];

    foreach ($state['ranking'] as $row) {
        $rows[$row['player_id']] = [$row['points'], $row['place']];
    }

    return $rows;
}

/**
 * The game from the start that ends by `$ending`
 * (tests/Fixtures/rooms/draughts/matches.json).
 *
 * @return list<string>
 */
function matchDrawn(string $ending): array
{
    foreach (Draughts::fixture('matches')['games'] as $game) {
        if ($game['ending'] === $ending) {
            return $game['moves'];
        }
    }

    throw new RuntimeException("No game ends by {$ending}.");
}

it('starts a match from the room\'s choice with its two seats and its clock, and changes the colours for the next', function (): void {
    $this->actingAs($this->resad)
        ->putJson(route('rooms.setup.update', $this->room), ['mechanic' => 'draughts', 'difficulty' => 2, 'white' => $this->aygunRow->id, 'black' => $this->resadRow->id])
        ->assertNoContent();
    $chosen = matchState($this->tural, $this->room);

    expect($chosen['setup'])->toBe([
        'kind' => 'board', 'mechanic' => 'draughts', 'difficulty' => 2,
        'clock' => ['base_ms' => 300_000, 'increment_ms' => 3_000],
        'white' => $this->aygunRow->id, 'black' => $this->resadRow->id, 'played' => false,
    ])->and($chosen['game'])->toBeNull()
        ->and($chosen['match'])->toBeNull();

    $this->actingAs($this->resad)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    $game = $this->room->latestGame();
    $state = matchState($this->tural, $this->room);

    expect($game)->not->toBeNull()
        ->and([$game?->mechanic, $game?->white_player_id, $game?->black_player_id, $game?->clock_ms, $game?->increment_ms, $game?->rounds->count()])
        ->toBe([RoomMechanic::Draughts, $this->aygunRow->id, $this->resadRow->id, 300_000, 3_000, 0])
        ->and($game?->started_ms)->toBe(RoomClock::nowMs() + Config::integer('elifba.rooms.countdown_ms'))
        ->and($state['game'])->toBe(['kind' => 'board', 'id' => $game?->id, 'mechanic' => 'draughts', 'difficulty' => 2, 'clock' => ['base_ms' => 300_000, 'increment_ms' => 3_000], 'phase' => 'live'])
        ->and($state['round'])->toBeNull()
        ->and($state['match']['phase'])->toBe('countdown')
        ->and(array_column($state['match']['sides'], 'player_id'))->toBe([$this->aygunRow->id, $this->resadRow->id])
        ->and(array_column($state['match']['sides'], 'left_ms'))->toBe([300_000, 300_000])
        ->and($state['match']['position'])->toHaveCount(24)
        // The next game's seats are already the other way round.
        ->and([$state['setup']['white'], $state['setup']['black']])->toBe([$this->resadRow->id, $this->aygunRow->id]);

    // The host's end: no result, and the choice is the game just played.
    $this->actingAs($this->resad)->deleteJson(route('rooms.game.destroy', $this->room))->assertNoContent();
    $over = matchState($this->aygun, $this->room);

    expect([$over['match']['result']['outcome'], $over['match']['result']['ending']])->toBe(['none', 'stopped'])
        ->and(array_column($over['match']['result']['standing'], 'place'))->toBe([null, null])
        ->and($over['setup']['played'])->toBeTrue();

    // «Yenidən oyna»: the same two, the colours changed.
    $this->actingAs($this->resad)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    $again = $this->room->latestGame();

    expect([$again?->white_player_id, $again?->black_player_id])->toBe([$this->resadRow->id, $this->aygunRow->id]);
});

it('seats the present players who joined first, and a seat whose player left is taken by the next', function (): void {
    $seats = fn (): array => array_intersect_key(matchState($this->resad, $this->room)['setup'], ['white' => true, 'black' => true]);

    $this->actingAs($this->resad)
        ->putJson(route('rooms.setup.update', $this->room), ['mechanic' => 'draughts', 'difficulty' => 3, 'white' => $this->turalRow->id, 'black' => $this->aygunRow->id])
        ->assertNoContent();
    expect($seats())->toBe(['white' => $this->turalRow->id, 'black' => $this->aygunRow->id]);

    // Tural leaves: the first present player without a seat, Rəşad, plays white.
    $this->actingAs($this->tural)->post(route('rooms.leave', $this->room));
    expect($seats())->toBe(['white' => $this->resadRow->id, 'black' => $this->aygunRow->id]);

    // A seat is a present player of the room, and the two seats two players.
    foreach ([['white' => $this->turalRow->id], ['white' => $this->aygunRow->id], ['white' => 'nope'], ['black' => null]] as $wrong) {
        $this->actingAs($this->resad)
            ->putJson(route('rooms.setup.update', $this->room), [...['mechanic' => 'draughts', 'difficulty' => 3, 'white' => $this->resadRow->id, 'black' => $this->aygunRow->id], ...$wrong])
            ->assertUnprocessable();
    }

    // A board game's choice keeps the rounds for the next race.
    $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $this->room), ['mechanic' => 'anagram', 'difficulty' => 2, 'rounds' => 7])->assertNoContent();
    $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $this->room), ['mechanic' => 'draughts', 'difficulty' => 1, 'white' => $this->aygunRow->id, 'black' => $this->resadRow->id])->assertNoContent();
    expect($this->room->refresh()->setup_rounds)->toBe(7);
    $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $this->room), ['mechanic' => 'anagram', 'difficulty' => 2, 'rounds' => 7])->assertNoContent();

    expect(matchState($this->resad, $this->room)['setup'])->toBe(['kind' => 'race', 'mechanic' => 'anagram', 'difficulty' => 2, 'rounds' => 7, 'played' => false]);
});

it('fills a choice of the other kind from the room: a race takes its kept rounds, a board game keeps its seats (D5)', function (): void {
    $choose = fn (array $body) => $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $this->room), $body);
    $setup = fn (): array => matchState($this->resad, $this->room)['setup'];

    $choose(['mechanic' => 'anagram', 'difficulty' => 2, 'rounds' => 7])->assertNoContent();
    $choose(['mechanic' => 'draughts', 'difficulty' => 3, 'white' => $this->turalRow->id, 'black' => $this->aygunRow->id])->assertNoContent();

    // A race chosen after the board game names no rounds: the 7 the room kept.
    $choose(['mechanic' => 'spelling', 'difficulty' => 2])->assertNoContent();
    expect($setup())->toBe(['kind' => 'race', 'mechanic' => 'spelling', 'difficulty' => 2, 'rounds' => 7, 'played' => false]);

    // The board game chosen again names no seats: Tural and Aygün, as the host seated them.
    $choose(['mechanic' => 'draughts', 'difficulty' => 3])->assertNoContent();
    expect([$setup()['white'], $setup()['black']])->toBe([$this->turalRow->id, $this->aygunRow->id]);

    // Both seats or neither; a race may still name its rounds.
    $choose(['mechanic' => 'draughts', 'difficulty' => 3, 'white' => $this->aygunRow->id])->assertUnprocessable()->assertJsonValidationErrors(['black']);
    $choose(['mechanic' => 'draughts', 'difficulty' => 3, 'black' => $this->aygunRow->id])->assertUnprocessable()->assertJsonValidationErrors(['white']);
    $choose(['mechanic' => 'spelling', 'difficulty' => 2, 'rounds' => 99])->assertUnprocessable()->assertJsonValidationErrors(['rounds']);

    // A room opened for draughts kept no rounds: its first race takes a new room's (`RoomDefaults`).
    // Alone in it, Tural changes the clock with no seats to name.
    $room = Rooms::open($this->tural, mechanic: RoomMechanic::Draughts);
    expect($room->setup_rounds)->toBeNull();
    $this->actingAs($this->tural)->putJson(route('rooms.setup.update', $room), ['mechanic' => 'draughts', 'difficulty' => 1])->assertNoContent();
    expect(matchState($this->tural, $room)['setup'])->toMatchArray(['difficulty' => 1, 'white' => $room->rowOf($this->tural)?->id, 'black' => null]);
    $this->actingAs($this->tural)->putJson(route('rooms.setup.update', $room), ['mechanic' => 'anagram', 'difficulty' => 2])->assertNoContent();
    expect(matchState($this->tural, $room)['setup']['rounds'])->toBe(Config::integer('elifba.rooms.defaults.rounds'));
});

it('refuses to start a match with fewer than two seats', function (): void {
    $room = Rooms::open($this->tural, mechanic: RoomMechanic::Draughts);

    expect(matchState($this->tural, $room)['setup'])->toBe([
        'kind' => 'board', 'mechanic' => 'draughts', 'difficulty' => 2,
        'clock' => ['base_ms' => 300_000, 'increment_ms' => 3_000],
        'white' => $room->rowOf($this->tural)?->id, 'black' => null, 'played' => false,
    ]);

    $this->actingAs($this->tural)->postJson(route('rooms.games.store', $room))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['setup' => __('rooms.errors.needs_two')]);

    expect($room->games()->count())->toBe(0);
});

it('takes a legal move from the seat to move, and nothing before the clock, out of turn, at another ply or off the rules', function (): void {
    $game = Rooms::match($this->room, $this->aygunRow, $this->resadRow);

    // In the countdown: too early.
    matchAct($this->aygun, $this->room, Rooms::act('move', 0, 'c3-d4'))->assertExactJson(['status' => 'late', 'revision' => $this->room->refresh()->revision]);

    $this->travelTo(RoomClock::at($game->started_ms));
    $revision = $this->room->refresh()->revision;
    $this->reverb->sent = [];

    // Black is not to move; white at another ply is late; a path that is no legal move is a 422.
    matchAct($this->resad, $this->room, Rooms::act('move', 0, 'f6-g5'))->assertJsonPath('status', 'late');
    matchAct($this->aygun, $this->room, Rooms::act('move', 1, 'c3-d4'))->assertJsonPath('status', 'late');
    matchAct($this->aygun, $this->room, Rooms::act('move', 0, 'c3-c5'))->assertUnprocessable()->assertJsonValidationErrors(['path' => __('rooms.match.illegal')]);
    matchAct($this->aygun, $this->room, Rooms::act('move', 0, 'c3-e5'))->assertUnprocessable()->assertJsonValidationErrors('path');

    expect(RoomAct::query()->count())->toBe(0)
        ->and($this->room->refresh()->revision)->toBe($revision)
        ->and($this->reverb->sent)->toBe([]);

    // White's legal move: stored at the server's moment, the revision raised, the room signalled.
    $this->travelTo(RoomClock::at($game->started_ms + 4_000));
    matchAct($this->aygun, $this->room, Rooms::act('move', 0, 'c3-d4'))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['status' => 'accepted', 'revision' => $revision + 1]);

    $act = RoomAct::query()->sole();
    $state = matchState($this->resad, $this->room);

    expect([$act->kind->value, $act->ply, $act->path, $act->acted_ms, $act->room_player_id])->toBe(['move', 0, ['c3', 'd4'], $game->started_ms + 4_000, $this->aygunRow->id])
        ->and($this->reverb->sent)->toHaveCount(1)
        ->and($state['match']['moves'])->toBe(['c3-d4'])
        ->and($state['match']['last'])->toBe(['path' => ['c3', 'd4'], 'captured' => [], 'crowned' => false])
        ->and($state['match']['turn'])->toBe('black')
        // The legal paths go only to the seat to move.
        ->and($state['match']['legal']['capture'])->toBeFalse()
        ->and($state['match']['legal']['paths'])->toContain(['f6', 'g5'])
        ->and(matchState($this->aygun, $this->room)['match']['legal'])->toBeNull()
        ->and(matchState($this->tural, $this->room)['match']['legal'])->toBeNull();
});

it('stores an act once by its event_id, and of two posts at once takes one', function (): void {
    $game = matchStarted();
    $eventId = Str::lower((string) Str::ulid());

    matchAct($this->aygun, $this->room, Rooms::act('move', 0, 'c3-d4', $eventId))->assertJsonPath('status', 'accepted');
    $revision = $this->room->refresh()->revision;
    matchAct($this->aygun, $this->room, Rooms::act('move', 0, 'c3-d4', Str::upper($eventId)))->assertExactJson(['status' => 'duplicate', 'revision' => $revision]);
    matchAct($this->aygun, $this->room, Rooms::act('resign', eventId: $eventId))->assertJsonPath('status', 'duplicate');

    // Two taps of one move, each with its own id: the room's lock takes the first, and the second meets the next ply.
    matchAct($this->resad, $this->room, Rooms::act('move', 1, 'f6-g5'))->assertJsonPath('status', 'accepted');
    matchAct($this->resad, $this->room, Rooms::act('move', 1, 'f6-g5'))->assertJsonPath('status', 'late');

    // Whatever reaches the table, it holds one act of a seat, a kind and a ply (in a savepoint, so PostgreSQL goes on).
    expect(fn () => DB::transaction(fn () => DB::table('room_acts')->insert([
        'event_id' => Str::lower((string) Str::ulid()), 'room_game_id' => $game->id, 'room_player_id' => $this->resadRow->id,
        'kind' => 'move', 'ply' => 1, 'path' => json_encode(['f6', 'e5']), 'acted_ms' => RoomClock::nowMs(),
    ])))->toThrow(UniqueConstraintViolationException::class)
        ->and(RoomAct::query()->count())->toBe(2)
        ->and($this->room->refresh()->revision)->toBe($revision + 1);
});

it('answers 403 to a watcher and to a host who does not play, late in a race, and 422 to an act that is none', function (): void {
    $game = matchStarted();

    matchAct($this->tural, $this->room, Rooms::act('move', 0, 'c3-d4'))->assertForbidden();
    matchAct($this->tural, $this->room, Rooms::act('resign'))->assertForbidden();

    $hostOnly = Rooms::open(User::factory()->player()->create(), plays: false);
    Rooms::match($hostOnly, Rooms::join($hostOnly), Rooms::join($hostOnly));
    matchAct($hostOnly->host, $hostOnly, Rooms::act('resign'))->assertForbidden();

    // A race takes no act.
    $race = Rooms::open($this->tural);
    Rooms::playing($race);
    matchAct($this->tural, $race, Rooms::act('resign'))->assertJsonPath('status', 'late');

    foreach ([['kind' => 'leave', 'ply' => 0], ['kind' => 'move', 'ply' => 0], ['kind' => 'offer'], ['kind' => 'resign', 'ply' => 0], ['kind' => 'move', 'ply' => 0, 'path' => ['c3', 'c4']], ['kind' => 'offer', 'ply' => 0, 'path' => ['c3', 'd4']], ['kind' => 'move', 'ply' => 0, 'path' => array_fill(0, 14, 'c3')]] as $wrong) {
        matchAct($this->aygun, $this->room, ['event_id' => Str::lower((string) Str::ulid()), ...$wrong])->assertUnprocessable();
    }

    expect(RoomAct::query()->count())->toBe(0)
        ->and($game->refresh()->ended_ms)->toBeNull();
});

it('adds the increment to the side that moved and runs only the clock of the side to move', function (): void {
    $game = matchStarted(3);
    $startsMs = $game->started_ms;

    $this->travelTo(RoomClock::at($startsMs + 10_000));
    matchAct($this->aygun, $this->room, Rooms::act('move', 0, 'c3-d4'))->assertJsonPath('status', 'accepted');
    $this->travelTo(RoomClock::at($startsMs + 25_000));
    $state = matchState($this->tural, $this->room);

    // 3+2: white thought 10 s and gained 2; black has thought 15 s of its three minutes.
    expect(array_column($state['match']['sides'], 'left_ms'))->toBe([180_000 - 10_000 + 2_000, 180_000 - 15_000])
        ->and($state['match']['since_ms'])->toBe($startsMs + 10_000)
        ->and($state['match']['flag_ms'])->toBe($startsMs + 10_000 + 180_000);

    $this->travelTo(RoomClock::at($startsMs + 30_000));
    matchAct($this->resad, $this->room, Rooms::act('move', 1, 'f6-g5'))->assertJsonPath('status', 'accepted');
    $state = matchState($this->tural, $this->room);

    expect(array_column($state['match']['sides'], 'left_ms'))->toBe([172_000, 180_000 - 20_000 + 2_000])
        ->and($state['match']['flag_ms'])->toBe($startsMs + 30_000 + 172_000);
});

it('derives a flag fall with nothing written: the side out of time has lost, and a move at the flag is late', function (): void {
    $game = matchStarted(3);
    matchAct($this->aygun, $this->room, Rooms::act('move', 0, 'c3-d4'))->assertJsonPath('status', 'accepted');
    $flagMs = matchState($this->tural, $this->room)['match']['flag_ms'];
    $revision = $this->room->refresh()->revision;

    $this->travelTo(RoomClock::at($flagMs - 1));
    expect(matchState($this->tural, $this->room)['match']['phase'])->toBe('play');

    $this->travelTo(RoomClock::at($flagMs));
    $state = matchState($this->tural, $this->room);
    matchAct($this->resad, $this->room, Rooms::act('move', 1, 'f6-g5'))->assertExactJson(['status' => 'late', 'revision' => $revision]);

    expect($state['game']['phase'])->toBe('over')
        ->and([$state['match']['result']['outcome'], $state['match']['result']['ending']])->toBe(['white', 'time'])
        ->and($state['match']['sides'][1]['left_ms'])->toBe(0)
        ->and($state['taunts'])->toBeNull()
        ->and(RoomAct::query()->count())->toBe(1)
        ->and($game->refresh()->ended_ms)->toBeNull()
        ->and($this->room->refresh()->revision)->toBe($revision)
        // The room is free for its next game the moment the flag falls.
        ->and($this->room->runningGame(RoomClock::nowMs()))->toBeNull();
});

it('reads a flag fall again under the room row\'s shared lock, so an act in flight before the flag is never taken back (D5)', function (): void {
    $game = matchStarted(3);
    matchAct($this->aygun, $this->room, Rooms::act('move', 0, 'c3-d4'))->assertJsonPath('status', 'accepted');
    $flagMs = matchState($this->tural, $this->room)['match']['flag_ms'];
    $outer = DB::transactionLevel();
    $reads = [];
    DB::listen(static function (QueryExecuted $query) use (&$reads): void {
        if (preg_match('/^select \* from "rooms" where "rooms"\."id" = \?/', $query->sql) === 1) {
            $reads[] = [$query->sql, $query->connection->transactionLevel()];
        }
    });
    // The room row's reads made inside a transaction of the read's own (an arrow function would copy `$reads`).
    $shared = static function () use (&$reads, $outer): array {
        return array_values(array_filter($reads, static fn (array $read): bool => $read[1] > $outer));
    };

    // In play nothing waits for a read: no lock, so a move is never held up by the room's readers.
    $this->travelTo(RoomClock::at($flagMs - 1));
    expect(matchState($this->tural, $this->room)['match']['phase'])->toBe('play')
        ->and($shared())->toBe([]);

    // At the flag the read is made again in a transaction of its own, the row taken for share: an act
    // stamped at the flag − 1 under the lock and committed after it would be seen, never taken back.
    $this->travelTo(RoomClock::at($flagMs));
    expect(matchState($this->tural, $this->room)['match']['result']['ending'])->toBe('time')
        ->and($shared())->not->toBeEmpty()
        ->and(DB::getDriverName() === 'pgsql' ? str_ends_with($shared()[0][0], 'for share') : true)->toBeTrue()
        ->and($game->refresh()->ended_ms)->toBeNull();
});

it('gives the match to the other side when a seat resigns, in the countdown too', function (): void {
    $game = Rooms::match($this->room, $this->aygunRow, $this->resadRow);
    matchAct($this->resad, $this->room, Rooms::act('resign'))->assertJsonPath('status', 'accepted');

    expect(matchState($this->tural, $this->room)['match']['result'])->toBe([
        'outcome' => 'white', 'ending' => 'resigned',
        'standing' => [['player_id' => $this->aygunRow->id, 'points' => 3, 'place' => 1], ['player_id' => $this->resadRow->id, 'points' => 0, 'place' => 2]],
        'spent' => [],
    ])->and(RoomAct::query()->sole()->ply)->toBe(0)
        ->and($game->refresh()->ended_ms)->toBeNull();

    // Over: nothing more is taken.
    matchAct($this->aygun, $this->room, Rooms::act('resign'))->assertJsonPath('status', 'late');
});

it('takes a draw offer from the seat that has just moved, again only after the other offered, and its answer', function (): void {
    $game = matchStarted();

    // Before a move, and from the side to move: no offer.
    matchAct($this->aygun, $this->room, Rooms::act('offer', 0))->assertJsonPath('status', 'late');
    matchAct($this->aygun, $this->room, Rooms::act('move', 0, 'c3-d4'))->assertJsonPath('status', 'accepted');
    matchAct($this->resad, $this->room, Rooms::act('offer', 1))->assertJsonPath('status', 'late');

    expect(matchState($this->aygun, $this->room)['match']['can_offer'])->toBeTrue()
        ->and(matchState($this->resad, $this->room)['match']['can_offer'])->toBeFalse();

    // White offers; an accept from white or at another ply is late; black declines in words.
    matchAct($this->aygun, $this->room, Rooms::act('offer', 1))->assertJsonPath('status', 'accepted');
    $offered = matchState($this->resad, $this->room)['match'];
    matchAct($this->aygun, $this->room, Rooms::act('accept', 1))->assertJsonPath('status', 'late');
    matchAct($this->resad, $this->room, Rooms::act('accept', 2))->assertJsonPath('status', 'late');
    matchAct($this->resad, $this->room, Rooms::act('decline', 1))->assertJsonPath('status', 'accepted');

    expect([$offered['offer'], $offered['can_offer']])->toBe(['white', false])
        ->and(matchState($this->resad, $this->room)['match']['offer'])->toBeNull();

    // White may not offer again until black has.
    matchAct($this->resad, $this->room, Rooms::act('move', 1, 'f6-g5'))->assertJsonPath('status', 'accepted');
    matchAct($this->aygun, $this->room, Rooms::act('move', 2, 'd4-c5'))->assertJsonPath('status', 'accepted');
    matchAct($this->aygun, $this->room, Rooms::act('offer', 3))->assertJsonPath('status', 'late');

    // Black offers, and white's reply move lets it lapse.
    matchAct($this->resad, $this->room, Rooms::act('move', 3, 'b6:d4'))->assertJsonPath('status', 'accepted');
    matchAct($this->resad, $this->room, Rooms::act('offer', 4))->assertJsonPath('status', 'accepted');
    expect(matchState($this->aygun, $this->room)['match']['offer'])->toBe('black');
    matchAct($this->aygun, $this->room, Rooms::act('move', 4, 'e3:c5'))->assertJsonPath('status', 'accepted');
    expect(matchState($this->aygun, $this->room)['match']['offer'])->toBeNull();
    matchAct($this->resad, $this->room, Rooms::act('accept', 5))->assertJsonPath('status', 'late');

    // White offers again, and black accepts: a draw by agreement.
    matchAct($this->aygun, $this->room, Rooms::act('offer', 5))->assertJsonPath('status', 'accepted');
    matchAct($this->resad, $this->room, Rooms::act('accept', 5))->assertJsonPath('status', 'accepted');
    $result = matchState($this->tural, $this->room)['match']['result'];

    expect([$result['outcome'], $result['ending']])->toBe(['draw', 'agreed'])
        ->and($result['standing'])->toBe([['player_id' => $this->aygunRow->id, 'points' => 1, 'place' => 1], ['player_id' => $this->resadRow->id, 'points' => 1, 'place' => 1]])
        ->and($game->refresh()->ended_ms)->toBeNull();
});

it('declares each draw of the official rules at the move that makes it', function (string $ending): void {
    $game = matchStarted(1);
    $moves = matchDrawn($ending);
    $last = count($moves) - 1;
    $lastMs = Rooms::moves($game, array_slice($moves, 0, $last), $game->started_ms + 1_000);
    $this->travelTo(RoomClock::at($lastMs + 1_000));

    expect(matchState($this->tural, $this->room)['match']['phase'])->toBe('play');

    matchAct($last % 2 === 0 ? $this->aygun : $this->resad, $this->room, Rooms::act('move', $last, $moves[$last]))->assertJsonPath('status', 'accepted');
    $state = matchState($this->tural, $this->room);

    expect($state['game']['phase'])->toBe('over')
        ->and($state['match']['ply'])->toBe($last + 1)
        ->and([$state['match']['result']['outcome'], $state['match']['result']['ending']])->toBe(['draw', $ending])
        ->and(matchRanking($state))->toBe([$this->aygunRow->id => [1, 1], $this->resadRow->id => [1, 1], $this->turalRow->id => [0, null]]);
})->with(['repetition', 'three_kings', 'main_road', 'balance', 'kings_only']);

it('ends a match when the side to move has no move, the mover winning', function (): void {
    $game = matchStarted(1);
    $moves = Draughts::fixture('games')['games'][0]['moves'];
    $last = count($moves) - 1;
    $lastMs = Rooms::moves($game, array_slice($moves, 0, $last), $game->started_ms + 1_000);
    $this->travelTo(RoomClock::at($lastMs + 1_000));

    matchAct($last % 2 === 0 ? $this->aygun : $this->resad, $this->room, Rooms::act('move', $last, $moves[$last]))->assertJsonPath('status', 'accepted');
    $result = matchState($this->tural, $this->room)['match']['result'];

    expect([$result['outcome'], $result['ending']])->toBe([$last % 2 === 0 ? 'white' : 'black', 'no_moves']);
});

it('gives the match to the other side when a seat leaves or is removed, and none when the host stops it or the room closes', function (): void {
    matchStarted();
    $this->actingAs($this->resad)->post(route('rooms.leave', $this->room));
    $result = matchState($this->aygun, $this->room)['match']['result'];
    $leave = RoomAct::query()->sole();

    expect([$result['outcome'], $result['ending']])->toBe(['white', 'left'])
        ->and([$leave->kind->value, $leave->event_id, $leave->room_player_id, $leave->ply])->toBe(['leave', null, $this->resadRow->id, 0]);

    // A watcher who leaves changes nothing; a seat the host removes has lost.
    $room = Rooms::open($host = User::factory()->player()->create());
    $white = Rooms::join($room);
    $black = Rooms::join($room);
    Rooms::join($room, $watcher = User::factory()->player()->create());
    $game = Rooms::match($room, $white, $black);
    $this->actingAs($watcher)->post(route('rooms.leave', $room));

    expect($room->runningGame(RoomClock::nowMs())?->id)->toBe($game->id);

    $this->actingAs($host)->delete(route('rooms.members.destroy', [$room, $black]));
    expect(matchState($host, $room)['match']['result']['ending'])->toBe('left');

    // The room's close: over without a result, nobody's points.
    $closing = Rooms::open($closer = User::factory()->player()->create());
    $closed = Rooms::match($closing, $closing->rowOf($closer) ?? throw new RuntimeException('The host plays.'), Rooms::join($closing));
    app(CloseRoom::class)->handle($closing);

    expect($closed->refresh()->ended_ms)->not->toBeNull()
        ->and(array_column(RoomRanking::ofRoom($closing->refresh(), RoomClock::nowMs()), 'points'))->toBe([0, 0]);
});

it('takes an act only in the game it names, so a retry from an ended match never lands in the next (D5)', function (): void {
    $first = matchStarted();
    $resign = Rooms::act('resign', gameId: $first->id);
    $move = Rooms::act('move', 0, 'c3-d4', gameId: $first->id);

    // Rəşad resigns; Aygün's own resignation, its answer lost, is still being sent.
    matchAct($this->resad, $this->room, Rooms::act('resign'))->assertJsonPath('status', 'accepted');

    // «Yenidən oyna»: Rəşad plays white, Aygün black; the old bodies arrive in the countdown and in play.
    $this->actingAs($this->resad)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    $second = $this->room->latestGame() ?? throw new RuntimeException('A game started.');
    $this->reverb->sent = [];
    $revision = $this->room->refresh()->revision;

    matchAct($this->aygun, $this->room, $resign)->assertExactJson(['status' => 'late', 'revision' => $revision]);
    $this->travelTo(RoomClock::at($second->started_ms));
    matchAct($this->resad, $this->room, $move)->assertExactJson(['status' => 'late', 'revision' => $revision]);

    expect(RoomAct::query()->where('room_game_id', $second->id)->count())->toBe(0)
        ->and(matchState($this->tural, $this->room)['match'])->toMatchArray(['phase' => 'play', 'ply' => 0, 'result' => null])
        ->and($this->reverb->sent)->toBe([]);

    // The same moves named in the running game are taken.
    matchAct($this->resad, $this->room, Rooms::act('move', 0, 'c3-d4', gameId: $second->id))->assertJsonPath('status', 'accepted');
});

it('counts a win 3 and a draw 1 in the room\'s standing, each game netted on its own', function (): void {
    Config::set('elifba.rooms.taunts.cooldown_ms', 0);

    // Aygün wins after throwing two taunts: 3 − 2 = 1. Before the end nobody has a point of the match.
    matchStarted();
    matchTaunt($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'accepted');
    matchTaunt($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'accepted');
    expect(matchRanking(matchState($this->tural, $this->room)))->toBe([$this->aygunRow->id => [0, null], $this->resadRow->id => [0, null], $this->turalRow->id => [0, null]]);

    matchAct($this->resad, $this->room, Rooms::act('resign'))->assertJsonPath('status', 'accepted');
    $state = matchState($this->tural, $this->room);

    expect($state['match']['result']['standing'])->toBe([['player_id' => $this->aygunRow->id, 'points' => 1, 'place' => 1], ['player_id' => $this->resadRow->id, 'points' => 0, 'place' => 2]])
        ->and($state['match']['result']['spent'])->toBe([['player_id' => $this->aygunRow->id, 'count' => 2]])
        ->and(matchRanking($state))->toBe([$this->aygunRow->id => [1, 1], $this->resadRow->id => [0, null], $this->turalRow->id => [0, null]]);

    // «Yenidən oyna»: Rəşad plays white now, and a draw by agreement gives 1 each.
    $this->actingAs($this->resad)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    $game = $this->room->latestGame() ?? throw new RuntimeException('A game started.');
    $this->travelTo(RoomClock::at($game->started_ms));
    matchAct($this->resad, $this->room, Rooms::act('move', 0, 'c3-d4'))->assertJsonPath('status', 'accepted');
    matchAct($this->resad, $this->room, Rooms::act('offer', 1))->assertJsonPath('status', 'accepted');
    matchAct($this->aygun, $this->room, Rooms::act('accept', 1))->assertJsonPath('status', 'accepted');

    expect(matchRanking(matchState($this->tural, $this->room)))->toBe([$this->aygunRow->id => [2, 1], $this->resadRow->id => [1, 2], $this->turalRow->id => [0, null]])
        ->and(array_column(RoomRanking::ofGame($game, RoomClock::nowMs()), 'points'))->toBe([1, 1, 0]);

    // «Keçmiş oyunlar» reads the same standing, and gives each board game its clock.
    app(CloseRoom::class)->handle($this->room);
    $clock = ['base_ms' => 300_000, 'increment_ms' => 3_000];
    $this->actingAs($this->aygun)->get(route('rooms.past'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('past.0.games', [['mechanic' => 'draughts', 'difficulty' => 2, 'clock' => $clock], ['mechanic' => 'draughts', 'difficulty' => 2, 'clock' => $clock]])
            ->where('past.0.mine.points', 2));
});

it('lets a seat taunt the other seat alone, for what the match can still pay, at most three', function (): void {
    Config::set('elifba.rooms.taunts.cooldown_ms', 0);
    $game = Rooms::match($this->room, $this->aygunRow, $this->resadRow);

    // In the countdown already: a seat's balance is what a win pays; a watcher has none.
    $aygun = matchState($this->aygun, $this->room)['taunts'];
    $tural = matchState($this->tural, $this->room)['taunts'];

    expect([$aygun['open'], $aygun['balance']])->toBe([true, 3])
        ->and([$tural['open'], $tural['balance']])->toBe([false, 0]);

    // A watcher throws nothing and is thrown nothing.
    matchTaunt($this->tural, $this->room, $this->aygunRow)->assertJsonPath('status', 'late');
    matchTaunt($this->aygun, $this->room, $this->turalRow)->assertUnprocessable()->assertJsonValidationErrors('target_id');

    foreach (range(1, 3) as $throw) {
        matchTaunt($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'accepted');
    }
    matchTaunt($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'no_points');

    expect(RoomTaunt::query()->where('room_game_id', $game->id)->count())->toBe(3)
        ->and(matchState($this->aygun, $this->room)['taunts']['balance'])->toBe(0)
        ->and(matchState($this->resad, $this->room)['taunts']['received'])->toHaveCount(3)
        ->and(array_unique(array_column(matchState($this->resad, $this->room, screen: true)['taunts']['shown'], 'to')))->toBe([$this->resadRow->id])
        ->and(matchState($this->tural, $this->room)['taunts']['received'])->toBe([]);

    // The loser who threw pays nothing beyond the loss; once the match is over nobody throws.
    matchTaunt($this->resad, $this->room, $this->aygunRow)->assertJsonPath('status', 'accepted');
    $this->travelTo(RoomClock::at($game->started_ms));
    matchAct($this->resad, $this->room, Rooms::act('resign'))->assertJsonPath('status', 'accepted');
    matchTaunt($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'late');

    expect(matchState($this->tural, $this->room)['match']['result']['standing'])->toBe([
        ['player_id' => $this->aygunRow->id, 'points' => 0, 'place' => 1],
        ['player_id' => $this->resadRow->id, 'points' => 0, 'place' => 2],
    ]);
});

it('keeps a page\'s read out of a match, and a match out of a race\'s answers', function (): void {
    $game = matchStarted();
    matchState($this->aygun, $this->room);

    expect($this->aygunRow->refresh()->seen_ms)->toBeNull()
        ->and($game->rounds()->count())->toBe(0);

    $this->actingAs($this->aygun)->postJson(route('rooms.answers.store', $this->room), [
        'event_id' => Str::lower((string) Str::ulid()), 'round_id' => $game->id, 'picked' => ['abcdefgh'],
    ])->assertJsonPath('status', 'late');
});

it('limits a seat\'s acts to 90 a minute, apart from its answers', function (): void {
    $game = matchStarted();
    $this->actingAs($this->aygun);

    foreach (range(1, 90) as $_) {
        $this->postJson(route('rooms.acts.store', $this->room), Rooms::act('offer', 0, gameId: $game->id))->assertOk();
    }

    $this->postJson(route('rooms.acts.store', $this->room), Rooms::act('offer', 0, gameId: $game->id))->assertTooManyRequests();
    $this->postJson(route('rooms.answers.store', $this->room), ['event_id' => Str::lower((string) Str::ulid()), 'round_id' => Str::lower((string) Str::ulid()), 'picked' => ['abcdefgh']])->assertOk();
});

it('keeps the rules of an act in its Data class', function (): void {
    $gameId = strtolower((string) Str::ulid());
    $body = Rooms::act('move', 4, 'c3:e5:g7', gameId: $gameId);

    expect(RoomActData::validate($body)->toArray())->toBe($body)
        ->and(RoomActData::validate(Rooms::act('resign', gameId: $gameId))->ply)->toBeNull()
        ->and(array_keys(RoomActData::rules()))->toBe(['event_id', 'game_id', 'kind', 'ply', 'path', 'path.*'])
        ->and(fn () => RoomActData::validate(Rooms::act('resign')))->toThrow(ValidationException::class);
});

/**
 * The match fixtures of tests/Fixtures/rooms, built from rows: Rəşad
 * hosts, Aygün plays white, Tural watches; each fixture is what one of
 * them reads at one moment of one match.
 *
 * @return array<string, mixed>
 */
function matchFixture(string $name): array
{
    $decoded = json_decode((string) file_get_contents(__DIR__."/../../Fixtures/rooms/{$name}.json"), true, 64, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : throw new RuntimeException("{$name} is not an object.");
}

it('builds the match fixtures from rows, and the DTOs round-trip them', function (string $name): void {
    $fixture = matchFixture($name);
    $roomId = '01kaqrx1zd7w4n5v6b8c9defgh';
    [$resadId, $aygunId, $turalId] = ['01kaqrx2ab3c4d5e6f7g8h9jkm', '01kaqrx3mn4p5q6r7s8t9vwxyz', '01kaqrx4bc5d6e7f8g9h0jkmnp'];
    $startedMs = 1_795_534_802_000;
    $this->travelTo(CarbonImmutable::createFromTimestampMs($startedMs - 3_000));

    $resad = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $aygun = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
    $tural = User::factory()->player()->create(['name' => 'Tural Əliyev']);
    $room = Room::factory()->for($resad, 'host')->create(['id' => $roomId, 'code' => '482913', 'revision' => $fixture['revision'], 'setup_mechanic' => RoomMechanic::Draughts, 'setup_difficulty' => 2, 'setup_rounds' => 10]);
    $at = static fn (int $minutes): CarbonImmutable => CarbonImmutable::now()->subMinutes(10 - $minutes);
    $resadRow = RoomPlayer::factory()->for($room)->for($resad)->create(['id' => $resadId, 'joined_at' => $at(0)]);
    $aygunRow = RoomPlayer::factory()->for($room)->for($aygun)->create(['id' => $aygunId, 'joined_at' => $at(1)]);
    RoomPlayer::factory()->for($room)->for($tural)->create(['id' => $turalId, 'joined_at' => $at(2)]);

    // Chosen: Aygün white, Rəşad black. Started: the seats are written the other way round for the next game.
    $room->forceFill(['setup_white_id' => $aygunId, 'setup_black_id' => $resadId])->save();

    if ($name !== 'state.match.chosen') {
        $room->forceFill(['setup_white_id' => $resadId, 'setup_black_id' => $aygunId])->save();
        $game = RoomGame::factory()->for($room)->draughts($aygunRow, $resadRow)->create(['id' => '01kaqs0a1b2c3d4e5f6g7h8j9k', 'started_ms' => $startedMs]);
        $line = static fn (string $moves): array => explode(' ', $moves);
        $act = static fn (string $kind, RoomPlayer $row, int $ply, int $atMs) => DB::table('room_acts')->insert([
            'event_id' => Str::lower((string) Str::ulid()), 'room_game_id' => $game->id, 'room_player_id' => $row->id,
            'kind' => $kind, 'ply' => $ply, 'path' => null, 'acted_ms' => $atMs,
        ]);

        match ($name) {
            'state.match.countdown' => null,
            'state.match.play', 'state.match.watch' => Rooms::moves($game, $line('c3-d4 f6-e5'), $startedMs + 5_000, 4_000),
            'state.match.offer' => [Rooms::moves($game, $line('c3-d4 f6-g5'), $startedMs + 5_000, 4_000), $act('offer', $resadRow, 2, $startedMs + 9_500)],
            'state.match.over' => [
                Rooms::moves($game, $line('c3-d4 f6-g5'), $startedMs + 5_000, 4_000),
                RoomTaunt::factory()->create(['room_game_id' => $game->id, 'room_player_id' => $aygunId, 'target_player_id' => $resadId, 'taunt' => 'turtle', 'sent_ms' => $startedMs + 7_000]),
                $act('resign', $resadRow, 2, $startedMs + 15_000),
            ],
        };
    }

    $this->travelTo(CarbonImmutable::createFromTimestampMs($fixture['server_ms']));
    $viewer = $name === 'state.match.watch' ? $tural : $aygun;

    $this->actingAs($viewer)->getJson(route('rooms.state', $room))
        ->assertOk()
        ->assertExactJson($fixture);

    expect(RoomStateData::fromArray($fixture)->toArray())->toBe($fixture);
})->with(['state.match.chosen', 'state.match.countdown', 'state.match.play', 'state.match.watch', 'state.match.offer', 'state.match.over']);
