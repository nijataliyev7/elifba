<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Models\CustomWord;
use App\Domain\Content\Support\Avatars;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Family\Models\Child;
use App\Domain\Play\Models\GameSession;
use App\Domain\Rooms\Actions\CloseRoom;
use App\Domain\Rooms\Actions\RemoveRoomPlayer;
use App\Domain\Rooms\Actions\SetRoomVoice;
use App\Domain\Rooms\Data\RoomVoiceSwitchData;
use App\Domain\Rooms\Enums\RoomBoardGame;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomMatch;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\Checkers;
use App\Models\User;
use App\Support\RoomInvitation;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\Games;
use Tests\Support\NameClips;
use Tests\Support\Rooms;

/*
 * Isolation (docs/architecture.md §7, §12 "Rooms and joining"). Every route
 * with a `{class}`, `{student}`, `{game}`, `{word}`, `{child}`, `{room}` or
 * `{member}` parameter must have a case below; the first test fails when
 * one is missing, so a new route cannot skip this file.
 *
 * Each case is a request account A may make on A's own data. The tests make
 * it as account B and as accounts with other roles or none (404 or 403,
 * nothing changes), as a guest (to the login page, nothing changes) and as
 * A (it works — so the case really reaches the route, and B's refusal is
 * the isolation, not a wrong URL). A and B are teachers and parents at once
 * (ADR 010), so only ownership tells them apart.
 */

const ISOLATED_PARAMETERS = ['class', 'student', 'game', 'word', 'child', 'room', 'member'];

/**
 * Account A owns 1A (two active students, an archived one and a live
 * game), 1B (one student), an archived 2A, a word of their own and two
 * children of their own — Aysel, who shares a name with the student and is
 * still another record (ADR 011), and Tural — with a home game; account B
 * owns their own 1A with one student and a game, a word, and a child with a
 * home game. Both play too, with the rooms area on: A hosts an open room
 * where A and Aysel Quliyeva play, and a closed one with both; B has a room
 * of their own. A also hosts a room with a game running in it, made by the
 * factories (`OpenRoom` gives a host one open room), since starting a game
 * needs a room without one and ending it a room with one.
 *
 * @return array{a: User, b: User, classA: SchoolClass, otherClassA: SchoolClass, archivedClassA: SchoolClass, studentA: Student, secondStudentA: Student, archivedStudentA: Student, otherStudentA: Student, gameA: GameSession, classB: SchoolClass, studentB: Student, gameB: GameSession, wordA: CustomWord, wordB: CustomWord, childA: Child, secondChildA: Child, homeGameA: GameSession, childB: Child, homeGameB: GameSession, roomA: Room, memberA: RoomPlayer, closedRoomA: Room, roomB: Room, memberB: RoomPlayer, playingRoomA: Room, roomGameA: RoomGame, boardRoomA: Room, boardWhiteA: RoomPlayer, boardBlackA: RoomPlayer, matchRoomA: Room}
 */
