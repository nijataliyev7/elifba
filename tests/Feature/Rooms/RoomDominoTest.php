<?php

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Actions\PlayComputerMove;
use App\Domain\Rooms\Enums\RoomActKind;
use App\Domain\Rooms\Enums\RoomComputerLevel;
use App\Domain\Rooms\Enums\RoomTable;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAct;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomPointsRule;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\Support\DominoRooms;
use Tests\Support\Rooms;

/*
 * Domino «101» through the room's own routes (docs/architecture.md §12
 * "Domino", ADR 038 decision 59's row of `RoomDominoTest`): a room chosen
 * for «101» starts through `StartRoomGame` at each table; only the seat to
 * move plays; an act is stored once; nothing at or after the flag; the
 * server's play after a response for a computer's seat, a flag, an away
 * seat and a leaver; `back`; `yield`; the host's end without a result; 3
 * and 6 in the standing less taunts; a taunt only at another side's
 * player — and a game played to 101 at each table.
 */

beforeEach(function (): void {
    DominoRooms::on();
    // A turn the server plays waits on the test's clock, not the wall's.
    Sleep::fake(syncWithCarbon: true);
    $this->travelTo('2026-11-24 10:00:00');
    $this->resad = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->aygun = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
    $this->tural = User::factory()->player()->create(['name' => 'Tural Əliyev']);
    $this->leyla = User::factory()->player()->create(['name' => 'Leyla Həsənova']);
    $this->kamal = User::factory()->player()->create(['name' => 'Kamal Səfərov']);
});

/**
 * Each seat's player's account in a game, by `room_players.id`.
 *
 * @param  list<User>  $accounts
 * @return array<string, User>
 */
function dominoAccounts(RoomGame $game, array $accounts): array
{
    $byId = [];

    foreach ($accounts as $account) {
        $row = $game->room->rowOf($account);

        if ($row !== null) {
            $byId[$row->id] = $account;
        }
    }

    return $byId;
}

/** The account of the seat to move. */
function dominoMover(RoomGame $game, array $accounts, User $watcher): User
{
    $domino = DominoRooms::peek($watcher, $game->room);

    return $accounts[$domino['seats'][$domino['turn']]['player_id']];
}

/** The game's acts as stored: kind, ply and whether a device sent it. */
function dominoActs(RoomGame $game): array
{
    return RoomAct::query()->where('room_game_id', $game->id)->orderBy('id')->get()
        ->map(static fn (RoomAct $act): array => [$act->kind->value, $act->ply, $act->room_player_id !== null])->all();
}

it('starts a room chosen for «101» at each table, its seats taken by the present players in the order they joined', function (RoomTable $table, int $players, array $seated): void {
    $everyone = [$this->resad, $this->aygun, $this->tural, $this->leyla, $this->kamal];
    $game = DominoRooms::start($this->resad, array_slice($everyone, 1, $players - 1), $table);
    $rows = array_map(static fn (User $account): ?string => $game->room->rowOf($account)?->id, $everyone);

    expect([$game->mechanic, $game->difficulty, $game->dominoTable(), $game->turn_ms, $game->reveal_ms, $game->computer])
        ->toBe([RoomMechanic::Domino101, 2, $table, 30_000, 10_000, null])
        ->and($game->seats)->toBe(array_map(static fn (int $index): ?string => $rows[$index], $seated))
        ->and($game->rounds()->count())->toBe(0)
        ->and($game->white_player_id)->toBeNull()
        ->and($game->clock_ms)->toBeNull();

    $state = DominoRooms::state($this->resad, $game->room);

    expect($state['game'])->toBe([
        'kind' => 'domino', 'id' => $game->id, 'mechanic' => 'domino_101', 'difficulty' => 2,
        'turn_ms' => 30_000, 'table' => $table->value, 'target' => 101, 'computer' => null, 'phase' => 'live',
    ])
        ->and($state['round'])->toBeNull()
        ->and($state['match'])->toBeNull()
        ->and($state['domino']['phase'])->toBe('countdown')
        ->and($state['domino']['starts_ms'])->toBe($game->started_ms)
        ->and(array_column($state['domino']['seats'], 'player_id'))->toBe($game->seats)
        ->and($state['domino']['mine']['seat'])->toBe(0);
})->with([
    'two players at two' => [RoomTable::Two, 2, [0, 1]],
    'three at three' => [RoomTable::Three, 3, [0, 1, 2]],
    'four each for itself' => [RoomTable::Four, 4, [0, 1, 2, 3]],
    'four as pairs, the first two on opposite sides' => [RoomTable::Pairs, 4, [0, 1, 2, 3]],
    'five at pairs: the last to join watches' => [RoomTable::Pairs, 5, [0, 1, 2, 3]],
]);

