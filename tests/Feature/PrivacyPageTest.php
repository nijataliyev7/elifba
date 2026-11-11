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

it('has the section for parents in both languages', function (?string $locale, string $title, string $stored, string $classNote, string $intro): void {
    config(['elifba.children.max' => 10, 'elifba.retention_days' => 400]);
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);
    $parts = ['title', 'stored', 'why', 'who', 'not_shared', 'delete', 'how_long'];

    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('childrenMax', 10)
            ->where('retentionDays', 400)
            ->where('translations', function ($translations) use ($locale, $parts, $title, $stored, $classNote, $intro): bool {
                foreach ($parts as $part) {
                    if (trim((string) ($translations["privacy.home.{$part}"] ?? '')) === '') {
                        return false;
                    }
                }

                return $translations['privacy.home.title'] === $title
                    && str_contains($translations['privacy.home.stored'], $stored)
                    && str_contains($translations['privacy.home.stored'], ':max')
                    && str_contains($translations['privacy.home.how_long'], ':days')
                    // The note a teacher copies stays, under a title that says it is for a class.
                    && $translations['privacy.parents.title'] === $classNote
                    // The introduction names both uses; the Google paragraph speaks of the account.
                    && str_contains($translations['privacy.intro'], $intro)
                    && ! str_contains($translations['privacy.google.body'], $locale === 'en' ? 'teacher' : 'müəllim');
            }));
})->with([
    'az' => [null, 'Evdə: valideynlər və uşaqları', 'yalnız ad (soyadsız)', 'Sinif valideynləri üçün qeyd', 'valideynlərin'],
    'en' => ['en', 'At home: parents and their children', 'a first name only (no surname)', 'A note for a class\'s parents', 'parents'],
]);
