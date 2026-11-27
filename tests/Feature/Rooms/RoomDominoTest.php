<?php

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Actions\EndRoomGame;
use App\Domain\Rooms\Actions\PlayComputerMove;
use App\Domain\Rooms\Actions\RecordRoomAct;
use App\Domain\Rooms\Actions\StartRoomGame;
use App\Domain\Rooms\Data\RoomActData;
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

it('never refuses the seats a choice keeps: a kept place whose player left is empty, and only posted seats are refused (ADR 038 decisions 11, 12, 75)', function (): void {
    $room = Rooms::open($this->resad, mechanic: RoomMechanic::Domino101);
    $this->travel(1)->seconds();
    $aygun = Rooms::join($room, $this->aygun)->id;
    $resad = $room->rowOf($this->resad)->id;
    $put = fn (array $choice) => $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $room), $choice);
    $setup = fn (): array => array_intersect_key(DominoRooms::state($this->resad, $room)['setup'], array_flip(['mechanic', 'table', 'seats', 'computer']));

    // A level tap on the start screen posts the whole choice the state shows: its table and both seats, stored.
    $shown = DominoRooms::state($this->resad, $room)['setup'];
    $put(['mechanic' => 'domino_101', 'difficulty' => 3, 'table' => $shown['table'], 'seats' => $shown['seats'], 'computer' => null])->assertNoContent();

    expect($room->refresh()->setup_seats)->toBe([$resad, $aygun]);

    $this->actingAs($this->aygun)->post(route('rooms.leave', $room))->assertRedirect();

    // «Anaqram», then «101» as a page sends a choice of another kind — the game and its level — and the room fills the rest.
    $put(['mechanic' => 'anagram', 'difficulty' => 2, 'rounds' => 5])->assertNoContent();
    $put(['mechanic' => 'domino_101', 'difficulty' => 2])->assertNoContent();

    expect($setup())->toBe(['mechanic' => 'domino_101', 'table' => 'two', 'seats' => [$resad, null], 'computer' => 2])
        ->and($room->refresh()->setup_seats)->toBe([$resad, null]);

    // A kept place she held is empty on the same kind too: with a level alone, and with a table alone, which fits the seats to it.
    $room->forceFill(['setup_seats' => [$resad, $aygun]])->save();
    $put(['mechanic' => 'domino_101', 'difficulty' => 1])->assertNoContent();

    expect($room->refresh()->setup_seats)->toBe([$resad, null]);

    $room->forceFill(['setup_seats' => [$aygun, $resad]])->save();
    $put(['mechanic' => 'domino_101', 'difficulty' => 1, 'table' => 'three'])->assertNoContent();

    expect($room->refresh()->setup_seats)->toBe([null, $resad, null])
        ->and($setup())->toBe(['mechanic' => 'domino_101', 'table' => 'three', 'seats' => [null, $resad, null], 'computer' => 2]);

    // Posted, a player who is gone is refused, and nothing changes.
    $put(['mechanic' => 'domino_101', 'difficulty' => 1, 'table' => 'two', 'seats' => [$resad, $aygun]])->assertUnprocessable()->assertJsonValidationErrors('seats');

    expect($room->refresh()->setup_table)->toBe(RoomTable::Three);
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

