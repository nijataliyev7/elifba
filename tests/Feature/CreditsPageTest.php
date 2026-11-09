<?php

use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\CreditsDocument;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The credits page (docs/architecture.md §8): public, az and en, every entry
 * of content/credits.json and OpenMoji's attribution verbatim.
 */

it('is public and lists every credit with OpenMoji\'s attribution', function (): void {
    $credits = (new ContentRepository(base_path('content')))->credits();

    $this->get(route('credits'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('credits')
            ->has('credits', count($credits))
            ->where('credits.0', $credits[0]->toArray())
            ->where('openmoji.attribution', 'All emojis designed by OpenMoji – the open-source emoji and icon project. License: CC BY-SA 4.0')
            ->where('openmoji.changed', [])
            ->where('openmoji', CreditsDocument::openMoji($credits)));
});

it('is in Azerbaijani by default and in English when chosen', function (?string $locale, string $title): void {
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    $request->get(route('credits'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', $locale ?? 'az')
            ->where('translations', fn ($translations): bool => $translations['credits.title'] === $title
                && $translations['nav.credits'] !== ''
                && str_contains($translations['credits.licenses.body'], 'CC BY-SA 4.0')
                && str_contains($translations['credits.licenses.body'], 'MIT')
                && str_contains($translations['credits.licenses.body'], 'Open Font License 1.1')));
})->with([
    'az' => [null, 'Müəlliflər və lisenziyalar'],
    'en' => ['en', 'Credits and licenses'],
]);
