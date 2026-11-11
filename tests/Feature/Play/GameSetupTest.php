<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Family\Models\Child;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Support\Playable;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Games;

/*
 * The setup page and POST /classes/{class}/games (docs/architecture.md §2).
 * The page and GameSetupData::rules() make the same check; isolation
 * between teachers is in TeacherIsolationTest.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '1A']);
    $this->actingAs($this->teacher);
});

/**
 * @param  list<array{mechanic: string, pack: string, level: string, drawable: bool, startable: bool, reason: string|null, code: string|null, own: array{startable: bool, reason: string|null, code: string|null}|null}>  $options
 * @return array{mechanic: string, pack: string, level: string, drawable: bool, startable: bool, reason: string|null, code: string|null, own: array{startable: bool, reason: string|null, code: string|null}|null}|null
 */
function setupOption(array $options, string $mechanic, string $pack, string $level): ?array
{
    foreach ($options as $option) {
        if ($option['mechanic'] === $mechanic && $option['pack'] === $pack && $option['level'] === $level) {
            return $option;
        }
    }

    return null;
}

describe('the setup page', function (): void {
    it('lists packs, levels, round limits and what the class can start', function (): void {
        $content = app(ContentRepository::class);

        $this->get(route('classes.games.create', $this->class))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('games/setup')
                ->where('schoolClass', ['id' => $this->class->id, 'name' => '1A'])
                ->has('packs', count($content->packs()))
                ->where('packs.1', [
                    'key' => 'balloons',
                    'title' => 'Şarlar',
                    // The theme's picture: one of its words' (`style.cover`).
                    'cover' => ContentAssets::url('fluent/1F388.svg', $content->pack('balloons')->version),
                ])
                ->has('levels', count($content->levels()))
                ->where('levels.0', ['key' => 'letters', 'title' => 'Hərflər', 'unit' => 'letter', 'mechanics' => ['pop', 'find'], 'hint' => 'lövhədə 4 hərf'])
                ->where('allLetters', true)
                // No «Son oyunlar»: the owner took them out (2026-11-11).
                ->missing('recent')
                ->where('rounds', ['min' => 5, 'max' => 30, 'default' => 10])
                ->where('wordMaxLength', 20)
                ->where('letterCases', ['upper', 'lower'])
                ->has('letters', 32)
                ->where('letters.13', ['lower' => 'i', 'upper' => 'İ'])
                ->where('options', function ($options): bool {
                    $options = $options->all();

                    // Own words (`own`) are offered at syllable and word levels
                    // but build_word; this teacher has none yet.
                    expect(setupOption($options, 'find', 'balloons', 'letters'))->toBe(['mechanic' => 'find', 'pack' => 'balloons', 'level' => 'letters', 'drawable' => true, 'startable' => true, 'reason' => null, 'code' => null, 'own' => null])
                        ->and(setupOption($options, 'find', 'balloons', 'words-short')['startable'] ?? null)->toBeTrue()
                        ->and(setupOption($options, 'find', 'symbols', 'letters'))->toBe(['mechanic' => 'find', 'pack' => 'symbols', 'level' => 'letters', 'drawable' => true, 'startable' => true, 'reason' => null, 'code' => null, 'own' => null])
                        ->and(setupOption($options, 'build_word', 'balloons', 'words-short'))->toBe(['mechanic' => 'build_word', 'pack' => 'balloons', 'level' => 'words-short', 'drawable' => true, 'startable' => true, 'reason' => null, 'code' => null, 'own' => null])
                        ->and(setupOption($options, 'build_sentence', 'train', 'sentences')['startable'] ?? null)->toBeTrue()
                        ->and(setupOption($options, 'find', 'balloons', 'sentences'))->toBeNull()
                        ->and(setupOption($options, 'pop', 'balloons', 'words-short'))->toBe(['mechanic' => 'pop', 'pack' => 'balloons', 'level' => 'words-short', 'drawable' => true, 'startable' => true, 'reason' => null, 'code' => null, 'own' => ['startable' => false, 'reason' => __('games.setup.errors.no_own_words'), 'code' => 'no_own_words']])
                        ->and(setupOption($options, 'pop', 'balloons', 'sentences'))->toBeNull();

                    // With every letter learned, every option starts: 15
                    // mechanic × level pairs of the reading levels in each of
                    // the 6 packs (A8.3, then pop at the five letter, syllable
                    // and word levels), and from R5 the 10 pairs of the picture
                    // and number levels in the 2 packs that list those units;
                    // from R6 the 4 picture pairs in the colours and the 6
                    // number pairs in the numbers.
                    expect($options)->toHaveCount(90 + 20 + 4 + 6)
                        ->and(array_unique(array_column($options, 'pack')))->toHaveCount(8)
                        ->and(array_count_values(array_column($options, 'mechanic')))->toBe(['pop' => 42, 'find' => 42, 'build_syllable' => 12, 'build_word' => 12, 'build_sentence' => 6, 'count' => 6])
                        // pop («Oxu və vur») leads, so it is the default chip.
                        ->and(array_values(array_unique(array_column($options, 'mechanic'))))->toBe(['pop', 'find', 'build_syllable', 'build_word', 'build_sentence', 'count']);

                    foreach ($options as $option) {
                        expect([$option['drawable'], $option['startable'], $option['reason']])->toBe([true, true, null], "{$option['mechanic']} × {$option['pack']} × {$option['level']}");
                    }

                    // Exactly the pairs the board can draw are startable.
                    foreach ($options as $option) {
                        $pack = app(ContentRepository::class)->pack($option['pack']);
                        $drawable = Playable::draws(Mechanic::from($option['mechanic']), $pack);

                        expect($option['drawable'])->toBe($drawable);

                        if (! $drawable) {
                            expect($option['startable'])->toBeFalse();
                        }
                    }

                    return true;
                }));
    });

    it('gives the reason when the learned letters are too few', function (): void {
        $this->class->update(['learned_letters' => ['a', 'b', 'ə']]);

        $this->get(route('classes.games.create', $this->class))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('letters', 3)
                ->where('allLetters', false)
                ->where('options', fn ($options): bool => setupOption($options->all(), 'find', 'balloons', 'letters') === [
                    'mechanic' => 'find',
                    'pack' => 'balloons',
                    'level' => 'letters',
                    'drawable' => true,
                    'startable' => false,
                    'reason' => __('games.setup.errors.too_few_letters', ['count' => 4]),
                    // The key of the reason, for «Daha çox hərf lazımdır».
                    'code' => 'too_few_letters',
                    'own' => null,
                ]));
    });

    it('shows titles and reasons in the interface language', function (): void {
        $this->teacher->update(['locale' => 'en']);
        $this->class->update(['learned_letters' => ['a', 'b', 'ə']]);

        $this->get(route('classes.games.create', $this->class))
            ->assertInertia(fn (Assert $page) => $page
                ->where('packs.1.title', 'Balloons')
                ->where('levels.0.title', 'Letters')
                ->where('options', fn ($options): bool => setupOption($options->all(), 'find', 'balloons', 'letters')['reason'] === 'This level needs at least 4 learned letters.'));
    });

    it('does not offer a level the pack does not list', function (): void {
        $this->get(route('classes.games.create', $this->class))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('levels.6', ['key' => 'pictures-easy', 'title' => 'Şəkillər: asan', 'unit' => 'picture', 'mechanics' => ['pop', 'find'], 'hint' => 'lövhədə 2 şəkil'])
                ->where('levels.8', ['key' => 'numbers-5', 'title' => '1-dən 5-ə', 'unit' => 'number', 'mechanics' => ['pop', 'find', 'count'], 'hint' => 'lövhədə 3 rəqəm'])
                ->where('options', function ($options): bool {
                    $options = $options->all();
                    $levels = ['pictures-easy', 'pictures', 'numbers-5', 'numbers-10'];
                    $offered = [];

                    foreach ($options as $option) {
                        if (in_array($option['level'], $levels, true)) {
                            $offered["{$option['mechanic']} × {$option['level']}"][] = $option['pack'];
                        }
                    }

                    expect($offered)->toBe([
                        ...array_fill_keys(['pop × pictures-easy', 'find × pictures-easy', 'pop × pictures', 'find × pictures'], ['animals', 'colors', 'fruit']),
                        ...array_fill_keys([
                            'pop × numbers-5', 'find × numbers-5', 'count × numbers-5',
                            'pop × numbers-10', 'find × numbers-10', 'count × numbers-10',
                        ], ['animals', 'fruit', 'numbers']),
                    ])
                        ->and(setupOption($options, 'find', 'cities', 'pictures'))->toBeNull()
                        ->and(setupOption($options, 'count', 'train', 'numbers-5'))->toBeNull()
                        // The colours are named, never counted; the numbers' things are counted, never named.
                        ->and(setupOption($options, 'count', 'colors', 'numbers-5'))->toBeNull()
                        ->and(setupOption($options, 'find', 'numbers', 'pictures'))->toBeNull()
                        ->and(setupOption($options, 'find', 'colors', 'letters'))->toBeNull()
                        // Nothing is read: no own words, whatever the letters.
                        ->and(setupOption($options, 'find', 'animals', 'pictures'))->toMatchArray(['startable' => true, 'own' => null]);

                    return true;
                }));
    });

    it("builds each level's hint from its data, in the interface language", function (): void {
        $hints = fn (Assert $page) => $page->where('levels', fn ($levels): bool => array_column($levels->all(), 'hint', 'key') === [
            'letters' => 'lövhədə 4 hərf',
            'syllables-open' => '1–2 hərfli hecalar',
            'syllables-closed' => '3–4 hərfli hecalar',
            'words-short' => '1–2 hecalı sözlər',
            'words-long' => '3–4 hecalı sözlər',
            'sentences' => '2–4 sözlü cümlələr',
            'pictures-easy' => 'lövhədə 2 şəkil',
            'pictures' => 'lövhədə 4 şəkil',
            'numbers-5' => 'lövhədə 3 rəqəm',
            'numbers-10' => 'lövhədə 4 rəqəm',
        ]);

        $this->get(route('classes.games.create', $this->class))->assertInertia($hints);

        $this->teacher->update(['locale' => 'en']);

        $this->get(route('classes.games.create', $this->class))
            ->assertInertia(fn (Assert $page) => $page
                ->where('levels.1.hint', 'syllables of 1–2 letters')
                ->where('levels.6.hint', '2 pictures on the board'));
    });

    it('hides an archived class from games', function (): void {
        $this->class->update(['archived_at' => now()]);

        $this->get(route('classes.games.create', $this->class))->assertNotFound();
        $this->post(route('classes.games.store', $this->class), ['mechanic' => 'find', 'pack' => 'balloons', 'level' => 'letters'])->assertNotFound();

        expect(GameSession::query()->count())->toBe(0);
    });
});

