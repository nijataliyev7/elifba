<?php

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Data\RoomActData;
use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Enums\RoomDominoPhase;
use App\Domain\Rooms\Enums\RoomSeatStanding;
use App\Domain\Rooms\Enums\RoomTable;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomTaunt;
use App\Domain\Rooms\Support\Domino\Domino101;
use App\Domain\Rooms\Support\Domino\DominoAct;
use App\Domain\Rooms\Support\Domino\DominoDeal;
use App\Domain\Rooms\Support\Domino\DominoGame;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DominoRooms;

/*
 * The domino fixtures of tests/Fixtures/rooms (ADR 038 decision 39), built
 * from rows: Rəşad hosts and sits, Aygün, Tural and Leyla sit, Kamal
 * watches; each fixture is what one of them — or the screen — reads at one
 * moment of one game, its acts made by the seat to move playing the first
 * of its plays a second into its turn. RoomContractTest and
 * contract.test.ts read the same files.
 */

const DOMINO_ROOM = '01kaqrx1zd7w4n5v6b8c9defgh';
const DOMINO_GAME = '01kaqs0a1b2c3d4e5f6g7h8j9k';
const DOMINO_STARTED_MS = 1_795_534_802_000;

/** @var array<string, string> each person's `room_players.id` */
const DOMINO_ROWS = [
    'resad' => '01kaqrx2ab3c4d5e6f7g8h9jkm',
    'aygun' => '01kaqrx3mn4p5q6r7s8t9vwxyz',
    'tural' => '01kaqrx4bc5d6e7f8g9h0jkmnp',
    'leyla' => '01kaqrx5cd6e7f8g9h0jkmnpqr',
    'kamal' => '01kaqrx6de7f8g9h0jkmnpqrst',
];

/**
 * Each fixture: its table, the seed, who sits, who reads (a person, or the
 * screen), how many acts were made, which seats the server played for
 * (their turns' acts have no player) and what else is true.
 *
 * @return array<string, array{table: RoomTable, seed: int, seats: list<string|null>, viewer: string, screen?: bool, acts: int, stood_in?: list<int>, after_ms?: int, ended?: bool, taunt?: bool, started?: bool, watcher?: bool}>
 */
function dominoScenarios(): array
{
    $four = ['resad', 'aygun', 'tural', 'leyla'];

    return [
        'chosen' => ['table' => RoomTable::Pairs, 'seed' => 1, 'seats' => ['resad', 'aygun', 'tural', null], 'viewer' => 'aygun', 'acts' => 0, 'started' => false, 'watcher' => false],
        'countdown' => ['table' => RoomTable::Pairs, 'seed' => 1, 'seats' => $four, 'viewer' => 'aygun', 'acts' => 0, 'after_ms' => -1_500],
        'play' => ['table' => RoomTable::Pairs, 'seed' => 1, 'seats' => $four, 'viewer' => 'mover', 'acts' => 6],
        'opener' => ['table' => RoomTable::Pairs, 'seed' => 1, 'seats' => $four, 'viewer' => 'mover', 'acts' => 21, 'after_ms' => 10_500],
        'drew' => ['table' => RoomTable::Two, 'seed' => 1, 'seats' => ['resad', 'aygun'], 'viewer' => 'mover', 'acts' => 21],
        'watch' => ['table' => RoomTable::Pairs, 'seed' => 1, 'seats' => $four, 'viewer' => 'kamal', 'acts' => 6],
        'screen' => ['table' => RoomTable::Pairs, 'seed' => 1, 'seats' => $four, 'viewer' => 'resad', 'screen' => true, 'acts' => 6],
        'away' => ['table' => RoomTable::Two, 'seed' => 1, 'seats' => ['resad', 'aygun'], 'viewer' => 'aygun', 'acts' => 4, 'stood_in' => [1]],
        'sheet.finished' => ['table' => RoomTable::Pairs, 'seed' => 2, 'seats' => $four, 'viewer' => 'aygun', 'acts' => 25],
        'sheet.blocked' => ['table' => RoomTable::Pairs, 'seed' => 1, 'seats' => $four, 'viewer' => 'aygun', 'acts' => 21],
        'sheet.tied' => ['table' => RoomTable::Pairs, 'seed' => 49, 'seats' => $four, 'viewer' => 'aygun', 'acts' => 24],
        'sheet.void' => ['table' => RoomTable::Pairs, 'seed' => 1002, 'seats' => $four, 'viewer' => 'aygun', 'acts' => 1],
        'over' => ['table' => RoomTable::Pairs, 'seed' => DominoRooms::DRY['pairs'], 'seats' => $four, 'viewer' => 'aygun', 'acts' => 1_000, 'taunt' => true],
        'stopped' => ['table' => RoomTable::Pairs, 'seed' => 1, 'seats' => $four, 'viewer' => 'aygun', 'acts' => 5, 'ended' => true],
    ];
}

