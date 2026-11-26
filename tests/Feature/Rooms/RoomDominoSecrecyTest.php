<?php

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Actions\ChooseRoomGame;
use App\Domain\Rooms\Actions\StartRoomGame;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Enums\RoomComputerLevel;
use App\Domain\Rooms\Enums\RoomDominoPhase;
use App\Domain\Rooms\Enums\RoomTable;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAct;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Support\Domino\DominoGame;
use App\Domain\Rooms\Support\Domino\DominoTile;
use App\Domain\Rooms\Support\RoomClock;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\DominoRooms;
use Tests\Support\Rooms;

/*
 * Hidden hands (docs/architecture.md §12 "Hidden hands", ADR 038 decisions
 * 5–9): scripted games played through the room's own routes — four seats
 * at `pairs` with a watcher, a host who does not sit and the screen, then
 * `two` and `three` with a boneyard and a host who sits — and after every
 * act, every viewer's whole state, the `rooms/show` and `rooms/screen`
 * props, «Keçmiş oyunlar», the act's receipt, refused acts' answers, what
 * the channel was sent and the log are read whole. In each, every tile
 * named anywhere must be one its viewer may know: on the table, its own
 * seat's (a seat's player only — never the screen, though it reads with the
 * host's account), and in the phases `sheet` and `over` the tiles the sheet
 * shows left; never a tile of the boneyard, never the seed, and never a
 * tile at all in the channel or the log. A play of a tile another seat
 * holds, of a tile in the boneyard and of a tile already played get one
 * and the same refusal.
 */

beforeEach(function (): void {
    DominoRooms::on();
    // Every viewer reads after every act, more often than a phone does: the limits are not what is tested here.
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->travelTo('2026-11-24 10:00:00');
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message): void {
        $exception = $message->context['exception'] ?? null;
        $this->logged[] = $message->message.' '.json_encode($message->context).($exception instanceof Throwable ? ' '.$exception : '');
    });
    // What went out of the app beside the responses: the channel and the log.
    $this->outside = fn (): array => [...array_map(json_encode(...), $this->reverb->sent), ...$this->logged];
});

/**
 * Every whole JSON string that names a tile, as a list of names.
 *
 * @return list<string>
 */
function secrecyTilesIn(string $json): array
{
    preg_match_all('/"([0-6])-([0-6])"/', $json, $matches, PREG_SET_ORDER);

    return array_values(array_unique(array_map(static fn (array $match): string => $match[1].'-'.$match[2], $matches)));
}

/** Whether a text names a tile anywhere, inside a longer string too. */
function secrecyNamesATile(string $text): bool
{
    return preg_match('/(?<![0-9])[0-6]-[0-6](?![0-9])/', $text) === 1;
}

/**
 * What a viewer may know now (decisions 6–8): the line, its own seat's
 * tiles for a seat's player, and every tile left once a hand is over.
 *
 * @return list<string>
 */
function secrecyAllowed(DominoGame $truth, ?int $seat): array
{
    $hand = $truth->hand();

    if ($hand === null) {
        return [];
    }

    $allowed = array_map(static fn (array $placed): string => $placed['tile']->name(), $hand->line);

    if ($seat !== null) {
        array_push($allowed, ...DominoTile::names($hand->tiles[$seat]));
    }

    if ($truth->phase === RoomDominoPhase::Sheet || ($truth->phase === RoomDominoPhase::Over && $truth->sheet() !== null)) {
        foreach ($hand->tiles as $tiles) {
            array_push($allowed, ...DominoTile::names($tiles));
        }
    }

    return $allowed;
}

/**
 * A scripted game's viewers: each seat's player, a watcher, the host —
 * who may or may not sit — and the screen, reading with the host's account.
 *
 * @param  list<User>  $seated  in the order of the seats
 * @return array{game: RoomGame, viewers: list<array{name: string, account: User, seat: int|null, screen: bool}>}
 */
