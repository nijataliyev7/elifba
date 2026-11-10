<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Content\Actions\AddCustomWord;
use App\Domain\Content\Actions\HideWord;
use App\Domain\Content\Actions\ShowWord;
use App\Domain\Content\Data\CustomWordData;
use App\Domain\Content\Data\HiddenWordData;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Support\GameSetupOptions;
use App\Domain\Play\Support\Playable;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\Support\Games;

/*
 * Teacher words in games (docs/architecture.md §2, §3 "Teacher words",
 * ADR 007): a teacher's own words play in that teacher's find, pop and
 * syllable games and never in build_word; a hidden built-in word leaves only that
 * teacher's games; the setup options, GameSetupData::rules() and StartGame
 * see the same words, so hiding too much gives the usual shortage reason.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
    $this->other = User::factory()->create();
    $this->otherClass = SchoolClass::factory()->for($this->other, 'teacher')->create();
});

/**
 * Every content key on the board in the game: targets and decoys.
 *
 * @return list<string>
 */
function boardKeys(GameSession $game): array
{
    $keys = [];

    foreach (Games::rounds($game) as $round) {
        array_push($keys, ...array_column($round->payload['items'], 'content_key'));
    }

    return array_values(array_unique($keys));
}

/**
 * The content key of each round's target (the first tile of a build round).
 *
 * @return list<string>
 */
function targetKeysOf(GameSession $game): array
{
    return array_map(static function (GameRound $round): string {
        $items = array_column($round->payload['items'], 'content_key', 'id');

        return $items[$round->payload['solution']['item_ids'][0]];
    }, Games::rounds($game));
}

/**
 * @return list<array<string, mixed>>
 */
function payloadsOf(GameSession $game): array
{
    return array_map(static fn (GameRound $round): array => $round->payload, Games::rounds($game));
}

function addTeacherWord(User $teacher, string $pack, string $text): void
{
    app(AddCustomWord::class)->handle($teacher, CustomWordData::validate(['pack' => $pack, 'text' => $text]));
}

function hideBuiltIn(User $teacher, string $pack, string $word): void
{
    app(HideWord::class)->handle($teacher, HiddenWordData::validate(['pack' => $pack, 'word' => $word]));
}

/**
 * @return array{mechanic: string, pack: string, level: string, drawable: bool, startable: bool, reason: string|null}
 */
function optionFor(SchoolClass $class, string $mechanic, string $pack, string $level): array
{
    foreach (GameSetupOptions::forClass($class)['options'] as $option) {
        if ($option['mechanic'] === $mechanic && $option['pack'] === $pack && $option['level'] === $level) {
            return $option;
        }
    }

    throw new RuntimeException("No option {$mechanic} × {$pack} × {$level}.");
}

/**
 * The errors GameSetupData::rules() gives for a setup, by field.
 *
 * @param  array<string, mixed>  $setup
 * @return array<string, list<string>>
 */
function setupErrors(SchoolClass $class, array $setup): array
{
    try {
        GameSetupData::validate($setup, $class);
    } catch (ValidationException $exception) {
        /** @var array<string, list<string>> $errors */
        $errors = $exception->errors();

        return $errors;
    }

    return [];
}

it('plays a teacher word in that teacher\'s find-word games only', function (): void {
    addTeacherWord($this->teacher, 'fruit', 'pomidor');
    $setup = ['mechanic' => 'find', 'pack' => 'fruit', 'level' => 'words-long', 'rounds' => 30];

    $mine = Games::start($this->class, $setup, seed: 11);
    $theirs = Games::start($this->otherClass, $setup, seed: 11);
    $round = collect(Games::rounds($mine))->first(fn (GameRound $round): bool => targetKeysOf($mine)[$round->position] === 'word:pomidor');

    expect(targetKeysOf($mine))->toContain('word:pomidor')
        ->and($round?->payload['prompt']['show'])->toBe('pomidor')
        ->and(collect($round?->payload['items'])->firstWhere('content_key', 'word:pomidor')['image'])->toBeNull()
        ->and(boardKeys($theirs))->not->toContain('word:pomidor');
});

