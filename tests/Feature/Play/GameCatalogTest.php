<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Content\Actions\AddCustomWord;
use App\Domain\Content\Actions\HideWord;
use App\Domain\Content\Data\CustomWordData;
use App\Domain\Content\Data\HiddenWordData;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Family\Models\Child;
use App\Domain\Play\Actions\ReplayGame;
use App\Domain\Play\Enums\SetupMode;
use App\Domain\Play\Models\SetupChoice;
use App\Domain\Play\Support\SetupMemory;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Games;

/*
 * «Yeni oyun» as a catalog of ready games (docs/frontend.md rule 13,
 * docs/architecture.md §2 "Game setup" and "Remembered setup"): the games
 * and cards the server builds — names with the pack's `with`, samples
 * without randomness — and what a class or a child chose last.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->teacherAndParent()->create();
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '1A']);
    $this->child = Child::factory()->for($this->teacher, 'parent')->create(['display_name' => 'Aysel']);
    $this->actingAs($this->teacher);
});

/**
 * @param  iterable<array{pack: string, unit: string, mechanic: string, title: string, sample: list<string>}>  $cards
 * @return array{pack: string, unit: string, mechanic: string, title: string, sample: list<string>}|null
 */
function catalogCard(iterable $cards, string $unit, string $mechanic, string $pack): ?array
{
    foreach ($cards as $card) {
        if ($card['unit'] === $unit && $card['mechanic'] === $mechanic && $card['pack'] === $pack) {
            return $card;
        }
    }

    return null;
}

