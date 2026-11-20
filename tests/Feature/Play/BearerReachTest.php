<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Family\Models\Child;
use App\Domain\Play\Actions\TransitionGameSession;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Models\GuestBoard;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Games;
use Tests\Support\GuestBoards;
use Tests\Support\NameClips;
use Tests\Support\Rooms;

/*
 * A guest board's bearer reaches only the game its board shows
 * (docs/architecture.md §4): the board routes (claim, state, sync, status,
 * a player's voice, ADR 016) of that one game, its own board (poll, close) and that game's channel.
 * Every other route of the app answers the bearer exactly as it answers a
 * visitor with no credentials — or 404 where a board route does not find
 * the game. A new route is covered the day it is added. With the rooms area
 * on, the account also hosts an open room with a player in it: every room
 * route answers the bearer as it answers a visitor (§12, ADR 020).
 */

/**
 * The routes a bearer may use, for the game its board shows or the board
 * itself.
 */
const BEARER_ROUTES = [
    'games.board.claim',
    'games.state',
    'games.sync',
    'games.status.update',
    'games.rounds.update',
    'games.players.voice',
    'guest-boards.show',
    'guest-boards.destroy',
];

/**
 * Account A (a teacher and a parent) with a class, a student, a game on a
 * guest board, a second live game, a child and the child's home game;
 * teacher B with a game of their own.
 *
 * @return array{teacher: User, class: SchoolClass, student: Student, child: Child, shown: GameSession, other: GameSession, home: GameSession, foreign: GameSession, board: GuestBoard, token: string, room: Room, member: RoomPlayer}
 */
function bearerWorld(): array
{
    // The student's and the child's names have clips (ADR 016).
    NameClips::fake(['Aysel', 'Firuzə']);
    Rooms::on();
    $teacher = User::factory()->teacherAndParent()->create(['player_agreed_at' => now()]);
    $room = Rooms::open($teacher);
    $class = SchoolClass::factory()->for($teacher, 'teacher')->create();
    $child = Child::factory()->for($teacher, 'parent')->create(['display_name' => 'Firuzə']);
    $student = Student::factory()->for($class)->create(['display_name' => 'Aysel']);
    $shown = Games::start($class, seed: 1);
    $board = GuestBoards::showing($shown);

    return [
        'teacher' => $teacher,
        'class' => $class,
        'student' => $student,
        'child' => $child,
        'shown' => $shown,
        'other' => Games::start($class, seed: 2),
        'home' => Games::start($child, seed: 4),
        'foreign' => Games::start(SchoolClass::factory()->create(), seed: 3),
        'board' => $board['board'],
        'token' => $board['token'],
        'room' => $room,
        'member' => Rooms::join($room),
    ];
}

/**
 * The route's URI with A's rows (the game: A's other game, not the shown one).
 *
 * @param  array<string, mixed>  $world
 */
function bearerUri(RoutingRoute $route, array $world, GameSession $game): string
{
    $values = [
        'class' => $world['class']->id,
        'student' => $world['student']->id,
        'child' => $world['child']->id,
        'game' => $game->id,
        'board' => $world['board']->id,
        'player' => $world['student']->id,
        'code' => (string) $world['room']->code,
        'room' => $world['room']->id,
        'member' => $world['member']->id,
    ];

    return '/'.ltrim((string) preg_replace_callback(
        '/\{(\w+)\??\}/',
        static fn (array $match): string => (string) ($values[$match[1]] ?? 'x'),
        $route->uri(),
    ), '/');
}