describe('starting a game', function (): void {
    it('starts a live game and opens its board', function (): void {
        $this->travelTo(now()->startOfMinute());

        $response = $this->post(route('classes.games.store', $this->class), [
            'mechanic' => 'find',
            'pack' => 'balloons',
            'level' => 'letters',
            'rounds' => 7,
        ]);

        $game = GameSession::query()->sole();

        $response->assertRedirect(route('games.board', $game));

        expect($game->user_id)->toBe($this->teacher->id)
            ->and($game->school_class_id)->toBe($this->class->id)
            ->and($game->status)->toBe(GameSessionStatus::Live)
            ->and($game->board_epoch)->toBe(0)
            ->and($game->pack)->toBe('balloons')
            ->and($game->pack_version)->toBe(app(ContentRepository::class)->pack('balloons')->version)
            ->and($game->letter_case)->toBe(LetterCase::Upper)
            ->and($game->seed)->toBeGreaterThan(0)
            ->and($game->started_at->equalTo(now()))->toBeTrue()
            ->and($game->ended_at)->toBeNull()
            ->and(GameRound::query()->where('game_session_id', $game->id)->count())->toBe(7);
    });

    it('starts find in every pack with the pack\'s own effect', function (string $pack, string $effect): void {
        $this->post(route('classes.games.store', $this->class), ['mechanic' => 'find', 'pack' => $pack, 'level' => 'words-short'])
            ->assertSessionHasNoErrors();

        $game = GameSession::query()->sole();
        $effects = GameRound::query()->where('game_session_id', $game->id)->get()
            ->map(fn (GameRound $round): string => $round->snapshot(10)->effect->value)
            ->unique()->values()->all();

        expect($game->pack)->toBe($pack)
            ->and($effects)->toBe([$effect]);
    })->with([
        'balloons' => ['balloons', 'burst'],
        'train' => ['train', 'burst'],
        'animals' => ['animals', 'sparkle'],
        'fruit' => ['fruit', 'burst'],
        'cities' => ['cities', 'sparkle'],
        'symbols' => ['symbols', 'sparkle'],
    ]);

    it('starts pop in every pack at every level that has it, every item an answer', function (string $pack, string $effect): void {
        $levels = ['letters' => 'letter', 'syllables-open' => 'syllable', 'syllables-closed' => 'syllable', 'words-short' => 'word', 'words-long' => 'word'];

        foreach ($levels as $level => $unit) {
            $this->post(route('classes.games.store', $this->class), ['mechanic' => 'pop', 'pack' => $pack, 'level' => $level])
                ->assertSessionHasNoErrors()
                ->assertRedirect();

            $game = GameSession::query()->where('level', $level)->sole();
            $rounds = GameRound::query()->where('game_session_id', $game->id)->get()
                ->map(fn (GameRound $round) => $round->snapshot(10));

            expect($game->mechanic)->toBe(Mechanic::Pop)
                ->and($game->pack)->toBe($pack)
                ->and($game->status)->toBe(GameSessionStatus::Live)
                ->and($rounds)->toHaveCount(10);

            foreach ($rounds as $round) {
                expect($round->mechanic)->toBe(Mechanic::Pop)
                    ->and($round->unit->value)->toBe($unit)
                    ->and($round->items)->toHaveCount(4)
                    ->and($round->solution->order->value)->toBe('any')
                    ->and($round->solution->item_ids)->toBe($round->itemIds())
                    ->and($round->slots)->toBe(0)
                    ->and($round->prompt->show)->toBeNull()
                    ->and($round->effect->value)->toBe($effect);
            }
        }
    })->with([
        'balloons' => ['balloons', 'burst'],
        'train' => ['train', 'burst'],
        'animals' => ['animals', 'sparkle'],
        'fruit' => ['fruit', 'burst'],
        'cities' => ['cities', 'sparkle'],
        'symbols' => ['symbols', 'sparkle'],
    ]);

    it('starts every build mechanic in every pack, with the pack\'s slots and effect', function (string $mechanic, string $level, string $pack, string $effect): void {
        $this->post(route('classes.games.store', $this->class), ['mechanic' => $mechanic, 'pack' => $pack, 'level' => $level])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $game = GameSession::query()->sole();
        $rounds = GameRound::query()->where('game_session_id', $game->id)->get()
            ->map(fn (GameRound $round) => $round->snapshot(10));

        expect($game->mechanic->value)->toBe($mechanic)
            ->and($game->pack)->toBe($pack)
            ->and($game->status)->toBe(GameSessionStatus::Live)
            ->and($rounds)->toHaveCount(10);

        foreach ($rounds as $round) {
            expect($round->mechanic->value)->toBe($mechanic)
                ->and($round->solution->order->value)->toBe('sequence')
                ->and($round->slots)->toBe(count($round->solution->item_ids))
                ->and($round->effect->value)->toBe($effect)
                ->and($round->prompt->tail !== null)->toBe($mechanic === 'build_sentence')
                ->and($round->prompt->image !== null)->toBe($mechanic === 'build_word');
        }
    })->with(function (): array {
        $packs = ['balloons' => 'burst', 'train' => 'burst', 'animals' => 'sparkle', 'fruit' => 'burst', 'cities' => 'sparkle', 'symbols' => 'sparkle'];
        $games = ['build_syllable' => 'syllables-closed', 'build_word' => 'words-long', 'build_sentence' => 'sentences'];
        $cases = [];

        foreach ($games as $mechanic => $level) {
            foreach ($packs as $pack => $effect) {
                $cases["{$mechanic} × {$pack}"] = [$mechanic, $level, $pack, $effect];
            }
        }

        return $cases;
    });

    it('fills the defaults: ten rounds, capitals for letters, words as written', function (): void {
        $this->post(route('classes.games.store', $this->class), ['mechanic' => 'find', 'pack' => 'balloons', 'level' => 'letters']);
        $this->post(route('classes.games.store', $this->class), ['mechanic' => 'find', 'pack' => 'balloons', 'level' => 'words-short']);

        [$letters, $words] = GameSession::query()->orderBy('level')->get()->all();

        expect(GameRound::query()->where('game_session_id', $letters->id)->count())->toBe(10)
            ->and($letters->letter_case)->toBe(LetterCase::Upper)
            ->and($words->letter_case)->toBe(LetterCase::Lower);
    });

    it('refuses what the board cannot draw or the class cannot read', function (array $input, string $field, string $message, array $replace = []): void {
        $this->class->update(['learned_letters' => $input['_learned'] ?? null]);
        unset($input['_learned']);

        $this->from(route('classes.games.create', $this->class))
            ->post(route('classes.games.store', $this->class), [
                'mechanic' => 'find',
                'pack' => 'balloons',
                'level' => 'letters',
                ...$input,
            ])
            ->assertRedirect(route('classes.games.create', $this->class))
            ->assertSessionHasErrors([$field => __($message, $replace)]);

        expect(GameSession::query()->count())->toBe(0);
    })->with([
        'a build game without content the class can read' => [['mechanic' => 'build_word', 'level' => 'words-short', '_learned' => ['a', 'e']], 'level', 'games.setup.errors.too_few_words', ['count' => 2, 'found' => 0]],
        'a mechanic the level does not have' => [['level' => 'sentences'], 'level', 'games.setup.errors.level_mechanic'],
        'too few learned letters' => [['_learned' => ['a', 'b', 'ə']], 'level', 'games.setup.errors.too_few_letters', ['count' => 4]],
        'no word of the learned letters' => [['level' => 'words-short', '_learned' => ['a', 'e']], 'level', 'games.setup.errors.too_few_words', ['count' => 4, 'found' => 0]],
        'a letter the class has not learned' => [['letters' => ['ə', 'ş'], '_learned' => ['a', 'b', 'e', 'ə', 'l']], 'letters.1', 'games.setup.errors.letter_not_learned'],
        'letters for a word level' => [['level' => 'words-short', 'letters' => ['a', 'b']], 'level', 'games.setup.errors.letters_level'],
        'one letter to ask for' => [['letters' => ['ə']], 'level', 'games.setup.errors.too_few_targets', ['count' => 2]],
    ]);

    it('refuses malformed fields', function (array $input, string $field): void {
        $this->from(route('classes.games.create', $this->class))
            ->post(route('classes.games.store', $this->class), [
                'mechanic' => 'find',
                'pack' => 'balloons',
                'level' => 'letters',
                ...$input,
            ])
            ->assertSessionHasErrors($field);

        expect(GameSession::query()->count())->toBe(0);
    })->with([
        'unknown mechanic' => [['mechanic' => 'dance'], 'mechanic'],
        'unknown pack' => [['pack' => 'dinosaurs'], 'pack'],
        'unknown level' => [['level' => 'poems'], 'level'],
        'too few rounds' => [['rounds' => 4], 'rounds'],
        'too many rounds' => [['rounds' => 31], 'rounds'],
        'unknown letter case' => [['letter_case' => 'title'], 'letter_case'],
        'a repeated letter' => [['letters' => ['ə', 'ə']], 'letters.0'],
        'not a letter' => [['letters' => ['ə', 'w']], 'letters.1'],
    ]);

    it('asks only for the chosen letters', function (): void {
        $this->post(route('classes.games.store', $this->class), [
            'mechanic' => 'find',
            'pack' => 'balloons',
            'level' => 'letters',
            'letters' => ['i', 'ı'],
            'letter_case' => 'lower',
        ])->assertRedirect();

        $targets = GameRound::query()->get()->map(function (GameRound $round): string {
            $target = $round->payload['solution']['item_ids'][0];

            return collect($round->payload['items'])->firstWhere('id', $target)['text'];
        })->unique()->sort()->values()->all();

        expect($targets)->toEqualCanonicalizing(['i', 'ı']);
    });

    it('starts every picture and number level in every pack that lists its unit', function (string $pack, string $for): void {
        $content = app(ContentRepository::class);
        $levels = array_filter(
            $content->levels(),
            static fn ($level): bool => ! $level->unit->reads() && $content->pack($pack)->lists($level->unit),
        );
        // One learned letter: nothing at these levels is read (ADR 012).
        $child = Child::factory()->for(User::factory()->parent(), 'parent')->create(['learned_letters' => ['a']]);
        $this->class->update(['learned_letters' => ['a']]);
        [$route, $owner] = $for === 'class'
            ? [route('classes.games.store', $this->class), ['school_class_id' => $this->class->id, 'child_id' => null]]
            : [route('children.games.store', $child), ['school_class_id' => null, 'child_id' => $child->id]];
        $pictures = array_map(static fn ($word): ?string => $word->image, $content->pack($pack)->pictured());
        $started = 0;

        if ($for === 'child') {
            $this->actingAs($child->parent);
        }

        expect($levels)->not->toBeEmpty();

        foreach ($levels as $level) {
            foreach ($level->mechanics as $mechanic) {
                $items = (int) $level->items;

                // The letter case is not offered here: whatever comes, the content is stored as written.
                $this->post($route, ['mechanic' => $mechanic->value, 'pack' => $pack, 'level' => $level->key, 'letter_case' => 'upper'])
                    ->assertSessionHasNoErrors()
                    ->assertRedirect();

                $game = GameSession::query()->where('level', $level->key)->where('mechanic', $mechanic)->sole();
                $rounds = GameRound::query()->where('game_session_id', $game->id)->get()->map(fn (GameRound $round) => $round->snapshot(10));
                $started++;

                expect($game->pack)->toBe($pack)
                    ->and($game->only(['school_class_id', 'child_id']))->toBe($owner)
                    ->and($game->letter_case)->toBe(LetterCase::Lower)
                    ->and($rounds)->toHaveCount(10);

                foreach ($rounds as $round) {
                    $counts = $mechanic === Mechanic::Count;

                    expect($round->unit)->toBe($level->unit)
                        ->and($round->items)->toHaveCount($items)
                        ->and($round->prompt->image !== null)->toBe($counts)
                        ->and($round->prompt->count !== null)->toBe($counts)
                        ->and($round->solution->item_ids)->toHaveCount($mechanic === Mechanic::Pop ? $items : 1);

                    if ($counts) {
                        expect($round->prompt->count)->toBe((int) $round->item($round->solution->item_ids[0])?->text)
                            ->and($pictures)->toContain(Str::before(Str::after((string) $round->prompt->image, url('/content/').'/'), '?'));
                    }

                    foreach ($round->items as $item) {
                        expect($item->image !== null)->toBe($level->unit === Unit::Picture)
                            ->and($item->text)->toBe($level->unit === Unit::Picture ? $item->text : (string) (int) $item->text);
                    }
                }
            }
        }

        expect($started)->toBe(array_sum(array_map(static fn ($level): int => count($level->mechanics), $levels)));
    })->with(function (): array {
        // Over the real content: every pack that lists `picture` or `number`.
        $cases = [];

        foreach (glob(dirname(__DIR__, 3).'/content/packs/*.json') ?: [] as $file) {
            $units = json_decode((string) file_get_contents($file), true)['units'] ?? [];

            if (array_intersect($units, ['picture', 'number']) !== []) {
                foreach (['class', 'child'] as $for) {
                    $cases[basename($file, '.json')." for a {$for}"] = [basename($file, '.json'), $for];
                }
            }
        }

        return $cases;
    });

    it('starts a counting game in animals and in fruit', function (string $pack, string $level, int $items): void {
        $this->class->update(['learned_letters' => ['a']]);

        $this->post(route('classes.games.store', $this->class), ['mechanic' => 'count', 'pack' => $pack, 'level' => $level, 'rounds' => 12])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $game = GameSession::query()->sole();
        $rounds = array_map(static fn (GameRound $round) => $round->snapshot(12), Games::rounds($game));

        expect($game->mechanic)->toBe(Mechanic::Count)
            ->and($game->letter_case)->toBe(LetterCase::Lower)
            ->and($rounds)->toHaveCount(12);

        foreach ($rounds as $round) {
            $answer = $round->item($round->solution->item_ids[0]);

            expect($round->mechanic)->toBe(Mechanic::Count)
                ->and($round->unit)->toBe(Unit::Number)
                ->and($round->items)->toHaveCount($items)
                ->and($round->prompt->image)->toStartWith(url('/content/'))
                ->and($round->prompt->count)->toBe((int) $answer?->text)
                ->and($round->prompt->show)->toBeNull()
                ->and($round->prompt->text)->toMatch('/^Neçə «\p{L}+» var\?$/u');
        }
    })->with([
        'animals, 1–5' => ['animals', 'numbers-5', 3],
        'animals, 1–10' => ['animals', 'numbers-10', 4],
        'fruit, 1–5' => ['fruit', 'numbers-5', 3],
        'fruit, 1–10' => ['fruit', 'numbers-10', 4],
    ]);

    it('refuses a level the pack does not list with pack_level', function (string $mechanic, string $pack, string $level): void {
        $this->from(route('classes.games.create', $this->class))
            ->post(route('classes.games.store', $this->class), ['mechanic' => $mechanic, 'pack' => $pack, 'level' => $level])
            ->assertRedirect(route('classes.games.create', $this->class))
            ->assertSessionHasErrors(['level' => __('games.setup.errors.pack_level')]);

        expect(GameSession::query()->count())->toBe(0);
    })->with([
        'pictures with the cities' => ['find', 'cities', 'pictures'],
        'pictures with the balloons' => ['pop', 'balloons', 'pictures-easy'],
        'numbers with the train' => ['find', 'train', 'numbers-5'],
        'counting with the symbols' => ['count', 'symbols', 'numbers-10'],
        'counting with the colours' => ['count', 'colors', 'numbers-5'],
        'pictures with the numbers' => ['find', 'numbers', 'pictures'],
        'letters with the colours' => ['find', 'colors', 'letters'],
        'sentences with the numbers' => ['build_sentence', 'numbers', 'sentences'],
    ]);

    it('refuses chosen letters and own words at a picture or number level', function (array $input, string $message): void {
        $this->from(route('classes.games.create', $this->class))
            ->post(route('classes.games.store', $this->class), ['mechanic' => 'find', 'pack' => 'animals', ...$input])
            ->assertSessionHasErrors(['level' => __($message)]);

        expect(GameSession::query()->count())->toBe(0);
    })->with([
        'letters for pictures' => [['level' => 'pictures', 'letters' => ['a', 'b']], 'games.setup.errors.letters_level'],
        'own words for numbers' => [['level' => 'numbers-5', 'own_words' => true], 'games.setup.errors.own_words_level'],
        'count at a picture level' => [['level' => 'pictures', 'mechanic' => 'count'], 'games.setup.errors.level_mechanic'],
    ]);

    it('needs a verified teacher', function (): void {
        $unverified = User::factory()->unverified()->create();
        $class = SchoolClass::factory()->for($unverified, 'teacher')->create();

        $this->actingAs($unverified)
            ->get(route('classes.games.create', $class))
            ->assertRedirect(route('verification.notice'));
    });
});
