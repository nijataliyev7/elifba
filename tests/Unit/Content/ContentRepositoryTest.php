<?php

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Enums\Effect;
use App\Domain\Content\Enums\ItemStyle;
use App\Domain\Content\Enums\License;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\SlotStyle;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Syllabifier;
use Illuminate\Support\Facades\File;
use Tests\Support\ContentFixture;

/*
 * ContentRepository (docs/architecture.md §3): the one reader of levels,
 * prompts, credits and packs.
 */

it('is one instance per request over content/ that never reads a compiled file in tests', function (): void {
    $content = app(ContentRepository::class);

    expect($content)->toBe(app(ContentRepository::class))
        ->and($content->root())->toBe(base_path('content'))
        ->and($content->compiledPath())->toBeNull()
        ->and($content->readsCompiled())->toBeFalse();
});

it('reads the fruit pack with its look, words and sentences', function (): void {
    $fruit = app(ContentRepository::class)->pack('fruit');

    expect($fruit->key)->toBe('fruit')
        ->and($fruit->version)->toBeGreaterThanOrEqual(1)
        ->and($fruit->title->in('az'))->toBe('Meyvələr')
        ->and($fruit->title->in('en'))->toBe('Fruit')
        ->and($fruit->style->item)->toBe(ItemStyle::Bubble)
        ->and($fruit->style->slot)->toBe(SlotStyle::Frame)
        ->and($fruit->style->effect)->toBe(Effect::Burst)
        ->and(count($fruit->words))->toBeGreaterThanOrEqual(20)
        ->and(count($fruit->sentences))->toBeGreaterThanOrEqual(10);
});

it('ships exactly the six built-in packs, each with at least 20 words and 10 sentences', function (): void {
    $packs = app(ContentRepository::class)->packs();

    expect(array_keys($packs))->toBe(['animals', 'balloons', 'cities', 'fruit', 'symbols', 'train']);

    foreach ($packs as $key => $pack) {
        expect($pack->key)->toBe($key)
            ->and(count($pack->words))->toBeGreaterThanOrEqual(20)
            ->and(count($pack->sentences))->toBeGreaterThanOrEqual(10);
    }
});

it('gives each pack the default look of the glossary', function (string $key, ItemStyle $item, SlotStyle $slot): void {
    $style = app(ContentRepository::class)->pack($key)->style;

    expect($style->item)->toBe($item)
        ->and($style->slot)->toBe($slot);
})->with([
    'train' => ['train', ItemStyle::Wagon, SlotStyle::Wagon],
    'balloons' => ['balloons', ItemStyle::Balloon, SlotStyle::Frame],
    'animals' => ['animals', ItemStyle::Card, SlotStyle::Frame],
    'cities' => ['cities', ItemStyle::Card, SlotStyle::Frame],
    'symbols' => ['symbols', ItemStyle::Card, SlotStyle::Frame],
]);

it('gives the symbols pack the sparkle effect only', function (): void {
    expect(app(ContentRepository::class)->pack('symbols')->style->effect)->toBe(Effect::Sparkle);
});

it('lets a syllables override win over the rule', function (): void {
    $fruit = app(ContentRepository::class)->pack('fruit');
    $override = $fruit->word('qreypfrut');
    $ruled = $fruit->word('portağal');

    expect($override?->syllables)->toBe(['qreyp', 'frut'])
        ->and($override?->overridden)->toBeTrue()
        ->and(Syllabifier::split('qreypfrut'))->toBe(['qreypf', 'rut'])
        ->and($ruled?->syllables)->toBe(['por', 'ta', 'ğal'])
        ->and($ruled?->overridden)->toBeFalse()
        ->and($ruled?->key())->toBe('word:portağal');
});

it('splits a sentence into its words and final mark', function (): void {
    $sentence = app(ContentRepository::class)->pack('fruit')->sentence('alma-qirmizidir');

    expect($sentence?->words())->toBe(['Alma', 'qırmızıdır'])
        ->and($sentence?->punctuation())->toBe('.')
        ->and($sentence?->key())->toBe('sentence:fruit:alma-qirmizidir');
});

it('lists the six levels of §2 in order', function (): void {
    $levels = app(ContentRepository::class)->levels();

    expect(array_keys($levels))->toBe(['letters', 'syllables-open', 'syllables-closed', 'words-short', 'words-long', 'sentences'])
        ->and(array_map(static fn (LevelData $level): Unit => $level->unit, array_values($levels)))
        ->toBe([Unit::Letter, Unit::Syllable, Unit::Syllable, Unit::Word, Unit::Word, Unit::Sentence])
        ->and($levels['letters']->allows(Mechanic::Find))->toBeTrue()
        ->and(array_map(static fn (LevelData $level): array => $level->mechanics, $levels))->toBe([
            'letters' => [Mechanic::Find, Mechanic::Pop],
            'syllables-open' => [Mechanic::Find, Mechanic::Pop, Mechanic::BuildSyllable],
            'syllables-closed' => [Mechanic::Find, Mechanic::Pop, Mechanic::BuildSyllable],
            'words-short' => [Mechanic::Find, Mechanic::Pop, Mechanic::BuildWord],
            'words-long' => [Mechanic::Find, Mechanic::Pop, Mechanic::BuildWord],
            'sentences' => [Mechanic::BuildSentence],
        ])
        ->and($levels['letters']->allows(Mechanic::BuildSyllable))->toBeFalse()
        ->and($levels['syllables-open']->shapes)->toBe(['V', 'CV', 'VC'])
        ->and($levels['syllables-closed']->shapes)->toBe(['CVC', 'VCC', 'CVCC'])
        ->and($levels['words-long']->syllables?->min)->toBe(3)
        ->and($levels['sentences']->words?->max)->toBe(4);
});