function secrecyGame(RoomTable $table, User $host, bool $hostSits, array $seated, User $watcher, int $seed): array
{
    $room = Rooms::open($host, plays: $hostSits, mechanic: RoomMechanic::Domino101);

    foreach ([...$seated, $watcher] as $account) {
        if ($account->isNot($host)) {
            test()->travel(1)->seconds();
            Rooms::join($room, $account);
        }
    }

    $seats = array_map(static fn (User $account): string => $room->rowOf($account)->id, $seated);
    app(ChooseRoomGame::class)->handle($room, new RoomGameSetupData(RoomMechanic::Domino101, 2, null, null, null, null, $table, $seats));
    $game = app(StartRoomGame::class)->handle($room->refresh());
    $game->forceFill(['seed' => $seed])->save();
    $viewers = [];

    foreach ($seated as $index => $account) {
        $viewers[] = ['name' => "seat {$index}", 'account' => $account, 'seat' => $index, 'screen' => false];
    }

    $viewers[] = ['name' => 'the watcher', 'account' => $watcher, 'seat' => null, 'screen' => false];

    if (! $hostSits) {
        $viewers[] = ['name' => 'the host who does not sit', 'account' => $host, 'seat' => null, 'screen' => false];
    }

    $viewers[] = ['name' => 'the screen', 'account' => $host, 'seat' => null, 'screen' => true];

    return ['game' => $game->refresh(), 'viewers' => $viewers];
}

/**
 * Reads everything every viewer is sent now and holds it to what each may
 * know; also the receipt and refusals of the act that led here, the channel
 * and the log.
 *
 * @param  list<array{name: string, account: User, seat: int|null, screen: bool}>  $viewers
 * @param  list<TestResponse>  $answers  the act's receipt and the refusals since the last check
 */
function secrecyCheck(RoomGame $game, array $viewers, array $answers, string $when): void
{
    $truth = DominoRooms::truth($game);
    $room = $game->room;
    $seed = (string) $game->seed;
    $hand = $truth->hand();
    $boneyard = $hand === null ? [] : DominoTile::names($hand->boneyard);

    $tables = [];

    foreach ($viewers as $viewer) {
        $allowed = secrecyAllowed($truth, $viewer['seat']);
        $payloads = [];

        // The whole state, by the route the page reads, through its DTO and back.
        $state = test()->actingAs($viewer['account'])->getJson(route('rooms.state', [$room, ...($viewer['screen'] ? ['for' => 'screen'] : [])]))->assertOk()->json();
        $payloads['state'] = json_encode($state, JSON_THROW_ON_ERROR);
        // Whatever the encoding, the table is everyone's: the domino but `mine` is the same for every viewer.
        $tables[$viewer['name']] = json_encode(array_diff_key($state['domino'], ['mine' => true]), JSON_THROW_ON_ERROR);

        expect(RoomStateData::fromArray($state)->toArray())->toBe($state, "{$viewer['name']} {$when}: the DTO");

        expect($state['domino']['mine'] === null)->toBe($viewer['seat'] === null, "{$viewer['name']} {$when}: mine");

        if ($viewer['seat'] !== null) {
            expect($state['domino']['mine']['seat'])->toBe($viewer['seat']);
        }

        // The page's props: the screen's page, or the room's page.
        $page = test()->actingAs($viewer['account'])->get($viewer['screen'] ? route('rooms.screen', $room) : route('rooms.show', $room))->assertOk();
        $payloads['props'] = json_encode($page->viewData('page')['props'], JSON_THROW_ON_ERROR);

        // «Keçmiş oyunlar».
        if (! $viewer['screen']) {
            $past = test()->actingAs($viewer['account'])->get(route('rooms.past'))->assertOk();
            $payloads['past'] = json_encode($past->viewData('page')['props'], JSON_THROW_ON_ERROR);
        }

        foreach ($payloads as $what => $json) {
            $named = secrecyTilesIn($json);

            expect(array_values(array_diff($named, $allowed)))->toBe([], "{$viewer['name']} {$when}: {$what} names a tile it may not know")
                ->and(array_values(array_intersect($named, $boneyard)))->toBe([], "{$viewer['name']} {$when}: {$what} names a boneyard tile")
                ->and(str_contains($json, $seed))->toBeFalse("{$viewer['name']} {$when}: {$what} holds the seed");
        }
    }

    expect(array_values(array_unique($tables)))->toHaveCount(1, "{$when}: the viewers' tables differ beyond their own tiles");

    foreach ($answers as $answer) {
        $body = $answer->getContent() ?: '';

        expect(secrecyNamesATile($body))->toBeFalse("{$when}: an answer names a tile: {$body}")
            ->and(str_contains($body, $seed))->toBeFalse();
    }

    foreach ((test()->outside)() as $line) {
        expect(secrecyNamesATile((string) $line))->toBeFalse("{$when}: the channel or the log names a tile: {$line}")
            ->and(str_contains((string) $line, $seed))->toBeFalse();
    }
}

