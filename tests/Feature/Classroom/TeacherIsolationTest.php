<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Models\CustomWord;
use App\Domain\Content\Support\Avatars;
use App\Domain\Play\Models\GameSession;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\Games;

/*
 * Teacher isolation (docs/architecture.md §7). Every route with a `{class}`,
 * `{student}`, `{game}` or `{word}` parameter must have a case below; the first test
 * fails when one is missing, so a new route cannot skip this file.
 *
 * Each case is a request teacher A may make on A's own data. The tests make
 * it as teacher B (404 or 403, nothing changes), as a guest (to the login
 * page, nothing changes) and as A (it works — so the case really reaches the
 * route, and B's refusal is the isolation, not a wrong URL).
 */

const ISOLATED_PARAMETERS = ['class', 'student', 'game', 'word'];

/**
 * Teacher A owns 1A (two active students, an archived one and a live
 * game), 1B (one student), an archived 2A and a word of their own; teacher
 * B owns their own 1A with one student and a game, and a word.
 *
 * @return array{a: User, b: User, classA: SchoolClass, otherClassA: SchoolClass, archivedClassA: SchoolClass, studentA: Student, secondStudentA: Student, archivedStudentA: Student, otherStudentA: Student, gameA: GameSession, classB: SchoolClass, studentB: Student, gameB: GameSession, wordA: CustomWord, wordB: CustomWord}
 */
function isolationWorld(): array
{
    $a = User::factory()->create();
    $b = User::factory()->create();

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
        'GET games/{game}/board' => ['games.board', $gameA, $none],
        'POST games/{game}/board/claim' => ['games.board.claim', $gameA, $none],
        'GET games/{game}/state' => ['games.state', $gameA, $none],
        'POST games/{game}/sync' => ['games.sync', $gameA, static fn (array $w): array => ['board_epoch' => 0, 'events' => [
            Games::hit(Games::round($w['gameA']), Games::target(Games::round($w['gameA'])), $w['studentA']->id),
        ]]],
        'PATCH games/{game}/status' => ['games.status.update', $gameA, static fn (array $w): array => ['status' => 'paused']],
        'POST games/{game}/join-code' => ['games.join-code', $gameA, $none],
        'GET games/{game}/remote' => ['games.remote', $gameA, $none],
        'GET classes/{class}/rankings' => ['classes.rankings', $classA, $none],
        'GET classes/{class}/podium' => ['classes.podium', $classA, $none],
        'PATCH words/{word}' => ['words.update', $wordA, static fn (array $w): array => ['text' => 'kələm', 'syllables' => 'kə-ləm']],
        'DELETE words/{word}' => ['words.destroy', $wordA, $none],
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

it('has an isolation case for every route with {class}, {student}, {game} or {word}', function (): void {
    $routes = array_keys(isolatedRoutes());
    $cases = array_keys(isolationCases());

    expect(array_values(array_diff($routes, $cases)))->toBe([], 'Add these routes to isolationCases().')
        ->and(array_values(array_diff($cases, $routes)))->toBe([], 'These cases name routes that no longer exist.');

    foreach ($cases as $case) {
        [$name] = isolationCases()[$case];
        expect(Route::getRoutes()->getByName($name)?->uri())->toBe(explode(' ', $case, 2)[1], "{$case} names the wrong route.");
    }
});

it('binds {student} only inside its {class} and checks a policy on every isolated route', function (): void {
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
});

it('binds nothing for a malformed or unknown id', function (string $id): void {
    $world = isolationWorld();

    $this->actingAs($world['a'])->get('/classes/'.rawurlencode($id))->assertNotFound();
    $this->actingAs($world['a'])->get('/games/'.rawurlencode($id).'/board')->assertNotFound();
})->with([
    'not a ulid' => ['1'],
    'unknown ulid' => ['01JZ0000000000000000000000'],
    'sql-ish' => ["x' OR '1'='1"],
]);
