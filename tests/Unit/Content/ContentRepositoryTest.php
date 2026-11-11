<?php

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\WordData;
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
        ->and($fruit->style->item)->toBe(ItemStyle::Fruit)
        ->and($fruit->style->slot)->toBe(SlotStyle::Frame)
        ->and($fruit->style->effect)->toBe(Effect::Burst)
        ->and(count($fruit->words))->toBeGreaterThanOrEqual(20)
        ->and(count($fruit->sentences))->toBeGreaterThanOrEqual(10);
});

it('ships exactly the eight built-in packs, each reading pack with at least 20 words and 10 sentences', function (): void {
    $packs = app(ContentRepository::class)->packs();

    expect(array_keys($packs))->toBe(['animals', 'balloons', 'cities', 'colors', 'fruit', 'numbers', 'symbols', 'train']);

    foreach ($packs as $key => $pack) {
        expect($pack->key)->toBe($key);

        if ($pack->lists(Unit::Word)) {
            expect(count($pack->words))->toBeGreaterThanOrEqual(20)
                ->and(count($pack->sentences))->toBeGreaterThanOrEqual(10);
        } else {
            expect($pack->sentences)->toBe([], $key);
        }
    }
});

it('ships the eleven colours of ADR 012 as one swatch each, without «mavi»', function (): void {
    $colors = app(ContentRepository::class)->pack('colors');
    $pictures = array_map(static fn (WordData $word): ?string => $word->image, $colors->words);

    expect($colors->title->in('az'))->toBe('Rənglər')
        ->and($colors->units)->toBe([Unit::Picture])
        ->and($colors->style->item)->toBe(ItemStyle::Picture)
        ->and(array_map(static fn (WordData $word): string => $word->text, $colors->words))
        ->toBe(['qırmızı', 'sarı', 'yaşıl', 'göy', 'narıncı', 'bənövşəyi', 'çəhrayı', 'qəhvəyi', 'qara', 'ağ', 'boz'])
        ->and($colors->word('mavi'))->toBeNull()
        ->and(array_unique($pictures))->toHaveCount(11);

    // The same swatch in every file (ADR 014 "Colour swatches"): with the
    // comment, the colours and the file's name in the ids taken out, the
    // files are alike, so only the colour tells two items apart.
    $svgs = array_map(static fn (?string $image): string => File::get(base_path("content/assets/{$image}")), $pictures);
    $shapes = array_map(
        static fn (string $svg): string => (string) preg_replace(['/<!--.*?-->/s', '/"#[0-9A-F]{6}"/', '/\b[a-z]+_(fill|blur)/'], ['', '""', 'swatch_$1'], $svg),
        $svgs,
    );

    expect(array_unique($shapes))->toHaveCount(1);

    // Its light and shade keep the base's hue: one swatch never shows two
    // colours, as Fluent's own circles do.
    $hue = static function (string $hex): ?float {
        [$r, $g, $b] = array_map(static fn (string $pair): float => hexdec($pair) / 255, str_split(substr($hex, 1), 2));
        $max = max($r, $g, $b);
        $range = $max - min($r, $g, $b);

        if ($range < 0.02) {
            return null;
        }

        $hue = match ($max) {
            $r => fmod(($g - $b) / $range + 6, 6),
            $g => ($b - $r) / $range + 2,
            default => ($r - $g) / $range + 4,
        };

        return $hue * 60;
    };

    foreach ($svgs as $index => $svg) {
        preg_match('/<radialGradient[^>]*>(.*?)<\/radialGradient>/s', $svg, $base);
        preg_match_all('/stop-color="(#[0-9A-F]{6})"/', $base[1] ?? '', $stops);
        $hues = array_map($hue, $stops[1]);

        expect($stops[1])->toHaveCount(3, (string) $pictures[$index]);

        foreach ($hues as $one) {
            $apart = abs(($one ?? 0.0) - ($hues[1] ?? 0.0));

            expect(min($apart, 360 - $apart))->toBeLessThan(4, (string) $pictures[$index]);
        }
    }
});

it('ships things to count with a picture each and no digit in the numbers pack', function (): void {
    $numbers = app(ContentRepository::class)->pack('numbers');

    expect($numbers->title->in('az'))->toBe('Rəqəmlər')
        ->and($numbers->units)->toBe([Unit::Number])
        ->and(count($numbers->pictured()))->toBeGreaterThanOrEqual(8)
        ->and($numbers->pictured())->toHaveCount(count($numbers->words));

    foreach ($numbers->words as $word) {
        expect($word->image)->toMatch('#^(fluent|numbers)/#')
            ->and(File::get(base_path("content/assets/{$word->image}")))->not->toContain('<text');
    }
});