/**
 * Everything a request could change.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function bearerSnapshot(): array
{
    $rows = static fn (string $table): array => DB::table($table)->orderBy('id')->get()
        ->map(static fn (object $row): array => (array) $row)->all();

    return [
        'game_sessions' => $rows('game_sessions'),
        'game_rounds' => $rows('game_rounds'),
        'game_answers' => $rows('game_answers'),
        'guest_boards' => $rows('guest_boards'),
        'school_classes' => $rows('school_classes'),
        'students' => $rows('students'),
        'children' => $rows('children'),
        'rooms' => $rows('rooms'),
        'room_players' => $rows('room_players'),
        'room_games' => $rows('room_games'),
        'room_rounds' => $rows('room_rounds'),
        'room_answers' => $rows('room_answers'),
        'room_taunts' => $rows('room_taunts'),
        'room_acts' => $rows('room_acts'),
    ];
}

it('answers a bearer like a visitor on every route but its own board routes', function (): void {
    $world = bearerWorld();
    $mismatches = [];
    $checked = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (in_array($route->getName(), ['guest-boards.show', 'guest-boards.destroy'], true)) {
            continue; // its own board: the tests below
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $uri = bearerUri($route, $world, $world['other']);
            $body = str_starts_with($route->uri(), 'broadcasting/')
                ? ['socket_id' => '1234.5678', 'channel_name' => 'private-game.'.$world['other']->id]
                : [];

            $visitor = $this->json($method, $uri, $body)->status();
            $bearer = $this->json($method, $uri, $body, GuestBoards::bearer($world['token']))->status();
            $checked++;

            // The board routes of another game: not found, like any id it cannot see.
            $expected = in_array($route->getName(), BEARER_ROUTES, true) ? [404] : [$visitor];

            if (! in_array($bearer, $expected, true)) {
                $mismatches["{$method} {$route->uri()}"] = ['visitor' => $visitor, 'bearer' => $bearer];
            }
        }
    }

    expect($mismatches)->toBe([])
        ->and($checked)->toBeGreaterThan(50);
});

it('answers a bearer like a visitor on every room route and changes nothing', function (): void {
    $world = bearerWorld();
    // The live voice on too (C3): its three routes answer as every room route.
    Rooms::voiceOn();
    $before = bearerSnapshot();
    $checked = 0;
    $doors = 0;

    foreach (Rooms::routes() as $key => $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $uri = bearerUri($route, $world, $world['other']);
            $visitor = $this->json($method, $uri, ['locked' => true]);
            $bearer = $this->json($method, $uri, ['locked' => true], GuestBoards::bearer($world['token']));

            expect($bearer->status())->toBe($visitor->status(), "{$method} {$key}");

            // The invitation's door is a guest route (I2, ADR 033): it answers the bearer as a visitor,
            // and without an invitation under way that is the login, or a refused form.
            if (str_starts_with($key, 'rooms.invited')) {
                expect($bearer->status())->toBe($method === 'GET' ? 302 : 422, "{$method} {$key}");
                $doors++;
            } else {
                expect($bearer->status())->toBe(401, "{$method} {$key}");
            }

            $checked++;
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(16)
        ->and($doors)->toBe(2)
        ->and(bearerSnapshot())->toBe($before);
});

it('lets the bearer drive the game its board shows, and nothing writes elsewhere', function (): void {
    $world = bearerWorld();
    $round = Games::round($world['shown']);
    $bearer = GuestBoards::bearer($world['token']);

    $this->postJson(route('games.board.claim', $world['shown']), [], $bearer)->assertOk();
    $this->getJson(route('games.state', $world['shown']), $bearer)->assertOk()->assertDontSee('Aysel');
    $this->postJson(route('games.sync', $world['shown']), ['board_epoch' => 1, 'events' => [
        Games::hit($round, Games::target($round), $world['student']->id),
    ]], $bearer)->assertOk()->assertJsonPath('results.0.status', 'correct');
    $this->patchJson(route('games.status.update', $world['shown']), ['status' => 'paused'], $bearer)->assertOk();
    $this->getJson(route('guest-boards.show', $world['board']), $bearer)->assertOk();

    expect($world['other']->refresh()->board_epoch)->toBe(0)
        ->and($world['other']->status)->toBe(GameSessionStatus::Live)
        ->and($world['home']->refresh()->board_epoch)->toBe(0)
        ->and($world['foreign']->refresh()->board_epoch)->toBe(0);
});

it('finds no other game for the bearer: claim, state, sync and status answer 404 and change nothing', function (string $which, string $route, string $method): void {
    $world = bearerWorld();
    $game = $world[$which];
    $round = Games::round($game);
    $body = match ($route) {
        'games.sync' => ['board_epoch' => 0, 'events' => [Games::hit($round, Games::target($round))]],
        'games.status.update' => ['status' => 'finished'],
        default => [],
    };
    $before = bearerSnapshot();

    $this->json($method, route($route, $game), $body, GuestBoards::bearer($world['token']))->assertNotFound();

    expect(bearerSnapshot())->toBe($before);
})->with([
    'the teacher\'s other game' => 'other',
    'the account\'s home game' => 'home',
    'another teacher\'s game' => 'foreign',
])->with([
    'claim' => ['games.board.claim', 'POST'],
    'state' => ['games.state', 'GET'],
    'sync' => ['games.sync', 'POST'],
    'status' => ['games.status.update', 'PATCH'],
]);

it('reaches only the name clips of the players of the game its board shows', function (): void {
    $world = bearerWorld();
    $bearer = GuestBoards::bearer($world['token']);
    $before = bearerSnapshot();
    $voice = fn (string $game, string $player) => $this->get(route('games.players.voice', ['game' => $world[$game]->id, 'player' => $player]), $bearer);

    $voice('shown', $world['student']->id)->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
    // The account's child plays no class game; the child's home game and the
    // teacher's other games are not this board's.
    $voice('shown', $world['child']->id)->assertNotFound();
    $voice('home', $world['child']->id)->assertNotFound();
    $voice('other', $world['student']->id)->assertNotFound();
    $voice('foreign', $world['student']->id)->assertNotFound();

    expect(bearerSnapshot())->toBe($before);
});

it('loses the game when the phone sends another one to the board', function (): void {
    $world = bearerWorld();
    $bearer = GuestBoards::bearer($world['token']);

    $this->actingAs($world['teacher'])->put(route('guest-boards.game.update', $world['board']), ['game' => $world['other']->id])
        ->assertRedirect(route('games.remote', $world['other']));
    $this->app['auth']->guard('web')->forgetUser();

    $this->getJson(route('games.state', $world['shown']), $bearer)->assertNotFound();
    $this->getJson(route('games.state', $world['other']), $bearer)->assertOk();
});

it('keeps a finished game readable for the board that shows it, but not writable', function (): void {
    $world = bearerWorld();
    $bearer = GuestBoards::bearer($world['token']);
    app(TransitionGameSession::class)->handle($world['shown'], GameSessionStatus::Finished);

    $this->postJson(route('games.board.claim', $world['shown']), [], $bearer)
        ->assertOk()
        ->assertExactJson(['game' => ['status' => 'finished', 'board_epoch' => 0]]);
    $this->getJson(route('games.state', $world['shown']), $bearer)->assertOk();
    $this->patchJson(route('games.status.update', $world['shown']), ['status' => 'live'], $bearer)
        ->assertConflict()
        ->assertJsonPath('error', 'invalid_transition');
});

it('cannot open the teacher\'s pages, even with a game id it may play', function (string $name): void {
    $world = bearerWorld();

    $this->getJson(route($name, $world['shown']), GuestBoards::bearer($world['token']))->assertUnauthorized();
    $this->get(route($name, $world['shown']), ['Authorization' => 'Bearer '.$world['token']])->assertRedirect(route('login'));
})->with([
    'the teacher board page' => 'games.board',
    'the remote' => 'games.remote',
]);
