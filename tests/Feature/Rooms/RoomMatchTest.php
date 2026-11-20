<?php

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Actions\LeaveRoom;
use App\Domain\Rooms\Actions\OpenRoom;
use App\Domain\Rooms\Data\OpenRoomData;
use App\Domain\Rooms\Enums\RoomBoardGame;
use App\Domain\Rooms\Enums\RoomMatchEnding;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomMatch;
use App\Domain\Rooms\Models\RoomMove;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\Checkers;
use App\Domain\Rooms\Support\RoomPointsRule;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Boards;
use Tests\Support\Rooms;

/*
 * Board games (docs/architecture.md §12 "Board games", ADR 034): a room
 * opened for checkers, a match the host starts between two present
 * players, moves the server alone judges (`Checkers`), and the ways a
 * match ends.
 */

beforeEach(function (): void {
    Rooms::on();
    $this->host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->guest = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
    $this->watcher = User::factory()->player()->create(['name' => 'Tural Əliyev']);

    $this->actingAs($this->host)->post(route('rooms.store'), ['board' => 'checkers'])->assertRedirect();
    $this->room = Room::query()->where('user_id', $this->host->id)->firstOrFail();
    $this->hostRow = $this->room->rowOf($this->host);
    $this->guestRow = Rooms::join($this->room, $this->guest);
    $this->watcherRow = Rooms::join($this->room, $this->watcher);
});

function startCheckersMatch(object $test): RoomMatch
{
    $test->actingAs($test->host)
        ->postJson(route('rooms.match.store', $test->room), ['white' => $test->hostRow->id, 'black' => $test->guestRow->id])
        ->assertNoContent();

    return RoomMatch::query()->where('room_id', $test->room->id)->latest('position')->firstOrFail();
}

/**
 * @param  list<int>  $path
 */
function playCheckersMove(object $test, User $account, RoomMatch $match, array $path, ?string $eventId = null): TestResponse
{
    return $test->actingAs($account)->postJson(route('rooms.match.moves.store', $test->room), [
        'event_id' => $eventId ?? strtolower((string) Str::ulid()),
        'ply' => $match->refresh()->ply,
        'path' => $path,
    ]);
}

it('opens a room for checkers from «Oyunlar» and shows it there', function (): void {
    expect($this->room->board)->toBe(RoomBoardGame::Checkers);

    $this->actingAs($this->host)->get(route('rooms.index'))
        ->assertInertia(fn (Assert $page) => $page->where('open.0.board', 'checkers'));

    $this->actingAs($this->guest)->get(route('rooms.show', $this->room))
        ->assertInertia(fn (Assert $page) => $page
            ->where('board', 'checkers')
            ->where('match.match', null)
            ->where('match.me.player_id', $this->guestRow->id));
});

it('turns the host\'s open room to checkers and back', function (): void {
    $room = app(OpenRoom::class)->handle($this->watcher, new OpenRoomData(RoomMechanic::Anagram));
    expect($room->board)->toBeNull();

    app(OpenRoom::class)->handle($this->watcher, new OpenRoomData(RoomMechanic::Anagram, RoomBoardGame::Checkers));
    expect($room->refresh()->board)->toBe(RoomBoardGame::Checkers);

    app(OpenRoom::class)->handle($this->watcher, new OpenRoomData(RoomMechanic::Spelling));
    expect($room->refresh()->board)->toBeNull()
        ->and($room->setup_mechanic)->toBe(RoomMechanic::Spelling);
});

it('lets only the host start a match, between two present players', function (): void {
    $this->actingAs($this->guest)
        ->postJson(route('rooms.match.store', $this->room), ['white' => $this->hostRow->id, 'black' => $this->guestRow->id])
        ->assertForbidden();

    $this->actingAs($this->host)
        ->postJson(route('rooms.match.store', $this->room), ['white' => $this->hostRow->id, 'black' => $this->hostRow->id])
        ->assertUnprocessable();

    app(LeaveRoom::class)->handle($this->room, $this->watcherRow);
    $this->actingAs($this->host)
        ->postJson(route('rooms.match.store', $this->room), ['white' => $this->hostRow->id, 'black' => $this->watcherRow->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('black');

    $match = startCheckersMatch($this);

    expect($match->board)->toBe(Checkers::initial())
        ->and($match->turn)->toBe('w')
        ->and($match->white_player_id)->toBe($this->hostRow->id);

    // One match at a time.
    $this->actingAs($this->host)
        ->postJson(route('rooms.match.store', $this->room), ['white' => $this->hostRow->id, 'black' => $this->guestRow->id])
        ->assertForbidden();
});

it('never starts a round game in a board room', function (): void {
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room))->assertForbidden();
});

it('serves the match with the legal moves of the side to move to every watcher', function (): void {
    $match = startCheckersMatch($this);

    $this->actingAs($this->watcher)->getJson(route('rooms.match.show', $this->room))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('me.side', null)
        ->assertJsonPath('match.turn', 'w')
        ->assertJsonPath('match.capture', false)
        ->assertJsonCount(7, 'match.moves');

    $this->actingAs($this->guest)->getJson(route('rooms.match.show', $this->room))
        ->assertJsonPath('me.side', 'b')
        ->assertJsonPath('match.id', $match->id);
});