it('derives the table from the present players and gives a place nobody is left for to the computer, at «Orta»', function (): void {
    $room = Rooms::open($this->resad, mechanic: RoomMechanic::Domino101);
    $setup = fn (): array => DominoRooms::state($this->resad, $room)['setup'];

    expect($setup())->toBe([
        'kind' => 'domino', 'mechanic' => 'domino_101', 'difficulty' => $setup()['difficulty'], 'turn_ms' => $setup()['turn_ms'],
        'table' => 'two', 'seats' => [$room->rowOf($this->resad)->id, null], 'computer' => 2, 'played' => false,
    ]);

    foreach ([$this->aygun, $this->tural, $this->leyla, $this->kamal] as $account) {
        $this->travel(1)->seconds();
        Rooms::join($room, $account);
    }

    $ids = array_map(static fn (User $account): string => $room->rowOf($account)->id, [$this->resad, $this->aygun, $this->tural, $this->leyla]);

    expect([$setup()['table'], $setup()['seats'], $setup()['computer']])->toBe(['pairs', $ids, null]);

    // The host places Rəşad and Tural as partners; the empty places take whoever joined first.
    $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $room), ['mechanic' => 'domino_101', 'difficulty' => 3, 'table' => 'pairs', 'seats' => [$ids[0], null, $ids[2], null]])
        ->assertNoContent();

    expect([$setup()['table'], $setup()['seats'], $setup()['turn_ms']])->toBe(['pairs', [$ids[0], $ids[1], $ids[2], $ids[3]], 15_000]);

    // At a table of three, Leyla and Kamal watch; at two, so do Tural and the rest.
    $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $room), ['mechanic' => 'domino_101', 'difficulty' => 1, 'table' => 'three', 'seats' => [$ids[3], null, null]])
        ->assertNoContent();

    expect([$setup()['seats'], $setup()['turn_ms'], $setup()['computer']])->toBe([[$ids[3], $ids[0], $ids[1]], 60_000, null]);
});

it('takes a domino choice\'s seats only of present players named once, as many as the table has', function (): void {
    $room = Rooms::open($this->resad, mechanic: RoomMechanic::Domino101);
    $aygun = Rooms::join($room, $this->aygun);
    $resad = $room->rowOf($this->resad);
    Rooms::join($room, $this->tural);
    $this->actingAs($this->tural)->post(route('rooms.leave', $room));
    $tural = $room->players()->where('user_id', $this->tural->id)->first();
    $put = fn (array $choice) => $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $room), ['mechanic' => 'domino_101', 'difficulty' => 2, ...$choice]);

    $put(['table' => 'two', 'seats' => [$resad->id, $tural->id]])->assertUnprocessable()->assertJsonValidationErrors('seats');
    $put(['table' => 'two', 'seats' => [$resad->id, $resad->id]])->assertUnprocessable()->assertJsonValidationErrors('seats');
    $put(['table' => 'three', 'seats' => [$resad->id, $aygun->id]])->assertUnprocessable()->assertJsonValidationErrors('seats');
    $put(['seats' => [$resad->id, $aygun->id]])->assertUnprocessable()->assertJsonValidationErrors('seats');
    $put(['table' => 'six'])->assertUnprocessable()->assertJsonValidationErrors('table');

    expect([$room->refresh()->setup_table, $room->setup_seats])->toBe([null, null]);

    $put(['table' => 'two', 'seats' => [$aygun->id, $resad->id], 'computer' => 3])->assertNoContent();

    expect([$room->refresh()->setup_table, $room->setup_seats, $room->setup_computer])->toBe([RoomTable::Two, [$aygun->id, $resad->id], RoomComputerLevel::Hard]);

    // A race chosen after it leaves the table and the seats, and drops the domino game's computer (ADR 038 decision 75);
    // «101» chosen again names only the game and its level.
    $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $room), ['mechanic' => 'anagram', 'difficulty' => 2, 'rounds' => 7])->assertNoContent();
    $put([])->assertNoContent();

    expect([$room->refresh()->setup_table, $room->setup_seats, $room->setup_computer, $room->setup_rounds])->toBe([RoomTable::Two, [$aygun->id, $resad->id], null, 7]);
});

it('stores «Orta» as no choice, so a board game chosen after it plays two players', function (): void {
    $room = Rooms::open($this->resad, mechanic: RoomMechanic::Domino101);
    Rooms::join($room, $this->aygun);

    $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $room), ['mechanic' => 'domino_101', 'difficulty' => 2, 'table' => 'pairs', 'computer' => 2])->assertNoContent();

    expect($room->refresh()->setup_computer)->toBeNull()
        ->and(DominoRooms::state($this->resad, $room)['setup']['computer'])->toBe(2);

    $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $room), ['mechanic' => 'draughts', 'difficulty' => 2])->assertNoContent();

    expect(DominoRooms::state($this->resad, $room)['setup']['computer'])->toBeNull();
});

it('refuses a table nobody sits at', function (): void {
    $room = Rooms::open($this->resad, plays: false, mechanic: RoomMechanic::Domino101);

    $this->actingAs($this->resad)->postJson(route('rooms.games.store', $room))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['setup' => __('rooms.errors.needs_one')]);

    expect($room->games()->count())->toBe(0);
});

