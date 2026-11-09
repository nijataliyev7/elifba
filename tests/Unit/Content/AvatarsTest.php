<?php

use App\Domain\Content\Support\Avatars;

/*
 * Avatars from content/avatars.json (docs/architecture.md §3, §7, §8): a
 * key and a CC0 animal picture each, sent to pages as URLs.
 */

it('ships about thirty unique avatar keys', function (): void {
    $keys = Avatars::keys();

    expect(count($keys))->toBeGreaterThanOrEqual(30)
        ->and(array_unique($keys))->toBe($keys);
});

it('uses keys that fit students.avatar', function (): void {
    foreach (Avatars::keys() as $key) {
        expect($key)->toMatch(Avatars::KEY_PATTERN)
            ->and(strlen($key))->toBeLessThanOrEqual(32);
    }
});

it('gives every avatar its own picture under content/assets', function (): void {
    $images = Avatars::images();

    expect(array_keys($images))->toBe(Avatars::keys())
        ->and(array_unique($images))->toBe($images);

    foreach ($images as $image) {
        expect(base_path("content/assets/{$image}"))->toBeFile();
    }
});

it('sends every avatar with its picture\'s public URL, in the file\'s order', function (): void {
    $all = Avatars::all();

    expect(array_column($all, 'key'))->toBe(Avatars::keys())
        ->and($all[0])->toBe(['key' => 'panda', 'url' => url('content/avatars/panda.svg')]);
});

it('knows which keys exist', function (): void {
    expect(Avatars::exists(Avatars::keys()[0]))->toBeTrue()
        ->and(Avatars::exists('unicorn-dragon'))->toBeFalse();
});