describe('the catalog', function (): void {
    it('lists every game some level offers, in catalog order, with its mode, name and line', function (): void {
        $this->get(route('classes.games.create', $this->class))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('games', fn ($games): bool => expect(collect($games)->map(fn ($game): string => "{$game['unit']}_{$game['mechanic']}")->all())->toBe([
                    'letter_pop', 'letter_find',
                    'syllable_pop', 'syllable_find', 'syllable_build_syllable',
                    'word_pop', 'word_find', 'word_build_word',
                    'sentence_build_sentence',
                    'picture_pop', 'picture_find',
                    'number_pop', 'number_find', 'number_count',
                ]) !== null)
                ->where('games.0', [
                    'unit' => 'letter',
                    'mechanic' => 'pop',
                    'mode' => 'easy',
                    'name' => 'Hərf vur',
                    'hint' => __('games.setup.mechanic_hints.pop'),
                ])
                ->where('games.4.mode', 'normal')
                ->where('games.4.name', 'Heca qur')
                // «De və vur» where nothing is read.
                ->where('games.9.hint', __('games.setup.mechanic_hints.pop_say'))
                ->where('games.13', [
                    'unit' => 'number',
                    'mechanic' => 'count',
                    'mode' => 'normal',
                    'name' => 'Say',
                    'hint' => __('games.setup.mechanic_hints.count'),
                ]));
    });

    it('gives one card per pack and game, named with the pack\'s with', function (): void {
        $content = app(ContentRepository::class);

        $this->get(route('classes.games.create', $this->class))
            ->assertInertia(fn (Assert $page) => $page
                // 6 reading packs × 9 reading games, 3 picture packs × 2, 3 number packs × 3.
                ->has('cards', 54 + 6 + 9)
                ->where('cards.0', [
                    'pack' => 'animals',
                    'unit' => 'letter',
                    'mechanic' => 'pop',
                    'title' => 'Heyvanlarla hərf vur',
                    'sample' => ['A', 'B', 'C'],
                ])
                ->where('cards', function ($cards) use ($content): bool {
                    $train = $content->pack('train');

                    expect(catalogCard($cards, 'sentence', 'build_sentence', 'train'))->toBe([
                        'pack' => 'train',
                        'unit' => 'sentence',
                        'mechanic' => 'build_sentence',
                        'title' => 'Qatarla cümlə qur',
                        // The words of its first sentence, as written.
                        'sample' => array_slice($train->sentences[0]->words(), 0, 3),
                    ])
                        ->and(catalogCard($cards, 'number', 'count', 'fruit'))->toBe([
                            'pack' => 'fruit',
                            'unit' => 'number',
                            'mechanic' => 'count',
                            'title' => 'Meyvələrlə say',
                            'sample' => ['2', '3', '4'],
                        ])
                        ->and(catalogCard($cards, 'picture', 'find', 'animals'))->toBe([
                            'pack' => 'animals',
                            'unit' => 'picture',
                            'mechanic' => 'find',
                            'title' => 'Heyvanlarla şəkil tap',
                            'sample' => [],
                        ])
                        ->and(catalogCard($cards, 'number', 'find', 'numbers')['sample'] ?? null)->toBe(['1', '2', '3'])
                        // The syllables of its first word, as split from it.
                        ->and(catalogCard($cards, 'word', 'build_word', 'cities')['sample'] ?? null)
                        ->toBe(array_slice($content->pack('cities')->words[0]->syllables, 0, 3))
                        // A syllable level is lowercase as written; three of its pool.
                        ->and(catalogCard($cards, 'syllable', 'find', 'balloons')['sample'] ?? [])->toHaveCount(3)
                        ->and(catalogCard($cards, 'syllable', 'build_syllable', 'balloons')['sample'] ?? null)
                        ->each->toMatch('/^\p{Ll}$/u')
                        // No picture or number game for a pack that does not list the unit.
                        ->and(catalogCard($cards, 'picture', 'pop', 'train'))->toBeNull()
                        ->and(catalogCard($cards, 'letter', 'find', 'colors'))->toBeNull();

                    // Within a game, the packs' order.
                    expect(collect($cards)->where('mechanic', 'find')->where('unit', 'letter')->pluck('pack')->all())
                        ->toBe(['animals', 'balloons', 'cities', 'fruit', 'symbols', 'train']);

                    return true;
                }));
    });

    it('names the cards in English with the English with', function (): void {
        $this->teacher->update(['locale' => 'en']);

        $this->get(route('classes.games.create', $this->class))
            ->assertInertia(fn (Assert $page) => $page
                ->where('games.1.name', 'Find the letter')
                ->where('cards', fn ($cards): bool => catalogCard($cards, 'sentence', 'build_sentence', 'train')['title'] === 'Build a sentence with the train'
                    && catalogCard($cards, 'number', 'count', 'fruit')['title'] === 'Count with fruit'));
    });

    it('shows no sample on a card that cannot start', function (): void {
        $this->class->update(['learned_letters' => ['a', 'b', 'ə']]);

        $this->get(route('classes.games.create', $this->class))
            ->assertInertia(fn (Assert $page) => $page
                ->where('cards', fn ($cards): bool => catalogCard($cards, 'letter', 'find', 'balloons')['sample'] === []
                    && catalogCard($cards, 'syllable', 'pop', 'balloons')['sample'] === []
                    // Pictures and numbers are not read: they still start.
                    && catalogCard($cards, 'number', 'count', 'fruit')['sample'] === ['2', '3', '4']));
    });

    it('never samples a teacher word or a word the owner hid', function (): void {
        $content = app(ContentRepository::class);
        $first = $content->pack('cities')->words[0];
        app(HideWord::class)->handle($this->teacher, HiddenWordData::validate(['pack' => 'cities', 'word' => $first->text]));
        app(AddCustomWord::class)->handle($this->teacher, CustomWordData::validate(['pack' => 'cities', 'text' => 'Aysel']));

        $this->get(route('classes.games.create', $this->class))
            ->assertInertia(fn (Assert $page) => $page
                ->where('cards', function ($cards) use ($first): bool {
                    $words = catalogCard($cards, 'word', 'find', 'cities')['sample'] ?? [];

                    expect($words)->toHaveCount(3)
                        ->not->toContain($first->text)
                        ->not->toContain('Aysel');

                    return true;
                }));
    });

    it('sends the remembered setup, Normal for a new class and Easy for a new child', function (): void {
        $this->get(route('classes.games.create', $this->class))
            ->assertInertia(fn (Assert $page) => $page
                ->where('remembered', ['mode' => 'normal', 'levels' => [], 'letterCases' => [], 'letters' => null, 'ownWords' => null]));

        $this->get(route('children.games.create', $this->child))
            ->assertInertia(fn (Assert $page) => $page
                ->where('remembered.mode', 'easy'));
    });

    it('draws a card\'s sample at the remembered level, with the remembered case', function (): void {
        Games::start($this->class, ['mechanic' => 'pop', 'pack' => 'balloons', 'level' => 'syllables-closed', 'letter_case' => 'upper']);

        $this->get(route('classes.games.create', $this->class))
            ->assertInertia(fn (Assert $page) => $page
                ->where('remembered.levels', ['syllable' => 'syllables-closed'])
                ->where('remembered.letterCases', ['syllable' => 'upper'])
                ->where('cards', function ($cards): bool {
                    // A closed syllable ends in a consonant, here in capitals.
                    foreach (catalogCard($cards, 'syllable', 'find', 'train')['sample'] ?? [] as $syllable) {
                        expect($syllable)->toBe(mb_strtoupper($syllable))->toHaveLength(mb_strlen($syllable))
                            ->and(mb_strlen($syllable))->toBeGreaterThanOrEqual(2);
                    }

                    expect(catalogCard($cards, 'syllable', 'find', 'train')['sample'] ?? [])->toHaveCount(3)
                        // Another unit keeps its own default: letters in capitals, words as written.
                        ->and(catalogCard($cards, 'letter', 'find', 'train')['sample'] ?? null)->toBe(['A', 'B', 'C']);

                    return true;
                }));
    });
});

