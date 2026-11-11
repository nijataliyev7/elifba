<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Content\Actions\AddCustomWord;
use App\Domain\Content\Data\CustomWordData;
use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\VoiceClips;
use App\Domain\Play\Models\GameRound;
use App\Models\User;
use Tests\Support\Games;
use Tests\Support\Voice;

/*
 * `prompt.audio` (docs/architecture.md §3 "Voice", ADR 015): every round
 * builder fills it with the clip that says its prompt, from the app's own
 * host and versioned; a teacher word has none, nor any prompt whose clip
 * is not made; `pop` and the build mechanics say only their instruction.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
});

/**
 * @return list<array{text: string, show: string|null, audio: string|null}>
 */
function prompts(array $setup): array
{
    $game = Games::start(test()->class, ['rounds' => 12, ...$setup], seed: 3);

    return array_map(static fn (GameRound $round): array => $round->payload['prompt'], Games::rounds($game));
}

/**
 * The URL of the clip of a phrase as the adult reads it, in a group.
 */
function clipOf(string $group, string $phrase): ?string
{
    $clips = app(VoiceClips::class);

    foreach ($clips->needed(app(ContentRepository::class)) as $line) {
        if ($line->group === $group && $line->phrase === $phrase) {
            return $clips->url($line);
        }
    }

    return null;
}

it('fills prompt.audio in every builder with the clip of what the prompt says', function (array $setup, string $group): void {
    Voice::made($this->app);

    foreach (prompts($setup) as $prompt) {
        expect($prompt['audio'])->toBeString()
            ->toStartWith(url('/content/voice/'))
            ->toEndWith('.mp3?v='.app(ContentRepository::class)->voice()->version);

        $line = array_values(array_filter(
            app(VoiceClips::class)->needed(app(ContentRepository::class)),
            static fn (VoiceLineData $line): bool => app(VoiceClips::class)->url($line) === $prompt['audio'],
        ));

        expect($line)->toHaveCount(1)
            ->and($line[0]->group)->toBe($group);
    }
})->with([
    'find a letter' => [['mechanic' => 'find', 'level' => 'letters'], 'find.letter'],
    'find a syllable' => [['mechanic' => 'find', 'pack' => 'fruit', 'level' => 'syllables-open'], 'find.syllable'],
    'find a word' => [['mechanic' => 'find', 'pack' => 'cities', 'level' => 'words-short'], 'find.word'],
    'find a picture' => [['mechanic' => 'find', 'pack' => 'animals', 'level' => 'pictures'], 'find.picture'],
    'find a number' => [['mechanic' => 'find', 'pack' => 'numbers', 'level' => 'numbers-10'], 'find.number'],
    'pop' => [['mechanic' => 'pop', 'pack' => 'train', 'level' => 'words-long'], 'pop.word'],
    'build a syllable' => [['mechanic' => 'build_syllable', 'pack' => 'fruit', 'level' => 'syllables-closed'], 'build_syllable.syllable'],
    'build a word' => [['mechanic' => 'build_word', 'pack' => 'fruit', 'level' => 'words-long'], 'build_word.word'],
    'build a sentence' => [['mechanic' => 'build_sentence', 'pack' => 'symbols', 'level' => 'sentences'], 'build_sentence.sentence'],
    'count' => [['mechanic' => 'count', 'pack' => 'fruit', 'level' => 'numbers-10'], 'count.number'],
]);

it('says the target a find round names, and only the instruction in pop', function (): void {
    Voice::made($this->app);

    foreach (prompts(['mechanic' => 'find', 'pack' => 'animals', 'level' => 'pictures']) as $prompt) {
        expect($prompt['audio'])->toBe(clipOf('find.picture', mb_strtoupper(mb_substr((string) $prompt['show'], 0, 1)).mb_substr((string) $prompt['show'], 1).' hansıdır?'));
    }

    $pop = prompts(['mechanic' => 'pop', 'pack' => 'animals', 'level' => 'pictures']);

    expect(array_unique(array_column($pop, 'audio')))->toBe([clipOf('pop.picture', 'Hər şəklin adını de və vur!')]);
});

it('says a letter as its sound whatever the letter case', function (): void {
    Voice::made($this->app);

    foreach (prompts(['mechanic' => 'find', 'level' => 'letters', 'letter_case' => 'upper', 'letters' => ['b', 'ə']]) as $prompt) {
        expect($prompt['audio'])->toBe(clipOf('find.letter', $prompt['show'] === 'B' ? 'Bı hərfini tap!' : 'Ə hərfini tap!'));
    }
});

it('leaves prompt.audio null where no clip is made yet, and the round plays as before', function (): void {
    Voice::none($this->app);

    expect(array_unique(array_column(prompts(['mechanic' => 'count', 'pack' => 'fruit', 'level' => 'numbers-5']), 'audio')))->toBe([null]);
});

it('never voices a teacher word, even one a built-in pack also has', function (): void {
    Voice::made($this->app);
    // Names could be children's; «alma» is a built-in word of fruit, not of cities.
    $words = ['nigar', 'alma', 'aysel', 'murad'];

    foreach ($words as $text) {
        app(AddCustomWord::class)->handle($this->teacher, CustomWordData::validate(['text' => $text]));
    }

    $prompts = prompts(['mechanic' => 'find', 'pack' => 'cities', 'level' => 'words-short', 'own_words' => true, 'rounds' => 8]);

    expect(array_values(array_unique(array_column($prompts, 'show'))))->toEqualCanonicalizing($words)
        ->and(array_unique(array_column($prompts, 'audio')))->toBe([null]);

    // In a game of the pack's own words beside them, the built-in ones are said.
    $mixed = prompts(['mechanic' => 'find', 'pack' => 'cities', 'level' => 'words-short', 'rounds' => 30]);
    $teacherWords = array_filter($mixed, static fn (array $prompt): bool => in_array($prompt['show'], $words, true));
    $builtIn = array_filter($mixed, static fn (array $prompt): bool => ! in_array($prompt['show'], $words, true));

    expect($teacherWords)->not->toBeEmpty()
        ->and(array_unique(array_column($teacherWords, 'audio')))->toBe([null])
        ->and(array_filter(array_column($builtIn, 'audio'), is_null(...)))->toBe([]);
});