/**
 * The seat to move tries three plays the judge does not take — a tile
 * another seat holds, a tile in the boneyard, a tile already played — and
 * gets one answer for all three.
 *
 * @param  array<int, User>  $bySeat
 * @return list<TestResponse>
 */
function secrecyRefusals(RoomGame $game, DominoGame $truth, array $bySeat): array
{
    $hand = $truth->hand();
    $seat = $truth->turn();

    if ($hand === null || $seat === null) {
        return [];
    }

    $other = DominoTile::names($hand->tiles[($seat + 1) % count($hand->tiles)])[0] ?? null;
    $tries = array_filter([
        $other,
        DominoTile::names($hand->boneyard)[0] ?? null,
        $hand->line === [] ? null : $hand->line[0]['tile']->name(),
    ]);
    $answers = [];

    foreach ($tries as $tile) {
        $answers[] = test()->actingAs($bySeat[$seat])->postJson(route('rooms.acts.store', $game->room), [
            'event_id' => strtolower((string) Str::ulid()), 'game_id' => $game->id, 'kind' => 'play', 'ply' => $truth->ply,
            'path' => $hand->line === [] ? [$tile] : [$tile, 'left'],
        ])->assertUnprocessable();
    }

    expect(array_values(array_unique(array_map(static fn (TestResponse $answer): string => (string) $answer->getContent(), $answers))))->toHaveCount($answers === [] ? 0 : 1);

    return $answers;
}

/**
 * Plays a scripted game through the room's routes — the seat to move plays
 * its first play a second into its turn, a pause is waited out — checking
 * everything after the start and after every act, until `$stop` says so.
 *
 * @param  list<array{name: string, account: User, seat: int|null, screen: bool}>  $viewers
 * @param  callable(DominoGame): bool  $stop
 */
function secrecyPlay(RoomGame $game, array $viewers, callable $stop): DominoGame
{
    $bySeat = [];

    foreach ($viewers as $viewer) {
        if ($viewer['seat'] !== null) {
            $bySeat[$viewer['seat']] = $viewer['account'];
        }
    }

    secrecyCheck($game, $viewers, [], 'in the countdown');
    $acts = 0;

    while (true) {
        $truth = DominoRooms::truth($game);

        if ($truth->phase === RoomDominoPhase::Over || $stop($truth)) {
            return $truth;
        }

        if ($truth->phase === RoomDominoPhase::Countdown) {
            test()->travelTo(RoomClock::at($truth->startsMs));

            continue;
        }

        if ($truth->phase === RoomDominoPhase::Sheet) {
            test()->travelTo(RoomClock::at((int) $truth->untilMs()));
            secrecyCheck($game, $viewers, [], "as hand {$truth->hand()?->number} opens");

            continue;
        }

        test()->travelTo(RoomClock::at((int) $truth->sinceMs() + 1_000));
        $refusals = secrecyRefusals($game, $truth, $bySeat);
        $receipt = DominoRooms::play($bySeat[(int) $truth->turn()], $game)->assertOk()->assertJsonPath('status', 'accepted');
        $acts++;

        expect(array_keys($receipt->json()))->toBe(['status', 'revision']);
        secrecyCheck($game, $viewers, [$receipt, ...$refusals], "after act {$acts}");
    }
}