describe('the mode', function (): void {
    it('keeps the mode a class taps and answers with a redirect back', function (): void {
        $this->from(route('classes.games.create', $this->class))
            ->put(route('classes.games.mode', $this->class), ['mode' => 'easy'])
            ->assertRedirect(route('classes.games.create', $this->class));

        expect(SetupChoice::query()->sole())
            ->school_class_id->toBe($this->class->id)
            ->child_id->toBeNull()
            ->mode->toBe(SetupMode::Easy);

        $this->put(route('classes.games.mode', $this->class), ['mode' => 'normal']);

        expect(SetupChoice::query()->sole()->mode)->toBe(SetupMode::Normal);
    });

    it('keeps the mode a child taps', function (): void {
        $this->put(route('children.games.mode', $this->child), ['mode' => 'normal'])->assertRedirect();

        expect(SetupChoice::query()->sole())
            ->child_id->toBe($this->child->id)
            ->mode->toBe(SetupMode::Normal);

        $this->get(route('children.games.create', $this->child))
            ->assertInertia(fn (Assert $page) => $page->where('remembered.mode', 'normal'));
    });

    it('refuses a mode that does not exist', function (mixed $mode): void {
        $this->put(route('classes.games.mode', $this->class), ['mode' => $mode])->assertSessionHasErrors('mode');

        expect(SetupChoice::query()->count())->toBe(0);
    })->with([['hard'], [null], [['easy']]]);

    it('hides an archived class from the mode as from its setup page', function (): void {
        $this->class->update(['archived_at' => now()]);

        $this->put(route('classes.games.mode', $this->class), ['mode' => 'easy'])->assertNotFound();
    });
});

