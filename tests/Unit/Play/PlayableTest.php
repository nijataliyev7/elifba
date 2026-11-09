<?php

use App\Domain\Content\Enums\Effect;
use App\Domain\Content\Enums\ItemStyle;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\SlotStyle;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Support\Mechanics\BuildSentenceRoundBuilder;
use App\Domain\Play\Support\Mechanics\BuildSyllableRoundBuilder;
use App\Domain\Play\Support\Mechanics\BuildWordRoundBuilder;
use App\Domain\Play\Support\Mechanics\FindRoundBuilder;
use App\Domain\Play\Support\Playable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * The one list of what the board can draw (docs/architecture.md §2). A6:
 * `find` in the `balloon` style with the `burst` effect. A8.1 lists the
 * build mechanics with their builders, A8.2 draws every item style and
 * `sparkle`, A8.3 the slot styles `wagon` and `frame`: every mechanic plays
 * in every pack. tests/Fixtures/playable.json pins the lists for Vitest,
 * which checks each key has its code in resources/js/game/styles/registry.ts.
 */

const PLAYABLE_FIXTURE = __DIR__.'/../../Fixtures/playable.json';

it('lists what the board draws and a builder for every mechanic', function (): void {
    expect(Playable::mechanics())->toBe([Mechanic::Find, Mechanic::BuildSyllable, Mechanic::BuildWord, Mechanic::BuildSentence])
        ->and(Playable::mechanics())->toBe(Mechanic::cases())
        ->and(Playable::itemStyles())->toBe(ItemStyle::cases())
        ->and(Playable::slotStyles())->toBe([SlotStyle::Wagon, SlotStyle::Frame])
        ->and(Playable::slotStyles())->toBe(SlotStyle::cases())
        ->and(Playable::effects())->toBe([Effect::Burst, Effect::Sparkle])
        ->and(Playable::effects())->toBe(Effect::cases())
        ->and(Playable::builder(Mechanic::Find))->toBeInstanceOf(FindRoundBuilder::class)
        ->and(Playable::builder(Mechanic::BuildSyllable))->toBeInstanceOf(BuildSyllableRoundBuilder::class)
        ->and(Playable::builder(Mechanic::BuildWord))->toBeInstanceOf(BuildWordRoundBuilder::class)
        ->and(Playable::builder(Mechanic::BuildSentence))->toBeInstanceOf(BuildSentenceRoundBuilder::class);
});

it('starts every build mechanic at every level that has it, in every pack, with all letters learned', function (): void {
    $content = app(ContentRepository::class);
    $count = 0;

    foreach ([Mechanic::BuildSyllable, Mechanic::BuildWord, Mechanic::BuildSentence] as $mechanic) {
        foreach ($content->levels() as $level) {
            if (! $level->allows($mechanic)) {
                continue;
            }

            foreach ($content->packs() as $pack) {
                expect(Playable::builder($mechanic)->shortage($pack, $level, Alphabet::letters()))->toBeNull()
                    ->and(Playable::reason($mechanic, $pack, $level, Alphabet::letters()))
                    ->toBeNull("{$mechanic->value} × {$level->key} × {$pack->key}");
                $count++;
            }
        }
    }

    // syllables-open, syllables-closed, words-short, words-long, sentences.
    expect($count)->toBe(5 * 6);
});

it('plays a pack only when the board draws its look, its effect and, for a build, its slots', function (): void {
    foreach (app(ContentRepository::class)->packs() as $pack) {
        $look = in_array($pack->style->item, Playable::itemStyles(), true) && in_array($pack->style->effect, Playable::effects(), true);

        expect(Playable::draws(Mechanic::Find, $pack))->toBe($look, $pack->key);

        foreach ([Mechanic::BuildSyllable, Mechanic::BuildWord, Mechanic::BuildSentence] as $mechanic) {
            expect(Playable::draws($mechanic, $pack))
                ->toBe($look && in_array($pack->style->slot, Playable::slotStyles(), true), "{$mechanic->value} × {$pack->key}");
        }
    }
});