function isolationWorld(): array
{
    // A's student Aysel and child Aysel have a name the board says (ADR 016).
    NameClips::fake(['Aysel']);
    // The area and its live voice (C3): the voice routes are isolated as every room route.
    Rooms::voiceOn();
    $a = User::factory()->teacherAndParent()->create(['player_agreed_at' => now()]);
    $b = User::factory()->teacherAndParent()->create(['player_agreed_at' => now()]);
    // A player's name is shown in the room, like a child's on the board.
    $guest = User::factory()->player()->create(['name' => 'Aysel Quliyeva']);
    $closedRoomA = Rooms::open($a);
    Rooms::join($closedRoomA, $guest);
    app(CloseRoom::class)->handle($closedRoomA);
    $roomA = Rooms::open($a);
    app(SetRoomVoice::class)->handle($roomA, new RoomVoiceSwitchData(true));
    $roomA->refresh();
    $roomB = Rooms::open($b);
    $playingRoomA = Room::factory()->for($a, 'host')->create();
    $roomGameA = RoomGame::factory()->for($playingRoomA)->withRounds(5)->create();
    // Board games (ADR 034): a checkers room waiting for its match, and one with a match running.
    $boardRoomA = Room::factory()->for($a, 'host')->create(['board' => RoomBoardGame::Checkers]);
    $boardWhiteA = Rooms::join($boardRoomA);
    $boardBlackA = Rooms::join($boardRoomA);
    $matchRoomA = Room::factory()->for($a, 'host')->create(['board' => RoomBoardGame::Checkers]);
    $match = new RoomMatch;
    $match->room_id = $matchRoomA->id;
    $match->position = 0;
    $match->board_game = RoomBoardGame::Checkers;
    $match->white_player_id = Rooms::join($matchRoomA)->id;
    $match->black_player_id = Rooms::join($matchRoomA)->id;
    $match->board = Checkers::initial();
    $match->turn = 'w';
    $match->started_ms = now()->getTimestampMs();
    $match->turn_since_ms = $match->started_ms;
    $match->white_left_ms = 300_000;
    $match->black_left_ms = 300_000;
    $match->save();
    $childA = Child::factory()->for($a, 'parent')->create(['display_name' => 'Aysel']);
    $childB = Child::factory()->for($b, 'parent')->create(['display_name' => 'Nigar']);

    $classA = SchoolClass::factory()->for($a, 'teacher')->create(['name' => '1A']);
    $otherClassA = SchoolClass::factory()->for($a, 'teacher')->create(['name' => '1B']);
    $archivedClassA = SchoolClass::factory()->for($a, 'teacher')->archived()->create(['name' => '2A']);
    $classB = SchoolClass::factory()->for($b, 'teacher')->create(['name' => '1A']);
    $studentA = Student::factory()->for($classA)->create(['display_name' => 'Aysel']);

    return [
        'a' => $a,
        'b' => $b,
        'classA' => $classA,
        'otherClassA' => $otherClassA,
        'archivedClassA' => $archivedClassA,
        'studentA' => $studentA,
        'secondStudentA' => Student::factory()->for($classA)->create(['display_name' => 'Əli']),
        'archivedStudentA' => Student::factory()->for($classA)->archived()->create(['display_name' => 'Leyla']),
        'otherStudentA' => Student::factory()->for($otherClassA)->create(['display_name' => 'Vüsal']),
        'gameA' => Games::start($classA, seed: 1),
        'classB' => $classB,
        'studentB' => Student::factory()->for($classB)->create(['display_name' => 'Nigar']),
        'gameB' => Games::start($classB, seed: 2),
        // A teacher word may be a child's name, like a display name.
        'wordA' => CustomWord::factory()->for($a, 'teacher')->create(['text' => 'Aysel']),
        'wordB' => CustomWord::factory()->for($b, 'teacher')->create(['text' => 'pomidor']),
        'childA' => $childA,
        'secondChildA' => Child::factory()->for($a, 'parent')->create(['display_name' => 'Tural']),
        'homeGameA' => Games::start($childA, seed: 3),
        'childB' => $childB,
        'homeGameB' => Games::start($childB, seed: 4),
        'roomA' => $roomA,
        'memberA' => Rooms::join($roomA, $guest),
        'closedRoomA' => $closedRoomA->refresh(),
        'roomB' => $roomB,
        'memberB' => Rooms::join($roomB),
        'playingRoomA' => $playingRoomA,
        'roomGameA' => $roomGameA->load('rounds'),
        'boardRoomA' => $boardRoomA->refresh(),
        'boardWhiteA' => $boardWhiteA,
        'boardBlackA' => $boardBlackA,
        'matchRoomA' => $matchRoomA->refresh(),
    ];
}

/**
 * "METHOD uri" => [route name, the route parameters (A's rows), a valid body].
 *
 * @return array<string, array{0: string, 1: Closure(array<string, mixed>): array<string, string>, 2: Closure(array<string, mixed>): array<string, mixed>}>
 */