it('takes a play only from the seat to move, at the game\'s ply and before its flag, and stores it once', function (): void {
    $game = DominoRooms::start($this->resad, [$this->aygun, $this->tural], RoomTable::Three);
    $accounts = dominoAccounts($game, [$this->resad, $this->aygun, $this->tural]);
    $this->travelTo(RoomClock::at($game->started_ms - 1));

    // Before the hand opens: the countdown.
    expect(DominoRooms::peek($this->resad, $game->room)['phase'])->toBe('countdown');
    $this->travelTo(RoomClock::at($game->started_ms));
    $domino = DominoRooms::peek($this->resad, $game->room);
    $mover = $accounts[$domino['seats'][$domino['turn']]['player_id']];
    $other = collect($accounts)->first(static fn (User $account): bool => $account->isNot($mover));
    $own = DominoRooms::peek($mover, $game->room)['mine']['legal'][0];

    // Another seat, another ply: late. A watcher holds no seat: 403.
    DominoRooms::play($other, $game, [$own['tile']])->assertOk()->assertJsonPath('status', 'late');
    DominoRooms::play($mover, $game, [$own['tile']], ply: 1)->assertOk()->assertJsonPath('status', 'late');
    Rooms::join($game->room, $this->leyla);
    DominoRooms::play($this->leyla, $game, [$own['tile']], ply: 0)->assertForbidden();

    // At the flag: late; a moment before it: taken, and the same event again is a duplicate.
    $this->travelTo(RoomClock::at($domino['flag_ms']));
    DominoRooms::play($mover, $game, [$own['tile']])->assertOk()->assertJsonPath('status', 'late');
    $this->travelTo(RoomClock::at($domino['flag_ms'] - 1));
    $eventId = strtolower((string) Str::ulid());
    $revision = $game->room->refresh()->revision;
    DominoRooms::play($mover, $game, [$own['tile']], eventId: $eventId)->assertOk()->assertExactJson(['status' => 'accepted', 'revision' => $revision + 1]);
    DominoRooms::play($mover, $game, [$own['tile']], ply: 0, eventId: $eventId)->assertOk()->assertExactJson(['status' => 'duplicate', 'revision' => $revision + 1]);

    expect(dominoActs($game))->toBe([['play', 0, true]])
        ->and(DominoRooms::peek($this->resad, $game->room)['line'][0]['tile'])->toBe($own['tile']);
});

it('refuses every play the judge does not take with one answer, whatever was wrong with it', function (): void {
    $game = DominoRooms::start($this->resad, [$this->aygun], RoomTable::Two);
    $accounts = dominoAccounts($game, [$this->resad, $this->aygun]);
    $this->travelTo(RoomClock::at($game->started_ms));
    DominoRooms::play(dominoMover($game, $accounts, $this->resad), $game)->assertOk()->assertJsonPath('status', 'accepted');

    $domino = DominoRooms::peek($this->resad, $game->room);
    $mover = $accounts[$domino['seats'][$domino['turn']]['player_id']];
    $other = collect($accounts)->first(static fn (User $account): bool => $account->isNot($mover));
    $mine = DominoRooms::peek($mover, $game->room)['mine'];
    $theirs = DominoRooms::peek($other, $game->room)['mine']['tiles'];
    $boneyard = array_values(array_diff(dominoAllTiles(), $mine['tiles'], $theirs, array_column($domino['line'], 'tile')));
    $unfit = array_values(array_diff($mine['tiles'], array_column($mine['legal'], 'tile')));
    $played = $domino['line'][0]['tile'];
    $refusals = [];

    foreach ([
        'a tile the other seat holds' => [$theirs[0], 'left'],
        'a tile in the boneyard' => [$boneyard[0], 'right'],
        'a tile already played' => [$played, 'left'],
        'a tile of its own at no end' => [$mine['legal'][0]['tile']],
        'an end the tile does not fit' => [$mine['legal'][0]['tile'], $mine['legal'][0]['ends'] === ['left'] ? 'right' : 'left'],
        ...($unfit === [] ? [] : ['a tile of its own that fits nowhere' => [$unfit[0], 'left']]),
        // The form, refused the same way: no tile, a tile written the other way, an end of no name, a third item.
        'no tile' => ['7-7', 'left'],
        'a tile written the other way' => [strrev($mine['legal'][0]['tile']), 'left'],
        'an end of no name' => [$mine['legal'][0]['tile'], 'middle'],
        'a third item' => [$mine['legal'][0]['tile'], 'left', 'right'],
        'no path at all' => [],
    ] as $why => $path) {
        $response = DominoRooms::play($mover, $game, $path)->assertUnprocessable();
        $refusals[$why] = $response->json();
    }

    expect(array_values(array_unique(array_map(json_encode(...), $refusals))))->toBe([json_encode(['message' => __('rooms.domino.illegal'), 'errors' => ['path' => [__('rooms.domino.illegal')]]])])
        ->and(dominoActs($game))->toBe([['play', 0, true]]);
});

/**
 * The 28 tiles by name.
 *
 * @return list<string>
 */
function dominoAllTiles(): array
{
    $tiles = [];

    foreach (range(0, 6) as $high) {
        foreach (range(0, $high) as $low) {
            $tiles[] = "{$high}-{$low}";
        }
    }

    return $tiles;
}