it('draws every mechanic in every pack, the train with wagons and the rest with frames', function (): void {
    $packs = app(ContentRepository::class)->packs();

    expect($packs)->toHaveCount(6);

    foreach ($packs as $pack) {
        expect($pack->style->slot)->toBe($pack->key === 'train' ? SlotStyle::Wagon : SlotStyle::Frame, $pack->key);

        foreach (Mechanic::cases() as $mechanic) {
            expect(Playable::draws($mechanic, $pack))->toBeTrue("{$mechanic->value} × {$pack->key}");
        }
    }
});

it('never bursts the national symbols or the cities', function (): void {
    $content = app(ContentRepository::class);
    $sparkling = array_values(array_filter($content->packs(), static fn ($pack): bool => $pack->style->effect === Effect::Sparkle));

    expect(array_map(static fn ($pack): string => $pack->key, $sparkling))->toBe(['cities', 'symbols']);

    foreach ($sparkling as $pack) {
        foreach ($content->levels() as $level) {
            if (! $level->allows(Mechanic::Find)) {
                continue;
            }

            $setup = new GameSetupData(Mechanic::Find, $pack->key, $level->key, 10, LetterCase::Upper);

            foreach (Playable::builder(Mechanic::Find)->build($setup, $pack, Alphabet::letters(), new Randomizer(new Mt19937(7))) as $round) {
                expect($round->effect)->toBe(Effect::Sparkle, "{$pack->key} × {$level->key}")
                    ->and($round->effect)->not->toBe(Effect::Burst);
            }
        }
    }
});

it('gives the round the pack\'s own effect', function (): void {
    $content = app(ContentRepository::class);

    foreach ($content->packs() as $pack) {
        $setup = new GameSetupData(Mechanic::Find, $pack->key, 'letters', 5, LetterCase::Upper);

        foreach (Playable::builder(Mechanic::Find)->build($setup, $pack, Alphabet::letters(), new Randomizer(new Mt19937(1))) as $round) {
            expect($round->effect)->toBe($pack->style->effect);
        }
    }
});

it('pins the lists Vitest checks against the board\'s registry', function (): void {
    $fixture = json_decode((string) file_get_contents(PLAYABLE_FIXTURE), true, 8, JSON_THROW_ON_ERROR);
    $values = static fn (array $cases): array => array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);

    expect($fixture)->toBe([
        'item_styles' => $values(Playable::itemStyles()),
        'slot_styles' => $values(Playable::slotStyles()),
        'effects' => $values(Playable::effects()),
    ]);
});

it('says why a game cannot start, first the level, then the board, then the content', function (): void {
    $content = app(ContentRepository::class);
    $balloons = $content->pack('balloons');

    // The board draws every key of the closed lists since A8.3, so no
    // pack reaches `not_playable` today; it stays for the next style.
    expect(Playable::reason(Mechanic::Find, $balloons, $content->level('sentences'), Alphabet::letters()))->toBe(__('games.setup.errors.level_mechanic'))
        ->and(Playable::reason(Mechanic::Find, $balloons, $content->level('words-short'), Alphabet::letters(), ['a', 'b']))->toBe(__('games.setup.errors.letters_level'))
        ->and(Playable::reason(Mechanic::BuildWord, $balloons, $content->level('words-short'), Alphabet::letters()))->toBeNull()
        ->and(Playable::reason(Mechanic::BuildWord, $content->pack('symbols'), $content->level('words-short'), ['a']))->toBe(__('games.setup.errors.too_few_words', ['count' => 2, 'found' => 0]))
        ->and(Playable::reason(Mechanic::BuildSentence, $content->pack('train'), $content->level('sentences'), ['a', 'n']))->toBe(__('games.setup.errors.too_few_sentences', ['count' => 2, 'found' => 0]))
        ->and(Playable::reason(Mechanic::Find, $content->pack('symbols'), $content->level('letters'), Alphabet::letters()))->toBeNull()
        ->and(Playable::reason(Mechanic::Find, $balloons, $content->level('letters'), ['a']))->toBe(__('games.setup.errors.too_few_letters', ['count' => 4]))
        ->and(Playable::reason(Mechanic::Find, $balloons, $content->level('letters'), Alphabet::letters()))->toBeNull();
});