function isolationCases(): array
{
    $none = static fn (array $w): array => [];
    $classA = static fn (array $w): array => ['class' => $w['classA']->id];
    $studentA = static fn (array $w): array => ['class' => $w['classA']->id, 'student' => $w['studentA']->id];
    $gameA = static fn (array $w): array => ['game' => $w['gameA']->id];
    $wordA = static fn (array $w): array => ['word' => $w['wordA']->id];
    $childA = static fn (array $w): array => ['child' => $w['childA']->id];
    $roomA = static fn (array $w): array => ['room' => $w['roomA']->id];
    $closedRoomA = static fn (array $w): array => ['room' => $w['closedRoomA']->id];

    return [
        'GET classes/{class}' => ['classes.show', $classA, $none],
        'PATCH classes/{class}' => ['classes.update', $classA, static fn (array $w): array => ['name' => '1C', 'grade' => 2]],
        'DELETE classes/{class}' => ['classes.destroy', $classA, $none],
        'POST classes/{class}/archive' => ['classes.archive', $classA, $none],
        'DELETE classes/{class}/archive' => ['classes.restore', static fn (array $w): array => ['class' => $w['archivedClassA']->id], $none],
        'PUT classes/{class}/learned-letters' => ['classes.learned-letters.update', $classA, static fn (array $w): array => ['letters' => ['a', 'b', 'ə']]],
        'POST classes/{class}/students/preview' => ['classes.students.preview', $classA, static fn (array $w): array => ['text' => "Məmmədov Əli Rəşid oğlu\nNərgiz Həsənova"]],
        'POST classes/{class}/students' => ['classes.students.store', $classA, static fn (array $w): array => ['names' => ['Nərgiz', 'Tural']]],
        'PUT classes/{class}/students/order' => ['classes.students.order', $classA, static fn (array $w): array => ['student_ids' => [$w['secondStudentA']->id, $w['studentA']->id]]],
        'PATCH classes/{class}/students/{student}' => ['classes.students.update', $studentA, static fn (array $w): array => ['display_name' => 'Aysel M.', 'avatar' => Avatars::keys()[0]]],
        'DELETE classes/{class}/students/{student}' => ['classes.students.destroy', $studentA, $none],
        'POST classes/{class}/students/{student}/archive' => ['classes.students.archive', $studentA, $none],
        'DELETE classes/{class}/students/{student}/archive' => ['classes.students.restore', static fn (array $w): array => ['class' => $w['classA']->id, 'student' => $w['archivedStudentA']->id], $none],
        'GET classes/{class}/games/create' => ['classes.games.create', $classA, $none],
        'POST classes/{class}/games' => ['classes.games.store', $classA, static fn (array $w): array => ['mechanic' => 'find', 'pack' => 'balloons', 'level' => 'letters', 'rounds' => 5]],
        'PUT classes/{class}/games/mode' => ['classes.games.mode', $classA, static fn (array $w): array => ['mode' => 'easy']],
        'GET games/{game}/board' => ['games.board', $gameA, $none],
        'POST games/{game}/board/claim' => ['games.board.claim', $gameA, $none],
        'GET games/{game}/state' => ['games.state', $gameA, $none],
        'POST games/{game}/sync' => ['games.sync', $gameA, static fn (array $w): array => ['board_epoch' => 0, 'events' => [
            Games::hit(Games::round($w['gameA']), Games::target(Games::round($w['gameA'])), $w['studentA']->id),
        ]]],
        'PATCH games/{game}/status' => ['games.status.update', $gameA, static fn (array $w): array => ['status' => 'paused']],
        'PATCH games/{game}/rounds' => ['games.rounds.update', $gameA, static fn (array $w): array => ['board_epoch' => 0, 'rounds' => 10]],
        // A live game goes back to its board; a finished one starts again.
        'POST games/{game}/replay' => ['games.replay', $gameA, $none],
        // A player of the game: the student of a class game, the child of a home game.
        'GET games/{game}/players/{player}/voice' => ['games.players.voice', static fn (array $w): array => ['game' => $w['gameA']->id, 'player' => $w['gameA']->child_id === null ? $w['studentA']->id : $w['childA']->id], $none],
        'POST games/{game}/join-code' => ['games.join-code', $gameA, $none],
        'GET games/{game}/remote' => ['games.remote', $gameA, $none],
        'GET classes/{class}/rankings' => ['classes.rankings', $classA, $none],
        'GET classes/{class}/podium' => ['classes.podium', $classA, $none],
        'PATCH words/{word}' => ['words.update', $wordA, static fn (array $w): array => ['text' => 'kələm', 'syllables' => 'kə-ləm']],
        'DELETE words/{word}' => ['words.destroy', $wordA, $none],
        'GET children/{child}' => ['children.show', $childA, $none],
        'PATCH children/{child}' => ['children.update', $childA, static fn (array $w): array => ['display_name' => 'Ayla', 'avatar' => Avatars::keys()[0]]],
        'DELETE children/{child}' => ['children.destroy', $childA, $none],
        'PUT children/{child}/learned-letters' => ['children.learned-letters.update', $childA, static fn (array $w): array => ['letters' => ['a', 'b', 'ə']]],
        'GET children/{child}/games/create' => ['children.games.create', $childA, $none],
        'POST children/{child}/games' => ['children.games.store', $childA, static fn (array $w): array => ['mechanic' => 'find', 'pack' => 'balloons', 'level' => 'letters', 'rounds' => 5]],
        'PUT children/{child}/games/mode' => ['children.games.mode', $childA, static fn (array $w): array => ['mode' => 'normal']],
        'GET rooms/{room}' => ['rooms.show', $roomA, $none],
        'GET rooms/{room}/state' => ['rooms.state', $roomA, $none],
        'GET rooms/{room}/screen' => ['rooms.screen', $roomA, $none],
        // The winner's name for the screen (ADR 030): a member of the room, by ids.
        'GET rooms/{room}/members/{member}/voice' => ['rooms.members.voice', static fn (array $w): array => ['room' => $w['roomA']->id, 'member' => $w['memberA']->id], $none],
        'POST rooms/{room}/leave' => ['rooms.leave', $roomA, $none],
        'PATCH rooms/{room}' => ['rooms.update', $roomA, static fn (array $w): array => ['locked' => true]],
        'DELETE rooms/{room}/members/{member}' => ['rooms.members.destroy', static fn (array $w): array => ['room' => $w['roomA']->id, 'member' => $w['memberA']->id], $none],
        'POST rooms/{room}/close' => ['rooms.close', $roomA, $none],
        // The host's choice of the next game and «Mən də oynayıram» (G2); the start takes no body.
        'PUT rooms/{room}/setup' => ['rooms.setup.update', $roomA, static fn (array $w): array => ['mechanic' => 'anagram', 'difficulty' => 1, 'rounds' => 5]],
        'PUT rooms/{room}/plays' => ['rooms.plays.update', $roomA, static fn (array $w): array => ['plays' => false]],
        'POST rooms/{room}/games' => ['rooms.games.store', $roomA, $none],
        'DELETE rooms/{room}/game' => ['rooms.game.destroy', static fn (array $w): array => ['room' => $w['playingRoomA']->id], $none],
        // A present player's answer: a round of another room is only late for A.
        'POST rooms/{room}/answers' => ['rooms.answers.store', $roomA, static fn (array $w): array => [
            'event_id' => '01kaqs1x2y3z4a5b6c7d8e9f0g',
            'round_id' => $w['roomGameA']->rounds[0]->id,
            'picked' => $w['roomGameA']->rounds[0]->askData()->ids(),
        ]],
        // A taunt at a player of A's room, and the host's switch for them (T2).
        'POST rooms/{room}/taunts' => ['rooms.taunts.store', $roomA, static fn (array $w): array => [
            'event_id' => '01kaqs2s1r0q9p8n7m6k5j4h3g',
            'target_id' => $w['memberA']->id,
            'taunt' => 'turtle',
        ]],
        'PUT rooms/{room}/taunts' => ['rooms.taunts.update', $roomA, static fn (array $w): array => ['on' => false]],
        // Live voice (C3): the host's switch, the host's word on a player, a ticket (A's room has its voice on).
        'PUT rooms/{room}/voice' => ['rooms.voice.update', $roomA, static fn (array $w): array => ['on' => false]],
        'PUT rooms/{room}/members/{member}/voice' => ['rooms.members.voice.update', static fn (array $w): array => ['room' => $w['roomA']->id, 'member' => $w['memberA']->id], static fn (array $w): array => ['standing' => 'muted']],
        'POST rooms/{room}/voice/tickets' => ['rooms.voice.tickets.store', $roomA, static fn (array $w): array => ['agree' => true]],
        // Board games (ADR 034): the match of a room, whichever game it was opened for.
        'GET rooms/{room}/match' => ['rooms.match.show', $roomA, $none],
        'POST rooms/{room}/match' => ['rooms.match.store', static fn (array $w): array => ['room' => $w['boardRoomA']->id], static fn (array $w): array => ['white' => $w['boardWhiteA']->id, 'black' => $w['boardBlackA']->id]],
        'DELETE rooms/{room}/match' => ['rooms.match.destroy', static fn (array $w): array => ['room' => $w['matchRoomA']->id], $none],
        'POST rooms/{room}/match/moves' => ['rooms.match.moves.store', $roomA, static fn (array $w): array => ['event_id' => '01jcheckers000000000000000', 'ply' => 0, 'path' => [40, 33]]],
        'POST rooms/{room}/match/resign' => ['rooms.match.resign', $roomA, $none],
        'POST rooms/{room}/match/taunts' => ['rooms.match.taunts.store', $roomA, static fn (array $w): array => ['event_id' => '01jcheckers000000000000001', 'taunt' => array_key_first(app(ContentRepository::class)->roomTaunts())]],
        // A closed room: the host deletes it, a player takes their name out.
        'DELETE rooms/{room}' => ['rooms.destroy', $closedRoomA, $none],
        'DELETE rooms/{room}/name' => ['rooms.name.destroy', $closedRoomA, $none],
    ];
}