it('takes from a pack only the items that fit a level and the learned letters', function (): void {
    $content = app(ContentRepository::class);
    $fruit = $content->pack('fruit');

    expect($content->level('letters')->keysFrom($fruit))->toBe([])
        ->and($content->level('words-short')->keysFrom($fruit))->toContain('word:alma', 'word:nar', 'word:qreypfrut')
        ->not->toContain('word:portağal')
        ->and($content->level('words-long')->keysFrom($fruit))->toContain('word:portağal', 'word:avokado')
        ->and($content->level('syllables-open')->keysFrom($fruit))->toContain('syllable:al', 'syllable:ma', 'syllable:ü')
        ->not->toContain('syllable:mud')
        ->and($content->level('syllables-closed')->keysFrom($fruit))->toContain('syllable:mud', 'syllable:yürt')
        ->and($content->level('words-short')->keysFrom($fruit, ['a', 'l', 'm', 'n', 'r']))->toBe(['word:alma', 'word:nar'])
        ->and($content->level('sentences')->keysFrom($fruit))->toContain('sentence:fruit:alma-qirmizidir');
});

it('fills a prompt template without touching the inserted text', function (): void {
    $content = app(ContentRepository::class);

    expect($content->prompt(Mechanic::Find, Unit::Letter)->fill('Ə'))->toBe('«Ə» hərfini tap!')
        ->and($content->prompt(Mechanic::Find, Unit::Syllable)->fill('BA'))->toBe('«BA» hecasını tap!')
        ->and($content->prompt(Mechanic::BuildWord, Unit::Word)->takesText())->toBeFalse()
        ->and($content->prompt(Mechanic::Pop, Unit::Word)->takesText())->toBeFalse();
});

it('credits the Inter font files', function (): void {
    $credits = app(ContentRepository::class)->credits();
    $inter = array_values(array_filter($credits, static fn ($credit): bool => $credit->covers('resources/fonts/inter/Inter-Bold.woff2')));

    expect($inter)->toHaveCount(1)
        ->and($inter[0]->license)->toBe(License::Ofl)
        ->and($inter[0]->covers('resources/fonts/inter/OFL.txt'))->toBeTrue()
        ->and($inter[0]->covers('resources/fonts/other/X.woff2'))->toBeFalse();
});

it('says which pack, level or prompt does not exist', function (Closure $read): void {
    $read(app(ContentRepository::class));
})->throws(InvalidArgumentException::class)->with([
    'pack' => [fn (ContentRepository $content) => $content->pack('cars')],
    'level' => [fn (ContentRepository $content) => $content->level('paragraphs')],
    'prompt' => [fn (ContentRepository $content) => $content->prompt(Mechanic::Find, Unit::Sentence)],
]);

it('reads the files once per instance, so an edit shows on the next request', function (): void {
    $root = ContentFixture::make();
    $content = new ContentRepository($root);

    expect($content->pack('sample')->version)->toBe(1);

    $pack = json_decode(File::get("{$root}/packs/sample.json"), true);
    $pack['version'] = 2;
    File::put("{$root}/packs/sample.json", json_encode($pack, JSON_UNESCAPED_UNICODE));

    expect($content->pack('sample')->version)->toBe(1)
        ->and((new ContentRepository($root))->pack('sample')->version)->toBe(2);
});

it('pairs every mechanic with the units of §2', function (): void {
    expect(Mechanic::Find->units())->toBe([Unit::Letter, Unit::Syllable, Unit::Word])
        ->and(Mechanic::Pop->units())->toBe([Unit::Letter, Unit::Syllable, Unit::Word])
        ->and(Mechanic::BuildSyllable->units())->toBe([Unit::Syllable])
        ->and(Mechanic::BuildWord->units())->toBe([Unit::Word])
        ->and(Mechanic::BuildSentence->units())->toBe([Unit::Sentence])
        ->and(Mechanic::Find->builds())->toBeFalse()
        ->and(Mechanic::Pop->builds())->toBeFalse()
        ->and(Mechanic::BuildSyllable->builds())->toBeTrue()
        ->and(Mechanic::BuildWord->builds())->toBeTrue()
        ->and(Mechanic::BuildSentence->builds())->toBeTrue();
});