it('takes a «Sən aç» only from a seat that opens by right at pairs, before its first tile, and never back', function (): void {
    $game = DominoRooms::start($this->resad, [$this->aygun, $this->tural, $this->leyla], RoomTable::Pairs);
    $accounts = dominoAccounts($game, [$this->resad, $this->aygun, $this->tural, $this->leyla]);
    $this->travelTo(RoomClock::at($game->started_ms));

    // The first hand opens with the lowest double (R6): nobody gives.
    $first = DominoRooms::peek($this->resad, $game->room);
    $opener = $accounts[$first['seats'][$first['turn']]['player_id']];

    expect($first['hand']['why'])->toBe('lowest_double')
        ->and(DominoRooms::peek($opener, $game->room)['mine']['may_yield'])->toBeFalse();
    DominoRooms::act($opener, $game, 'yield', 0)->assertOk()->assertJsonPath('status', 'late');

    // A later hand while an account is open: the seat with the right may give it once.
    $domino = DominoRooms::playOut($game, $this->resad, $accounts, static fn (array $domino): bool => $domino['phase'] === 'play' && $domino['line'] === [] && $domino['hand']['why'] !== 'lowest_double' && $domino['hand']['why'] !== 'lowest_tile');
    $entitled = $accounts[$domino['seats'][$domino['turn']]['player_id']];
    $partner = $accounts[$domino['seats'][($domino['turn'] + 2) % 4]['player_id']];

    expect(DominoRooms::peek($entitled, $game->room)['mine']['may_yield'])->toBeTrue();
    $this->travelTo(RoomClock::at($domino['since_ms'] + 500));
    DominoRooms::act($partner, $game, 'yield', $domino['ply'])->assertOk()->assertJsonPath('status', 'late');
    DominoRooms::act($entitled, $game, 'yield', $domino['ply'])->assertOk()->assertJsonPath('status', 'accepted');

    $after = DominoRooms::peek($partner, $game->room);

    expect([$after['turn'], $after['hand']['why'], $after['ply'], $after['last']['kind'], $after['mine']['may_yield']])
        ->toBe([($domino['turn'] + 2) % 4, 'given', $domino['ply'] + 1, 'yield', false])
        ->and($after['since_ms'])->toBe($domino['since_ms'] + 500);
    DominoRooms::act($partner, $game, 'yield', $after['ply'])->assertOk()->assertJsonPath('status', 'late');
});

it('takes a «back» only from a seat that is away', function (): void {
    $game = DominoRooms::start($this->resad, [$this->aygun], RoomTable::Two);
    $this->travelTo(RoomClock::at($game->started_ms));

    DominoRooms::act($this->aygun, $game, 'back')->assertOk()->assertJsonPath('status', 'late');
    DominoRooms::act($this->aygun, $game, 'back', 0)->assertUnprocessable()->assertJsonValidationErrors('ply');

    expect(dominoActs($game))->toBe([]);
});

it('ends a game the host stops, at any moment, without a result: nobody earns, nobody is placed', function (): void {
    $game = DominoRooms::start($this->resad, [$this->aygun], RoomTable::Two);
    $accounts = dominoAccounts($game, [$this->resad, $this->aygun]);
    DominoRooms::playOut($game, $this->resad, $accounts, static fn (array $domino): bool => $domino['ply'] >= 3);

    $this->actingAs($this->resad)->deleteJson(route('rooms.game.destroy', $game->room))->assertNoContent();
    $state = DominoRooms::state($this->aygun, $game->room);

    expect([$state['game']['phase'], $state['domino']['phase'], $state['domino']['sheet'], $state['domino']['turn']])->toBe(['over', 'over', null, null])
        ->and($state['domino']['result'])->toMatchArray(['ending' => 'stopped', 'winner' => null, 'dry' => false, 'spent' => []])
        ->and(array_column($state['domino']['result']['standing'], 'points'))->toBe([0, 0])
        ->and(array_column($state['domino']['result']['standing'], 'place'))->toBe([null, null])
        ->and(array_column($state['ranking'], 'points'))->toBe([0, 0]);

    // An act after the end is late.
    DominoRooms::play($this->aygun, $game, ['6-6'], ply: $state['domino']['ply'])->assertOk()->assertJsonPath('status', 'late');
});

it('plays a game to 101 at each table through the room\'s own routes, the winning side earning 3 each, or 6 after a dry win', function (RoomTable $table, int $players): void {
    $everyone = array_slice([$this->resad, $this->aygun, $this->tural, $this->leyla], 0, $players);
    $game = DominoRooms::start($this->resad, array_slice($everyone, 1), $table);
    $accounts = dominoAccounts($game, $everyone);

    $over = DominoRooms::playOut($game, $this->resad, $accounts);
    $winner = $over['result']['winner'];
    $state = DominoRooms::state($this->resad, $game->room);
    $won = array_values(array_filter($over['seats'], static fn (array $seat): bool => $seat['side'] === $winner));
    $dry = collect($over['sides'])->where('side', '!=', $winner)->every(static fn (array $side): bool => $side['balance'] === 0);
    $points = $dry ? RoomPointsRule::DRY_WIN : RoomPointsRule::MATCH_WIN;

    expect([$over['result']['ending'], $over['result']['dry']])->toBe(['target', $dry])
        ->and(max(array_column($over['sides'], 'balance')))->toBeGreaterThanOrEqual(101)
        ->and($over['sides'][0]['side'])->toBe($winner)
        ->and($over['sheet']['next'])->toBeNull()
        ->and($over['sheet']['until_ms'])->toBeNull()
        ->and($state['game']['phase'])->toBe('over')
        ->and(collect($state['ranking'])->where('points', $points)->pluck('player_id')->sort()->values()->all())
        ->toBe(collect($won)->pluck('player_id')->sort()->values()->all())
        ->and(collect($state['ranking'])->sum('points'))->toBe($points * count($won));

    // «Yenidən oyna»: the same people in the same seats.
    expect($state['setup']['played'])->toBeTrue()
        ->and($state['setup']['seats'])->toBe($game->seats);
})->with([
    'two' => [RoomTable::Two, 2],
    'three' => [RoomTable::Three, 3],
    'four' => [RoomTable::Four, 4],
    'pairs' => [RoomTable::Pairs, 4],
]);