/**
 * Every route with an isolated parameter, as "METHOD uri" => route.
 *
 * @return array<string, RoutingRoute>
 */
function isolatedRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (array_intersect($route->parameterNames(), ISOLATED_PARAMETERS) === []) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes["{$method} {$route->uri()}"] = $route;
        }
    }

    return $routes;
}

/**
 * Everything the cases could change.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function isolationSnapshot(): array
{
    $rows = static fn (string $table): array => DB::table($table)->orderBy('id')->get()
        ->map(static fn (object $row): array => (array) $row)->all();

    return [
        'users' => $rows('users'),
        'school_classes' => $rows('school_classes'),
        'students' => $rows('students'),
        'game_sessions' => $rows('game_sessions'),
        'game_rounds' => $rows('game_rounds'),
        'game_answers' => $rows('game_answers'),
        'guest_boards' => $rows('guest_boards'),
        'custom_words' => $rows('custom_words'),
        'hidden_words' => $rows('hidden_words'),
        'children' => $rows('children'),
        'setup_choices' => $rows('setup_choices'),
        'rooms' => $rows('rooms'),
        'room_players' => $rows('room_players'),
        'room_games' => $rows('room_games'),
        'room_rounds' => $rows('room_rounds'),
        'room_answers' => $rows('room_answers'),
        'room_taunts' => $rows('room_taunts'),
        'room_matches' => $rows('room_matches'),
        'room_moves' => $rows('room_moves'),
        'room_match_taunts' => $rows('room_match_taunts'),
    ];
}

/**
 * Makes one case's request with the given rows.
 *
 * @param  array<string, mixed>  $world
 */
