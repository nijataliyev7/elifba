<?php

namespace Tests\Support;

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Actions\ChooseRoomGame;
use App\Domain\Rooms\Actions\EndRoomGame;
use App\Domain\Rooms\Actions\JoinRoom;
use App\Domain\Rooms\Actions\OpenRoom;
use App\Domain\Rooms\Actions\SetHostPlays;
use App\Domain\Rooms\Actions\StartRoomGame;
use App\Domain\Rooms\Data\HostPlaysData;
use App\Domain\Rooms\Data\OpenRoomData;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Enums\RoomActKind;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomMatchColor;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAct;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Support\RoomClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The adults' rooms area for feature tests (docs/architecture.md §12 "The
 * area and the switch", ADR 019). `Tests\TestCase` leaves the switch off,
 * as an instance has it; a test of the area turns it on first. Rooms are
 * opened behind a chosen game and joined through their actions (`open()`,
 * `join()`), as a player's requests would, and a game is chosen through
 * `ChooseRoomGame` and started through `StartRoomGame` (`playing()`). A test reaches a moment of the game's timetable with
 * `$this->travelTo(Rooms::at($game, RoomPhase::Answer, 1))`, and reads a
 * round's hidden solution with `right()` — a device never can.
 */
final class Rooms
{
    /** Switches the rooms area on for the rest of the test. */
    public static function on(): void
    {
        Config::set('elifba.rooms.enabled', true);
    }

    /**
     * Switches the area and its live voice on for the rest of the test
     * (docs/architecture.md §12 "Live voice"), with a voice server named and
     * faked — with `Config::set`, never the environment, which CI's `.env`
     * beats — and gives the fake that answers in its place. With the test's
     * `FakeReverb`, the fake also notes how many broadcasts went out before
     * each call.
     */
    public static function voiceOn(?FakeReverb $reverb = null): FakeVoiceServer
    {
        self::on();

        Config::set('elifba.rooms.voice.enabled', true);
        Config::set('elifba.rooms.voice.key', FakeVoiceServer::KEY);
        Config::set('elifba.rooms.voice.secret', FakeVoiceServer::SECRET);
        Config::set('elifba.rooms.voice.url', FakeVoiceServer::URL);
        Config::set('elifba.rooms.voice.api_url', FakeVoiceServer::API_URL);

        return FakeVoiceServer::install($reverb);
    }

    /**
     * A room opened by `$host` (a new player when null) choosing `$mechanic`
     * through `OpenRoom`: a code, the choice, and the host as its first
     * player — stepped out through `SetHostPlays` when `$plays` is false.
     * `$voice` is what the controller tells `OpenRoom` (`RoomsArea::voice()`):
     * false by default, a room opened with the instance's voice off — or
     * before ADR 031 as amended 2026-11-19 — whose host turns it on.
     */
    public static function open(?User $host = null, bool $plays = true, RoomMechanic $mechanic = RoomMechanic::RecallOrder, bool $voice = false): Room
    {
        $room = app(OpenRoom::class)->handle($host ?? User::factory()->player()->create(), new OpenRoomData($mechanic), $voice);

        if (! $plays) {
            app(SetHostPlays::class)->handle($room, new HostPlaysData(false));
        }

        return $room->refresh();
    }

    /**
     * The entrance's one refusal of a code that opens no room for the
     * account (§12 "Rooms and joining"): to the code field at `/oyun`, with
     * `rooms.errors.unknown` on `code` — never an error page.
     *
     * @param  TestResponse<Response>  $response
     */
    public static function assertRefused(TestResponse $response): void
    {
        $response->assertRedirect(route('rooms.entrance'))
            ->assertSessionHasErrors(['code' => __('rooms.errors.unknown')]);
    }

    /**
     * `$account` (a new player when null) joins the room through `JoinRoom`.
     */
    public static function join(Room $room, ?User $account = null): RoomPlayer
    {
        return app(JoinRoom::class)->handle($room, $account ?? User::factory()->player()->create());
    }

    /**
     * `$account` (a new player when null) joins the room while a game runs —
     * the one running, or one started for it and ended right after — so that,
     * with the room's voice on, its row waits for «Səsə burax»: a player who
     * comes in before a game is let in at once (ADR 031 decision 39, as
     * amended 2026-11-19). The room is back between games unless its game
     * was already running.
     */
    public static function newcomer(Room $room, ?User $account = null): RoomPlayer
    {
        $running = $room->refresh()->runningGame(RoomClock::nowMs()) !== null;

        if (! $running) {
            self::playing($room);
        }

        $row = self::join($room, $account);

        if (! $running) {
            app(EndRoomGame::class)->handle($room);
        }

        return $row->refresh();
    }

    /**
     * A game of `recall_order` (or `$mechanic`) chosen in `$room` (a new
     * room when null) through `ChooseRoomGame` and started through
     * `StartRoomGame`, with its rounds loaded; round 0 opens after the
     * countdown.
     */
    public static function playing(?Room $room = null, int $rounds = 5, int $difficulty = 1, RoomMechanic $mechanic = RoomMechanic::RecallOrder): RoomGame
    {
        $room ??= self::open();
        app(ChooseRoomGame::class)->handle($room, new RoomGameSetupData($mechanic, $difficulty, $rounds));

        return app(StartRoomGame::class)->handle($room)->load('rounds');
    }

    /**
     * A match of draughts (ADR 034) chosen in `$room` with `$white` and
     * `$black` as its seats through `ChooseRoomGame` and started through
     * `StartRoomGame`; its clock starts after the countdown
     * (`$game->started_ms`).
     */
    public static function match(Room $room, RoomPlayer $white, RoomPlayer $black, int $difficulty = 2): RoomGame
    {
        app(ChooseRoomGame::class)->handle($room, new RoomGameSetupData(RoomMechanic::Draughts, $difficulty, null, $white->id, $black->id));

        return app(StartRoomGame::class)->handle($room);
    }

