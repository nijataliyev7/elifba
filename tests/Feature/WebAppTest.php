<?php

use App\Models\User;
use App\Support\WebApp;

/*
 * Elifba as an installable app (docs/architecture.md §10 "Installing
 * Elifba"): every page links the manifest, the theme colour and iOS's icon;
 * the manifest has what Chrome needs to offer installation, and every icon
 * it names is a file of the size it states.
 */

it('links the manifest, the theme colour and the touch icon on every page', function (string $url, bool $signedIn): void {
    if ($signedIn) {
        $this->actingAs(User::factory()->create());
    }

    $this->get($url)
        ->assertOk()
        ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', escape: false)
        ->assertSee('<meta name="theme-color" content="'.WebApp::THEME_COLOR.'">', escape: false)
        ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', escape: false)
        ->assertSee('<meta name="apple-mobile-web-app-title" content="'.config('app.name').'">', escape: false)
        ->assertSee("addEventListener('beforeinstallprompt'", escape: false);
})->with([
    'welcome' => ['/', false],
    'login' => ['/login', false],
    'classes' => ['/classes', true],
]);

it('serves a manifest Chrome can install from', function (): void {
    $response = $this->get(route('manifest'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json');

    $manifest = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)
        ->toMatchArray([
            'id' => '/',
            'name' => 'Elifba',
            'short_name' => 'Elifba',
            'lang' => 'az',
            'start_url' => '/classes',
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => WebApp::THEME_COLOR,
            'background_color' => WebApp::BACKGROUND_COLOR,
        ])
        ->not->toHaveKey('prefer_related_applications')
        ->and($manifest['description'])->toBe(__('welcome.meta.description', [], 'az'))
        ->and($manifest['start_url'])->toBe(route('classes.index', absolute: false))
        ->and($manifest['theme_color'])->toMatch('/^#[0-9A-F]{6}$/')
        ->and($manifest['background_color'])->toMatch('/^#[0-9A-F]{6}$/');

    $sizes = fn (string $purpose): array => collect($manifest['icons'])
        ->where('purpose', $purpose)
        ->pluck('sizes')
        ->all();

    expect($sizes('any'))->toContain('192x192', '512x512')
        ->and($sizes('maskable'))->toContain('512x512');
});

it('names only icon files of the size and type it states', function (): void {
    $manifest = $this->get(route('manifest'))->json();

    foreach ($manifest['icons'] as $icon) {
        $path = public_path(ltrim($icon['src'], '/'));

        expect($path)->toBeFile();

        [$width, $height] = explode('x', $icon['sizes']);
        $image = getimagesize($path);

        expect($image)->not->toBeFalse()
            ->and($image[0])->toBe((int) $width)
            ->and($image[1])->toBe((int) $height)
            ->and($image['mime'])->toBe($icon['type']);
    }

    $touch = getimagesize(public_path(ltrim(WebApp::APPLE_TOUCH_ICON, '/')));

    expect($touch)->not->toBeFalse()
        ->and([$touch[0], $touch[1], $touch['mime']])->toBe([180, 180, 'image/png']);
});

it('serves the manifest without a session, in the default language', function (): void {
    $response = $this->withSession(['locale' => 'en'])
        ->withCookie('appearance', 'dark')
        ->get(route('manifest'))
        ->assertOk();

    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->get('Cache-Control'))->toContain('public')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=86400')
        ->and($response->json('description'))->toBe(__('welcome.meta.description', [], 'az'))
        ->and($response->json('lang'))->toBe('az');
});