it('takes a legal move from the player to move, and nothing else', function (): void {
    $match = startCheckersMatch($this);

    // Not black's turn.
    playCheckersMove($this, $this->guest, $match, [Boards::sq(2, 1), Boards::sq(3, 0)])->assertJsonPath('status', 'late');
    // A watcher plays no side.
    playCheckersMove($this, $this->watcher, $match, [Boards::sq(5, 0), Boards::sq(4, 1)])->assertForbidden();
    // Not a legal move.
    playCheckersMove($this, $this->host, $match, [Boards::sq(5, 0), Boards::sq(3, 2)])->assertUnprocessable()->assertJsonValidationErrors('path');

    $revision = $this->room->refresh()->revision;
    $eventId = strtolower((string) Str::ulid());
    playCheckersMove($this, $this->host, $match, [Boards::sq(5, 0), Boards::sq(4, 1)], $eventId)->assertJsonPath('status', 'accepted');
    playCheckersMove($this, $this->host, $match, [Boards::sq(5, 0), Boards::sq(4, 1)], $eventId)->assertJsonPath('status', 'duplicate');

    $match->refresh();

    expect($match->turn)->toBe('b')
        ->and($match->ply)->toBe(1)
        ->and($match->board[Boards::sq(4, 1)])->toBe('w')
        ->and($match->last_path)->toBe([Boards::sq(5, 0), Boards::sq(4, 1)])
        ->and(RoomMove::query()->count())->toBe(1)
        ->and($this->room->refresh()->revision)->toBeGreaterThan($revision);

    // A move against a position the page no longer shows is late.
    $this->actingAs($this->guest)->postJson(route('rooms.match.moves.store', $this->room), [
        'event_id' => strtolower((string) Str::ulid()),
        'ply' => 0,
        'path' => [Boards::sq(2, 1), Boards::sq(3, 0)],
    ])->assertJsonPath('status', 'late');
});

it('ends a match when the side to move has no move, the mover winning', function (): void {
    $match = startCheckersMatch($this);
    // White can capture black's last piece.
    $match->board = Boards::of(['w' => [Boards::sq(5, 2)], 'b' => [Boards::sq(4, 3)]]);
    $match->save();

    playCheckersMove($this, $this->host, $match, [Boards::sq(5, 2), Boards::sq(3, 4)])->assertJsonPath('status', 'accepted');

    $match->refresh();

    expect($match->isOver())->toBeTrue()
        ->and($match->result)->toBe('w')
        ->and($match->ending)->toBe(RoomMatchEnding::NoMoves);

    $this->actingAs($this->guest)->getJson(route('rooms.match.show', $this->room))
        ->assertJsonPath('match.over', true)
        ->assertJsonPath('match.moves', []);
});

it('draws after fifteen quiet king moves each', function (): void {
    $match = startCheckersMatch($this);
    $match->board = Boards::of(['W' => [Boards::sq(7, 0)], 'B' => [Boards::sq(0, 7)]]);
    $match->quiet_plies = RoomMatch::QUIET_DRAW - 1;
    $match->save();

    playCheckersMove($this, $this->host, $match, [Boards::sq(7, 0), Boards::sq(6, 1)])->assertJsonPath('status', 'accepted');

    expect($match->refresh()->result)->toBe('draw')
        ->and($match->ending)->toBe(RoomMatchEnding::Quiet);
});

it('gives the match to the other side when a player resigns or leaves', function (): void {
    $match = startCheckersMatch($this);

    $this->actingAs($this->watcher)->postJson(route('rooms.match.resign', $this->room))->assertNoContent();
    expect($match->refresh()->isOver())->toBeFalse();

    $this->actingAs($this->guest)->postJson(route('rooms.match.resign', $this->room))->assertNoContent();
    expect($match->refresh()->result)->toBe('w')
        ->and($match->ending)->toBe(RoomMatchEnding::Resign);

    $second = startCheckersMatch($this);
    app(LeaveRoom::class)->handle($this->room, $this->hostRow->refresh());

    expect($second->refresh()->result)->toBe('b')
        ->and($second->ending)->toBe(RoomMatchEnding::Left);
});

it('lets the host end a running match with no result', function (): void {
    $match = startCheckersMatch($this);

    $this->actingAs($this->guest)->deleteJson(route('rooms.match.destroy', $this->room))->assertForbidden();
    $this->actingAs($this->host)->deleteJson(route('rooms.match.destroy', $this->room))->assertNoContent();

    expect($match->refresh()->isOver())->toBeTrue()
        ->and($match->result)->toBeNull()
        ->and($match->ending)->toBe(RoomMatchEnding::Host);
});

it('ends a running match when the room closes', function (): void {
    $match = startCheckersMatch($this);

    $this->actingAs($this->host)->post(route('rooms.close', $this->room))->assertRedirect();

    expect($match->refresh()->ending)->toBe(RoomMatchEnding::Host);
});

