<?php

use App\Domain\Content\Enums\Effect;
use App\Domain\Content\Enums\Sound;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\ContentValidator;
use Tests\Support\ContentFixture;

/*
 * ContentValidator (docs/architecture.md §3, §8). The real content/ must be
 * valid; so must the small fixture root in tests/Fixtures/content/base; and
 * every case in tests/Fixtures/content/broken.json breaks that root in one
 * way and must be reported, in the file it broke.
 */

it('accepts the real content', function (): void {
    expect((new ContentValidator(base_path('content')))->validate())->toBe([]);
});

it('accepts the fixture content root', function (): void {
    expect((new ContentValidator(ContentFixture::make()))->validate())->toBe([]);
});

it('rejects each broken fixture with its problem', function (array $patch, string $problem): void {
    $problems = (new ContentValidator(ContentFixture::make($patch)))->validate();

    expect($problems)->not->toBeEmpty()
        ->and(implode("\n", $problems))->toContain($problem)
        ->and($problems)->each->toStartWith($patch['file'].': ');
})->with(ContentFixture::broken());

it('requires a picture for every word since A5 switched it on', function (): void {
    $root = ContentFixture::make(['file' => 'packs/sample.json', 'unset' => ['words.1.image']]);

    expect(ContentValidator::WORD_PICTURES_REQUIRED)->toBeTrue()
        ->and((new ContentValidator($root))->validate())
        ->toBe(['packs/sample.json: words[1] "ata" needs an `image`'])
        ->and((new ContentValidator($root, wordPicturesRequired: false))->validate())
        ->toBe([]);
});

it('gives every pack a sound for every moment, a chime instead of a pop where the effect is sparkle', function (): void {
    foreach ((new ContentRepository(base_path('content')))->packs() as $pack) {
        foreach (Sound::cases() as $sound) {
            expect($pack->style->sound($sound))->not->toBeNull();
        }

        expect($pack->style->sound(Sound::Hit))->toBe($pack->style->effect === Effect::Sparkle ? 'sounds/chime.mp3' : 'sounds/pop.mp3');
    }
});

it('credits every font and asset file the repository ships', function (): void {
    $problems = (new ContentValidator(base_path('content')))->validate();

    expect(array_filter($problems, static fn (string $problem): bool => str_starts_with($problem, 'credits.json')))->toBe([]);
});
