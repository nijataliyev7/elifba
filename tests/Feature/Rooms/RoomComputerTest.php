<?php

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Actions\CloseRoom;
use App\Domain\Rooms\Actions\LeaveRoom;
use App\Domain\Rooms\Actions\PlayComputerMove;
use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Enums\RoomActKind;
use App\Domain\Rooms\Enums\RoomComputerLevel;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAct;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\Draughts\DraughtsPosition;
use App\Domain\Rooms\Support\Draughts\DraughtsRules;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomRanking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Rooms;

/*
 * The computer's seat (docs/architecture.md §12 "The computer's seat",
 * ADR 036): a match of draughts may have one seat nobody holds, played by
 * the computer at one of three levels. It is chosen with the room's
 * choice, its move is an act without a player that only
 * `PlayComputerMove` writes — after a response, never inside one — and it
 * is a seat, never a player: it earns nothing and stands in no ranking.
 */

beforeEach(function (): void {
    Rooms::on();
    // The computer's wait passes on the test's clock, not the wall's.
    Sleep::fake(syncWithCarbon: true);
    $this->travelTo('2026-11-24 10:00:00');
    $this->resad = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->room = Rooms::open($this->resad, mechanic: RoomMechanic::Draughts);
    $this->resadRow = $this->room->rowOf($this->resad);
});

/**
 * @return array<string, mixed>
 */
function computerState(User $account, Room $room): array
{
    $state = test()->actingAs($account)->getJson(route('rooms.state', $room))->assertOk()->json();

    expect(RoomStateData::fromArray($state)->toArray())->toBe($state);

    return $state;
}

/**
 * @param  array<string, mixed>  $choice
 * @return TestResponse<Response>
 */
function computerChoice(User $account, Room $room, array $choice): TestResponse
{
    return test()->actingAs($account)->putJson(route('rooms.setup.update', $room), ['mechanic' => 'draughts', 'difficulty' => 2, ...$choice]);
}

/**
 * A match of Rəşad against the computer whose clock has started.
 */
function computerMatch(RoomComputerLevel $level = RoomComputerLevel::Medium, bool $white = true): RoomGame
{
    $game = Rooms::computer(test()->room, test()->resadRow, $level, $white);
    test()->travelTo(RoomClock::at($game->started_ms));

    return $game;
}

it('chooses the computer for the other colour, with at most one seat', function (): void {
    computerChoice($this->resad, $this->room, ['computer' => 3])->assertNoContent();

    expect(computerState($this->resad, $this->room)['setup'])->toBe([
        'kind' => 'board', 'mechanic' => 'draughts', 'difficulty' => 2,
        'clock' => ['base_ms' => 300_000, 'increment_ms' => 3_000],
        'white' => $this->resadRow->id, 'black' => null, 'computer' => 3, 'played' => false,
    ]);

    // The player takes black: the computer plays white.
    computerChoice($this->resad, $this->room, ['computer' => 1, 'black' => $this->resadRow->id])->assertNoContent();
    $setup = computerState($this->resad, $this->room)['setup'];

    expect([$setup['white'], $setup['black'], $setup['computer']])->toBe([null, $this->resadRow->id, 1])
        ->and($this->room->refresh()->setup_computer)->toBe(RoomComputerLevel::Easy);

    // Two seats and the computer are three; a level it has not is none.
    $aygun = Rooms::join($this->room);

    computerChoice($this->resad, $this->room, ['computer' => 2, 'white' => $this->resadRow->id, 'black' => $aygun->id])->assertUnprocessable();
    computerChoice($this->resad, $this->room, ['computer' => 4])->assertUnprocessable()->assertJsonValidationErrors(['computer']);
    computerChoice($this->resad, $this->room, ['computer' => 2, 'white' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors(['white']);

    // Null turns it off: the room seats its two players again.
    computerChoice($this->resad, $this->room, ['computer' => null])->assertNoContent();
    $setup = computerState($this->resad, $this->room)['setup'];

    expect($setup['computer'])->toBeNull()
        ->and([$setup['white'], $setup['black']])->toEqualCanonicalizing([$this->resadRow->id, $aygun->id]);
});

it('keeps the computer through a race and takes it back with a board game chosen after it', function (): void {
    computerChoice($this->resad, $this->room, ['computer' => 3])->assertNoContent();
    $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $this->room), ['mechanic' => 'recall_order', 'difficulty' => 1])->assertNoContent();

    expect($this->room->refresh()->setup_computer)->toBe(RoomComputerLevel::Hard);

    // A board game chosen after a race names only the game and its level.
    $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $this->room), ['mechanic' => 'draughts', 'difficulty' => 3])->assertNoContent();

    expect(computerState($this->resad, $this->room)['setup'])->toMatchArray(['difficulty' => 3, 'white' => $this->resadRow->id, 'black' => null, 'computer' => 3]);
});

