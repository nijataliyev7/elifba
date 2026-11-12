<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Models\CustomWord;
use App\Domain\Family\Models\Child;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Games;

/*
 * Areas by role (docs/architecture.md §1 "Accounts and roles", ADR 010
 * decision 3). Every authenticated route is in exactly one group: teacher,
 * parent or shared (`auth`, `role.chosen`, `verified` and the package's
 * `role` middleware), the account's own pages (no role named; listed
 * below) or the board routes a guest board's bearer may call. A new route
 * fails the first test until it is placed. Rows stay guarded by ownership,
 * which binds before the role is checked.
 */

/**
 * The account's own routes: no role named. Fortify's (log out, e-mail
 * confirmation, password confirmation, two-factor), the Google
 * confirmation, the role choice, `/start`, the switch and the settings.
 */
const ACCOUNT_ROUTES = [
    'logout',
    'verification.notice',
    'verification.verify',
    'verification.send',
    'password.confirm',
    'password.confirm.store',
    'password.confirmation',
    'two-factor.enable',
    'two-factor.confirm',
    'two-factor.disable',
    'two-factor.qr-code',
    'two-factor.secret-key',
    'two-factor.recovery-codes',
    'two-factor.regenerate-recovery-codes',
    'google.confirm',
    'role',
    'role.store',
    'start',
    'area.update',
    'profile.edit',
    'profile.update',
    'profile.destroy',
    'security.edit',
    'user-password.update',
    'appearance.edit',
    'play-settings.edit',
    'play-settings.update',
    'play-settings.sounds.update',
    'play-settings.voice.update',
    'roles.edit',
    'roles.store',
    // `Route::redirect('settings', …)` has no name.
    'GET settings',
];

/** The account routes that wait for the role choice. */
const ACCOUNT_ROUTES_AFTER_THE_CHOICE = [
    'security.edit',
    'user-password.update',
    'appearance.edit',
    'play-settings.edit',
    'play-settings.update',
    'play-settings.sounds.update',
    'play-settings.voice.update',
    'roles.edit',
    'roles.store',
];

/** What a guest board's bearer may call (`auth:board,web`, `auth:board`, §4). */
const BOARD_ROUTES = [
    'games.board.claim',
    'games.state',
    'games.sync',
    'games.status.update',
    'games.players.voice',
    'guest-boards.show',
    'guest-boards.destroy',
];

/**
 * A route's name, or "METHOD uri" when it has none.
 */
function areaRouteKey(RoutingRoute $route): string
{
    return $route->getName() ?? $route->methods()[0].' '.$route->uri();
}

/**
 * Every route behind authentication, by its key.
 *
 * @return array<string, RoutingRoute>
 */
function authenticatedRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $auth = array_filter($route->gatherMiddleware(), static fn (mixed $middleware): bool => is_string($middleware)
            && ($middleware === 'auth' || str_starts_with($middleware, 'auth:')));

        if ($auth !== []) {
            $routes[areaRouteKey($route)] = $route;
        }
    }

    return $routes;
}

/**
 * The groups a route is in.
 *
 * @return list<string>
 */
function areaGroupsOf(RoutingRoute $route): array
{
    $middleware = $route->gatherMiddleware();
    $key = areaRouteKey($route);
    $base = ['auth', 'role.chosen', 'verified'];
    $groups = [];

    foreach ([
        'teacher' => Role::middleware(Role::Teacher),
        'parent' => Role::middleware(Role::Parent),
        'shared' => Role::middleware(Role::Teacher, Role::Parent),
    ] as $group => $role) {
        if (in_array($role, $middleware, true) && array_diff($base, $middleware) === []) {
            $groups[] = $group;
        }
    }

    if (in_array($key, ACCOUNT_ROUTES, true)) {
        $groups[] = 'account';
    }

    if (in_array($key, BOARD_ROUTES, true)) {
        $groups[] = 'board';
    }

    return $groups;
}

/**
 * The teacher-area routes, with the rows a parent-only account is made to
 * own so that ownership would let it through and only the role refuses.
 *
 * @return array{parent: User, uri: Closure(RoutingRoute): string}
 */