function isolationRequest(string $case, array $world): TestResponse
{
    [$name, $parameters, $body] = isolationCases()[$case];
    [$method] = explode(' ', $case);

    return test()->call($method, route($name, $parameters($world)), $body($world));
}

$caseNames = static fn (): array => array_combine(array_keys(isolationCases()), array_keys(isolationCases()));

it('has an isolation case for every route with {class}, {student}, {game}, {word}, {child}, {room} or {member}', function (): void {
    $routes = array_keys(isolatedRoutes());
    $cases = array_keys(isolationCases());

    expect(array_values(array_diff($routes, $cases)))->toBe([], 'Add these routes to isolationCases().')
        ->and(array_values(array_diff($cases, $routes)))->toBe([], 'These cases name routes that no longer exist.');

    foreach ($cases as $case) {
        [$name] = isolationCases()[$case];
        expect(Route::getRoutes()->getByName($name)?->uri())->toBe(explode(' ', $case, 2)[1], "{$case} names the wrong route.");
    }
});

it('binds {student} only inside its {class}, {member} only inside its {room}, and checks a policy on every isolated route', function (): void {
    foreach (isolatedRoutes() as $key => $route) {
        $parameters = $route->parameterNames();
        $policies = array_filter($route->gatherMiddleware(), static fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'can:'));
        // `auth` (the web session) or, on the board routes, `auth:board,web` (§4).
        $auth = array_filter($route->gatherMiddleware(), static fn (mixed $middleware): bool => $middleware === 'auth' || $middleware === 'auth:board,web');

        expect($policies)->not->toBeEmpty("{$key} has no ->can() policy.")
            ->and($auth)->not->toBeEmpty("{$key} is not behind auth.");

        if (in_array('student', $parameters, true)) {
            expect($parameters)->toContain('class')
                ->and($route->enforcesScopedBindings())->toBeTrue("{$key} must use scopeBindings().");
        }

        if (in_array('member', $parameters, true)) {
            expect($parameters)->toContain('room')
                ->and($route->enforcesScopedBindings())->toBeTrue("{$key} must use scopeBindings().");
        }
    }
});

it('refuses another teacher and changes nothing', function (string $case): void {
    $world = isolationWorld();
    $before = isolationSnapshot();

    $this->actingAs($world['b'])->from(route('classes.index'));
    $response = isolationRequest($case, $world);

    expect($response->status())->toBeIn([403, 404])
        ->and($response->getContent())->not->toContain('Aysel')
        ->and(isolationSnapshot())->toBe($before);
})->with($caseNames);

// Ownership binds before the role is checked (ADR 010): another account's
// row is not found whatever roles the account holds, or none.
it('refuses any other account, whatever its roles, and changes nothing', function (string $case, string $state): void {
    $world = isolationWorld();
    $other = User::factory()->{$state}()->create();
    $before = isolationSnapshot();

    $this->actingAs($other)->from(route('profile.edit'));
    $response = isolationRequest($case, $world);

    expect($response->status())->toBeIn([403, 404])
        ->and($response->getContent())->not->toContain('Aysel')
        ->and(isolationSnapshot())->toBe($before);
})->with($caseNames)->with([
    'a parent' => 'parent',
    'a teacher and parent' => 'teacherAndParent',
    'no role yet' => 'withoutRole',
]);