it('starts a match of one player against the computer, and changes the colours for the next', function (): void {
    computerChoice($this->resad, $this->room, ['computer' => 3])->assertNoContent();
    $this->actingAs($this->resad)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    $game = $this->room->latestGame();

    expect([$game->white_player_id, $game->black_player_id, $game->computer])->toBe([$this->resadRow->id, null, RoomComputerLevel::Hard])
        ->and([$this->room->refresh()->setup_white_id, $this->room->setup_black_id, $this->room->setup_computer])->toBe([null, $this->resadRow->id, RoomComputerLevel::Hard]);

    $state = computerState($this->resad, $this->room);

    expect($state['game'])->toMatchArray(['kind' => 'board', 'computer' => 3, 'phase' => 'live'])
        ->and(array_column($state['match']['sides'], 'player_id'))->toBe([$this->resadRow->id, null])
        ->and($state['setup'])->toMatchArray(['white' => null, 'black' => $this->resadRow->id, 'computer' => 3, 'played' => false])
        ->and($state['taunts']['balance'] ?? 0)->toBe(0);
});

/*
 * A room of one plays the computer by itself (ADR 036 as amended
 * 2026-11-22): while nobody chose it and fewer than two players are
 * present, the choice has the computer at «Orta» in the seat without a
 * player — derived at each read, never stored — so «Başla» starts a match
 * with no other tap, and a second player takes the seat by joining.
 */

it('seats the computer by itself in a room of one, and starts against it with no choosing', function (): void {
    expect(computerState($this->resad, $this->room)['setup'])->toBe([
        'kind' => 'board', 'mechanic' => 'draughts', 'difficulty' => 2,
        'clock' => ['base_ms' => 300_000, 'increment_ms' => 3_000],
        'white' => $this->resadRow->id, 'black' => null, 'computer' => 2, 'played' => false,
    ])->and($this->room->refresh()->setup_computer)->toBeNull();

    $this->actingAs($this->resad)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    $game = $this->room->latestGame();

    expect([$game->white_player_id, $game->black_player_id, $game->computer])->toBe([$this->resadRow->id, null, RoomComputerLevel::Medium])
        // The start writes the seats round, as ever, and no level.
        ->and([$this->room->refresh()->setup_white_id, $this->room->setup_black_id, $this->room->setup_computer])->toBe([null, $this->resadRow->id, null]);

    // The computer answers the player's first move.
    $this->travelTo(RoomClock::at($game->started_ms));
    $this->actingAs($this->resad)->postJson(route('rooms.acts.store', $this->room), Rooms::act('move', 0, 'c3-d4', gameId: $game->id))
        ->assertOk()->assertJsonPath('status', 'accepted');
    $reply = $game->acts()->get()->last();

    expect([$reply?->room_player_id, $reply?->ply])->toBe([null, 1]);

    $state = computerState($this->resad, $this->room);

    expect([$state['match']['ply'], $state['match']['turn']])->toBe([2, 'white'])
        ->and(array_column($state['match']['sides'], 'player_id'))->toBe([$this->resadRow->id, null]);
});

it('offers «Yenidən oyna» after a match against the computer it seated by itself, with the colours changed', function (): void {
    $this->actingAs($this->resad)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    $game = $this->room->latestGame();
    $this->travelTo(RoomClock::at($game->started_ms));
    $this->actingAs($this->resad)->postJson(route('rooms.acts.store', $this->room), Rooms::act('resign', gameId: $game->id))->assertOk();

    expect(computerState($this->resad, $this->room)['setup'])->toMatchArray(['white' => null, 'black' => $this->resadRow->id, 'computer' => 2, 'played' => true]);

    // «Yenidən oyna» is the same start: the player has black, the computer white.
    $this->actingAs($this->resad)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    $next = $this->room->latestGame();

    expect([$next->white_player_id, $next->black_player_id, $next->computer])->toBe([null, $this->resadRow->id, RoomComputerLevel::Medium]);
});

