<?php

namespace Tests\Support;

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Actions\ChooseRoomGame;
use App\Domain\Rooms\Actions\StartRoomGame;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Enums\RoomActKind;
use App\Domain\Rooms\Enums\RoomComputerLevel;
use App\Domain\Rooms\Enums\RoomSeatStanding;
use App\Domain\Rooms\Enums\RoomTable;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAct;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\Domino\Domino101;
use App\Domain\Rooms\Support\Domino\DominoAct;
use App\Domain\Rooms\Support\Domino\DominoDeal;
use App\Domain\Rooms\Support\Domino\DominoGame;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Domino «101» in a room, for feature tests (docs/architecture.md §12
 * "Domino", ADR 038). `on()` turns the rooms area on and offers «101»,
 * which no instance does before Y6 (ADR 038 as amended 2026-11-26, Y5).
 * A game is chosen through `ChooseRoomGame` and started through
 * `StartRoomGame`, as a host's requests would, and then given a known
 * seed, so its deals are the same on every run. A seat plays through the
 * room's own route, `POST /rooms/{room}/acts`, choosing among the plays
 * its own state offers (`mine.legal`) — what its device knows, never more.
 */
final class DominoRooms
{
    /** The seed every game of these tests is dealt from, unless a test names another. */
    public const int SEED = 20_261_126;

    /**
     * Seeds whose games, played by `playOut()`, end in a dry win: at `two`
     * after 5 hands, `three` after 3, `four` after 2 and `pairs` after 4 —
     * under `on()`'s key.
     */
    public const array DRY = ['two' => 5, 'three' => 13, 'four' => 24, 'pairs' => 19];

    /** A seed whose game at `two`, played by `playOut()`, lasts 15 hands (278 turn acts). */
    public const int LONG = 41;

    /**
     * Turns the rooms area on and offers «101». The deals are keyed by the
     * instance's `app.key` (ADR 038 decision 5), so the tests fix one: the
     * same seed deals the same hands on every machine.
     */
    public static function on(): void
    {
        Rooms::on();
        Config::set('elifba.rooms.domino.offered', true);
        Config::set('app.key', 'base64:'.base64_encode(str_repeat('d', 32)));
    }

    /**
     * A room hosted by `$host` — who sits when `$plays` — that `$players`
     * join in order, its choice of «101» made with the given table, seats and
     * computer, and the game started and dealt from `$seed`.
     *
     * @param  list<User>  $players
     * @param  list<string|null>|null  $seats
     */
    public static function start(User $host, array $players, ?RoomTable $table = null, ?array $seats = null, ?RoomComputerLevel $computer = null, int $difficulty = 2, bool $plays = true, int $seed = self::SEED): RoomGame
    {
        $room = Rooms::open($host, plays: $plays, mechanic: RoomMechanic::Domino101);

        foreach ($players as $player) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
            Rooms::join($room, $player);
        }

        app(ChooseRoomGame::class)->handle($room, new RoomGameSetupData(RoomMechanic::Domino101, $difficulty, null, null, null, $computer, $table, $seats));
        $game = app(StartRoomGame::class)->handle($room->refresh());
        $game->forceFill(['seed' => $seed])->save();

