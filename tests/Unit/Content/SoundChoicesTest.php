<?php

use App\Domain\Content\Enums\Effect;
use App\Domain\Content\Enums\Sound;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\SoundChoices;

/*
 * The sounds an account may choose, from content/sounds.json
 * (docs/architecture.md §3 "Sounds"), sent to pages as versioned URLs.
 */

it('lists sounds for every moment an account may choose, each key once', function (Sound $sound): void {
    $keys = SoundChoices::keys($sound);

    expect($keys)->not->toBeEmpty()
        ->and(array_unique($keys))->toBe($keys);

    foreach (SoundChoices::of($sound) as $choice) {
        expect($choice->key)->toMatch(SoundChoices::KEY_PATTERN)
            ->and(base_path("content/assets/{$choice->file}"))->toBeFile();
    }
})->with(Sound::choosable());

it('lists none for the moments a pack keeps to itself', function (): void {
    expect(Sound::choosable())->toBe([Sound::Hit, Sound::Miss, Sound::Solved])
        ->and(SoundChoices::of(Sound::Arrive))->toBe([])
        ->and(SoundChoices::keys(Sound::Leave))->toBe([])
        ->and(Sound::Arrive->isChoosable())->toBeFalse()
        ->and(Sound::Hit->isChoosable())->toBeTrue();
});

it('lists every pack\'s own sound, so a choice can always fall back to it', function (): void {
    foreach ((new ContentRepository(base_path('content')))->packs() as $pack) {
        foreach (Sound::choosable() as $sound) {
            $files = array_map(static fn ($choice): string => $choice->file, SoundChoices::of($sound));

            expect($files)->toContain($pack->style->sound($sound));
        }
    }
});

it('keeps the pop for packs whose items burst', function (): void {
    $pop = SoundChoices::find(Sound::Hit, 'pop');

    expect($pop)->not->toBeNull()
        ->and($pop?->suits(Effect::Burst))->toBeTrue()
        ->and($pop?->suits(Effect::Sparkle))->toBeFalse()
        ->and(SoundChoices::find(Sound::Hit, 'chime')?->suits(Effect::Sparkle))->toBeTrue()
        ->and(SoundChoices::find(Sound::Miss, 'pop'))->toBeNull();
});

it('versions its URLs like a pack\'s', function (): void {
    $choice = SoundChoices::find(Sound::Miss, 'pluck-down');

    expect($choice)->not->toBeNull()
        ->and(SoundChoices::url($choice ?? throw new LogicException))->toBe(url('content/sounds/pluck-down.mp3').'?v='.SoundChoices::version());
});