it('gives the computer\'s seat to a second player who joins, and takes it back when one leaves', function (): void {
    $aygun = Rooms::join($this->room);
    $setup = computerState($this->resad, $this->room)['setup'];

    expect([$setup['white'], $setup['black'], $setup['computer']])->toBe([$this->resadRow->id, $aygun->id, null]);

    $this->actingAs($this->resad)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    $game = $this->room->latestGame();

    expect([$game->white_player_id, $game->black_player_id, $game->computer])->toBe([$this->resadRow->id, $aygun->id, null]);

    // Aygün leaves: the match is over, and Rəşad alone plays the computer next.
    app(LeaveRoom::class)->handle($this->room, $aygun);
    $setup = computerState($this->resad, $this->room)['setup'];

    expect([$setup['white'], $setup['black'], $setup['computer']])->toBe([null, $this->resadRow->id, 2])
        ->and($this->room->refresh()->setup_computer)->toBeNull();
});

it('keeps a level the player chose when a second player joins', function (): void {
    computerChoice($this->resad, $this->room, ['computer' => 1])->assertNoContent();

    expect($this->room->refresh()->setup_computer)->toBe(RoomComputerLevel::Easy);

    $aygun = Rooms::join($this->room);
    $setup = computerState($this->resad, $this->room)['setup'];

    // The host seats the friend with one tap, as before: Aygün on the computer's colour.
    expect([$setup['white'], $setup['black'], $setup['computer']])->toBe([$this->resadRow->id, null, 1]);

    computerChoice($this->resad, $this->room, ['computer' => null, 'white' => $this->resadRow->id, 'black' => $aygun->id])->assertNoContent();

    expect(computerState($this->resad, $this->room)['setup'])->toMatchArray(['white' => $this->resadRow->id, 'black' => $aygun->id, 'computer' => null]);
});

it('stores nothing for the level a room of one plays by itself, and nothing for a null', function (): void {
    $revision = $this->room->refresh()->revision;

    // Null in a room of one: the computer still plays, and nothing changed.
    computerChoice($this->resad, $this->room, ['computer' => null])->assertNoContent();

    expect([$this->room->refresh()->revision, $this->room->setup_computer])->toBe([$revision, null])
        ->and(computerState($this->resad, $this->room)['setup']['computer'])->toBe(2);

    // «Yerləri dəyiş» sends the whole choice, «Orta» with it: the seats are stored, the level is not.
    computerChoice($this->resad, $this->room, ['computer' => 2, 'black' => $this->resadRow->id])->assertNoContent();
    $setup = computerState($this->resad, $this->room)['setup'];

    expect([$setup['white'], $setup['black'], $setup['computer']])->toBe([null, $this->resadRow->id, 2])
        ->and($this->room->refresh()->setup_computer)->toBeNull()
        ->and($this->room->revision)->toBe($revision + 1);

    // So a second player still takes the computer's seat.
    $aygun = Rooms::join($this->room);
    $setup = computerState($this->resad, $this->room)['setup'];

    expect([$setup['white'], $setup['black'], $setup['computer']])->toBe([$aygun->id, $this->resadRow->id, null]);
});

it('seats no computer by itself in a room of two or in a race', function (): void {
    $aygun = Rooms::join($this->room);

    expect(computerState($this->resad, $this->room)['setup'])->toMatchArray(['white' => $this->resadRow->id, 'black' => $aygun->id, 'computer' => null]);

    $race = Rooms::open(User::factory()->player()->create());
    $setup = computerState($race->host, $race)['setup'];

    expect($setup['kind'])->toBe('race')
        ->and($setup)->not->toHaveKeys(['white', 'black', 'computer']);
});

it('refuses to start against the computer without a player to seat', function (): void {
    $room = Rooms::open(User::factory()->player()->create(), plays: false, mechanic: RoomMechanic::Draughts);
    computerChoice($room->host, $room, ['computer' => 1])->assertNoContent();

    $this->actingAs($room->host)->postJson(route('rooms.games.store', $room))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['setup' => __('rooms.errors.needs_one')]);

    expect($room->games()->count())->toBe(0);
});