it('gives each pack the default look of the glossary', function (string $key, ItemStyle $item, SlotStyle $slot): void {
    $style = app(ContentRepository::class)->pack($key)->style;

    expect($style->item)->toBe($item)
        ->and($style->slot)->toBe($slot);
})->with([
    'train' => ['train', ItemStyle::Wagon, SlotStyle::Wagon],
    'balloons' => ['balloons', ItemStyle::Balloon, SlotStyle::Frame],
    'animals' => ['animals', ItemStyle::Animal, SlotStyle::Frame],
    'fruit' => ['fruit', ItemStyle::Fruit, SlotStyle::Frame],
    'cities' => ['cities', ItemStyle::Card, SlotStyle::Frame],
    'symbols' => ['symbols', ItemStyle::Card, SlotStyle::Frame],
    'colors' => ['colors', ItemStyle::Picture, SlotStyle::Frame],
    'numbers' => ['numbers', ItemStyle::Bubble, SlotStyle::Frame],
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

it('lists the ten levels of §2 in order', function (): void {
    $levels = app(ContentRepository::class)->levels();

    expect(array_keys($levels))->toBe(['letters', 'syllables-open', 'syllables-closed', 'words-short', 'words-long', 'sentences', 'pictures-easy', 'pictures', 'numbers-5', 'numbers-10'])
        ->and(array_map(static fn (LevelData $level): Unit => $level->unit, array_values($levels)))
        ->toBe([Unit::Letter, Unit::Syllable, Unit::Syllable, Unit::Word, Unit::Word, Unit::Sentence, Unit::Picture, Unit::Picture, Unit::Number, Unit::Number])
        ->and($levels['letters']->allows(Mechanic::Find))->toBeTrue()
        ->and(array_map(static fn (LevelData $level): array => $level->mechanics, $levels))->toBe([
            'letters' => [Mechanic::Pop, Mechanic::Find],
            'syllables-open' => [Mechanic::Pop, Mechanic::Find, Mechanic::BuildSyllable],
            'syllables-closed' => [Mechanic::Pop, Mechanic::Find, Mechanic::BuildSyllable],
            'words-short' => [Mechanic::Pop, Mechanic::Find, Mechanic::BuildWord],
            'words-long' => [Mechanic::Pop, Mechanic::Find, Mechanic::BuildWord],
            'sentences' => [Mechanic::BuildSentence],
            'pictures-easy' => [Mechanic::Pop, Mechanic::Find],
            'pictures' => [Mechanic::Pop, Mechanic::Find],
            'numbers-5' => [Mechanic::Pop, Mechanic::Find, Mechanic::Count],
            'numbers-10' => [Mechanic::Pop, Mechanic::Find, Mechanic::Count],
        ])
        ->and(array_map(static fn (LevelData $level): ?int => $level->items, array_slice($levels, 6)))->toBe([
            'pictures-easy' => 2,
            'pictures' => 4,
            'numbers-5' => 3,
            'numbers-10' => 4,
        ])
        ->and([$levels['numbers-5']->numbers?->min, $levels['numbers-5']->numbers?->max])->toBe([1, 5])
        ->and([$levels['numbers-10']->numbers?->min, $levels['numbers-10']->numbers?->max])->toBe([1, 10])
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
    expect(Mechanic::Find->units())->toBe([Unit::Letter, Unit::Syllable, Unit::Word, Unit::Picture, Unit::Number])
        ->and(Mechanic::Pop->units())->toBe([Unit::Letter, Unit::Syllable, Unit::Word, Unit::Picture, Unit::Number])
        ->and(Mechanic::BuildSyllable->units())->toBe([Unit::Syllable])
        ->and(Mechanic::BuildWord->units())->toBe([Unit::Word])
        ->and(Mechanic::BuildSentence->units())->toBe([Unit::Sentence])
        ->and(Mechanic::Count->units())->toBe([Unit::Number])
        ->and(Mechanic::Find->builds())->toBeFalse()
        ->and(Mechanic::Pop->builds())->toBeFalse()
        ->and(Mechanic::Count->builds())->toBeFalse()
        ->and(Mechanic::BuildSyllable->builds())->toBeTrue()
        ->and(Mechanic::BuildWord->builds())->toBeTrue()
        ->and(Mechanic::BuildSentence->builds())->toBeTrue();
});

it('lists the units each pack has content for: pictures in the animals, the fruit and the colours, numbers in the animals, the fruit and the numbers', function (): void {
    $units = array_map(
        static fn (PackData $pack): array => array_map(static fn (Unit $unit): string => $unit->value, $pack->units),
        app(ContentRepository::class)->packs(),
    );
    $reading = ['letter', 'syllable', 'word', 'sentence'];

    expect($units)->toBe([
        'animals' => [...$reading, 'picture', 'number'],
        'balloons' => $reading,
        'cities' => $reading,
        'colors' => ['picture'],
        'fruit' => [...$reading, 'picture', 'number'],
        'numbers' => ['number'],
        'symbols' => $reading,
        'train' => $reading,
    ])
        ->and(Unit::reading())->toBe([Unit::Letter, Unit::Syllable, Unit::Word, Unit::Sentence])
        ->and(Unit::Picture->reads())->toBeFalse()
        ->and(Unit::Number->reads())->toBeFalse();
});

it('takes a picture level\'s words with a picture and a number level\'s range, whatever letters are learned', function (): void {
    $content = app(ContentRepository::class);
    $animals = $content->pack('animals');
    $pictured = array_map(static fn (WordData $word): string => $word->key(), $animals->words);

    expect($content->level('pictures')->keysFrom($animals))->toBe($pictured)
        ->and($content->level('pictures')->keysFrom($animals, ['a', 't']))->toBe($pictured)
        ->and($content->level('pictures')->keysFrom($animals->withWords([...$animals->words, new WordData('Aysel', null, ['Ay', 'sel'], false)])))->toBe($pictured)
        ->and($content->level('numbers-5')->keysFrom($animals, ['a']))->toBe(['number:1', 'number:2', 'number:3', 'number:4', 'number:5'])
        ->and($content->level('numbers-10')->keysFrom($animals))->toHaveCount(10);
});