function parentWithTeacherRows(): array
{
    $parent = User::factory()->parent()->create();
    $class = SchoolClass::factory()->for($parent, 'teacher')->create();
    $student = Student::factory()->for($class)->create(['display_name' => 'Aysel']);
    $game = Games::start($class, seed: 1);
    $word = CustomWord::factory()->for($parent, 'teacher')->create(['text' => 'pomidor']);
    $values = ['class' => $class->id, 'student' => $student->id, 'game' => $game->id, 'word' => $word->id];

    return [
        'parent' => $parent,
        'uri' => static fn (RoutingRoute $route): string => '/'.ltrim((string) preg_replace_callback(
            '/\{(\w+)\??\}/',
            static fn (array $match): string => (string) $values[$match[1]],
            $route->uri(),
        ), '/'),
    ];
}

/**
 * @return array<string, RoutingRoute>
 */
function teacherAreaRoutes(): array
{
    return array_filter(authenticatedRoutes(), static fn (RoutingRoute $route): bool => areaGroupsOf($route) === ['teacher']);
}

/**
 * The parent-area routes, with a child a teacher-only account is made to
 * own so that ownership would let it through and only the role refuses.
 *
 * @return array{teacher: User, child: Child, uri: Closure(RoutingRoute): string}
 */
function teacherWithParentRows(): array
{
    $teacher = User::factory()->create();
    $child = Child::factory()->for($teacher, 'parent')->create(['display_name' => 'Firuzə']);
    $values = ['child' => $child->id];

    return [
        'teacher' => $teacher,
        'child' => $child,
        'uri' => static fn (RoutingRoute $route): string => '/'.ltrim((string) preg_replace_callback(
            '/\{(\w+)\??\}/',
            static fn (array $match): string => (string) $values[$match[1]],
            $route->uri(),
        ), '/'),
    ];
}

/**
 * @return array<string, RoutingRoute>
 */
function parentAreaRoutes(): array
{
    return array_filter(authenticatedRoutes(), static fn (RoutingRoute $route): bool => areaGroupsOf($route) === ['parent']);
}