it('sends a guest to the login page and changes nothing', function (string $case): void {
    $world = isolationWorld();
    $before = isolationSnapshot();

    isolationRequest($case, $world)->assertRedirect(route('login'));

    expect(isolationSnapshot())->toBe($before);
})->with($caseNames);

it('lets the owner through, so each case reaches its route', function (string $case): void {
    $world = isolationWorld();

    $this->actingAs($world['a'])->from(route('classes.show', $world['classA']));
    $response = isolationRequest($case, $world);

    expect($response->status())->toBeLessThan(400);
    $response->assertSessionHasNoErrors();
})->with($caseNames);

it('refuses a student of another class, even of the same teacher', function (string $case): void {
    $world = isolationWorld();
    [$name, , $body] = isolationCases()[$case];
    [$method] = explode(' ', $case);
    $before = isolationSnapshot();

    // A's own other class with a student of A's 1A: the scoped binding misses.
    $this->actingAs($world['a'])
        ->call($method, route($name, ['class' => $world['otherClassA']->id, 'student' => $world['studentA']->id]), $body($world))
        ->assertNotFound();

    // B's own class with A's student.
    $this->actingAs($world['b'])
        ->call($method, route($name, ['class' => $world['classB']->id, 'student' => $world['studentA']->id]), $body($world))
        ->assertNotFound();

    expect(isolationSnapshot())->toBe($before);
})->with(fn (): array => array_filter(
    array_combine(array_keys(isolationCases()), array_keys(isolationCases())),
    static fn (string $case): bool => str_contains($case, '{student}'),
));

it('refuses an order that carries another class\'s students in the body', function (): void {
    $world = isolationWorld();
    $before = isolationSnapshot();

    // B's own class, with A's student added to or swapped in for B's.
    foreach ([
        [$world['studentB']->id, $world['studentA']->id],
        [$world['studentA']->id],
    ] as $ids) {
        $this->actingAs($world['b'])->from(route('classes.show', $world['classB']))
            ->put(route('classes.students.order', $world['classB']), ['student_ids' => $ids])
            ->assertSessionHasErrors('student_ids');
    }

    // A's 1A, with the student of A's other class instead of one of its own.
    $this->actingAs($world['a'])->from(route('classes.show', $world['classA']))
        ->put(route('classes.students.order', $world['classA']), [
            'student_ids' => [$world['studentA']->id, $world['otherStudentA']->id],
        ])
        ->assertSessionHasErrors('student_ids');

    expect(isolationSnapshot())->toBe($before);
});