/**
 * A game's acts as the seat to move playing the first of its plays a
 * second into each turn — the server's for the seats in `$stoodIn` —, at
 * most `$count` of them or to the end, and the moment after the last.
 *
 * @param  list<int>  $stoodIn
 * @return array{acts: list<DominoAct>, movers: list<int>, last_ms: int}
 */
function dominoScript(RoomTable $table, int $seed, int $count, array $stoodIn): array
{
    $acts = [];
    $movers = [];
    $nowMs = DOMINO_STARTED_MS;
    $presence = array_fill(0, $table->seats(), RoomSeatStanding::Playing);

    while (count($acts) < $count) {
        $game = DominoGame::replay(new Domino101, $table, DominoDeal::source($table, $seed), $presence, DOMINO_STARTED_MS, 30_000, 10_000, 2, null, $acts, $nowMs);

        if ($game->phase === RoomDominoPhase::Over) {
            break;
        }

        if ($game->phase === RoomDominoPhase::Sheet) {
            $nowMs = (int) $game->untilMs();

            continue;
        }

        $seat = (int) $game->turn();
        $nowMs = (int) $game->sinceMs() + 1_000;
        $play = $game->legal()[0];
        $acts[] = DominoAct::play($game->ply, $play['ends'] === [] ? [$play['tile']->name()] : [$play['tile']->name(), $play['ends'][0]], $nowMs, in_array($seat, $stoodIn, true));
        $movers[] = $seat;
    }

    return ['acts' => $acts, 'movers' => $movers, 'last_ms' => $nowMs];
}

/**
 * The state of a scenario, read through the room's route as its viewer.
 *
 * @return array<string, mixed>
 */