/**
 * Everything a refused request could change.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function areaSnapshot(): array
{
    $rows = static fn (string $table): array => DB::table($table)->orderBy('id')->get()
        ->map(static fn (object $row): array => (array) $row)->all();

    return [
        'users' => $rows('users'),
        'model_has_roles' => DB::table('model_has_roles')->orderBy('model_id')->orderBy('role_id')->get()
            ->map(static fn (object $row): array => (array) $row)->all(),
        'school_classes' => $rows('school_classes'),
        'students' => $rows('students'),
        'game_sessions' => $rows('game_sessions'),
        'game_rounds' => $rows('game_rounds'),
        'custom_words' => $rows('custom_words'),
        'hidden_words' => $rows('hidden_words'),
        'children' => $rows('children'),
    ];
}

it('puts every authenticated route in one area', function (): void {
    $routes = authenticatedRoutes();
    $misplaced = [];

    foreach ($routes as $key => $route) {
        $groups = areaGroupsOf($route);

        if (count($groups) !== 1) {
            $misplaced[$key] = $groups;
        }
    }

    expect($misplaced)->toBe([], 'Put each route in exactly one area (docs/architecture.md §1 "Accounts and roles").')
        ->and(array_values(array_diff([...ACCOUNT_ROUTES, ...BOARD_ROUTES], array_keys($routes))))->toBe([], 'These listed routes no longer exist.');

    foreach (ACCOUNT_ROUTES_AFTER_THE_CHOICE as $name) {
        expect($routes[$name]->gatherMiddleware())->toContain('role.chosen');
    }

    // The board routes and the account routes name no role.
    foreach ([...ACCOUNT_ROUTES, ...BOARD_ROUTES] as $key) {
        $roles = array_filter($routes[$key]->gatherMiddleware(), static fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'role:'));
        expect($roles)->toBe([], "{$key} names a role.");
    }

    // Each area has its routes.
    $count = static fn (string $group): int => count(array_filter($routes, static fn (RoutingRoute $route): bool => areaGroupsOf($route) === [$group]));
    expect($count('teacher'))->toBeGreaterThan(20)
        ->and($count('parent'))->toBeGreaterThanOrEqual(1)
        ->and($count('shared'))->toBeGreaterThan(5);
});

it('places classes, rankings, teacher words and class games in the teacher area, the board and the remote in the shared one', function (): void {
    $routes = authenticatedRoutes();

    foreach (['classes.index', 'classes.show', 'classes.students.store', 'classes.games.create', 'classes.games.store', 'classes.rankings', 'classes.podium', 'words.index', 'words.update', 'words.hidden.store'] as $name) {
        expect(areaGroupsOf($routes[$name]))->toBe(['teacher'], $name);
    }

    foreach (['games.board', 'games.join-code', 'games.remote', 'pult', 'pult.code', 'pult.claim', 'guest-boards.game.edit', 'guest-boards.game.update'] as $name) {
        expect(areaGroupsOf($routes[$name]))->toBe(['shared'], $name);
    }

    foreach (['children.index', 'children.store', 'children.order', 'children.show', 'children.update', 'children.destroy', 'children.learned-letters.update', 'children.games.create', 'children.games.store'] as $name) {
        expect(areaGroupsOf($routes[$name]))->toBe(['parent'], $name);
    }
});

it('refuses a teacher without the parent role on every child route', function (): void {
    ['teacher' => $teacher, 'child' => $child, 'uri' => $uri] = teacherWithParentRows();
    $before = areaSnapshot();
    $checked = 0;

    foreach (parentAreaRoutes() as $key => $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $page = $this->actingAs($teacher)->call($method, $uri($route), ['display_name' => 'Ayla', 'child_ids' => [$child->id], 'letters' => ['a']]);
            $json = $this->actingAs($teacher)->json($method, $uri($route));

            // A page visit leads home; anything else is refused.
            expect($page->status())->toBe($method === 'GET' ? 302 : 403, "{$method} {$key}")
                ->and($json->status())->toBe(403, "{$method} {$key} (JSON)")
                ->and($json->getContent())->not->toContain('Firuzə');

            if ($method === 'GET') {
                $page->assertRedirect(route('start'));
            }

            $checked++;
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(9)
        ->and(areaSnapshot())->toBe($before);
});

it('redirects a parent\'s page visit to the teacher area home', function (): void {
    ['parent' => $parent, 'uri' => $uri] = parentWithTeacherRows();
    $visited = 0;

    foreach (teacherAreaRoutes() as $key => $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $this->actingAs($parent)->get($uri($route))->assertRedirect(route('start'));
        $visited++;
    }

    expect($visited)->toBeGreaterThanOrEqual(6);

    // …which is the parent's own home.
    $this->get(route('start'))->assertRedirect(route('children.index'));
    $this->get(route('children.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('children/index'));
});

it('refuses a parent\'s write to the teacher area', function (): void {
    ['parent' => $parent, 'uri' => $uri] = parentWithTeacherRows();
    $before = areaSnapshot();
    $refused = 0;

    foreach (teacherAreaRoutes() as $key => $route) {
        foreach (array_diff($route->methods(), ['GET', 'HEAD']) as $method) {
            $this->actingAs($parent)->call($method, $uri($route), ['name' => '1C', 'text' => 'kələm'])->assertForbidden();
            $refused++;
        }
    }

    expect($refused)->toBeGreaterThan(15)
        ->and(areaSnapshot())->toBe($before);
});

it('refuses a parent on every teacher route in JSON as well', function (): void {
    ['parent' => $parent, 'uri' => $uri] = parentWithTeacherRows();
    $before = areaSnapshot();

    foreach (teacherAreaRoutes() as $key => $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $response = $this->actingAs($parent)->json($method, $uri($route));

            expect($response->status())->toBe(403, "{$method} {$key}")
                ->and($response->getContent())->not->toContain('Aysel')
                ->and($response->getContent())->not->toContain('pomidor');
        }
    }

    expect(areaSnapshot())->toBe($before);
});

it('sends a teacher away from the parent area', function (): void {
    $teacher = User::factory()->create();

    $this->actingAs($teacher)->get(route('children.index'))->assertRedirect(route('start'));
    $this->actingAs($teacher)->getJson(route('children.index'))->assertForbidden();
});

it('lets an account with both roles into both areas', function (): void {
    $both = User::factory()->teacherAndParent()->create(['area' => Role::Parent]);

    $this->actingAs($both)->get(route('classes.index'))->assertOk();
    $this->actingAs($both)->get(route('words.index'))->assertOk();
    $this->actingAs($both)->get(route('children.index'))->assertOk();
});

it('answers 404 for another account\'s class whatever the role', function (string $state): void {
    $owner = User::factory()->create();
    $class = SchoolClass::factory()->for($owner, 'teacher')->create();
    $student = Student::factory()->for($class)->create(['display_name' => 'Aysel']);
    $game = Games::start($class, seed: 1);
    $other = User::factory()->{$state}()->create();
    $before = areaSnapshot();

    $this->actingAs($other);

    $this->get(route('classes.show', $class))->assertNotFound();
    $this->get(route('classes.rankings', $class))->assertNotFound();
    $this->patch(route('classes.update', $class), ['name' => '1C'])->assertNotFound();
    $this->delete(route('classes.students.destroy', [$class, $student]))->assertNotFound();
    $this->get(route('games.board', $game))->assertNotFound();
    $this->getJson(route('games.state', $game))->assertNotFound();

    expect(areaSnapshot())->toBe($before);
})->with([
    'another teacher' => 'teacherAndParent',
    'a parent' => 'parent',
    'no role yet' => 'withoutRole',
]);

it('answers 409 to a JSON request without a role', function (): void {
    $user = User::factory()->withoutRole()->create();

    $this->actingAs($user)->getJson(route('classes.index'))
        ->assertStatus(409)
        ->assertExactJson(['error' => 'role_required']);
    $this->actingAs($user)->post(route('classes.store'), ['name' => '1A'])
        ->assertStatus(409)
        ->assertExactJson(['error' => 'role_required']);
    $this->actingAs($user)->putJson(route('play-settings.update'), ['phone_remote' => false])
        ->assertStatus(409);

    expect(SchoolClass::query()->count())->toBe(0)
        ->and($user->refresh()->phone_remote)->toBeTrue();
});

it('keeps the profile reachable without a role', function (): void {
    $user = User::factory()->withoutRole()->create();

    $this->actingAs($user)->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/profile')
            ->where('auth.roles', [])
            ->where('auth.area', null));

    $this->patch(route('profile.update'), ['name' => 'Aygün', 'email' => $user->email])
        ->assertSessionHasNoErrors();
    expect($user->refresh()->name)->toBe('Aygün');

    // The other settings wait for the choice.
    $this->get(route('appearance.edit'))->assertRedirect(route('role'));
    $this->get(route('roles.edit'))->assertRedirect(route('role'));
});

it('shares the roles and the area with every page', function (): void {
    $teacher = User::factory()->create();
    $parent = User::factory()->parent()->create();
    $both = User::factory()->teacherAndParent()->create(['area' => Role::Parent]);

    $shared = static fn (array $roles, ?string $area): Closure => static fn (Assert $page): Assert => $page
        ->where('auth.roles', $roles)
        ->where('auth.area', $area)
        ->missing('auth.user.roles')
        ->missing('auth.user.area')
        ->missing('auth.user.teacher_agreed_at')
        ->missing('auth.user.parent_agreed_at');

    $this->actingAs($teacher)->get(route('classes.index'))->assertInertia($shared(['teacher'], 'teacher'));
    $this->actingAs($teacher)->get(route('profile.edit'))->assertInertia($shared(['teacher'], 'teacher'));
    $this->actingAs($parent)->get(route('children.index'))->assertInertia($shared(['parent'], 'parent'));
    $this->actingAs($parent)->get(route('play-settings.edit'))->assertInertia($shared(['parent'], 'parent'));

    // Both roles: a page inside an area shows that area; the others follow `users.area`.
    $this->actingAs($both)->get(route('classes.index'))->assertInertia($shared(['teacher', 'parent'], 'teacher'));
    $this->actingAs($both)->get(route('words.index'))->assertInertia($shared(['teacher', 'parent'], 'teacher'));
    $this->actingAs($both)->get(route('children.index'))->assertInertia($shared(['teacher', 'parent'], 'parent'));
    $this->actingAs($both)->get(route('profile.edit'))->assertInertia($shared(['teacher', 'parent'], 'parent'));
    $this->actingAs($both)->get(route('pult'))->assertInertia($shared(['teacher', 'parent'], 'parent'));

    // A guest: no account, no roles.
    auth()->logout();
    $this->get(route('privacy'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.user', null)
        ->where('auth.roles', [])
        ->where('auth.area', null));
});
