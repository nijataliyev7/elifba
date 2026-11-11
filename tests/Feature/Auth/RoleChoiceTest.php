<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\Support\FakeGoogle;
use Tests\TestCase;

/*
 * The role choice right after registration (docs/architecture.md §1
 * "Accounts and roles", §7 "Agreements", ADR 010 decision 4, ADR 013): an
 * account without a role reaches only `/role`, its profile, logging out,
 * e-mail confirmation and the language switch; choosing a role stamps that
 * role's sentence and opens its area.
 */

/**
 * Registers by the form and follows the redirect to `/start`.
 */
function registerByForm(TestCase $test): User
{
    $test->post(route('register.store'), [
        'name' => 'Aygün Məmmədova',
        'email' => 'aygun@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ])->assertRedirect(route('start', absolute: false));

    return User::query()->sole();
}

it('sends a new account to the role choice', function (): void {
    $this->skipUnlessFortifyHas(Features::registration());
    Notification::fake();

    $user = registerByForm($this);

    expect($user->roleList())->toBe([])
        ->and($user->email_verified_at)->toBeNull();

    // Before e-mail confirmation: the choice comes first.
    $this->get(route('start'))->assertRedirect(route('role'));
    $this->get(route('classes.index'))->assertRedirect(route('role'));
    $this->get(route('words.index'))->assertRedirect(route('role'));
    $this->get(route('children.index'))->assertRedirect(route('role'));
    $this->get(route('pult'))->assertRedirect(route('role'));
    $this->get(route('play-settings.edit'))->assertRedirect(route('role'));
    $this->get(route('roles.edit'))->assertRedirect(route('role'));

    $this->get(route('role'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/choose-role')
            ->where('roles', ['teacher', 'parent'])
            ->where('auth.roles', [])
            ->where('auth.area', null));
});

it('sends an account that signed up with Google to the role choice', function (): void {
    FakeGoogle::install();
    Notification::fake();

    $location = (string) $this->get(route('google.redirect'))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $this->get(route('google.callback', ['code' => '4/test-code', 'state' => $query['state']]))
        ->assertRedirect(route('google.register'));

    $this->post(route('google.register.store'), ['name' => 'Aygün', 'terms' => '1'])
        ->assertRedirect(route('start', absolute: false));

    $user = User::query()->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->roleList())->toBe([])
        ->and($user->email_verified_at)->not->toBeNull();

    $this->get(route('start'))->assertRedirect(route('role'));
    $this->get(route('classes.index'))->assertRedirect(route('role'));
    $this->get(route('role'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/choose-role'));

    $this->post(route('role.store'), ['role' => 'parent', 'agreement' => '1'])
        ->assertRedirect(route('start'));
    $this->get(route('start'))->assertRedirect(route('children.index'));
});

it('grants the chosen role and stamps its agreement', function (Role $role, string $home): void {
    $this->travelTo('2026-11-11 09:15:00');
    $user = User::factory()->withoutRole()->create();

    $this->actingAs($user)
        ->post(route('role.store'), ['role' => $role->value, 'agreement' => '1'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('start'));

    $user->refresh();
    $other = $role === Role::Teacher ? Role::Parent : Role::Teacher;

    expect($user->roleList())->toBe([$role])
        ->and($user->area)->toBe($role)
        ->and($user->getAttribute($role->agreementColumn())?->toDateTimeString())->toBe('2026-11-11 09:15:00')
        ->and($user->getAttribute($other->agreementColumn()))->toBeNull();

    $this->get(route('start'))->assertRedirect(route($home));
    $this->get(route($home))->assertOk();
})->with([
    'teacher' => [Role::Teacher, 'classes.index'],
    'parent' => [Role::Parent, 'children.index'],
]);

it('lets an account choose before confirming its address, then asks for the confirmation', function (): void {
    $user = User::factory()->withoutRole()->unverified()->create();

    $this->actingAs($user)->get(route('role'))->assertOk();
    $this->post(route('role.store'), ['role' => 'teacher', 'agreement' => '1'])->assertRedirect(route('start'));

    $this->get(route('classes.index'))->assertRedirect(route('verification.notice'));
    expect($user->refresh()->roleList())->toBe([Role::Teacher]);
});

it('refuses a role without its agreement', function (array $input, string $field): void {
    $user = User::factory()->withoutRole()->create();

    $this->actingAs($user)
        ->from(route('role'))
        ->post(route('role.store'), $input)
        ->assertRedirect(route('role'))
        ->assertSessionHasErrors($field);

    $user->refresh();
    expect($user->roleList())->toBe([])
        ->and($user->area)->toBeNull()
        ->and($user->teacher_agreed_at)->toBeNull()
        ->and($user->parent_agreed_at)->toBeNull();
})->with([
    'no agreement' => [['role' => 'teacher'], 'agreement'],
    'unticked' => [['role' => 'parent', 'agreement' => '0'], 'agreement'],
    'no role' => [['agreement' => '1'], 'role'],
    'unknown role' => [['role' => 'admin', 'agreement' => '1'], 'role'],
    'role as an array' => [['role' => ['teacher'], 'agreement' => '1'], 'role'],
]);

it('says which sentence is missing, in the interface language', function (): void {
    $user = User::factory()->withoutRole()->create();

    $this->actingAs($user)
        ->post(route('role.store'), ['role' => 'teacher'])
        ->assertSessionHasErrors(['agreement' => __('roles.errors.agreement')]);
});

it('passes an account that already has a role on to its home', function (): void {
    $this->travelTo('2026-11-11 09:00:00');
    $teacher = User::factory()->create();
    $agreed = $teacher->teacher_agreed_at?->toDateTimeString();
    $this->travelTo('2026-11-12 09:00:00');

    $this->actingAs($teacher)->get(route('role'))->assertRedirect(route('start'));

    // The first choice only: the second role is added in Settings → «Rollar».
    $this->post(route('role.store'), ['role' => 'parent', 'agreement' => '1'])->assertRedirect(route('start'));

    $teacher->refresh();
    expect($teacher->roleList())->toBe([Role::Teacher])
        ->and($teacher->teacher_agreed_at?->toDateTimeString())->toBe($agreed)
        ->and($teacher->parent_agreed_at)->toBeNull();
});

it('keeps logout, e-mail confirmation and the language switch working without a role', function (): void {
    $user = User::factory()->withoutRole()->unverified()->create();
    $this->actingAs($user);

    $this->get(route('verification.notice'))->assertOk();
    $this->put(route('locale.update'), ['locale' => 'en'])->assertRedirect();
    expect($user->refresh()->locale)->toBe('en');

    $this->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

it('answers the choice page only to a signed-in account', function (): void {
    $this->get(route('role'))->assertRedirect(route('login'));
    $this->post(route('role.store'), ['role' => 'teacher', 'agreement' => '1'])->assertRedirect(route('login'));
    $this->get(route('start'))->assertRedirect(route('login'));
});
