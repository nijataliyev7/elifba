<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * There is no dashboard (docs/frontend.md §1): a signed-in account lands by
 * role through `/start` (a teacher on the classes, ADR 010), and the starter
 * kit's /dashboard is only a redirect for old bookmarks.
 */

it('sends an old /dashboard bookmark on to the start', function (): void {
    $this->get('/dashboard')->assertStatus(302)->assertRedirect('/start');

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertStatus(302)
        ->assertRedirect('/start');
});

it('has no dashboard route or page', function (): void {
    expect(Route::has('dashboard'))->toBeFalse()
        ->and(config('fortify.home'))->toBe('/start');
});

it('lands a teacher on the classes', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('classes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('classes/index'));
});

it('sends a signed-in teacher away from the guest pages to the start', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('login'))
        ->assertRedirect(route('start'));
});

it('sends a guest from the classes to the login page', function (): void {
    $this->get(route('classes.index'))->assertRedirect(route('login'));
});

it('makes no third-party request from the welcome page', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();
    $host = parse_url((string) config('app.url'), PHP_URL_HOST);

    // Everything the server's HTML loads (scripts, styles, fonts, icons,
    // images) is relative or on the app's own host.
    preg_match_all('/<(?:script|img|link|source|iframe)\b[^>]*\b(?:src|href)="([^"]+)"/i', (string) $html, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $url) {
        $urlHost = parse_url(html_entity_decode($url), PHP_URL_HOST);

        expect($urlHost === null || $urlHost === $host)->toBeTrue("{$url} is a third-party request");
    }

    // The page's own code names no outside address: the pictures are
    // imported files and the repository is a plain link from a prop.
    $files = [resource_path('js/pages/welcome.tsx'), ...glob(resource_path('js/components/welcome/*.tsx')) ?: []];

    foreach ($files as $file) {
        expect((string) file_get_contents($file))->not->toMatch('#https?://#');
    }
});