it('answers the player\'s move after the response, with one of the judge\'s moves and no player', function (): void {
    $game = computerMatch();

    $this->actingAs($this->resad)->postJson(route('rooms.acts.store', $this->room), Rooms::act('move', 0, 'c3-d4', gameId: $game->id))
        ->assertOk()->assertJsonPath('status', 'accepted');

    $acts = RoomAct::query()->where('room_game_id', $game->id)->orderBy('id')->get();
    $reply = $acts->last();
    $afterWhite = DraughtsRules::apply(DraughtsPosition::initial(), DraughtsRules::find(DraughtsPosition::initial(), ['c3', 'd4']));

    expect($acts)->toHaveCount(2)
        ->and([$reply->room_player_id, $reply->event_id, $reply->kind, $reply->ply])->toBe([null, null, RoomActKind::Move, 1])
        ->and(DraughtsRules::find($afterWhite, $reply->path))->not->toBeNull();

    $state = computerState($this->resad, $this->room);

    expect([$state['match']['ply'], $state['match']['turn']])->toBe([2, 'white'])
        ->and($state['match']['legal'])->not->toBeNull();
});

it('moves first when it plays white, once a read finds the clock started', function (): void {
    // A move due far ahead is left to a later read.
    Config::set('elifba.rooms.computer.think_ms', 6_000);
    $game = Rooms::computer($this->room, $this->resadRow, RoomComputerLevel::Easy, white: false);
    computerState($this->resad, $this->room);

    expect($game->acts()->count())->toBe(0);

    // A read in the countdown waits for the clock to start and the think time to pass, after its own answer.
    Config::set('elifba.rooms.computer.think_ms', 800);
    $state = computerState($this->resad, $this->room);
    $first = $game->acts()->first();

    expect([$state['match']['phase'], $state['match']['ply']])->toBe(['countdown', 0])
        ->and($game->acts()->count())->toBe(1)
        ->and([$first->room_player_id, $first->ply, $first->acted_ms])->toBe([null, 0, $game->started_ms + 800]);

    // A second read finds the player to move and writes nothing more.
    $state = computerState($this->resad, $this->room);

    expect([$state['match']['ply'], $state['match']['turn']])->toBe([1, 'black'])
        ->and($game->acts()->count())->toBe(1);
});

it('makes the same move when asked twice, and none while another search holds the turn', function (): void {
    $game = computerMatch(RoomComputerLevel::Easy, white: false);
    $held = Cache::lock('room-computer:'.$game->id.':0', 10);

    expect($held->get())->toBeTrue();
    app(PlayComputerMove::class)->handle($this->room);
    expect($game->acts()->count())->toBe(0);

    $held->release();
    app(PlayComputerMove::class)->handle($this->room);
    app(PlayComputerMove::class)->handle($this->room);

    expect($game->acts()->count())->toBe(1);

    // Told again from the game and the ply, the move is the same.
    $path = $game->acts()->first()->path;
    $game->acts()->delete();
    $game->unsetRelation('acts');
    app(PlayComputerMove::class)->handle($this->room);

    expect($game->acts()->first()->path)->toBe($path);
});

it('writes nothing in a match of two players, a race or a room without a game', function (): void {
    app(PlayComputerMove::class)->handle($this->room);
    $aygun = Rooms::join($this->room);
    $match = Rooms::match($this->room, $this->resadRow, $aygun);
    $this->travelTo(RoomClock::at($match->started_ms));
    app(PlayComputerMove::class)->handle($this->room);

    expect(RoomAct::query()->count())->toBe(0);

    $race = Rooms::open(User::factory()->player()->create());
    Rooms::join($race);
    Rooms::playing($race);
    app(PlayComputerMove::class)->handle($race);

    expect(RoomAct::query()->count())->toBe(0);
});

it('gives the player a match\'s points and the computer none, in no ranking', function (): void {
    $game = computerMatch(RoomComputerLevel::Easy);

    // The computer has lost on time: nobody read the room while its clock ran.
    Rooms::moves($game, ['c3-d4'], $game->started_ms + 1_000);
    $this->travelTo(RoomClock::at($game->started_ms + 1_000 + 300_000));
    $state = computerState($this->resad, $this->room);

    expect([$state['match']['result']['outcome'], $state['match']['result']['ending']])->toBe(['white', 'time'])
        ->and($state['match']['result']['standing'])->toBe([
            ['player_id' => $this->resadRow->id, 'points' => 3, 'place' => 1],
            ['player_id' => null, 'points' => 0, 'place' => 2],
        ])
        ->and(array_column($state['ranking'], 'points', 'player_id'))->toBe([$this->resadRow->id => 3])
        ->and(RoomRanking::ofRoom($this->room, RoomClock::nowMs()))->toHaveCount(1)
        ->and($state['setup']['played'])->toBeTrue();
});

