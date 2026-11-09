<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The privacy notice (docs/architecture.md §7): public, az and en, with the
 * retention period and the operator from `.env`.
 */

it('is public and shows the retention period and the operator', function (): void {
    config([
        'elifba.retention_days' => 400,
        'elifba.operator.name' => ' Məktəb №5 ',
        'elifba.operator.email' => 'elifba@example.az',
    ]);

    $this->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('retentionDays', 400)
            ->where('operator', ['name' => 'Məktəb №5', 'email' => 'elifba@example.az']));
});

it('sends no operator when none is configured', function (?string $name, ?string $email): void {
    config(['elifba.operator.name' => $name, 'elifba.operator.email' => $email]);

    $this->get(route('privacy'))
        ->assertInertia(fn (Assert $page) => $page->where('operator', ['name' => null, 'email' => null]));
})->with([
    'unset' => [null, null],
    'blank' => ['', '  '],
]);

it('is in Azerbaijani by default and in English when chosen', function (?string $locale, string $title, string $parents): void {
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', $locale ?? 'az')
            ->where('translations', fn ($translations): bool => $translations['privacy.title'] === $title
                && str_contains($translations['privacy.parents.note'], $parents)
                && str_contains($translations['privacy.how_long.body'], ':days')));
})->with([
    'az' => [null, 'Məxfilik qeydi', 'Hörmətli valideynlər'],
    'en' => ['en', 'Privacy note', 'Dear parents'],
]);

it('follows a teacher\'s own language', function (): void {
    $this->actingAs(User::factory()->create(['locale' => 'en']))
        ->get(route('privacy'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});