describe('starting a game', function (): void {
    it('remembers the mode, the level, the case, the letters and own words it started with', function (): void {
        Games::start($this->class, ['mechanic' => 'find', 'level' => 'letters', 'letter_case' => 'lower', 'letters' => ['a', 'ə']]);

        expect(SetupMemory::recall(Games::players($this->class)))->toBe([
            'mode' => 'normal',
            'levels' => ['letter' => 'letters'],
            'letterCases' => ['letter' => 'lower'],
            'letters' => ['a', 'ə'],
            'ownWords' => null,
        ]);

        foreach (['nənə', 'baba', 'bibi', 'xala'] as $word) {
            app(AddCustomWord::class)->handle($this->teacher, CustomWordData::validate(['pack' => 'balloons', 'text' => $word]));
        }

        Games::start($this->class, ['mechanic' => 'pop', 'level' => 'words-short', 'own_words' => true]);

        expect(SetupMemory::recall(Games::players($this->class)))->toBe([
            'mode' => 'easy',
            'levels' => ['letter' => 'letters', 'word' => 'words-short'],
            'letterCases' => ['letter' => 'lower', 'word' => 'lower'],
            'letters' => ['a', 'ə'],
            'ownWords' => true,
        ]);

        // build_word takes no own words, and a picture level reads nothing.
        Games::start($this->class, ['mechanic' => 'build_word', 'level' => 'words-long', 'letter_case' => 'upper']);
        Games::start($this->class, ['mechanic' => 'find', 'pack' => 'fruit', 'level' => 'pictures']);

        expect(SetupMemory::recall(Games::players($this->class)))->toBe([
            'mode' => 'normal',
            'levels' => ['letter' => 'letters', 'word' => 'words-long', 'picture' => 'pictures'],
            'letterCases' => ['letter' => 'lower', 'word' => 'upper'],
            'letters' => ['a', 'ə'],
            'ownWords' => true,
        ]);
    });

    it('keeps «Hamısı» only when the owner\'s own words could have played instead', function (): void {
        // No own words yet: «Hamısı» was no choice.
        Games::start($this->class, ['mechanic' => 'find', 'level' => 'words-short']);

        expect(SetupMemory::recall(Games::players($this->class))['ownWords'])->toBeNull();

        foreach (['nənə', 'baba', 'bibi', 'xala'] as $word) {
            app(AddCustomWord::class)->handle($this->teacher, CustomWordData::validate(['pack' => 'balloons', 'text' => $word]));
        }

        Games::start($this->class, ['mechanic' => 'find', 'level' => 'words-short', 'own_words' => false]);

        expect(SetupMemory::recall(Games::players($this->class))['ownWords'])->toBeFalse();
    });

    it('forgets the letters when the letter level is started with all of them', function (): void {
        Games::start($this->class, ['letters' => ['a', 'ə']]);
        Games::start($this->class);

        expect(SetupMemory::recall(Games::players($this->class))['letters'])->toBeNull();
    });

    it('remembers a child\'s game for the child only', function (): void {
        Games::start($this->child, ['mechanic' => 'pop', 'pack' => 'animals', 'level' => 'pictures-easy']);

        expect(SetupMemory::recall(Games::players($this->child)))->toBe([
            'mode' => 'easy',
            'levels' => ['picture' => 'pictures-easy'],
            'letterCases' => [],
            'letters' => null,
            'ownWords' => null,
        ])
            ->and(SetupMemory::recall(Games::players($this->class))['levels'])->toBe([]);
    });

    it('remembers the same again on «Yenidən oyna»', function (): void {
        $game = Games::start($this->class, ['mechanic' => 'find', 'level' => 'syllables-open']);
        SetupChoice::query()->delete();

        app(ReplayGame::class)->handle($game);

        expect(SetupMemory::recall(Games::players($this->class))['levels'])->toBe(['syllable' => 'syllables-open']);
    });

    it('keeps only what still applies', function (): void {
        Games::start($this->class, ['letters' => ['a', 'b', 'ə']]);
        $row = SetupChoice::query()->sole();
        $row->levels = ['letter' => 'letters', 'word' => 'gone', 'syllable' => 'letters'];
        $row->letter_cases = ['letter' => 'upper', 'picture' => 'upper', 'word' => 'sideways'];
        $row->save();

        // Two of the three letters stay learned: kept.
        $this->class->update(['learned_letters' => ['a', 'ə', 'l', 'm']]);

        expect(SetupMemory::recall(Games::players($this->class->refresh())))
            ->levels->toBe(['letter' => 'letters'])
            ->letterCases->toBe(['letter' => 'upper'])
            ->letters->toBe(['a', 'ə']);

        // One left: no choice at all.
        $this->class->update(['learned_letters' => ['a', 'l', 'm', 'n']]);

        expect(SetupMemory::recall(Games::players($this->class->refresh()))['letters'])->toBeNull();
    });

    it('goes with its class or child', function (): void {
        Games::start($this->class);
        Games::start($this->child);

        expect(SetupChoice::query()->count())->toBe(2);

        $this->class->delete();
        $this->child->delete();

        expect(SetupChoice::query()->count())->toBe(0);
    });
});