it('sends no viewer a tile it may not know at four seats, to a watcher, a host who does not sit and the screen', function (): void {
    [$resad, $aygun, $tural, $leyla, $kamal, $nigar] = User::factory()->player()->count(6)->create()->all();
    ['game' => $game, 'viewers' => $viewers] = secrecyGame(RoomTable::Pairs, $nigar, false, [$resad, $aygun, $tural, $leyla], $kamal, 1_357_924_680);

    $truth = secrecyPlay($game, $viewers, static fn (DominoGame $truth): bool => $truth->hand()?->number === 2 && count($truth->hand()->line) >= 3);

    // The host ends it inside a hand: over without a sheet, the tiles still in the hands never shown.
    test()->actingAs($nigar)->deleteJson(route('rooms.game.destroy', $game->room))->assertNoContent();
    secrecyCheck($game, $viewers, [], 'once the host ended it');

    expect($truth->hands)->toHaveCount(2)
        ->and(DominoRooms::truth($game)->sheet())->toBeNull();
});

it('sends no viewer a tile it may not know at two seats with a boneyard, the host sitting, to the end at 101', function (): void {
    [$resad, $aygun, $tural] = User::factory()->player()->count(3)->create()->all();
    ['game' => $game, 'viewers' => $viewers] = secrecyGame(RoomTable::Two, $resad, true, [$resad, $aygun], $tural, 1_468_035_791);

    $truth = secrecyPlay($game, $viewers, static fn (DominoGame $truth): bool => false);

    expect($truth->phase)->toBe(RoomDominoPhase::Over)
        ->and($truth->winner)->not->toBeNull()
        ->and(collect($truth->hands)->sum(static fn ($hand): int => collect($hand->events)->where('kind', 'draw')->count()))->toBeGreaterThan(0);
});

it('sends no viewer a tile it may not know at three seats with a boneyard, through a hand\'s sheet', function (): void {
    [$resad, $aygun, $tural, $leyla] = User::factory()->player()->count(4)->create()->all();
    ['game' => $game, 'viewers' => $viewers] = secrecyGame(RoomTable::Three, $resad, true, [$resad, $aygun, $tural], $leyla, 1_579_146_802);

    $truth = secrecyPlay($game, $viewers, static fn (DominoGame $truth): bool => $truth->hand()?->number === 3);

    expect($truth->hands)->toHaveCount(3);
});

it('keeps the log and the channel free of tiles when a play is refused or comes late', function (): void {
    Log::shareContext([]);
    [$resad, $aygun] = User::factory()->player()->count(2)->create()->all();
    $game = DominoRooms::start($resad, [$aygun], RoomTable::Two, seed: 1_691_257_913);
    test()->travelTo(RoomClock::at($game->started_ms));
    $truth = DominoRooms::truth($game);
    $mover = $truth->turn() === 0 ? $resad : $aygun;
    $other = $truth->turn() === 0 ? $aygun : $resad;
    $tile = DominoTile::names($truth->hand()?->tiles[(int) $truth->turn()] ?? [])[0];

    $answers = [
        DominoRooms::play($other, $game, [$tile])->assertOk(),
        DominoRooms::play($mover, $game, ['9-9'])->assertUnprocessable(),
        DominoRooms::play($mover, $game, [$tile, 'middle'])->assertUnprocessable(),
        DominoRooms::play($mover, $game, [$tile], ply: 7)->assertOk(),
    ];

    foreach ($answers as $answer) {
        expect(secrecyNamesATile((string) $answer->getContent()))->toBeFalse();
    }

    expect(array_filter(($this->outside)(), secrecyNamesATile(...)))->toBe([]);
});

