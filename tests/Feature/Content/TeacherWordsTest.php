<?php

use App\Domain\Content\Actions\AddCustomWord;
use App\Domain\Content\Actions\DeleteCustomWord;
use App\Domain\Content\Actions\HideWord;
use App\Domain\Content\Actions\ShowWord;
use App\Domain\Content\Actions\UpdateCustomWord;
use App\Domain\Content\Data\CustomWordData;
use App\Domain\Content\Data\HiddenWordData;
use App\Domain\Content\Models\CustomWord;
use App\Domain\Content\Models\HiddenWord;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TeacherPacks;
use App\Domain\Content\Support\WordRules;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/*
 * The teacher-word actions and TeacherPacks over the database
 * (docs/architecture.md §3 "Teacher words", ADR 007): a teacher's own words
 * and hidden words reach only that teacher's packs. The rules without the
 * database are in tests/Unit/Content/TeacherWordRulesTest.php.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->other = User::factory()->create();
});

/**
 * @param  array<string, mixed>  $input
 */
function addWord(User $teacher, array $input): CustomWord
{
    return app(AddCustomWord::class)->handle($teacher, CustomWordData::validate($input));
}

function hideWord(User $teacher, string $pack, string $word): HiddenWord
{
    return app(HideWord::class)->handle($teacher, HiddenWordData::validate(['pack' => $pack, 'word' => $word]));
}

function showWord(User $teacher, string $pack, string $word): void
{
    app(ShowWord::class)->handle($teacher, HiddenWordData::validate(['pack' => $pack, 'word' => $word]));
}

/**
 * The errors an action throws, by field.
 *
 * @return array<string, list<string>>
 */
function actionErrors(Closure $action): array
{
    try {
        $action();
    } catch (ValidationException $exception) {
        /** @var array<string, list<string>> $errors */
        $errors = $exception->errors();

        return $errors;
    }

    return [];
}

/**
 * @return list<string>
 */
function packTexts(User $teacher, string $pack): array
{
    return array_map(static fn ($word): string => $word->text, app(TeacherPacks::class)->pack($teacher, $pack)->words);
}

it('stores a teacher word normalized, with its key and no override', function (): void {
    $word = addWord($this->teacher, ['pack' => 'fruit', 'text' => " k\u{04D9}l\u{04D9}m "]);

    expect($word->fresh())
        ->user_id->toBe($this->teacher->id)
        ->pack->toBe('fruit')
        ->text->toBe("k\u{0259}l\u{0259}m")
        ->word_key->toBe("word:k\u{0259}l\u{0259}m")
        ->syllables->toBeNull()
        ->and(strlen($word->id))->toBe(26);
});

it('stores an override and plays the word with it', function (): void {
    addWord($this->teacher, ['pack' => 'fruit', 'text' => 'pomidor', 'syllables' => ['pom', 'i', 'dor']]);

    $word = app(TeacherPacks::class)->pack($this->teacher, 'fruit')->word('pomidor');

    expect($word?->syllables)->toBe(['pom', 'i', 'dor'])
        ->and($word?->overridden)->toBeTrue()
        ->and($word?->image)->toBeNull();
});

it('adds a word only to that teacher\'s pack', function (): void {
    addWord($this->teacher, ['pack' => 'fruit', 'text' => 'pomidor']);

    expect(packTexts($this->teacher, 'fruit'))->toContain('pomidor')
        ->and(packTexts($this->other, 'fruit'))->not->toContain('pomidor')
        ->and(packTexts($this->teacher, 'train'))->not->toContain('pomidor')
        ->and(app(ContentRepository::class)->pack('fruit')->word('pomidor'))->toBeNull()
        ->and(app(TeacherPacks::class)->packs($this->teacher)['fruit']->word('pomidor'))->not->toBeNull()
        ->and(array_keys(app(TeacherPacks::class)->packs($this->teacher)))->toBe(array_keys(app(ContentRepository::class)->packs()));
});

it('refuses a word the teacher already has in that pack, in any case', function (): void {
    addWord($this->teacher, ['pack' => 'fruit', 'text' => 'kələm']);

    $errors = actionErrors(fn () => addWord($this->teacher, ['pack' => 'fruit', 'text' => 'Kələm']));

    expect($errors)->toBe(['text' => [WordRules::message('taken')]])
        ->and(implode(' ', $errors['text']))->not->toContain('ələm')
        ->and(CustomWord::query()->count())->toBe(1);

    // The same word in another pack, or for another teacher, is a new word.
    addWord($this->teacher, ['pack' => 'train', 'text' => 'kələm']);
    addWord($this->other, ['pack' => 'fruit', 'text' => 'kələm']);

    expect(CustomWord::query()->count())->toBe(3);
});