it('gives 6 for a dry win, less the taunts its thrower threw, and a taunt goes only to a player of another side', function (): void {
    $game = DominoRooms::start($this->resad, [$this->aygun, $this->tural, $this->leyla], RoomTable::Pairs, seed: DominoRooms::DRY['pairs']);
    $accounts = dominoAccounts($game, [$this->resad, $this->aygun, $this->tural, $this->leyla]);
    $rows = array_map(fn (User $account): string => $game->room->rowOf($account)->id, [$this->resad, $this->aygun, $this->tural, $this->leyla]);
    $this->travelTo(RoomClock::at($game->started_ms));
    $throw = fn (User $account, string $target) => $this->actingAs($account)->postJson(route('rooms.taunts.store', $game->room), [
        'event_id' => strtolower((string) Str::ulid()), 'target_id' => $target, 'taunt' => 'turtle',
    ]);
    Rooms::join($game->room, $this->kamal);
    $kamal = $game->room->rowOf($this->kamal)->id;

    // Rəşad (seat 0) and Tural (seat 2) are partners; Aygün and Leyla the other side; Kamal watches.
    $throw($this->resad, $rows[2])->assertUnprocessable()->assertJsonValidationErrors('target_id');
    $throw($this->resad, $kamal)->assertUnprocessable()->assertJsonValidationErrors('target_id');
    $throw($this->kamal, $rows[1])->assertOk()->assertJsonPath('status', 'late');
    $throw($this->resad, $rows[1])->assertOk()->assertJsonPath('status', 'accepted');
    $this->travel(10)->seconds();
    $throw($this->aygun, $rows[0])->assertOk()->assertJsonPath('status', 'accepted');

    $over = DominoRooms::playOut($game, $this->resad, $accounts);
    $state = DominoRooms::state($this->resad, $game->room);
    $points = array_column($state['ranking'], 'points', 'player_id');
    $winners = array_values(array_filter($over['seats'], static fn (array $seat): bool => $seat['side'] === $over['result']['winner']));

    expect([$over['result']['ending'], $over['result']['dry']])->toBe(['target', true])
        ->and(collect($over['sides'])->where('side', '!=', $over['result']['winner'])->pluck('balance')->all())->toBe([0])
        ->and(count($winners))->toBe(2);

    foreach ($winners as $seat) {
        expect($points[$seat['player_id']])->toBe(RoomPointsRule::DRY_WIN - ($seat['player_id'] === $rows[0] || $seat['player_id'] === $rows[1] ? RoomPointsRule::TAUNT_COST : 0));
    }

    expect(collect($state['domino']['result']['spent'])->pluck('count', 'player_id')->all())->toEqualCanonicalizing([$rows[0] => 1, $rows[1] => 1]);
});

it('goes on when a seat\'s player leaves, its seat standing «left», and lists the game in «Keçmiş oyunlar»', function (): void {
    $game = DominoRooms::start($this->resad, [$this->aygun], RoomTable::Two);
    $this->travelTo(RoomClock::at($game->started_ms));
    $aygunRow = $game->room->rowOf($this->aygun);

    $this->actingAs($this->aygun)->post(route('rooms.leave', $game->room))->assertRedirect();
    $domino = DominoRooms::state($this->resad, $game->room)['domino'];

    expect($domino['phase'])->toBe('play')
        ->and(collect($domino['seats'])->firstWhere('player_id', $aygunRow->id)['standing'])->toBe('left');

    $past = $this->actingAs($this->aygun)->get(route('rooms.past'))->assertOk()->viewData('page')['props']['past'];

    expect($past[0]['games'])->toBe([[
        'mechanic' => 'domino_101', 'difficulty' => 2, 'turn_ms' => 30_000, 'table' => 'two', 'computer' => null, 'balances' => [0, 0],
    ]]);
});

/**
 * The domino part as `$account` reads it by the route — the read that
 * hands a turn the server plays to `defer()`.
 *
 * @return array<string, mixed>
 */
function dominoRead(User $account, Room $room): array
{
    return DominoRooms::state($account, $room)['domino'];
}

it('plays a computer\'s seat after a response, at the game\'s level, a play of the judge\'s without a player', function (): void {
    // A room of one: two seats, the other the computer's at «Orta», with no choosing.
    $room = Rooms::open($this->resad, mechanic: RoomMechanic::Domino101);
    $this->actingAs($this->resad)->postJson(route('rooms.games.store', $room))->assertSuccessful();
    $game = $room->games()->sole();
    $game->forceFill(['seed' => DominoRooms::SEED])->save();
    $resad = $room->rowOf($this->resad)->id;

    expect([$game->dominoTable(), $game->seats, $game->computer])->toBe([RoomTable::Two, [$resad, null], RoomComputerLevel::Medium]);

    $this->travelTo(RoomClock::at($game->started_ms));
    $computerSeat = 1;
    $plays = 0;

    // Whoever opens, the computer answers each of Rəşad's plays after the response, think_ms after its turn began.
    while ($plays < 6) {
        $domino = dominoRead($this->resad, $room);

        if ($domino['phase'] !== 'play') {
            break;
        }

        if ($domino['turn'] === $computerSeat) {
            continue;
        }

        $this->travelTo(RoomClock::at($domino['since_ms'] + 2_000));
        DominoRooms::play($this->resad, $game)->assertOk()->assertJsonPath('status', 'accepted');
        $plays++;
    }

    $acts = RoomAct::query()->where('room_game_id', $game->id)->orderBy('id')->get();
    $server = $acts->whereNull('room_player_id');

    expect($server)->not->toBeEmpty()
        ->and($server->every(static fn (RoomAct $act): bool => $act->kind === RoomActKind::Play && $act->event_id === null))->toBeTrue()
        ->and($acts->pluck('ply')->all())->toBe(range(0, $acts->count() - 1));

    // Each was stored think_ms after the act before it, the same time whatever the seat held.
    foreach ($acts as $index => $act) {
        if ($act->room_player_id === null && $index > 0) {
            expect($act->acted_ms - $acts[$index - 1]->acted_ms)->toBe(Config::integer('elifba.rooms.domino.think_ms'));
        }
    }

    $domino = dominoRead($this->resad, $room);

    expect(collect($domino['seats'])->pluck('standing')->all())->toBe(['playing', 'computer'])
        ->and(collect($domino['seats'])->pluck('player_id')->all())->toBe([$resad, null]);
});