        return $game->refresh();
    }

    /**
     * The game as the server knows it — every hand and the boneyard —,
     * replayed here as `DominoDriver` replays it, for a test to know what
     * no device may: never sent anywhere.
     */
    public static function truth(RoomGame $game): DominoGame
    {
        $game = $game->fresh() ?? throw new RuntimeException('The game is gone.');
        $table = $game->dominoTable() ?? throw new RuntimeException('Not a domino game.');
        $seats = $game->seats ?? [];
        $presence = array_map(static fn (?string $player): RoomSeatStanding => match (true) {
            $player === null => RoomSeatStanding::Computer,
            RoomPlayer::query()->find($player)?->isPresent() === true => RoomSeatStanding::Playing,
            default => RoomSeatStanding::Left,
        }, $seats);

        return DominoGame::replay(
            new Domino101,
            $table,
            DominoDeal::source($table, $game->seed),
            $presence,
            $game->started_ms,
            (int) $game->turn_ms,
            $game->reveal_ms,
            Config::integer('elifba.rooms.domino.away_after'),
            $game->ended_ms,
            array_values(array_filter($game->acts->map(static fn (RoomAct $act): ?DominoAct => match ($act->kind) {
                RoomActKind::Play => DominoAct::play($act->ply, $act->path ?? [], $act->acted_ms, $act->room_player_id === null),
                RoomActKind::Yield => DominoAct::yield($act->ply, $act->acted_ms),
                RoomActKind::Back => DominoAct::back((int) array_search($act->room_player_id, $seats, true), $act->ply, $act->acted_ms),
                default => null,
            })->all())),
            RoomClock::nowMs(),
        );
    }

    /** The state as `$account` reads it, by the room's own route. */
    public static function state(User $account, Room $room, bool $screen = false): array
    {
        $state = test()->actingAs($account)->getJson(route('rooms.state', [$room, ...($screen ? ['for' => 'screen'] : [])]))->assertOk()->json();

        expect(RoomStateData::fromArray($state)->toArray())->toBe($state);

        return $state;
    }

    /**
     * The domino part of the state as `$account` reads it, without the
     * route — for the tests that only choose a play.
     *
     * @return array<string, mixed>
     */
    public static function peek(User $account, Room $room): array
    {
        $domino = RoomState::for($room->refresh(), $account)->domino?->toArray();

        return $domino ?? throw new RuntimeException('No domino game in the room.');
    }

    /**
     * A play of `$account`'s seat posted by the room's route: `$path` as
     * given, or the first of its own legal plays at its first end.
     *
     * @param  list<string>|null  $path
     * @return TestResponse<Response>
     */
    public static function play(User $account, RoomGame $game, ?array $path = null, ?int $ply = null, ?string $eventId = null): TestResponse
    {
        $room = $game->room;
        $domino = self::peek($account, $room);

        if ($path === null) {
            $legal = $domino['mine']['legal'][0] ?? throw new RuntimeException('The seat has no play.');
            $path = $legal['ends'] === [] ? [$legal['tile']] : [$legal['tile'], $legal['ends'][0]];
        }

        return test()->actingAs($account)->postJson(route('rooms.acts.store', $room), [
            'event_id' => $eventId ?? strtolower((string) Str::ulid()),
            'game_id' => $game->id,
            'kind' => 'play',
            'ply' => $ply ?? $domino['ply'],
            'path' => $path,
        ]);
    }

    /**
     * An act of another kind — `yield` or `back` — posted by the room's route.
     *
     * @return TestResponse<Response>
     */
    public static function act(User $account, RoomGame $game, string $kind, ?int $ply = null): TestResponse
    {
        return test()->actingAs($account)->postJson(route('rooms.acts.store', $game->room), array_filter([
            'event_id' => strtolower((string) Str::ulid()),
            'game_id' => $game->id,
            'kind' => $kind,
            'ply' => $ply,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * Plays the game through the room's routes until `$until` says stop or
     * it is over: the seat to move plays the first of its plays, a second
     * after the turn began; a pause between hands is waited out. Each act's
     * response goes to `$after` with the account that sent it.
     *
     * @param  array<string, User>  $accounts  each seat's player's account, by `room_players.id`
     * @param  (callable(array<string, mixed>): bool)|null  $until  given the domino part, true to stop
     * @param  (callable(TestResponse<Response>, User): void)|null  $after
     * @return array<string, mixed> the domino part where it stopped
     */
    public static function playOut(RoomGame $game, User $watcher, array $accounts, ?callable $until = null, ?callable $after = null, int $limit = 2_000): array
    {
        $room = $game->room;

        for ($step = 0; $step < $limit; $step++) {
            $domino = self::peek($watcher, $room);

            if ($domino['phase'] === 'over' || ($until !== null && $until($domino))) {
                return $domino;
            }

            if ($domino['phase'] === 'countdown') {
                test()->travelTo(RoomClock::at($domino['starts_ms']));

                continue;
            }

            if ($domino['phase'] === 'sheet') {
                test()->travelTo(RoomClock::at($domino['sheet']['until_ms']));

                continue;
            }

            $player = $domino['seats'][$domino['turn']]['player_id'] ?? throw new RuntimeException('A seat without a player is to move.');
            $account = $accounts[$player];
            test()->travelTo(RoomClock::at($domino['since_ms'] + 1_000));
            $response = self::play($account, $game)->assertOk()->assertJsonPath('status', 'accepted');

            if ($after !== null) {
                $after($response, $account);
            }
        }

        throw new RuntimeException('The game did not end.');
    }
}
