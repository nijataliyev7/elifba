<?php

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\RangeData;
use App\Domain\Content\Data\RoomCategoryData;
use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Data\RoomShapeData;
use App\Domain\Content\Data\RoomWordData;
use App\Domain\Content\Data\WordData;
use App\Domain\Content\Enums\Effect;
use App\Domain\Content\Enums\ItemStyle;
use App\Domain\Content\Enums\License;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\RoomFamily;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Enums\RoomSequenceRule;
use App\Domain\Content\Enums\RoomSource;
use App\Domain\Content\Enums\SlotStyle;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\ContentValidator;
use App\Domain\Content\Support\RoomWords;
use App\Domain\Language\Support\Az;
use App\Domain\Language\Support\AzCollator;
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

it('ships the thirteen built-in packs, each reading pack with at least 20 words and 10 sentences', function (): void {
    $packs = app(ContentRepository::class)->packs();

    expect(array_keys($packs))->toBe(['animals', 'balloons', 'cities', 'colors', 'dinosaurs', 'fruit', 'nature', 'numbers', 'school', 'space', 'symbols', 'train', 'vehicles']);

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
    'dinosaurs' => ['dinosaurs', ItemStyle::Dino, SlotStyle::Frame],
    'fruit' => ['fruit', ItemStyle::Fruit, SlotStyle::Frame],
    'cities' => ['cities', ItemStyle::Card, SlotStyle::Frame],
    'symbols' => ['symbols', ItemStyle::Card, SlotStyle::Frame],
    'colors' => ['colors', ItemStyle::Picture, SlotStyle::Frame],
    'numbers' => ['numbers', ItemStyle::Bubble, SlotStyle::Frame],
    'space' => ['space', ItemStyle::Bubble, SlotStyle::Frame],
    'vehicles' => ['vehicles', ItemStyle::Card, SlotStyle::Frame],
    'nature' => ['nature', ItemStyle::Bubble, SlotStyle::Frame],
    'school' => ['school', ItemStyle::Card, SlotStyle::Frame],
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
            'letters' => [Mechanic::Pop, Mechanic::Find, Mechanic::FirstLetter],
            'syllables-open' => [Mechanic::Pop, Mechanic::Find, Mechanic::BuildSyllable],
            'syllables-closed' => [Mechanic::Pop, Mechanic::Find, Mechanic::BuildSyllable],
            'words-short' => [Mechanic::Pop, Mechanic::Find, Mechanic::BuildWord, Mechanic::ReadWord],
            'words-long' => [Mechanic::Pop, Mechanic::Find, Mechanic::BuildWord, Mechanic::ReadWord],
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

it('reads the room levels', function (): void {
    $content = app(ContentRepository::class);
    $levels = $content->roomLevels();
    $orders = array_values(array_filter($levels, static fn (RoomLevelData $level): bool => $level->mechanic === RoomMechanic::RecallOrder));
    $cells = array_values(array_filter($levels, static fn (RoomLevelData $level): bool => $level->mechanic === RoomMechanic::RecallCells));
    $anagrams = array_values(array_filter($levels, static fn (RoomLevelData $level): bool => $level->mechanic === RoomMechanic::Anagram));
    $spellings = array_values(array_filter($levels, static fn (RoomLevelData $level): bool => $level->mechanic === RoomMechanic::Spelling));

    // B3: «Ardıcıllığı yadda saxla» at the four difficulties, digits first,
    // then digits, letters and (B4) shapes; B4: «Xanaları yadda saxla»;
    // B5: the two logic games; B6: «Anaqram» and «Düzgün yazılış»; D3: «Şaşki»'s three clocks;
    // Y5: «101»'s three turns (ADR 038 decision 22).
    expect($content->roomVersion())->toBeGreaterThanOrEqual(5)
        ->and(array_map(static fn (RoomLevelData $level): array => [$level->mechanic, $level->difficulty], $levels))->toBe([
            [RoomMechanic::RecallOrder, 1],
            [RoomMechanic::RecallOrder, 2],
            [RoomMechanic::RecallOrder, 3],
            [RoomMechanic::RecallOrder, 4],
            [RoomMechanic::RecallCells, 1],
            [RoomMechanic::RecallCells, 2],
            [RoomMechanic::RecallCells, 3],
            [RoomMechanic::RecallCells, 4],
            [RoomMechanic::NextInSequence, 1],
            [RoomMechanic::NextInSequence, 2],
            [RoomMechanic::NextInSequence, 3],
            [RoomMechanic::NextInSequence, 4],
            [RoomMechanic::OddOneOut, 1],
            [RoomMechanic::OddOneOut, 2],
            [RoomMechanic::OddOneOut, 3],
            [RoomMechanic::OddOneOut, 4],
            [RoomMechanic::Anagram, 1],
            [RoomMechanic::Anagram, 2],
            [RoomMechanic::Anagram, 3],
            [RoomMechanic::Anagram, 4],
            [RoomMechanic::Spelling, 1],
            [RoomMechanic::Spelling, 2],
            [RoomMechanic::Spelling, 3],
            [RoomMechanic::Spelling, 4],
            [RoomMechanic::Draughts, 1],
            [RoomMechanic::Draughts, 2],
            [RoomMechanic::Draughts, 3],
            [RoomMechanic::Domino101, 1],
            [RoomMechanic::Domino101, 2],
            [RoomMechanic::Domino101, 3],
        ])
        ->and($orders[0]->kinds)->toBe([RoomItemKind::Digit])
        ->and($orders[3]->kinds)->toBe([RoomItemKind::Digit, RoomItemKind::Letter, RoomItemKind::Shape])
        ->and(RoomMechanic::RecallOrder->family())->toBe(RoomFamily::Memory)
        ->and(RoomMechanic::RecallOrder->studies())->toBeTrue()
        ->and(RoomMechanic::RecallCells->family())->toBe(RoomFamily::Memory)
        ->and(RoomMechanic::RecallCells->studies())->toBeTrue()
        ->and(RoomMechanic::RecallCells->kinds())->toBe([])
        ->and(RoomMechanic::RecallOrder->kinds())->toContain(RoomItemKind::Word)
        ->and(RoomMechanic::Anagram->family())->toBe(RoomFamily::Words)
        ->and(RoomMechanic::Spelling->family())->toBe(RoomFamily::Words)
        ->and(RoomMechanic::Anagram->studies())->toBeFalse()
        ->and(RoomMechanic::Spelling->kinds())->toBe([]);

    // No level shows a row of words yet (§12, B6 "As built"): a word needs a wider tile than the view has.
    foreach ($orders as $level) {
        expect($level->kinds)->not->toContain(RoomItemKind::Word);
    }

    // A word game studies nothing; a harder anagram has longer words and more time.
    foreach ([...$anagrams, ...$spellings] as $level) {
        expect($level->studyMs)->toBe(0)
            ->and($level->wordLength)->not->toBeNull()
            ->and($level->kinds)->toBe([]);
    }

    foreach (array_slice($anagrams, 1, null, true) as $index => $level) {
        expect($level->wordLength?->max)->toBeGreaterThan($anagrams[$index - 1]->wordLength->max ?? 0)
            ->and($level->answerMs)->toBeGreaterThan($anagrams[$index - 1]->answerMs)
            ->and($level->swaps)->toBeNull();
    }

    // Spelling: two swaps first (any two spellings differ in two letters), then one.
    expect(array_map(static fn (RoomLevelData $level): ?int => $level->swaps, $spellings))->toBe([2, 2, 1, 1]);

    // Harder is longer, studied longer and answered in more time.
    foreach (array_slice($orders, 1, null, true) as $index => $level) {
        $easier = $orders[$index - 1];

        expect($level->length?->min)->toBeGreaterThan($easier->length->max ?? 0)
            ->and($level->studyMs)->toBeGreaterThan($easier->studyMs)
            ->and($level->answerMs)->toBeGreaterThan($easier->answerMs);
    }

    // Harder is a larger grid with more cells lit, never all of them.
    foreach ($cells as $index => $level) {
        expect($level->grid)->not->toBeNull()
            ->and($level->lit)->not->toBeNull()
            ->and($level->lit->max ?? 0)->toBeLessThan($level->grid?->cells() ?? 0)
            ->and($level->kinds)->toBe([])
            ->and($level->length)->toBeNull();

        if ($index > 0) {
            $easier = $cells[$index - 1];

            expect($level->grid?->cells())->toBeGreaterThan($easier->grid?->cells() ?? 0)
                ->and($level->lit?->min)->toBeGreaterThan($easier->lit->max ?? 0)
                ->and($level->studyMs)->toBeGreaterThan($easier->studyMs)
                ->and($level->answerMs)->toBeGreaterThan($easier->answerMs);
        }
    }

    // B5: the logic games, studied for no time; a row's rules grow with the difficulty, all six at the hardest.
    $sequences = array_values(array_filter($levels, static fn (RoomLevelData $level): bool => $level->mechanic === RoomMechanic::NextInSequence));
    $odd = array_values(array_filter($levels, static fn (RoomLevelData $level): bool => $level->mechanic === RoomMechanic::OddOneOut));

    foreach ([...$sequences, ...$odd] as $level) {
        expect($level->studyMs)->toBe(0)
            ->and($level->mechanic->family())->toBe(RoomFamily::Logic)
            ->and($level->mechanic->studies())->toBeFalse()
            ->and($level->kinds)->toBe([]);
    }

    foreach ($sequences as $index => $level) {
        expect($level->terms)->toBeGreaterThanOrEqual(max(array_map(static fn (RoomSequenceRule $rule): int => $rule->minTerms(), $level->rules)))
            ->and($level->range?->max)->toBeLessThanOrEqual(999)
            ->and($level->source)->toBeNull();

        if ($index > 0) {
            expect(count($level->rules))->toBeGreaterThan(count($sequences[$index - 1]->rules));
        }
    }

    expect($sequences[0]->rules)->toBe([RoomSequenceRule::Arithmetic])
        ->and($sequences[3]->rules)->toEqualCanonicalizing(RoomSequenceRule::cases())
        ->and(array_map(static fn (RoomLevelData $level): ?RoomSource => $level->source, $odd))->toBe([RoomSource::Words, RoomSource::Numbers, RoomSource::Words, RoomSource::Numbers])
        ->and(array_map(static fn (RoomLevelData $level): ?int => $level->options, $odd))->toBe([4, 4, 5, 5]);

    expect($content->roomLevel(RoomMechanic::RecallOrder, 2))->toBe($orders[1])
        ->and($content->roomLevel(RoomMechanic::RecallCells, 3))->toBe($cells[2])
        ->and(fn () => $content->roomLevel(RoomMechanic::RecallOrder, 5))->toThrow(InvalidArgumentException::class);

    // A fixture root reads its own.
    $fixture = new ContentRepository(ContentFixture::make());

    expect(array_map(static fn (RoomLevelData $level): ?int => $level->length?->max, $fixture->roomLevels()))->toBe([3, 4, 8, 12, ...array_fill(0, 26, null)])
        ->and($fixture->roomLevel(RoomMechanic::NextInSequence, 4)->terms)->toBe(7)
        ->and($fixture->roomLevel(RoomMechanic::OddOneOut, 2)->source)->toBe(RoomSource::Numbers)
        ->and($fixture->roomLevel(RoomMechanic::Spelling, 2)->wordLength?->min)->toBe(5)
        ->and($fixture->roomLevel(RoomMechanic::Spelling, 2)->swaps)->toBe(1)
        ->and($fixture->roomLevel(RoomMechanic::RecallOrder, 4)->studyMs)->toBe(60_000)
        ->and($fixture->roomLevel(RoomMechanic::RecallCells, 4)->grid?->toArray())->toBe(['cols' => 4, 'rows' => 5])
        ->and($fixture->roomLevel(RoomMechanic::RecallCells, 3)->lit?->max)->toBe(35);
});

it('reads the word list', function (): void {
    $list = app(ContentRepository::class)->roomWords();
    $words = RoomWords::of($list, app(ContentRepository::class)->names()->names);
    $names = array_map(Az::lower(...), app(ContentRepository::class)->names()->names);

    // B6: our own list, lowercase and in Azerbaijani order, starting from
    // the packs' words (but no name and no place), with the spellings that
    // are correct too.
    expect($list)->toBeInstanceOf(RoomWordData::class)
        ->and($list->version)->toBeGreaterThanOrEqual(1)
        ->and($list->words)->toBe(AzCollator::sort($list->words))
        ->and($list->alsoCorrect)->toBe(AzCollator::sort($list->alsoCorrect))
        ->and($list->words)->toContain('alma', 'kitab', 'qələm', 'kələm', 'çiyələk')
        ->and($list->words)->not->toContain('ulduz', 'bakı', 'Bakı', 'günəş')
        ->and(array_intersect($list->words, $names))->toBe([])
        ->and(array_intersect($list->words, $list->alsoCorrect))->toBe([])
        ->and($list->alsoCorrect)->toContain('babam', 'səhər');

    foreach ($list->words as $word) {
        expect(Az::lower($word))->toBe($word)
            ->and(RoomWords::letters($word))->toBeGreaterThanOrEqual(RoomWords::MIN_LETTERS)->toBeLessThanOrEqual(RoomWords::MAX_LETTERS);
    }

    // Every length a word level draws has enough words.
    foreach ([4, 5, 6, 7, 8, 9] as $letters) {
        expect(count($words->ofLength(new RangeData($letters, $letters))))->toBeGreaterThanOrEqual(ContentValidator::MIN_ROOM_WORDS);
    }

    // What the games make of it: an anagram's letters accept both words, a
    // form is never a wrong spelling.
    expect($words->accepted('alma'))->toBe(['alma', 'amal'])
        ->and($words->accepted('operator'))->toBe(['operator', 'aeroport'])
        ->and($words->isCorrect('babam'))->toBeTrue()
        ->and($words->isCorrect('Aysel'))->toBeTrue()
        ->and($words->isCorrect('qitab'))->toBeFalse();

    // A fixture root reads its own.
    $fixture = (new ContentRepository(ContentFixture::make()))->roomWords();

    expect($fixture->words)->toHaveCount(65)
        ->and($fixture->alsoCorrect)->toBe(['babam']);
});

it('reads the room shapes', function (): void {
    $shapes = app(ContentRepository::class)->roomShapes();
    $keys = array_map(static fn (RoomShapeData $shape): string => $shape->key, $shapes);

    // B4: twelve shapes of our own, more than the longest row of any level.
    expect($shapes)->toHaveCount(12)
        ->and(array_unique($keys))->toBe($keys)
        ->and($shapes[0]->image)->toBe('rooms/daire.svg')
        ->and($shapes[0]->url())->toEndWith('/content/rooms/daire.svg?v='.$shapes[0]->version);

    foreach ($shapes as $shape) {
        expect($shape->image)->toBe("rooms/{$shape->key}.svg")
            ->and(base_path("content/assets/{$shape->image}"))->toBeFile();
    }

    // A fixture root reads its own.
    $fixture = new ContentRepository(ContentFixture::make());

    expect(array_map(static fn (RoomShapeData $shape): string => $shape->key, $fixture->roomShapes()))->toBe(['circle', 'square', 'triangle', 'star']);
});

it('reads the room categories', function (): void {
    $categories = app(ContentRepository::class)->roomCategories();
    $words = array_merge(...array_map(static fn (RoomCategoryData $category): array => $category->words, $categories));

    // B5: groups far apart in meaning, at least 8 lowercase words each, no word in two of them.
    expect(count($categories))->toBeGreaterThanOrEqual(4)
        ->and(array_map(static fn (RoomCategoryData $category): string => $category->key, $categories))->toContain('heyvanlar', 'meyveler')
        ->and(array_unique(array_map(Az::lower(...), $words)))->toHaveCount(count($words));

    foreach ($categories as $category) {
        expect(count($category->words))->toBeGreaterThanOrEqual(8);

        foreach ($category->words as $word) {
            expect($word)->toBe(Az::lower($word));
        }
    }

    // A fixture root reads its own.
    $fixture = new ContentRepository(ContentFixture::make());

    expect(array_map(static fn (RoomCategoryData $category): string => $category->key, $fixture->roomCategories()))->toBe(['fruit', 'transport', 'clothes', 'music'])
        ->and($fixture->roomCategories()[0]->words[0])->toBe('alma');
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
        ->and(Mechanic::FirstLetter->units())->toBe([Unit::Letter])
        ->and(Mechanic::BuildSyllable->units())->toBe([Unit::Syllable])
        ->and(Mechanic::BuildWord->units())->toBe([Unit::Word])
        ->and(Mechanic::ReadWord->units())->toBe([Unit::Word])
        ->and(Mechanic::BuildSentence->units())->toBe([Unit::Sentence])
        ->and(Mechanic::Count->units())->toBe([Unit::Number])
        ->and(Mechanic::Find->builds())->toBeFalse()
        ->and(Mechanic::Pop->builds())->toBeFalse()
        ->and(Mechanic::FirstLetter->builds())->toBeFalse()
        ->and(Mechanic::ReadWord->builds())->toBeFalse()
        ->and(Mechanic::Count->builds())->toBeFalse()
        ->and(Mechanic::BuildSyllable->builds())->toBeTrue()
        ->and(Mechanic::BuildWord->builds())->toBeTrue()
        ->and(Mechanic::BuildSentence->builds())->toBeTrue();
});

it('lists the units each pack has content for: pictures and numbers in the full packs alongside reading, and single-unit packs for colours and numbers', function (): void {
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
        'dinosaurs' => [...$reading, 'picture', 'number'],
        'fruit' => [...$reading, 'picture', 'number'],
        'nature' => [...$reading, 'picture', 'number'],
        'numbers' => ['number'],
        'school' => [...$reading, 'picture', 'number'],
        'space' => [...$reading, 'picture', 'number'],
        'symbols' => $reading,
        'train' => $reading,
        'vehicles' => [...$reading, 'picture', 'number'],
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