it('refuses a play whose end is null or empty by the act\'s rules, with the one answer, though it comes out of turn or at another ply (ADR 038 decision 78)', function (): void {
    $game = DominoRooms::start($this->resad, [$this->aygun], RoomTable::Two);
    $accounts = dominoAccounts($game, [$this->resad, $this->aygun]);
    $this->travelTo(RoomClock::at($game->started_ms));
    DominoRooms::play(dominoMover($game, $accounts, $this->resad), $game)->assertOk()->assertJsonPath('status', 'accepted');

    $mover = dominoMover($game, $accounts, $this->resad);
    $other = collect($accounts)->first(static fn (User $account): bool => $account->isNot($mover));
    $tile = DominoRooms::peek($other, $game->room)['mine']['tiles'][0];
    $refusals = [];

    // A form the act's rules take is `late` from these seats; a form they refuse is the one 422, whatever else is wrong.
    DominoRooms::play($other, $game, [$tile, 'left'])->assertOk()->assertJsonPath('status', 'late');

    foreach ([
        'an end of no name' => [$tile, 'middle'],
        'a null end' => [$tile, null],
        // A JSON body's empty string reaches the rules as null.
        'an empty end' => [$tile, ''],
    ] as $why => $path) {
        $refusals["{$why}, out of turn"] = DominoRooms::play($other, $game, $path)->assertUnprocessable()->json();
        $refusals["{$why}, at another ply"] = DominoRooms::play($mover, $game, $path, ply: 7)->assertUnprocessable()->json();
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

/**
 * A room of one against the computer, dealt from the tests' seed, played
 * until `$stop` names the turn: the host plays a second into its own turns,
 * the server the computer's.
 *
 * @param  callable(array<string, mixed>): bool  $stop
 * @return array{0: Room, 1: RoomGame, 2: array<string, mixed>}
 */
function dominoAlone(User $host, callable $stop): array
{
    $room = Rooms::open($host, mechanic: RoomMechanic::Domino101);
    test()->actingAs($host)->postJson(route('rooms.games.store', $room))->assertSuccessful();
    $game = $room->games()->sole();
    $game->forceFill(['seed' => DominoRooms::SEED])->save();
    test()->travelTo(RoomClock::at($game->started_ms));

    for ($step = 0; $step < 100; $step++) {
        $domino = DominoRooms::peek($host, $room);

        if ($domino['phase'] === 'play' && $stop($domino)) {
            return [$room, $game->refresh(), $domino];
        }

        match ($domino['phase']) {
            'countdown' => test()->travelTo(RoomClock::at($domino['starts_ms'])),
            'sheet' => test()->travelTo(RoomClock::at($domino['sheet']['until_ms'])),
            default => $domino['turn'] === 0
                ? [test()->travelTo(RoomClock::at($domino['since_ms'] + 1_000)), DominoRooms::play($host, $game)->assertOk()]
                : [test()->travelTo(RoomClock::at($domino['since_ms'] + Config::integer('elifba.rooms.domino.think_ms'))), app(PlayComputerMove::class)->handle($room)],
        };
    }

    throw new RuntimeException('No such turn.');
}

/**
 * Runs `$inside` once, inside the next wait of the server's play — after
 * its choice and before it stores under the room's lock — and leaves the
 * clock where that wait ends, or later if `$inside` moved it there.
 *
 * @param  callable(): void  $inside
 */
function dominoInsideSleep(callable $inside): void
{
    $done = false;

    Sleep::whenFakingSleep(static function () use (&$done, $inside): void {
        if ($done) {
            return;
        }

        $done = true;
        $wake = RoomClock::nowMs();
        $inside();

        if (RoomClock::nowMs() < $wake) {
            test()->travelTo(RoomClock::at($wake));
        }
    });
}

it('stores nothing at a ply the seat played while the server waited to play it, the computer to move next (ADR 038 decision 28)', function (): void {
    [$room, $game, $domino] = dominoAlone($this->resad, static fn (array $domino): bool => $domino['turn'] === 0 && $domino['ply'] > 0);
    $ply = $domino['ply'];
    $flag = $domino['flag_ms'];
    $before = count(dominoActs($game));
    $legal = $domino['mine']['legal'][0];
    $path = $legal['ends'] === [] ? [$legal['tile']] : [$legal['tile'], $legal['ends'][0]];

    // The server holds the seat's turn for its flag; the seat plays before the flag, while the server waits.
    dominoInsideSleep(function () use ($room, $game, $ply, $flag, $path): void {
        $this->travelTo(RoomClock::at($flag - 2_000));
        $receipt = app(RecordRoomAct::class)->handle($room->refresh(), $this->resad, new RoomActData(strtolower((string) Str::ulid()), $game->id, RoomActKind::Play, $ply, $path));

        expect($receipt->status->value)->toBe('accepted');
    });

    $this->travelTo(RoomClock::at($flag - 4_000));
    app(PlayComputerMove::class)->handle($room);

    // At the wake the computer's turn is due, at the next ply: nothing is stored at the seat's.
    expect(array_slice(dominoActs($game), $before))->toBe([['play', $ply, true]]);
});

it('stores nothing in either game when the host ends the game and starts another while the server waited to play (ADR 038 decision 28)', function (): void {
    [$room, $game, $domino] = dominoAlone($this->resad, static fn (array $domino): bool => $domino['ply'] === 0);
    $due = $domino['turn'] === 0 ? $domino['flag_ms'] : $domino['since_ms'] + Config::integer('elifba.rooms.domino.think_ms');
    $second = null;

    dominoInsideSleep(function () use ($room, &$second): void {
        app(EndRoomGame::class)->handle($room->refresh());
        $second = app(StartRoomGame::class)->handle($room->refresh());
        $second->forceFill(['seed' => DominoRooms::SEED])->save();
        // The new game's first turn is due by the time the server stores.
        $this->travelTo(RoomClock::at($second->started_ms + (int) $second->turn_ms));
    });

    $this->travelTo(RoomClock::at($due - 1_000));
    app(PlayComputerMove::class)->handle($room);

    expect($second)->toBeInstanceOf(RoomGame::class)
        ->and(dominoActs($game))->toBe([])
        ->and(dominoActs($second))->toBe([]);
});

it('stores nothing for a seat whose player came back while the server waited to play it, and plays it at its flag (ADR 038 decisions 27, 28)', function (): void {
    $game = DominoRooms::start($this->resad, [$this->aygun], RoomTable::Two);
    $room = $game->room;
    $aygunSeat = array_search($room->rowOf($this->aygun)->id, $game->seats, true);
    $this->travelTo(RoomClock::at($game->started_ms));
    $this->actingAs($this->aygun)->post(route('rooms.leave', $room))->assertRedirect();

    for ($step = 0; ($domino = DominoRooms::peek($this->resad, $room))['phase'] !== 'play' || $domino['turn'] !== $aygunSeat; $step++) {
        expect($step)->toBeLessThan(100);

        match ($domino['phase']) {
            'countdown' => $this->travelTo(RoomClock::at($domino['starts_ms'])),
            'sheet' => $this->travelTo(RoomClock::at($domino['sheet']['until_ms'])),
            default => [$this->travelTo(RoomClock::at($domino['since_ms'] + 1_000)), DominoRooms::play($this->resad, $game)->assertOk()],
        };
    }

    expect($domino['seats'][$aygunSeat]['standing'])->toBe('left');
    $ply = $domino['ply'];
    $since = $domino['since_ms'];
    $before = count(dominoActs($game));

    // She comes back while the server waits to play her seat at a computer's pace: her turn is due at its flag now.
    dominoInsideSleep(function () use ($room): void {
        Rooms::join($room, $this->aygun);
    });

    $this->travelTo(RoomClock::at($since));
    app(PlayComputerMove::class)->handle($room);

    expect(array_slice(dominoActs($game), $before))->toBe([])
        ->and(DominoRooms::peek($this->resad, $room)['seats'][$aygunSeat]['standing'])->toBe('playing');

    $this->travelTo(RoomClock::at($since + (int) $game->turn_ms));
    app(PlayComputerMove::class)->handle($room);

    expect(array_slice(dominoActs($game), $before))->toBe([['play', $ply, false]])
        ->and(RoomAct::query()->where('room_game_id', $game->id)->orderByDesc('id')->first()?->acted_ms)->toBeGreaterThanOrEqual($since + (int) $game->turn_ms);
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

it('carries no computer between a domino game and a board game chosen on «Oyunlar», directly or through a race, and keeps a match\'s own (ADR 038 decision 75, ADR 036 decision 2)', function (): void {
    $room = Rooms::open($this->resad, mechanic: RoomMechanic::Domino101);
    $this->travel(1)->seconds();
    Rooms::join($room, $this->aygun);
    $resad = $room->rowOf($this->resad)->id;
    $aygun = $room->rowOf($this->aygun)->id;
    $put = fn (array $choice) => $this->actingAs($this->resad)->putJson(route('rooms.setup.update', $room), $choice)->assertNoContent();
    // A game tapped on «Oyunlar» while the host's room is open (`OpenRoom`).
    $games = fn (string $mechanic) => $this->actingAs($this->resad)->post(route('rooms.store'), ['mechanic' => $mechanic])->assertRedirect(route('rooms.show', $room));
    $setup = fn (): array => DominoRooms::state($this->resad, $room)['setup'];

    // «101» at «Çətin», then «Şaşki» on «Oyunlar»: the two players play each other.
    $put(['mechanic' => 'domino_101', 'difficulty' => 2, 'table' => 'pairs', 'computer' => 3]);
    $games('draughts');

    expect([$setup()['mechanic'], $setup()['computer'], $setup()['black']])->toBe(['draughts', null, $aygun]);

    // Through a race chosen on «Oyunlar», then the start screen's «Şaşki»: still no computer.
    $put(['mechanic' => 'domino_101', 'difficulty' => 2, 'table' => 'pairs', 'computer' => 3]);
    $games('anagram');

    expect($room->refresh()->setup_computer)->toBeNull();

    $put(['mechanic' => 'draughts', 'difficulty' => 2]);

    expect([$setup()['computer'], $setup()['black']])->toBe([null, $aygun]);

    // A match's «Çətin», then «101» on «Oyunlar»: its computer's seats take «Orta».
    $put(['mechanic' => 'draughts', 'difficulty' => 2, 'white' => $resad, 'black' => null, 'computer' => 3]);
    $games('domino_101');

    expect([$setup()['mechanic'], $setup()['table'], $setup()['seats'], $setup()['computer']])->toBe(['domino_101', 'pairs', [$resad, $aygun, null, null], 2]);

    // A match's own level stays when «Şaşki» is chosen again on «Oyunlar», directly or through a race (ADR 036 decision 2).
    $put(['mechanic' => 'draughts', 'difficulty' => 2, 'white' => $resad, 'black' => null, 'computer' => 3]);
    $games('draughts');

    expect([$setup()['computer'], $setup()['white'], $setup()['black']])->toBe([3, $resad, null]);

    $games('anagram');
    $games('draughts');

    expect([$setup()['computer'], $setup()['white'], $setup()['black']])->toBe([3, $resad, null]);
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