it('keeps one word per teacher, pack and key in the database too', function (): void {
    CustomWord::factory()->for($this->teacher, 'teacher')->create(['pack' => 'fruit', 'text' => 'kələm']);

    expect(fn () => CustomWord::factory()->for($this->teacher, 'teacher')->create(['pack' => 'fruit', 'text' => 'Kələm']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('caps the words a teacher keeps over all packs', function (): void {
    config(['elifba.custom_words.max_per_teacher' => 2]);

    addWord($this->teacher, ['pack' => 'fruit', 'text' => 'pomidor']);
    addWord($this->teacher, ['pack' => 'train', 'text' => 'kələm']);

    expect(actionErrors(fn () => addWord($this->teacher, ['pack' => 'animals', 'text' => 'kələm'])))->toBe(['text' => [WordRules::message('too_many', ['max' => 2])]])
        ->and(actionErrors(fn () => addWord($this->other, ['pack' => 'animals', 'text' => 'kələm'])))->toBe([]);
});

it('edits a word: text, override and pack, unique among the teacher\'s other words', function (): void {
    $pomidor = addWord($this->teacher, ['pack' => 'fruit', 'text' => 'pomidor']);
    addWord($this->teacher, ['pack' => 'fruit', 'text' => 'kələm']);
    $update = static fn (CustomWord $word, array $input): CustomWord => app(UpdateCustomWord::class)->handle($word, CustomWordData::validate($input));

    expect(actionErrors(fn () => $update($pomidor, ['pack' => 'fruit', 'text' => 'Kələm'])))->toBe(['text' => [WordRules::message('taken')]]);

    $update($pomidor, ['pack' => 'fruit', 'text' => 'Pomidor', 'syllables' => ['Pom', 'i', 'dor']]);

    expect($pomidor->fresh())
        ->text->toBe('Pomidor')
        ->word_key->toBe('word:pomidor')
        ->syllables->toBe(['Pom', 'i', 'dor']);

    $update($pomidor, ['pack' => 'train', 'text' => 'pomidor']);

    expect($pomidor->fresh())
        ->pack->toBe('train')
        ->syllables->toBeNull()
        ->and(packTexts($this->teacher, 'fruit'))->not->toContain('pomidor')
        ->and(packTexts($this->teacher, 'train'))->toContain('pomidor');
});

it('deletes a word', function (): void {
    $word = addWord($this->teacher, ['pack' => 'fruit', 'text' => 'pomidor']);

    app(DeleteCustomWord::class)->handle($word);

    expect(CustomWord::query()->count())->toBe(0)
        ->and(packTexts($this->teacher, 'fruit'))->not->toContain('pomidor');
});

it('hides a built-in word only for that teacher and shows it again', function (): void {
    $hidden = hideWord($this->teacher, 'fruit', 'Alma');

    expect($hidden->fresh())
        ->user_id->toBe($this->teacher->id)
        ->pack->toBe('fruit')
        ->word_key->toBe('word:alma')
        ->and(packTexts($this->teacher, 'fruit'))->not->toContain('alma')
        ->and(packTexts($this->other, 'fruit'))->toContain('alma')
        ->and(app(TeacherPacks::class)->hiddenKeys($this->teacher))->toBe(['fruit' => ['word:alma']])
        ->and(app(TeacherPacks::class)->hiddenKeys($this->other))->toBe([]);

    // Hiding twice keeps one row; showing twice is harmless.
    expect(hideWord($this->teacher, 'fruit', 'alma')->id)->toBe($hidden->id)
        ->and(HiddenWord::query()->count())->toBe(1);

    showWord($this->teacher, 'fruit', 'alma');
    showWord($this->teacher, 'fruit', 'alma');

    expect(HiddenWord::query()->count())->toBe(0)
        ->and(packTexts($this->teacher, 'fruit'))->toBe(packTexts($this->other, 'fruit'));
});

it('refuses a hidden built-in word as a teacher word', function (): void {
    hideWord($this->teacher, 'fruit', 'alma');

    expect(fn () => addWord($this->teacher, ['pack' => 'fruit', 'text' => 'alma']))->toThrow(ValidationException::class);
});

it('plays the built-in word when a later pack version adds a word the teacher has', function (): void {
    // Stored before the pack had it: the rules refuse it now.
    CustomWord::factory()->for($this->teacher, 'teacher')->create(['pack' => 'fruit', 'text' => 'Alma']);
    $pack = app(TeacherPacks::class)->pack($this->teacher, 'fruit');
    $almas = array_filter($pack->words, static fn ($word): bool => $word->key() === 'word:alma');

    expect($almas)->toHaveCount(1)
        ->and(array_values($almas)[0]->text)->toBe('alma')
        ->and(array_values($almas)[0]->image)->not->toBeNull()
        ->and($pack->words)->toHaveCount(count(app(ContentRepository::class)->pack('fruit')->words));
});

it('lists the teacher\'s words in the order they were added', function (): void {
    $this->travelTo(now()->subMinutes(2));
    addWord($this->teacher, ['pack' => 'fruit', 'text' => 'pomidor']);
    $this->travelBack();
    addWord($this->teacher, ['pack' => 'fruit', 'text' => 'kələm']);

    expect(array_slice(packTexts($this->teacher, 'fruit'), -2))->toBe(['pomidor', 'kələm']);
});

it('deletes a teacher\'s words and hidden words with the teacher', function (): void {
    addWord($this->teacher, ['pack' => 'fruit', 'text' => 'pomidor']);
    hideWord($this->teacher, 'fruit', 'alma');
    addWord($this->other, ['pack' => 'fruit', 'text' => 'pomidor']);

    $this->teacher->delete();

    expect(CustomWord::query()->pluck('user_id')->all())->toBe([$this->other->id])
        ->and(HiddenWord::query()->count())->toBe(0);
});
