<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * Elifba as an app a teacher installs from Chrome (docs/architecture.md §10
 * "Installing Elifba"): the web app manifest, and the colour every page's
 * head repeats. Chrome fetches the manifest without cookies, so its text is
 * in the default interface language (APP_LOCALE, az).
 */
final class WebApp
{
    /** The logo's green (architecture §8): the installed app's title bar, the phone's status bar. */
    public const string THEME_COLOR = '#15803D';

    /** Behind the icon while the installed app starts: the light theme's background. */
    public const string BACKGROUND_COLOR = '#FFFFFF';

    /**
     * Drawn from `resources/images/brand/tile.svg` by `scripts/app-icons/generate.php`.
     *
     * @var list<array{src: string, sizes: string, type: string, purpose: string}>
     */
    public const array ICONS = [
        ['src' => '/icons/elifba-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/icons/elifba-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/icons/elifba-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ];

    /** iOS's home-screen icon (180×180, full-bleed: iOS rounds the corners). */
    public const string APPLE_TOUCH_ICON = '/apple-touch-icon.png';

    /**
     * The manifest. The id stays `/` so the installed app keeps its identity
     * if the start page ever moves; the start page is `/start`, which lands a
     * signed-in account by role (a guest is sent on to the login).
     *
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        $locale = Translations::default();
        $name = Config::string('app.name');

        return [
            'id' => '/',
            'name' => $name,
            'short_name' => $name,
            'description' => __('welcome.meta.description', [], $locale),
            'lang' => $locale,
            'dir' => 'ltr',
            'start_url' => route('start', absolute: false),
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => self::THEME_COLOR,
            'background_color' => self::BACKGROUND_COLOR,
            'categories' => ['education'],
            'icons' => self::ICONS,
        ];
    }
}