it('lists only the teacher\'s own classes', function (): void {
    $world = isolationWorld();

    $this->actingAs($world['b'])->get(route('classes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('classes/index')
            ->where('classes', [['id' => $world['classB']->id, 'name' => '1A', 'grade' => $world['classB']->grade, 'students_count' => 1]])
            ->where('archivedClasses', []));
});

it('lists only the account\'s own rooms on «Oyunlar» and «Keçmiş oyunlar»', function (): void {
    $world = isolationWorld();

    $this->actingAs($world['b'])->get(route('rooms.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('rooms/index')
            ->where('open', [['id' => $world['roomB']->id, 'code' => $world['roomB']->code, 'host' => true, 'players' => 2, 'mechanic' => 'recall_order', 'board' => null, 'running' => false]]));
    $this->get(route('rooms.past'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('rooms/past')->where('past', []));

    // The entrance at `/oyun` and its old address know a room only by its own code: a class's, a game's or a pairing code is unknown.
    foreach ([$world['classA']->id, $world['gameA']->id, '000000'] as $code) {
        $this->get('/oyun/'.$code)->assertStatus(preg_match('/^\d{6}$/', (string) $code) === 1 ? 302 : 404);
    }
    $this->get(route('rooms.join.old', ['code' => $world['roomA']->code]))->assertRedirect(route('rooms.join', ['code' => $world['roomA']->code]));
});

it('answers the invitation\'s door alike to every account, and takes no row in its address', function (): void {
    // ADR 033 decision 11: `/oyun/qeydiyyat` has no `{room}` or `{member}`, and `guest` sends any account to `/start`.
    $world = isolationWorld();
    Rooms::on();

    foreach (['rooms.invited', 'rooms.invited.store'] as $name) {
        expect(array_intersect(Route::getRoutes()->getByName($name)?->parameterNames() ?? ['missing'], ISOLATED_PARAMETERS))->toBe([], $name);
    }

    $invitation = [RoomInvitation::KEY => ['code' => (string) $world['roomA']->code, 'tag' => null, 'until' => now()->addHour()->unix()]];

    foreach (['a' => $world['a'], 'b' => $world['b'], 'a parent' => User::factory()->parent()->create(), 'a player' => User::factory()->player()->create(), 'no role' => User::factory()->withoutRole()->create()] as $who => $account) {
        $this->actingAs($account)->withSession($invitation)->get(route('rooms.invited'))->assertRedirect(route('start'));
        $this->actingAs($account)->withSession($invitation)->post(route('rooms.invited.store'), ['name' => 'X', 'email' => "door-{$account->id}@example.com", 'password' => 'password', 'terms' => '1', 'agreement' => '1'])
            ->assertRedirect(route('start'));
        expect(User::query()->where('email', "door-{$account->id}@example.com")->exists())->toBeFalse($who);
    }
});

it('lets the policies refuse another teacher on their own', function (): void {
    $world = isolationWorld();
    $class = $world['classA'];
    $student = $world['studentA'];
    $game = $world['gameA'];

    foreach (['view', 'update', 'play'] as $ability) {
        expect(Gate::forUser($world['a'])->allows($ability, $game))->toBeTrue()
            ->and(Gate::forUser($world['b'])->allows($ability, $game))->toBeFalse();
    }

    foreach (['view', 'update', 'delete'] as $ability) {
        expect(Gate::forUser($world['a'])->allows($ability, $class))->toBeTrue()
            ->and(Gate::forUser($world['b'])->allows($ability, $class))->toBeFalse();
    }

    foreach (['update', 'delete'] as $ability) {
        expect(Gate::forUser($world['a'])->allows($ability, $student))->toBeTrue()
            ->and(Gate::forUser($world['b'])->allows($ability, $student))->toBeFalse()
            ->and(Gate::forUser($world['a'])->allows($ability, $world['wordA']))->toBeTrue()
            ->and(Gate::forUser($world['b'])->allows($ability, $world['wordA']))->toBeFalse();
    }

    foreach (['view', 'update', 'delete'] as $ability) {
        expect(Gate::forUser($world['a'])->allows($ability, $world['childA']))->toBeTrue()
            ->and(Gate::forUser($world['b'])->allows($ability, $world['childA']))->toBeFalse();
    }

    foreach (['view', 'update', 'play'] as $ability) {
        expect(Gate::forUser($world['a'])->allows($ability, $world['homeGameA']))->toBeTrue()
            ->and(Gate::forUser($world['b'])->allows($ability, $world['homeGameA']))->toBeFalse();
    }

    foreach (['view', 'play', 'host', 'watch', 'start'] as $ability) {
        expect(Gate::forUser($world['a'])->allows($ability, $world['roomA']))->toBeTrue()
            ->and(Gate::forUser($world['b'])->allows($ability, $world['roomA']))->toBeFalse();
    }

    expect(Gate::forUser($world['a'])->allows('end', $world['playingRoomA']))->toBeTrue()
        ->and(Gate::forUser($world['b'])->allows('end', $world['playingRoomA']))->toBeFalse()
        ->and(Gate::forUser($world['a'])->allows('end', $world['roomA']))->toBeFalse()
        ->and(Gate::forUser($world['a'])->allows('start', $world['playingRoomA']))->toBeFalse();

    expect(Gate::forUser($world['a'])->allows('remove', [$world['roomA'], $world['memberA']]))->toBeTrue()
        ->and(Gate::forUser($world['b'])->allows('remove', [$world['roomA'], $world['memberA']]))->toBeFalse()
        ->and(Gate::forUser($world['b'])->allows('remove', [$world['roomB'], $world['memberA']]))->toBeFalse();

    foreach (['delete', 'forget'] as $ability) {
        expect(Gate::forUser($world['a'])->allows($ability, $world['closedRoomA']))->toBeTrue()
            ->and(Gate::forUser($world['b'])->allows($ability, $world['closedRoomA']))->toBeFalse();
    }
});

// A parent's home game goes through the same {game} routes as a class's:
// every case again, with A's home game in place of A's class game.
it('refuses another account a home game on every {game} route and lets its parent through', function (string $case): void {
    $world = isolationWorld();
    $world['gameA'] = $world['homeGameA'];
    $before = isolationSnapshot();

    $this->actingAs($world['b'])->from(route('children.index'));
    $response = isolationRequest($case, $world);

    expect($response->status())->toBeIn([403, 404])
        ->and($response->getContent())->not->toContain('Aysel')
        ->and(isolationSnapshot())->toBe($before);

    $this->actingAs($world['a'])->from(route('children.show', $world['childA']));
    $owner = isolationRequest($case, $world);

    expect($owner->status())->toBeLessThan(400);
    $owner->assertSessionHasNoErrors();
})->with(fn (): array => array_filter(
    array_combine(array_keys(isolationCases()), array_keys(isolationCases())),
    static fn (string $case): bool => str_contains($case, '{game}'),
));

it('refuses an order that carries another parent\'s children in the body', function (): void {
    $world = isolationWorld();
    $before = isolationSnapshot();

    foreach ([
        [$world['childB']->id, $world['childA']->id],
        [$world['childA']->id],
        [$world['childA']->id, $world['childB']->id],
    ] as $ids) {
        $this->actingAs($world['b'])->from(route('children.index'))
            ->put(route('children.order'), ['child_ids' => $ids])
            ->assertSessionHasErrors('child_ids');
    }

    // A's own two children, with B's in place of one.
    $this->actingAs($world['a'])->from(route('children.index'))
        ->put(route('children.order'), ['child_ids' => [$world['childA']->id, $world['childB']->id]])
        ->assertSessionHasErrors('child_ids');

    expect(isolationSnapshot())->toBe($before);
});

it('lists only the parent\'s own children and their own home games', function (): void {
    $world = isolationWorld();

    $this->actingAs($world['b'])->get(route('children.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('children/index')
            ->where('children', [['id' => $world['childB']->id, 'display_name' => 'Nigar', 'avatar' => $world['childB']->avatar, 'stars_today' => 0, 'has_voice' => false]]));

    $this->actingAs($world['b'])->get(route('pult', ['list' => 1]))
        ->assertInertia(fn ($page) => $page->where('games', fn ($games): bool => ! collect($games)->contains('id', $world['homeGameA']->id)));
});

it('binds nothing for a malformed or unknown id', function (string $id): void {
    $world = isolationWorld();

    $this->actingAs($world['a'])->get('/classes/'.rawurlencode($id))->assertNotFound();
    $this->actingAs($world['a'])->get('/games/'.rawurlencode($id).'/board')->assertNotFound();
    $this->actingAs($world['a'])->get('/children/'.rawurlencode($id))->assertNotFound();
    $this->actingAs($world['a'])->get('/children/'.rawurlencode($id).'/games/create')->assertNotFound();
    $this->actingAs($world['a'])->get('/rooms/'.rawurlencode($id))->assertNotFound();
    $this->actingAs($world['a'])->getJson('/rooms/'.rawurlencode($id).'/state')->assertNotFound();
    $this->actingAs($world['a'])->delete('/rooms/'.$world['roomA']->id.'/members/'.rawurlencode($id))->assertNotFound();
})->with([
    'not a ulid' => ['1'],
    'unknown ulid' => ['01JZ0000000000000000000000'],
    'sql-ish' => ["x' OR '1'='1"],
]);

// A room's own refusals (§12 "Rooms and joining", ADR 020 decision 5): a
// player the host removed is any other account, on every room route.
it('refuses a removed player on every room route and changes nothing', function (string $case): void {
    $world = isolationWorld();
    $removed = User::query()->findOrFail($world['memberA']->user_id);
    app(RemoveRoomPlayer::class)->handle($world['roomA'], $world['memberA']);
    // Removed from the closed room as well, as if before it closed.
    RoomPlayer::query()->where('room_id', $world['closedRoomA']->id)->where('user_id', $removed->id)->update(['removed_at' => now()]);
    $before = isolationSnapshot();

    $this->actingAs($removed)->from(route('rooms.index'));
    $response = isolationRequest($case, $world);

    expect($response->status())->toBe(404)
        ->and($response->getContent())->not->toContain('Aysel')
        ->and(isolationSnapshot())->toBe($before);
})->with(fn (): array => array_filter(
    array_combine(array_keys(isolationCases()), array_keys(isolationCases())),
    static fn (string $case): bool => str_contains($case, '{room}'),
));

it('binds a {member} only inside its {room}', function (): void {
    $world = isolationWorld();
    $before = isolationSnapshot();

    // A's room with B's room's player, and B's room with A's.
    $this->actingAs($world['a'])
        ->delete(route('rooms.members.destroy', ['room' => $world['roomA']->id, 'member' => $world['memberB']->id]))
        ->assertNotFound();
    $this->actingAs($world['b'])
        ->delete(route('rooms.members.destroy', ['room' => $world['roomB']->id, 'member' => $world['memberA']->id]))
        ->assertNotFound();

    expect(isolationSnapshot())->toBe($before);
});