it('plays a teacher word in that teacher\'s pop games only, as one more item to read', function (): void {
    addTeacherWord($this->teacher, 'fruit', 'pomidor');
    $setup = ['mechanic' => 'pop', 'pack' => 'fruit', 'level' => 'words-long', 'rounds' => 30];

    $mine = Games::start($this->class, $setup, seed: 11);
    $round = collect(Games::rounds($mine))->first(fn (GameRound $round): bool => in_array('word:pomidor', array_column($round->payload['items'], 'content_key'), true));
    $item = collect($round?->payload['items'])->firstWhere('content_key', 'word:pomidor');

    expect($item)->toBeArray()
        ->and($item['image'])->toBeNull()
        ->and($round?->payload['solution']['item_ids'])->toContain($item['id'])
        ->and(boardKeys(Games::start($this->otherClass, $setup, seed: 11)))->not->toContain('word:pomidor');
});

it('gives a teacher word\'s syllables to that teacher\'s syllable games only', function (): void {
    addTeacherWord($this->teacher, 'fruit', 'pomidor');
    // Few letters keep the pools under 30, so every target comes up.
    $learned = ['a', 'd', 'i', 'l', 'm', 'n', 'o', 'p', 'r', 't', 'u'];
    $this->class->update(['learned_letters' => $learned]);
    $this->otherClass->update(['learned_letters' => $learned]);
    $open = ['mechanic' => 'find', 'pack' => 'fruit', 'level' => 'syllables-open', 'rounds' => 30];
    $closed = ['mechanic' => 'build_syllable', 'pack' => 'fruit', 'level' => 'syllables-closed', 'rounds' => 30];

    expect(targetKeysOf(Games::start($this->class, $open, seed: 3)))->toContain('syllable:po', 'syllable:mi')
        ->and(array_intersect(boardKeys(Games::start($this->otherClass, $open, seed: 3)), ['syllable:po', 'syllable:mi']))->toBe([]);

    // build_syllable builds the closed syllable from its letters.
    $mine = Games::start($this->class, $closed, seed: 3);
    $built = array_map(
        static fn (GameRound $round): string => implode('', array_map(
            static fn (string $id): string => collect($round->payload['items'])->firstWhere('id', $id)['text'],
            $round->payload['solution']['item_ids'],
        )),
        Games::rounds($mine),
    );

    expect($built)->toContain('dor')
        ->and(array_map(static fn (GameRound $round): ?string => $round->payload['prompt']['show'], Games::rounds(Games::start($this->otherClass, $closed, seed: 3))))->not->toContain('dor');
});

it('never plays a teacher word in build_word, as a target or as decoys', function (string $level): void {
    addTeacherWord($this->teacher, 'fruit', 'pomidor');
    addTeacherWord($this->teacher, 'fruit', 'kələm');
    $setup = ['mechanic' => 'build_word', 'pack' => 'fruit', 'level' => $level, 'rounds' => 30];

    $mine = Games::start($this->class, $setup, seed: 5);
    $theirs = Games::start($this->otherClass, $setup, seed: 5);
    $keys = boardKeys($mine);

    expect(array_intersect($keys, ['word:pomidor', 'word:kələm', 'syllable:po', 'syllable:mi', 'syllable:dor', 'syllable:kə', 'syllable:ləm']))->toBe([])
        ->and(payloadsOf($mine))->toBe(payloadsOf($theirs))
        ->and(optionFor($this->class, 'build_word', 'fruit', $level)['startable'])->toBeTrue();
})->with(['words-short', 'words-long']);

it('leaves a hidden built-in word out of that teacher\'s games until it is shown again', function (): void {
    $setup = ['mechanic' => 'find', 'pack' => 'fruit', 'level' => 'words-short', 'rounds' => 30];
    hideBuiltIn($this->teacher, 'fruit', 'alma');

    expect(boardKeys(Games::start($this->class, $setup, seed: 9)))->not->toContain('word:alma')
        ->and(targetKeysOf(Games::start($this->otherClass, $setup, seed: 9)))->toContain('word:alma');

    // build_word does not ask for it either: its picture never shows.
    $fruit = app(ContentRepository::class)->pack('fruit');
    $picture = ContentAssets::url((string) $fruit->word('alma')?->image, $fruit->version);
    $pictures = static fn (GameSession $game): array => array_map(static fn (GameRound $round): ?string => $round->payload['prompt']['image'], Games::rounds($game));
    $build = ['mechanic' => 'build_word', 'pack' => 'fruit', 'level' => 'words-short', 'rounds' => 30];

    expect($pictures(Games::start($this->class, $build, seed: 9)))->not->toContain($picture)
        ->and($pictures(Games::start($this->otherClass, $build, seed: 9)))->toContain($picture);

    app(ShowWord::class)->handle($this->teacher, HiddenWordData::validate(['pack' => 'fruit', 'word' => 'alma']));

    expect(targetKeysOf(Games::start($this->class, $setup, seed: 9)))->toContain('word:alma')
        ->and(payloadsOf(Games::start($this->class, $setup, seed: 9)))->toBe(payloadsOf(Games::start($this->otherClass, $setup, seed: 9)));
});

