<?php

use App\Domain\Content\Enums\Effect;
use App\Domain\Content\Enums\ItemStyle;
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

it('gives every pack a sound for every moment an account may choose, a chime instead of a pop where the effect is sparkle', function (): void {
    foreach ((new ContentRepository(base_path('content')))->packs() as $pack) {
        foreach (Sound::choosable() as $sound) {
            expect($pack->style->sound($sound))->not->toBeNull();
        }

        expect($pack->style->sound(Sound::Hit))->toBe($pack->style->effect === Effect::Sparkle ? 'sounds/chime.mp3' : 'sounds/pop.mp3');
    }
});

it('gives the train its own sounds for a round arriving and leaving, and no other pack any', function (): void {
    foreach ((new ContentRepository(base_path('content')))->packs() as $pack) {
        $train = $pack->key === 'train';

        expect($pack->style->sound(Sound::Arrive))->toBe($train ? 'sounds/train-arrive.mp3' : null, $pack->key)
            ->and($pack->style->sound(Sound::Leave))->toBe($train ? 'sounds/train-leave.mp3' : null, $pack->key);
    }
});

it('accepts a pack\'s own arrive and leave sounds, which sounds.json does not list', function (): void {
    $root = ContentFixture::make([
        'file' => 'packs/sample.json',
        'set' => ['style.sounds.arrive' => 'sounds/arrive.ogg', 'style.sounds.leave' => 'sounds/pop.ogg'],
        'write' => ['assets/sounds/arrive.ogg' => ''],
    ]);

    expect((new ContentValidator($root))->validate())->toBe([]);
});

it('needs a credits entry for an arrive or leave sound, as for every asset', function (): void {
    $root = ContentFixture::make([
        'file' => 'packs/sample.json',
        'set' => ['style.sounds.leave' => 'sample/leave.ogg'],
        'write' => ['assets/sample/leave.ogg' => ''],
    ]);

    expect((new ContentValidator($root))->validate())->toBe(['credits.json: content/assets/sample/leave.ogg has no entry']);
});

it('credits every font and asset file the repository ships', function (): void {
    $problems = (new ContentValidator(base_path('content')))->validate();

    expect(array_filter($problems, static fn (string $problem): bool => str_starts_with($problem, 'credits.json')))->toBe([]);
});

/** Ten figures of the fixture pack: its word pictures and the backdrop, a picture of no word. */
const SAMPLE_FIGURES = [
    'sample/alma.svg', 'sample/words/ata.svg', 'sample/words/ana.svg', 'sample/words/nene.svg', 'sample/words/baba.svg',
    'sample/words/dede.svg', 'sample/words/baci.svg', 'sample/words/kitab.svg', 'sample/words/qelem.svg', 'sample/backdrop.svg',
];

it('accepts an animal or fruit look with enough figures, word pictures or others', function (string $item): void {
    $root = ContentFixture::make(['file' => 'packs/sample.json', 'set' => ['style.item' => $item, 'style.figures' => SAMPLE_FIGURES]]);

    expect((new ContentValidator($root))->validate())->toBe([]);
})->with(['animal', 'fruit']);

it('needs a credits entry for a figure, as for every asset', function (): void {
    $root = ContentFixture::make([
        'file' => 'packs/sample.json',
        'set' => ['style.item' => 'animal', 'style.figures' => [...array_slice(SAMPLE_FIGURES, 0, 9), 'sample/dinozavr.svg']],
        'write' => ['assets/sample/dinozavr.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 72 72"/>'],
    ]);

    expect((new ContentValidator($root))->validate())->toBe(['credits.json: content/assets/sample/dinozavr.svg has no entry']);
});

it('asks for twice as many figures as the fullest round of any level shows', function (): void {
    $content = new ContentRepository(base_path('content'));
    $most = [];

    foreach ($content->levels() as $level) {
        $most[$level->key] = $level->mostItems();
    }

    // find and pop show 4; a build round its longest answer and one decoy.
    expect($most)->toBe([
        'letters' => 4,
        'syllables-open' => 4,
        'syllables-closed' => 5,
        'words-short' => 4,
        'words-long' => 5,
        'sentences' => 5,
        'pictures-easy' => 2,
        'pictures' => 4,
        'numbers-5' => 3,
        'numbers-10' => 4,
    ])->and(ContentValidator::FIGURES_PER_ITEM * max($most))->toBe(10);

    expect(array_map(static fn (ItemStyle $style): bool => $style->hasFigures(), ItemStyle::cases()))
        ->toBe([false, false, false, false, true, true, false]);

    foreach ($content->packs() as $pack) {
        $figures = count($pack->style->figures);

        expect($pack->style->item->hasFigures() ? $figures >= 10 : $figures === 0)->toBeTrue($pack->key);
    }
});