it('plays a whole game of one player against three computers at pairs, the computer earning nothing', function (): void {
    $room = Rooms::open($this->resad, mechanic: RoomMechanic::Domino101);
    $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $room), ['mechanic' => 'domino_101', 'difficulty' => 2, 'table' => 'pairs', 'computer' => 1])->assertNoContent();
    $this->actingAs($this->resad)->postJson(route('rooms.games.store', $room))->assertSuccessful();
    $game = $room->games()->sole();
    $game->forceFill(['seed' => DominoRooms::SEED])->save();

    expect([$game->seats, $game->computer])->toBe([[$room->rowOf($this->resad)->id, null, null, null], RoomComputerLevel::Easy]);

    for ($step = 0; $step < 2_000; $step++) {
        $domino = dominoRead($this->resad, $room);

        if ($domino['phase'] === 'over') {
            break;
        }

        match (true) {
            $domino['phase'] === 'countdown' => $this->travelTo(RoomClock::at($domino['starts_ms'])),
            $domino['phase'] === 'sheet' => $this->travelTo(RoomClock::at($domino['sheet']['until_ms'])),
            $domino['turn'] === 0 => [$this->travelTo(RoomClock::at($domino['since_ms'] + 1_000)), DominoRooms::play($this->resad, $game)->assertOk()],
            default => null,
        };
    }

    $state = DominoRooms::state($this->resad, $room);

    expect($state['domino']['result']['ending'])->toBe('target')
        ->and(collect($state['domino']['result']['standing'])->whereNull('player_id')->pluck('points')->all())->toBe([0, 0, 0])
        ->and(array_column($state['ranking'], 'player_id'))->toBe([$room->rowOf($this->resad)->id]);
});

it('plays the turn of a seat out of time at its flag, at «Orta», and the seat stays its player\'s', function (): void {
    $game = DominoRooms::start($this->resad, [$this->aygun], RoomTable::Two);
    $accounts = dominoAccounts($game, [$this->resad, $this->aygun]);
    $this->travelTo(RoomClock::at($game->started_ms));
    $domino = dominoRead($this->resad, $game->room);
    $mover = $accounts[$domino['seats'][$domino['turn']]['player_id']];

    // Before the flag a read plays nothing; at the flag the next read does, after its response.
    $this->travelTo(RoomClock::at($domino['flag_ms'] - 6_000));
    dominoRead($this->resad, $game->room);

    expect(dominoActs($game))->toBe([]);

    $this->travelTo(RoomClock::at($domino['flag_ms']));
    dominoRead($this->resad, $game->room);
    $act = RoomAct::query()->where('room_game_id', $game->id)->sole();
    $after = dominoRead($mover, $game->room);

    // Stored think_ms after the search began at the flag, so its moment never tells what the seat held (ADR 038 decision 7).
    expect([$act->kind, $act->ply, $act->room_player_id, $act->event_id, $act->acted_ms])->toBe([RoomActKind::Play, 0, null, null, $domino['flag_ms'] + Config::integer('elifba.rooms.domino.think_ms')])
        ->and($after['last'])->toMatchArray(['seat' => $domino['turn'], 'kind' => 'play', 'stood_in' => true])
        ->and($after['seats'][$domino['turn']]['standing'])->toBe('playing')
        ->and($after['mine']['away'])->toBeFalse()
        ->and($after['ply'])->toBe(1);

    // A device's play of that turn, sent late, is late.
    DominoRooms::play($mover, $game, [$act->path[0]], ply: 0)->assertOk()->assertJsonPath('status', 'late');
});