it('gives the shortage reason, on the setup page and in the rules, when hiding leaves a level short', function (): void {
    $content = app(ContentRepository::class);
    $long = $content->level('words-long')->keysFrom($content->pack('fruit'));
    $setup = ['mechanic' => 'find', 'pack' => 'fruit', 'level' => 'words-long'];

    // Leave three long words: find shows four items.
    foreach (array_slice($long, 3) as $key) {
        hideBuiltIn($this->teacher, 'fruit', explode(':', $key, 2)[1]);
    }

    $reason = Playable::message('too_few_words', ['count' => 4, 'found' => 3]);

    expect(optionFor($this->class, 'find', 'fruit', 'words-long'))->toMatchArray(['drawable' => true, 'startable' => false, 'reason' => $reason])
        ->and(setupErrors($this->class, $setup))->toBe(['level' => [$reason]])
        ->and(optionFor($this->otherClass, 'find', 'fruit', 'words-long')['startable'])->toBeTrue()
        ->and(setupErrors($this->otherClass, $setup))->toBe([])
        // build_word needs two targets, so three words still play.
        ->and(optionFor($this->class, 'build_word', 'fruit', 'words-long')['startable'])->toBeTrue()
        ->and(optionFor($this->class, 'find', 'animals', 'words-long')['startable'])->toBeTrue();

    // A teacher word brings the level back.
    addTeacherWord($this->teacher, 'fruit', 'pomidor');

    expect(optionFor($this->class, 'find', 'fruit', 'words-long')['startable'])->toBeTrue()
        ->and(setupErrors($this->class, $setup))->toBe([])
        ->and(targetKeysOf(Games::start($this->class, [...$setup, 'rounds' => 8], seed: 2)))->toContain('word:pomidor');
});

it('answers with reasons, never an error, when a teacher hides every word of a pack', function (): void {
    foreach (app(ContentRepository::class)->pack('fruit')->words as $word) {
        hideBuiltIn($this->teacher, 'fruit', $word->text);
    }

    $fruit = array_values(array_filter(GameSetupOptions::forClass($this->class)['options'], static fn (array $option): bool => $option['pack'] === 'fruit'));
    $startable = array_values(array_filter($fruit, static fn (array $option): bool => $option['startable']));

    expect($fruit)->toHaveCount(15)
        ->and(array_map(static fn (array $option): string => "{$option['mechanic']} × {$option['level']}", $startable))->toBe(['find × letters', 'pop × letters', 'build_sentence × sentences'])
        ->and(optionFor($this->class, 'find', 'fruit', 'syllables-open')['reason'])->toBe(Playable::message('too_few_syllables', ['count' => 4, 'found' => 0]))
        ->and(optionFor($this->class, 'pop', 'fruit', 'words-short')['reason'])->toBe(Playable::message('too_few_words', ['count' => 4, 'found' => 0]))
        ->and(optionFor($this->class, 'build_syllable', 'fruit', 'syllables-closed')['reason'])->toBe(Playable::message('too_few_syllables', ['count' => 2, 'found' => 0]))
        ->and(optionFor($this->class, 'build_word', 'fruit', 'words-short')['reason'])->toBe(Playable::message('too_few_words', ['count' => 2, 'found' => 0]))
        ->and(setupErrors($this->class, ['mechanic' => 'build_word', 'pack' => 'fruit', 'level' => 'words-long']))->toHaveKey('level')
        ->and(Games::start($this->class, ['mechanic' => 'build_sentence', 'pack' => 'fruit', 'level' => 'sentences'])->pack)->toBe('fruit');
});

it('offers every mechanic of every level in every pack of the teacher', function (): void {
    addTeacherWord($this->teacher, 'fruit', 'pomidor');
    hideBuiltIn($this->teacher, 'fruit', 'alma');
    $content = app(ContentRepository::class);
    $expected = 0;

    foreach ($content->levels() as $level) {
        $expected += count($level->mechanics) * count($content->packs());
    }

    expect(Playable::options($this->teacher, GameSetupData::learned($this->class)))->toHaveCount($expected)
        ->and(array_filter(GameSetupOptions::forClass($this->class)['options'], static fn (array $option): bool => ! $option['startable']))->toBe([]);
});