it('sends no viewer a tile it may not know through a computer\'s seat, flags, an away seat, a back, a leaver and a close', function (): void {
    // The server's wait does not move the test's clock: every read of a check is at one moment, and only the test moves it.
    Sleep::fake();
    [$resad, $aygun] = User::factory()->player()->count(2)->create()->all();
    $room = Rooms::open($resad, mechanic: RoomMechanic::Domino101);
    $this->travel(1)->seconds();
    Rooms::join($room, $aygun);
    $rows = [$room->rowOf($resad)->id, $room->rowOf($aygun)->id];
    app(ChooseRoomGame::class)->handle($room, new RoomGameSetupData(RoomMechanic::Domino101, 2, null, null, null, RoomComputerLevel::Hard, RoomTable::Three, [...$rows, null]));
    $game = app(StartRoomGame::class)->handle($room->refresh());
    $game->forceFill(['seed' => 1_802_368_024])->save();
    $game->refresh();
    $viewers = [
        ['name' => 'seat 0, the host', 'account' => $resad, 'seat' => 0, 'screen' => false],
        ['name' => 'seat 1', 'account' => $aygun, 'seat' => 1, 'screen' => false],
        ['name' => 'the screen', 'account' => $resad, 'seat' => null, 'screen' => true],
    ];
    $read = fn (): array => DominoRooms::peek($resad, $room);
    $acts = static fn (): int => RoomAct::query()->where('room_game_id', $game->id)->count();
    $flags = 0;
    $back = false;
    $left = false;

    secrecyCheck($game, $viewers, [], 'in the countdown');

    for ($step = 0; $acts() < 30 && $step < 400; $step++) {
        $domino = $read();

        if ($domino['phase'] === 'over') {
            break;
        }

        if ($domino['phase'] !== 'play') {
            $this->travelTo(RoomClock::at($domino['phase'] === 'countdown' ? $domino['starts_ms'] : $domino['sheet']['until_ms']));

            continue;
        }

        $before = $acts();
        $answers = [];

        match (true) {
            // Rəşad plays his own turns.
            $domino['turn'] === 0 => [$this->travelTo(RoomClock::at($domino['since_ms'] + 1_000)), $answers[] = DominoRooms::play($resad, $game)->assertOk()],
            // Aygün's first two turns run out at the flag: the server plays them, and she is away.
            $domino['turn'] === 1 && $flags < 2 && ! $left => [$this->travelTo(RoomClock::at($domino['flag_ms'])), DominoRooms::state($resad, $room), $flags++],
            // Then she comes back and plays once herself, and leaves: the server plays for her from then on.
            $domino['turn'] === 1 && ! $back => [$answers[] = DominoRooms::act($aygun, $game, 'back')->assertOk()->assertJsonPath('status', 'accepted'), $back = true],
            $domino['turn'] === 1 && ! $left => [
                $this->travelTo(RoomClock::at($domino['since_ms'] + 1_000)),
                $answers[] = DominoRooms::play($aygun, $game)->assertOk(),
                $this->actingAs($aygun)->post(route('rooms.leave', $room)),
                $left = true,
            ],
            // A seat played for — the computer's, and Aygün's once she left — by a read once its play is due.
            default => [$this->travelTo(RoomClock::at($domino['since_ms'] + Config::integer('elifba.rooms.domino.think_ms'))), DominoRooms::state($resad, $room)],
        };

        if ($left) {
            $viewers = array_values(array_filter($viewers, static fn (array $viewer): bool => $viewer['account']->isNot($aygun)));
        }

        if ($acts() !== $before || $answers !== []) {
            secrecyCheck($game, $viewers, $answers, "after act {$acts()}");
        }
    }

    expect([$flags, $back, $left])->toBe([2, true, true])
        ->and(RoomAct::query()->where('room_game_id', $game->id)->whereNull('room_player_id')->count())->toBeGreaterThan(4);

    // The host closes the room: its page and «Keçmiş oyunlar» say the game's numbers, never a tile but those on the table.
    $this->actingAs($resad)->post(route('rooms.close', $room))->assertRedirect();
    $truth = DominoRooms::truth($game);
    $allowed = secrecyAllowed($truth, 0);

    foreach ([[$resad, route('rooms.show', $room)], [$resad, route('rooms.past')], [$aygun, route('rooms.past')]] as [$account, $url]) {
        $json = json_encode($this->actingAs($account)->get($url)->assertOk()->viewData('page')['props'], JSON_THROW_ON_ERROR);

        expect(array_values(array_diff(secrecyTilesIn($json), $allowed)))->toBe([], "{$url} names a tile")
            ->and(str_contains($json, (string) $game->seed))->toBeFalse();
    }

    $past = $this->actingAs($aygun)->get(route('rooms.past'))->viewData('page')['props']['past'];

    expect($past[0]['games'][0])->toMatchArray(['mechanic' => 'domino_101', 'table' => 'three', 'computer' => 3])
        ->and($past[0]['games'][0]['balances'])->toHaveCount(3);
});