it('keeps a stranger out of the match', function (): void {
    $stranger = User::factory()->player()->create();
    startCheckersMatch($this);

    $this->actingAs($stranger)->getJson(route('rooms.match.show', $this->room))->assertNotFound();
    expect(RoomPlayer::query()->where('user_id', $stranger->id)->exists())->toBeFalse();
});

it('runs each side\'s clock only on its turn, and a side out of time loses', function (): void {
    $this->freezeTime();
    $match = startCheckersMatch($this);
    $total = Config::integer('elifba.rooms.checkers.clock_ms');

    $this->travel(20)->seconds();
    playCheckersMove($this, $this->host, $match, [Boards::sq(5, 0), Boards::sq(4, 1)])->assertJsonPath('status', 'accepted');

    $this->travel(5)->seconds();
    $this->actingAs($this->watcher)->getJson(route('rooms.match.show', $this->room))
        ->assertJsonPath('match.clock.total_ms', $total)
        ->assertJsonPath('match.clock.white_ms', $total - 20_000)
        ->assertJsonPath('match.clock.black_ms', $total - 5_000);

    // Black thinks until its time is gone: white wins on time, with nothing written by a job.
    $this->travel($total)->milliseconds();
    $this->actingAs($this->watcher)->getJson(route('rooms.match.show', $this->room))
        ->assertJsonPath('match.over', true)
        ->assertJsonPath('match.result', 'w')
        ->assertJsonPath('match.ending', 'time')
        ->assertJsonPath('match.clock.black_ms', 0);
    playCheckersMove($this, $this->guest, $match, [Boards::sq(2, 1), Boards::sq(3, 0)])->assertJsonPath('status', 'late');

    // The next start writes the lost match down.
    startCheckersMatch($this);
    expect($match->refresh()->ending)->toBe(RoomMatchEnding::Time)
        ->and($match->result)->toBe('w');
});

it('counts a won match 3 and a draw 1 in the room\'s standing', function (): void {
    $first = startCheckersMatch($this);
    $this->actingAs($this->guest)->postJson(route('rooms.match.resign', $this->room))->assertNoContent();

    $second = startCheckersMatch($this);
    $second->board = Boards::of(['W' => [Boards::sq(7, 0)], 'B' => [Boards::sq(0, 7)]]);
    $second->quiet_plies = RoomMatch::QUIET_DRAW - 1;
    $second->save();
    playCheckersMove($this, $this->host, $second, [Boards::sq(7, 0), Boards::sq(6, 1)])->assertJsonPath('status', 'accepted');

    expect($first->refresh()->result)->toBe('w')
        ->and($second->refresh()->result)->toBe('draw');

    $ranking = collect($this->actingAs($this->watcher)->getJson(route('rooms.state', $this->room))->json('ranking'))
        ->mapWithKeys(fn (array $row): array => [$row['player_id'] => $row['points']]);

    expect($ranking->get($this->hostRow->id))->toBe(RoomPointsRule::MATCH_WIN + RoomPointsRule::MATCH_DRAW)
        ->and($ranking->get($this->guestRow->id))->toBe(RoomPointsRule::MATCH_DRAW)
        ->and($ranking->get($this->watcherRow->id, 0))->toBe(0);
});

it('lets a player taunt the opponent, free and after the cooldown, and shows it to the target and the screen', function (): void {
    $this->freezeTime();
    startCheckersMatch($this);
    $taunt = array_key_first(app(ContentRepository::class)->roomTaunts());
    $throw = fn (User $account) => $this->actingAs($account)->postJson(route('rooms.match.taunts.store', $this->room), [
        'event_id' => strtolower((string) Str::ulid()),
        'taunt' => $taunt,
    ]);

    $throw($this->watcher)->assertJsonPath('status', 'late');
    $throw($this->host)->assertJsonPath('status', 'accepted');
    $throw($this->host)->assertJsonPath('status', 'cooling');

    $this->actingAs($this->guest)->getJson(route('rooms.match.show', $this->room))
        ->assertJsonPath('taunts.open', true)
        ->assertJsonPath('taunts.received.0.from', $this->hostRow->id)
        ->assertJsonPath('taunts.received.0.taunt', $taunt)
        ->assertJsonPath('taunts.shown', null);
    $this->actingAs($this->host)->getJson(route('rooms.match.show', $this->room))
        ->assertJsonPath('taunts.received', [])
        ->assertJsonPath('taunts.ready_ms', fn (int $ms): bool => $ms > now()->getTimestampMs());
    $this->actingAs($this->host)->getJson(route('rooms.match.show', ['room' => $this->room, 'for' => 'screen']))
        ->assertJsonPath('taunts.shown.0.to', $this->guestRow->id);
    $this->actingAs($this->guest)->getJson(route('rooms.match.show', ['room' => $this->room, 'for' => 'screen']))
        ->assertForbidden();

    $this->travel(Config::integer('elifba.rooms.taunts.cooldown_ms'))->milliseconds();
    $throw($this->host)->assertJsonPath('status', 'accepted');

    // The host's switch.
    $this->room->refresh()->forceFill(['taunts' => false])->save();
    $throw($this->guest)->assertJsonPath('status', 'off');
});