function dominoScenarioState(string $name, int $revision): array
{
    $scenario = dominoScenarios()[$name];
    test()->travelTo(CarbonImmutable::createFromTimestampMs(DOMINO_STARTED_MS - 600_000));

    $people = [
        'resad' => User::factory()->player()->create(['name' => 'Rəşad Quliyev']),
        'aygun' => User::factory()->player()->create(['name' => 'Aygün Məmmədova']),
        'tural' => User::factory()->player()->create(['name' => 'Tural Əliyev']),
        'leyla' => User::factory()->player()->create(['name' => 'Leyla Həsənova']),
        'kamal' => User::factory()->player()->create(['name' => 'Kamal Səfərov']),
    ];
    $present = array_values(array_unique([...array_filter($scenario['seats']), ...(($scenario['watcher'] ?? true) ? ['kamal'] : [])]));
    $room = Room::factory()->for($people['resad'], 'host')->create([
        'id' => DOMINO_ROOM, 'code' => '482913', 'revision' => $revision,
        'setup_mechanic' => RoomMechanic::Domino101, 'setup_difficulty' => 2, 'setup_rounds' => 10,
        'setup_table' => $scenario['table'], 'setup_seats' => array_map(static fn (?string $person): ?string => $person === null ? null : DOMINO_ROWS[$person], $scenario['seats']),
    ]);

    foreach (array_keys(DOMINO_ROWS) as $order => $person) {
        if (in_array($person, $present, true)) {
            RoomPlayer::factory()->for($room)->for($people[$person])->create(['id' => DOMINO_ROWS[$person], 'joined_at' => CarbonImmutable::now()->addMinutes($order)]);
        }
    }

    $script = ['acts' => [], 'movers' => [], 'last_ms' => DOMINO_STARTED_MS];

    if ($scenario['started'] ?? true) {
        $seats = array_map(static fn (?string $person): ?string => $person === null ? null : DOMINO_ROWS[$person], $scenario['seats']);
        $game = RoomGame::factory()->for($room)->create([
            'id' => DOMINO_GAME, 'mechanic' => RoomMechanic::Domino101, 'difficulty' => 2, 'started_ms' => DOMINO_STARTED_MS,
            'seed' => $scenario['seed'], 'reveal_ms' => 10_000, 'seats' => $seats, 'turn_ms' => 30_000,
        ]);
        $game->setDominoTable($scenario['table']);
        $game->save();
        $script = dominoScript($scenario['table'], $scenario['seed'], $scenario['acts'], $scenario['stood_in'] ?? []);

        foreach ($script['acts'] as $index => $act) {
            DB::table('room_acts')->insert([
                'event_id' => $act->stoodIn ? null : Str::lower((string) Str::ulid()), 'room_game_id' => DOMINO_GAME,
                'room_player_id' => $act->stoodIn ? null : $seats[$script['movers'][$index]],
                'kind' => 'play', 'ply' => $act->ply, 'path' => json_encode($act->path), 'acted_ms' => $act->actedMs,
            ]);
        }

        if ($scenario['taunt'] ?? false) {
            RoomTaunt::factory()->create(['room_game_id' => DOMINO_GAME, 'room_player_id' => DOMINO_ROWS['aygun'], 'target_player_id' => DOMINO_ROWS['resad'], 'taunt' => 'turtle', 'sent_ms' => DOMINO_STARTED_MS + 7_000]);
        }

        if ($scenario['ended'] ?? false) {
            $game->forceFill(['ended_ms' => $script['last_ms'] + 4_000])->save();
        }
    }

    $readMs = $script['last_ms'] + ($scenario['after_ms'] ?? 500) + ($scenario['ended'] ?? false ? 5_000 : 0);
    test()->travelTo(CarbonImmutable::createFromTimestampMs($readMs));
    $viewer = $scenario['viewer'] === 'mover'
        ? dominoMoverAt($scenario, $readMs)
        : $scenario['viewer'];

    return test()->actingAs($people[$viewer])
        ->getJson(route('rooms.state', [$room, ...(($scenario['screen'] ?? false) ? ['for' => 'screen'] : [])]))
        ->assertOk()
        ->json();
}

/** Who is to move in a scenario at a moment, by name. */
function dominoMoverAt(array $scenario, int $atMs): string
{
    $script = dominoScript($scenario['table'], $scenario['seed'], $scenario['acts'], $scenario['stood_in'] ?? []);
    $presence = array_fill(0, $scenario['table']->seats(), RoomSeatStanding::Playing);
    $game = DominoGame::replay(new Domino101, $scenario['table'], DominoDeal::source($scenario['table'], $scenario['seed']), $presence, DOMINO_STARTED_MS, 30_000, 10_000, 2, null, $script['acts'], $atMs);

    return $scenario['seats'][(int) $game->turn()] ?? throw new RuntimeException('Nobody to move.');
}

beforeEach(function (): void {
    DominoRooms::on();
});

it('builds the domino fixtures from rows, and the DTOs round-trip them', function (string $name): void {
    $path = __DIR__."/../../Fixtures/rooms/state.domino.{$name}.json";
    $fixture = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);

    $state = dominoScenarioState($name, $fixture['revision']);

    expect($state)->toBe($fixture)
        ->and(RoomStateData::fromArray($fixture)->toArray())->toBe($fixture);
})->with(array_keys(dominoScenarios()));

it('takes a domino act of each kind as the device sends it', function (string $kind): void {
    $fixture = json_decode((string) file_get_contents(__DIR__."/../../Fixtures/rooms/act.{$kind}.json"), true, 64, JSON_THROW_ON_ERROR);

    expect(RoomActData::validate($fixture)->toArray())->toBe($fixture)
        ->and(RoomActData::fromArray($fixture)->toArray())->toBe($fixture);
})->with(['play', 'yield', 'back']);