    /**
     * Moves stored as `RecordRoomAct` stores them, for a long game a test
     * does not post move by move: the lines from ply `$from` on, each by the
     * seat of the side to move, the first at `$atMs` and one every
     * `$everyMs` after it. Gives the moment of the last.
     *
     * @param  list<string>  $lines
     */
    public static function moves(RoomGame $game, array $lines, int $atMs, int $everyMs = 1000, int $from = 0): int
    {
        foreach ($lines as $offset => $line) {
            $ply = $from + $offset;
            $act = new RoomAct;
            $act->event_id = strtolower((string) Str::ulid());
            $act->room_game_id = $game->id;
            $act->room_player_id = (string) (RoomMatchColor::toMove($ply) === RoomMatchColor::White ? $game->white_player_id : $game->black_player_id);
            $act->kind = RoomActKind::Move;
            $act->ply = $ply;
            $act->path = Draughts::path($line);
            $act->acted_ms = $atMs + $offset * $everyMs;
            $act->save();
        }

        $game->unsetRelation('acts');

        return $atMs + (count($lines) - 1) * $everyMs;
    }

    /**
     * The body a seat's device posts for an act, with a new event id unless
     * one is given: a move with its line's squares, or another kind, in the
     * game `$gameId` — left out here when null, for the caller to name.
     *
     * @return array<string, mixed>
     */
    public static function act(string $kind, ?int $ply = null, ?string $line = null, ?string $eventId = null, ?string $gameId = null): array
    {
        return array_filter([
            'event_id' => $eventId ?? strtolower((string) Str::ulid()),
            'game_id' => $gameId,
            'kind' => $kind,
            'ply' => $ply,
            'path' => $line === null ? null : Draughts::path($line),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The moment a phase of round `$index` begins by the game's timetable
     * as it is now (an early close moves it): the countdown's is a moment
     * before round 0 opens.
     */
    public static function at(RoomGame $game, RoomPhase $phase, int $index = 0): CarbonImmutable
    {
        $timetable = $game->refresh()->load('rounds')->timetable();

        return RoomClock::at(match ($phase) {
            RoomPhase::Countdown => $timetable->opens(0) - 1,
            RoomPhase::Study => $timetable->opens($index),
            RoomPhase::Answer => $timetable->answerFrom($index),
            RoomPhase::Reveal => $timetable->closes($index),
            RoomPhase::Over => $timetable->overMs(),
        });
    }

    /** Round `$index` of the game. */
    public static function round(RoomGame $game, int $index): RoomRound
    {
        return RoomRound::query()->where('room_game_id', $game->id)->where('position', $index)->firstOrFail();
    }

    /**
     * What a right answer picks: the round's solution, which only a test
     * reads.
     *
     * @return list<string>
     */
    public static function right(RoomRound $round): array
    {
        return $round->solutionData()->ids;
    }

    /**
     * A wrong answer of the right shape: for a sequence, the solution turned
     * by one place — or by more, until it reads like none of the accepted
     * readings, since an anagram's letters may spell two words (`QAYA`
     * turned is `AYAQ`); for a set, one of its items swapped for an item
     * of the question that is not in it; for a choice, another option.
     *
     * @return list<string>
     */
    public static function wrong(RoomRound $round): array
    {
        $solution = $round->solutionData();
        $ids = $solution->ids;
        $others = array_values(array_diff($round->askData()->ids(), $ids));

        if ($solution->shape === RoomAnswerShape::Choice) {
            return [$others[0]];
        }

        if ($solution->shape === RoomAnswerShape::Set) {
            return [...array_slice($ids, 1), $others[0]];
        }

        $says = [];

        foreach ($round->askData()->items as $item) {
            $says[$item->id] = $item->says();
        }

        for ($turn = 1; $turn < count($ids); $turn++) {
            $turned = [...array_slice($ids, $turn), ...array_slice($ids, 0, $turn)];

            if (! in_array(array_map(static fn (string $id): string => $says[$id], $turned), $solution->accept, true)) {
                return $turned;
            }
        }

        throw new RuntimeException('Every turn of the solution reads like an accepted reading.');
    }

    /**
     * The body a device posts for an answer, with a new event id unless
     * one is given.
     *
     * @param  list<string>  $picked
     * @return array{event_id: string, round_id: string, picked: list<string>}
     */
    public static function answer(RoomRound $round, array $picked, ?string $eventId = null): array
    {
        return ['event_id' => $eventId ?? Str::lower((string) Str::ulid()), 'round_id' => $round->id, 'picked' => $picked];
    }

    /**
     * Every route the switch closes (`rooms.enabled`), by name.
     *
     * @return array<string, RoutingRoute>
     */
    public static function routes(): array
    {
        $routes = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (in_array('rooms.enabled', $route->gatherMiddleware(), true)) {
                $routes[$route->getName() ?? $route->methods()[0].' '.$route->uri()] = $route;
            }
        }

        return $routes;
    }

    /**
     * The URI of a room route with the given values for its parameters; a
     * route that takes one the test did not give fails loudly.
     *
     * @param  array<string, int|string>  $values
     */
    public static function uri(RoutingRoute $route, array $values = []): string
    {
        return '/'.ltrim((string) preg_replace_callback(
            '/\{(\w+)\??\}/',
            static fn (array $match): string => (string) ($values[$match[1]] ?? throw new RuntimeException("No value for {{$match[1]}} in {$route->uri()}.")),
            $route->uri(),
        ), '/');
    }
}
