<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Support\Playable;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

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
 * @param  list<array{mechanic: string, pack: string, level: string, drawable: bool, startable: bool, reason: string|null}>  $options
 * @return array{mechanic: string, pack: string, level: string, drawable: bool, startable: bool, reason: string|null}|null
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
                    'style' => ['item' => 'balloon', 'effect' => 'burst', 'palette' => $content->pack('balloons')->style->palette],
                ])
                ->has('levels', count($content->levels()))
                ->where('levels.0.key', 'letters')
                ->where('levels.0.unit', 'letter')
                ->where('levels.0.mechanics', ['find'])
                ->where('rounds', ['min' => 5, 'max' => 30, 'default' => 10])
                ->where('letterCases', ['upper', 'lower'])
                ->has('letters', 32)
                ->where('letters.13', ['lower' => 'i', 'upper' => 'İ'])
                ->where('options', function ($options): bool {
                    $options = $options->all();

                    expect(setupOption($options, 'find', 'balloons', 'letters'))->toBe(['mechanic' => 'find', 'pack' => 'balloons', 'level' => 'letters', 'drawable' => true, 'startable' => true, 'reason' => null])
                        ->and(setupOption($options, 'find', 'balloons', 'words-short')['startable'] ?? null)->toBeTrue()
                        ->and(setupOption($options, 'find', 'symbols', 'letters'))->toBe(['mechanic' => 'find', 'pack' => 'symbols', 'level' => 'letters', 'drawable' => true, 'startable' => true, 'reason' => null])
                        ->and(setupOption($options, 'build_word', 'balloons', 'words-short'))->toBe(['mechanic' => 'build_word', 'pack' => 'balloons', 'level' => 'words-short', 'drawable' => true, 'startable' => true, 'reason' => null])
                        ->and(setupOption($options, 'build_sentence', 'train', 'sentences')['startable'] ?? null)->toBeTrue()
                        ->and(setupOption($options, 'find', 'balloons', 'sentences'))->toBeNull();

                    // With every letter learned, every option starts: 10
                    // mechanic × level pairs in each of the 6 packs (A8.3).
                    expect($options)->toHaveCount(60)
                        ->and(array_unique(array_column($options, 'pack')))->toHaveCount(6)
                        ->and(array_count_values(array_column($options, 'mechanic')))->toBe(['find' => 30, 'build_syllable' => 12, 'build_word' => 12, 'build_sentence' => 6]);

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
                ->where('options', fn ($options): bool => setupOption($options->all(), 'find', 'balloons', 'letters') === [
                    'mechanic' => 'find',
                    'pack' => 'balloons',
                    'level' => 'letters',
                    'drawable' => true,
                    'startable' => false,
                    'reason' => __('games.setup.errors.too_few_letters', ['count' => 4]),
                ]));
    });

    it('shows titles and reasons in the interface language', function (): void {
        $this->teacher->update(['locale' => 'en']);
        $this->class->update(['learned_letters' => ['a', 'b', 'ə']]);

        $this->get(route('classes.games.create', $this->class))
            ->assertInertia(fn (Assert $page) => $page
                ->where('packs.1.title', 'Balloons')
                ->where('levels.0.title', 'Letters')
                ->where('options', fn ($options): bool => setupOption($options->all(), 'find', 'balloons', 'letters')['reason'] === 'The class needs at least 4 learned letters for this level.'));
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
        'animals' => ['animals', 'burst'],
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
        $packs = ['balloons' => 'burst', 'train' => 'burst', 'animals' => 'burst', 'fruit' => 'burst', 'cities' => 'sparkle', 'symbols' => 'sparkle'];
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

    it('needs a verified teacher', function (): void {
        $unverified = User::factory()->unverified()->create();
        $class = SchoolClass::factory()->for($unverified, 'teacher')->create();

        $this->actingAs($unverified)
            ->get(route('classes.games.create', $class))
            ->assertRedirect(route('verification.notice'));
    });
});
