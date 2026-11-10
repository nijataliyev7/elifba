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
 * (docs/architecture.md §3 "Teacher words", ADR 007, ADR 009): a teacher's
 * own words reach every pack of that teacher and no other teacher's; hidden
 * words reach only that teacher's pack. The rules without the
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
    $word = addWord($this->teacher, ['text' => " k\u{04D9}l\u{04D9}m "]);

    expect($word->fresh())
        ->user_id->toBe($this->teacher->id)
        ->text->toBe("k\u{0259}l\u{0259}m")
        ->word_key->toBe("word:k\u{0259}l\u{0259}m")
        ->syllables->toBeNull()
        ->and(strlen($word->id))->toBe(26);
});

it('stores an override and plays the word with it', function (): void {
    addWord($this->teacher, ['text' => 'pomidor', 'syllables' => ['pom', 'i', 'dor']]);

    $word = app(TeacherPacks::class)->pack($this->teacher, 'fruit')->word('pomidor');

    expect($word?->syllables)->toBe(['pom', 'i', 'dor'])
        ->and($word?->overridden)->toBeTrue()
        ->and($word?->image)->toBeNull();
});

it('adds a word to every pack of that teacher only', function (): void {
    addWord($this->teacher, ['text' => 'pomidor']);

    foreach (array_keys(app(ContentRepository::class)->packs()) as $pack) {
        expect(in_array('pomidor', packTexts($this->teacher, $pack), true))->toBeTrue($pack)
            ->and(packTexts($this->other, $pack))->not->toContain('pomidor');
    }

    expect(app(TeacherPacks::class)->pack($this->teacher, 'train', own: true)->words)->toHaveCount(1)
        ->and(app(ContentRepository::class)->pack('fruit')->word('pomidor'))->toBeNull()
        ->and(app(TeacherPacks::class)->packs($this->teacher)['fruit']->word('pomidor'))->not->toBeNull()
        ->and(array_keys(app(TeacherPacks::class)->packs($this->teacher)))->toBe(array_keys(app(ContentRepository::class)->packs()));
});

it('refuses a word the teacher already has, in any case', function (): void {
    addWord($this->teacher, ['text' => 'kələm']);

    $errors = actionErrors(fn () => addWord($this->teacher, ['text' => 'Kələm']));

    expect($errors)->toBe(['text' => [WordRules::message('taken')]])
        ->and(implode(' ', $errors['text']))->not->toContain('ələm')
        ->and(CustomWord::query()->count())->toBe(1);

    // For another teacher it is a new word.
    addWord($this->other, ['text' => 'kələm']);

    expect(CustomWord::query()->count())->toBe(2);
});

it('keeps one word per teacher and key in the database too', function (): void {
    CustomWord::factory()->for($this->teacher, 'teacher')->create(['text' => 'kələm']);

    expect(fn () => CustomWord::factory()->for($this->teacher, 'teacher')->create(['text' => 'Kələm']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('caps the words a teacher keeps', function (): void {
    config(['elifba.custom_words.max_per_teacher' => 2]);

    addWord($this->teacher, ['text' => 'pomidor']);
    addWord($this->teacher, ['text' => 'kələm']);

    expect(actionErrors(fn () => addWord($this->teacher, ['text' => 'qarpız'])))->toBe(['text' => [WordRules::message('too_many', ['max' => 2])]])
        ->and(actionErrors(fn () => addWord($this->other, ['text' => 'kələm'])))->toBe([]);
});

it('edits a word: text and override, unique among the teacher\'s other words', function (): void {
    $pomidor = addWord($this->teacher, ['text' => 'pomidor']);
    addWord($this->teacher, ['text' => 'kələm']);
    $update = static fn (CustomWord $word, array $input): CustomWord => app(UpdateCustomWord::class)->handle($word, CustomWordData::validate($input));

    expect(actionErrors(fn () => $update($pomidor, ['text' => 'Kələm'])))->toBe(['text' => [WordRules::message('taken')]]);

    $update($pomidor, ['text' => 'Pomidor', 'syllables' => ['Pom', 'i', 'dor']]);

    expect($pomidor->fresh())
        ->text->toBe('Pomidor')
        ->word_key->toBe('word:pomidor')
        ->syllables->toBe(['Pom', 'i', 'dor']);

    $update($pomidor, ['text' => 'pomidor']);

    expect($pomidor->fresh())
        ->syllables->toBeNull()
        ->and(packTexts($this->teacher, 'fruit'))->toContain('pomidor')
        ->and(packTexts($this->teacher, 'train'))->toContain('pomidor');
});

it('deletes a word', function (): void {
    $word = addWord($this->teacher, ['text' => 'pomidor']);

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

it('takes a built-in word of one pack as a teacher word, played in the other packs', function (): void {
    addWord($this->teacher, ['text' => 'alma']);
    $almas = static fn (string $pack): array => array_values(array_filter(
        app(TeacherPacks::class)->pack(test()->teacher, $pack)->words,
        static fn ($word): bool => $word->key() === 'word:alma',
    ));

    // In fruit the built-in alma plays, with its picture; elsewhere the teacher's.
    expect($almas('fruit'))->toHaveCount(1)
        ->and($almas('fruit')[0]->image)->not->toBeNull()
        ->and($almas('train'))->toHaveCount(1)
        ->and($almas('train')[0]->image)->toBeNull()
        ->and(app(TeacherPacks::class)->pack($this->teacher, 'fruit', own: true)->words)->toBe([]);

    // Hiding the built-in alma does not bring the teacher's back in fruit.
    hideWord($this->teacher, 'fruit', 'alma');

    expect($almas('fruit'))->toBe([]);
});

it('plays the built-in word when a later pack version adds a word the teacher has', function (): void {
    CustomWord::factory()->for($this->teacher, 'teacher')->create(['text' => 'Alma']);
    $pack = app(TeacherPacks::class)->pack($this->teacher, 'fruit');
    $almas = array_filter($pack->words, static fn ($word): bool => $word->key() === 'word:alma');

    expect($almas)->toHaveCount(1)
        ->and(array_values($almas)[0]->text)->toBe('alma')
        ->and(array_values($almas)[0]->image)->not->toBeNull()
        ->and($pack->words)->toHaveCount(count(app(ContentRepository::class)->pack('fruit')->words));
});

it('lists the teacher\'s words in the order they were added', function (): void {
    $this->travelTo(now()->subMinutes(2));
    addWord($this->teacher, ['text' => 'pomidor']);
    $this->travelBack();
    addWord($this->teacher, ['text' => 'kələm']);

    expect(array_slice(packTexts($this->teacher, 'fruit'), -2))->toBe(['pomidor', 'kələm']);
});

it('deletes a teacher\'s words and hidden words with the teacher', function (): void {
    addWord($this->teacher, ['text' => 'pomidor']);
    hideWord($this->teacher, 'fruit', 'alma');
    addWord($this->other, ['text' => 'pomidor']);

    $this->teacher->delete();

    expect(CustomWord::query()->pluck('user_id')->all())->toBe([$this->other->id])
        ->and(HiddenWord::query()->count())->toBe(0);
});