it('makes a seat away after two turns played for it, plays its turns at once, and takes it back at «Oyuna qayıt»', function (): void {
    $game = DominoRooms::start($this->resad, [$this->aygun], RoomTable::Two);
    $accounts = dominoAccounts($game, [$this->resad, $this->aygun]);
    $aygun = $game->room->rowOf($this->aygun)->id;
    $aygunSeat = array_search($aygun, $game->seats, true);
    $this->travelTo(RoomClock::at($game->started_ms));
    $timedOut = 0;

    // Rəşad plays his turns; Aygün's run out, one after another.
    while ($timedOut < 2) {
        $domino = dominoRead($this->resad, $game->room);

        if ($domino['phase'] === 'sheet') {
            $this->travelTo(RoomClock::at($domino['sheet']['until_ms']));

            continue;
        }

        if ($domino['turn'] === $aygunSeat) {
            $this->travelTo(RoomClock::at($domino['flag_ms']));
            dominoRead($this->resad, $game->room);
            $timedOut++;
        } else {
            $this->travelTo(RoomClock::at($domino['since_ms'] + 1_000));
            DominoRooms::play($this->resad, $game)->assertOk()->assertJsonPath('status', 'accepted');
        }
    }

    $domino = dominoRead($this->aygun, $game->room);

    expect($domino['seats'][$aygunSeat]['standing'])->toBe('away')
        ->and($domino['mine']['away'])->toBeTrue();

    // Away: its next turn is played think_ms after it began, not at its flag.
    while (($domino = dominoRead($this->resad, $game->room))['turn'] !== $aygunSeat) {
        $domino['phase'] === 'sheet'
            ? $this->travelTo(RoomClock::at($domino['sheet']['until_ms']))
            : [$this->travelTo(RoomClock::at($domino['since_ms'] + 1_000)), DominoRooms::play($this->resad, $game)->assertOk()];
    }

    $since = $domino['since_ms'];
    $this->travelTo(RoomClock::at($since));
    dominoRead($this->resad, $game->room);

    expect(RoomAct::query()->where('room_game_id', $game->id)->orderByDesc('id')->first()?->acted_ms)->toBe($since + Config::integer('elifba.rooms.domino.think_ms'));

    // «Oyuna qayıt»: a back, at the ply the game stands at; then it is its own again.
    $ply = dominoRead($this->aygun, $game->room)['ply'];
    DominoRooms::act($this->aygun, $game, 'back')->assertOk()->assertJsonPath('status', 'accepted');
    DominoRooms::act($this->aygun, $game, 'back')->assertOk()->assertJsonPath('status', 'late');
    $back = RoomAct::query()->where('room_game_id', $game->id)->where('kind', 'back')->sole();

    expect([$back->ply, $back->room_player_id, $back->path])->toBe([$ply, $aygun, null])
        ->and(dominoRead($this->aygun, $game->room)['seats'][$aygunSeat]['standing'])->toBe('playing');
});

it('plays for a seat whose player left at a computer\'s pace, and gives a «back» to the player who returns while away', function (): void {
    $game = DominoRooms::start($this->resad, [$this->aygun], RoomTable::Two);
    $aygun = $game->room->rowOf($this->aygun)->id;
    $aygunSeat = array_search($aygun, $game->seats, true);
    $this->travelTo(RoomClock::at($game->started_ms));
    $this->actingAs($this->aygun)->post(route('rooms.leave', $game->room))->assertRedirect();
    $stoodIn = 0;

    while ($stoodIn < 2) {
        $domino = dominoRead($this->resad, $game->room);

        if ($domino['phase'] === 'sheet') {
            $this->travelTo(RoomClock::at($domino['sheet']['until_ms']));

            continue;
        }

        if ($domino['turn'] === $aygunSeat) {
            expect($domino['seats'][$aygunSeat]['standing'])->toBe('left');
            $this->travelTo(RoomClock::at($domino['since_ms']));
            dominoRead($this->resad, $game->room);

            expect(RoomAct::query()->where('room_game_id', $game->id)->orderByDesc('id')->first()?->acted_ms)
                ->toBe($domino['since_ms'] + Config::integer('elifba.rooms.domino.think_ms'));
            $stoodIn++;
        } else {
            $this->travelTo(RoomClock::at($domino['since_ms'] + 1_000));
            DominoRooms::play($this->resad, $game)->assertOk();
        }
    }

    // She comes back: the join writes her «back» at the ply the game stands at, and she holds her tiles again.
    $ply = dominoRead($this->resad, $game->room)['ply'];
    Rooms::join($game->room, $this->aygun);
    $back = RoomAct::query()->where('room_game_id', $game->id)->where('kind', 'back')->sole();
    $domino = dominoRead($this->aygun, $game->room);

    expect([$back->ply, $back->room_player_id, $back->event_id])->toBe([$ply, $aygun, null])
        ->and($domino['seats'][$aygunSeat]['standing'])->toBe('playing')
        ->and($domino['mine']['seat'])->toBe($aygunSeat)
        ->and($domino['mine']['tiles'])->not->toBeEmpty();
});

it('stores the server\'s play once, the same play when asked twice, and none while another holds the turn or after the seat played', function (): void {
    $game = DominoRooms::start($this->resad, [$this->aygun], RoomTable::Two);
    $accounts = dominoAccounts($game, [$this->resad, $this->aygun]);
    $this->travelTo(RoomClock::at($game->started_ms));
    $domino = dominoRead($this->resad, $game->room);
    $mover = $accounts[$domino['seats'][$domino['turn']]['player_id']];

    // Another search holds the turn: nothing.
    $this->travelTo(RoomClock::at($domino['flag_ms']));
    $held = Cache::lock('room-computer:'.$game->id.':0', 10);
    $held->get();
    app(PlayComputerMove::class)->handle($game->room);
    $held->release();

    expect(dominoActs($game))->toBe([]);

    // Asked twice: one play stored.
    app(PlayComputerMove::class)->handle($game->room);
    app(PlayComputerMove::class)->handle($game->room);
    $first = RoomAct::query()->where('room_game_id', $game->id)->sole();

    // The same game again, the same play: the computer's source is the game's and the ply's.
    $first->delete();
    app(PlayComputerMove::class)->handle($game->room);

    expect(RoomAct::query()->where('room_game_id', $game->id)->sole()->path)->toBe($first->path);

    // A seat that played in time leaves the server nothing to play.
    $next = dominoRead($this->resad, $game->room);
    $nextMover = $accounts[$next['seats'][$next['turn']]['player_id']];
    $this->travelTo(RoomClock::at($next['flag_ms'] - 1));
    DominoRooms::play($nextMover, $game)->assertOk()->assertJsonPath('status', 'accepted');
    $count = RoomAct::query()->where('room_game_id', $game->id)->count();
    app(PlayComputerMove::class)->handle($game->room);

    expect(RoomAct::query()->where('room_game_id', $game->id)->count())->toBe($count)
        ->and($mover)->not->toBeNull();
});