it('ends the match when the player resigns or leaves, with a point for nobody', function (): void {
    $game = computerMatch();

    $this->actingAs($this->resad)->postJson(route('rooms.acts.store', $this->room), Rooms::act('resign', gameId: $game->id))->assertJsonPath('status', 'accepted');
    $result = computerState($this->resad, $this->room)['match']['result'];

    expect([$result['outcome'], $result['ending']])->toBe(['black', 'resigned'])
        ->and($result['standing'])->toBe([
            ['player_id' => null, 'points' => 0, 'place' => 1],
            ['player_id' => $this->resadRow->id, 'points' => 0, 'place' => 2],
        ])
        // The match is over: the computer has no move to make.
        ->and($game->acts()->where('kind', 'move')->count())->toBe(0);
});

it('throws no taunt in a match against the computer', function (): void {
    $aygun = User::factory()->player()->create();
    $aygunRow = Rooms::join($this->room, $aygun);
    computerMatch();

    foreach ([[$this->resad, $aygunRow], [$aygun, $this->resadRow]] as [$thrower, $target]) {
        $this->actingAs($thrower)->postJson(route('rooms.taunts.store', $this->room), [
            'event_id' => Str::lower((string) Str::ulid()), 'target_id' => $target->id, 'taunt' => 'turtle',
        ])->assertOk()->assertJsonPath('status', 'late');
    }

    expect($this->room->latestGame()?->id)->not->toBeNull()
        ->and(DB::table('room_taunts')->count())->toBe(0);
});

it('lists a past match against the computer with its level, and on the closed room\'s page', function (): void {
    $game = computerMatch(RoomComputerLevel::Hard);
    $this->actingAs($this->resad)->postJson(route('rooms.acts.store', $this->room), Rooms::act('resign', gameId: $game->id))->assertOk();
    app(CloseRoom::class)->handle($this->room);

    $this->actingAs($this->resad)->get(route('rooms.past'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('past.0.games', [['mechanic' => 'draughts', 'difficulty' => 2, 'clock' => ['base_ms' => 300_000, 'increment_ms' => 3_000], 'computer' => 3]]));

    // The closed room's own page says it too, and ranks the player alone.
    $this->actingAs($this->resad)->get(route('rooms.show', $this->room))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('games.0.computer', 3)
            ->where('games.0.clock', ['base_ms' => 300_000, 'increment_ms' => 3_000])
            ->has('games.0.ranking', 1));
});

it('builds the computer\'s fixtures from rows, and the DTOs round-trip them', function (string $name): void {
    $path = __DIR__."/../../Fixtures/rooms/{$name}.json";
    $roomId = '01kaqrx1zd7w4n5v6b8c9defgh';
    $resadId = '01kaqrx2ab3c4d5e6f7g8h9jkm';
    $startedMs = 1_795_534_802_000;
    $this->travelTo(CarbonImmutable::createFromTimestampMs($startedMs - 3_000));

    $resad = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $room = Room::factory()->for($resad, 'host')->create([
        'id' => $roomId, 'code' => '482913', 'revision' => $name === 'state.match.computer' ? 4 : 1,
        'setup_mechanic' => RoomMechanic::Draughts, 'setup_difficulty' => 2, 'setup_rounds' => 10,
        'setup_white_id' => null, 'setup_black_id' => null, 'setup_computer' => RoomComputerLevel::Medium,
    ]);
    $resadRow = RoomPlayer::factory()->for($room)->for($resad)->create(['id' => $resadId, 'joined_at' => CarbonImmutable::now()->subMinutes(10)]);
    $serverMs = $startedMs - 1_000;

    if ($name === 'state.match.computer') {
        // Started with Rəşad on white: the seats are written the other way round for the next game.
        $room->forceFill(['setup_black_id' => $resadId])->save();
        $game = RoomGame::factory()->for($room)->againstComputer($resadRow)->create(['id' => '01kaqs0a1b2c3d4e5f6g7h8j9k', 'started_ms' => $startedMs]);
        // Rəşad's move and the computer's answer, an act without a player.
        Rooms::moves($game, ['c3-d4', 'f6-g5'], $startedMs + 5_000, 1_000);
        $serverMs = $startedMs + 9_000;
    }

    $this->travelTo(CarbonImmutable::createFromTimestampMs($serverMs));
    $state = $this->actingAs($resad)->getJson(route('rooms.state', $room))->assertOk()->json();

    $fixture = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);

    expect($state)->toBe($fixture)
        ->and(RoomStateData::fromArray($fixture)->toArray())->toBe($fixture);
})->with(['state.match.computer', 'state.match.computer.chosen']);