it('never carries a domino game\'s computer into a board game chosen after it, directly or through a race', function (): void {
    $room = Rooms::open($this->resad, mechanic: RoomMechanic::Domino101);
    Rooms::join($room, $this->aygun);
    $put = fn (array $choice) => $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $room), $choice)->assertNoContent();
    $setup = fn (): array => DominoRooms::state($this->resad, $room)['setup'];

    $put(['mechanic' => 'domino_101', 'difficulty' => 2, 'table' => 'pairs', 'computer' => 3]);

    expect($setup()['computer'])->toBe(3);

    // A choice of another kind names only the game and its level (D5): the two players play each other.
    $put(['mechanic' => 'draughts', 'difficulty' => 2]);

    expect([$setup()['computer'], $setup()['black']])->toBe([null, $room->rowOf($this->aygun)->id]);

    $put(['mechanic' => 'domino_101', 'difficulty' => 2, 'table' => 'pairs', 'computer' => 1]);
    $put(['mechanic' => 'anagram', 'difficulty' => 2, 'rounds' => 5]);
    $put(['mechanic' => 'draughts', 'difficulty' => 2]);

    expect($setup()['computer'])->toBeNull();
});

it('plays a computer\'s opening of a hand after a read that came just before it opened', function (): void {
    $room = Rooms::open($this->resad, mechanic: RoomMechanic::Domino101);
    $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $room), ['mechanic' => 'domino_101', 'difficulty' => 2, 'table' => 'pairs'])->assertNoContent();
    $this->actingAs($this->resad)->postJson(route('rooms.games.store', $room))->assertSuccessful();
    // Seed 1 deals the lowest double to seat 2, a computer's.
    $game = $room->games()->sole();
    $game->forceFill(['seed' => 1])->save();
    $this->travelTo(RoomClock::at($game->started_ms));

    expect(DominoRooms::truth($game)->turn())->toBe(2);

    // A read in the countdown, 300 ms before the hand opens, arms the opener's play.
    $this->travelTo(RoomClock::at($game->started_ms - 300));
    $domino = dominoRead($this->resad, $room);
    $act = RoomAct::query()->where('room_game_id', $game->id)->sole();

    expect($domino['phase'])->toBe('countdown')
        ->and([$act->ply, $act->room_player_id, $act->acted_ms])->toBe([0, null, $game->started_ms + Config::integer('elifba.rooms.domino.think_ms')]);

    // A read long before an opening arms nothing.
    while (($domino = dominoRead($this->resad, $room))['phase'] !== 'sheet') {
        $domino['turn'] === 0
            ? [$this->travelTo(RoomClock::at($domino['since_ms'] + 1_000)), DominoRooms::play($this->resad, $game)->assertOk()]
            : $this->travelTo(RoomClock::at($domino['since_ms'] + Config::integer('elifba.rooms.domino.think_ms')));
    }

    $count = RoomAct::query()->where('room_game_id', $game->id)->count();
    $this->travelTo(RoomClock::at($domino['sheet']['until_ms'] - 6_000));
    dominoRead($this->resad, $room);

    expect(RoomAct::query()->where('room_game_id', $game->id)->count())->toBe($count);
});

it('stores the server\'s play think_ms after its search began, whatever the search took', function (): void {
    $room = Rooms::open($this->resad, mechanic: RoomMechanic::Domino101);
    $this->actingAs($this->resad)->postJson(route('rooms.games.store', $room))->assertSuccessful();
    $game = $room->games()->sole();
    $game->forceFill(['seed' => DominoRooms::SEED])->save();
    $this->travelTo(RoomClock::at($game->started_ms));
    // A long think keeps every read from playing the computer's turn until the late one.
    Config::set('elifba.rooms.domino.think_ms', 60_000);

    // Whoever opens, find the computer's turn and read it late: its play is stored think_ms after that read.
    while (($domino = dominoRead($this->resad, $room))['turn'] === 0) {
        $this->travelTo(RoomClock::at($domino['since_ms'] + 1_000));
        DominoRooms::play($this->resad, $game)->assertOk();
    }

    Config::set('elifba.rooms.domino.think_ms', 1_200);
    $late = $domino['since_ms'] + 4_000;
    $this->travelTo(RoomClock::at($late));
    dominoRead($this->resad, $room);

    expect(RoomAct::query()->where('room_game_id', $game->id)->whereNull('room_player_id')->orderByDesc('id')->first()?->acted_ms)->toBe($late + 1_200);
});
