<?php

use App\Domain\Content\Data\CustomWordData;
use App\Domain\Content\Data\HiddenWordData;
use App\Domain\Content\Data\WordData;
use App\Domain\Content\Enums\WordProblem;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TeacherPacks;
use App\Domain\Content\Support\WordRules;
use Illuminate\Validation\ValidationException;

/*
 * Teacher words without the database (docs/architecture.md §3 "Teacher
 * words", ADR 007): the word rules shared with ContentValidator, the rules
 * of CustomWordData and HiddenWordData, and the merge behind TeacherPacks.
 * The actions and Play are in tests/Feature/Content/TeacherWordsTest.php and
 * tests/Feature/Play/TeacherWordsInGamesTest.php.
 */

/**
 * The validation errors of an input, by field; [] when it passes.
 *
 * @param  array<string, mixed>  $input
 * @return array<string, list<string>>
 */
function customWordErrors(array $input): array
{
    try {
        CustomWordData::validate($input);
    } catch (ValidationException $exception) {
        /** @var array<string, list<string>> $errors */
        $errors = $exception->errors();

        return $errors;
    }

    return [];
}

it('checks a word as pack files are checked', function (string $text, ?array $syllables, array $problems): void {
    expect(WordRules::problems($text, $syllables))->toBe($problems);
})->with([
    'a word' => ['alma', null, []],
    'a proper noun' => ['Bakı', null, []],
    'a space' => ['iki söz', null, [WordProblem::Letters]],
    'a hyphen' => ['gül-gül', null, [WordProblem::Letters]],
    'a digit' => ['alma1', null, [WordProblem::Letters]],
    'a letter outside the alphabet' => ['wifi', null, [WordProblem::Letters]],
    'Cyrillic ә, not normalized' => ["k\u{04D9}l\u{04D9}m", null, [WordProblem::Letters]],
    'capitals' => ['ALMA', null, [WordProblem::Casing]],
    'a capital inside' => ['alMa', null, [WordProblem::Casing]],
    'no vowel' => ['qrz', null, [WordProblem::NoVowel]],
    'a valid override' => ['pomidor', ['pom', 'i', 'dor'], []],
    'an override that does not join back' => ['pomidor', ['po', 'mi', 'do'], [WordProblem::Split]],
    'an override with two vowels in a part' => ['pomidor', ['pomi', 'dor'], [WordProblem::Split]],
    'an override in another case' => ['Bakı', ['ba', 'kı'], [WordProblem::Split]],
    'an override the rule gives' => ['pomidor', ['po', 'mi', 'dor'], [WordProblem::SplitByRule]],
]);

it('accepts a teacher word and normalizes what a phone keyboard types', function (): void {
    $data = CustomWordData::validate(['pack' => 'fruit', 'text' => "  k\u{04D9}l\u{04D9}m\u{200B} "]);

    expect($data->text)->toBe("k\u{0259}l\u{0259}m")
        ->and($data->pack)->toBe('fruit')
        ->and($data->syllables)->toBeNull();
});

it('keeps an override that differs from the rule, and drops one that does not', function (): void {
    expect(CustomWordData::validate(['pack' => 'fruit', 'text' => 'pomidor', 'syllables' => ['pom', 'i', 'dor']])->syllables)->toBe(['pom', 'i', 'dor'])
        ->and(CustomWordData::validate(['pack' => 'fruit', 'text' => 'pomidor', 'syllables' => ['po', 'mi', 'dor']])->syllables)->toBeNull()
        ->and(CustomWordData::validate(['pack' => 'fruit', 'text' => "k\u{04D9}l\u{04D9}m", 'syllables' => ["k\u{04D9}", "l\u{04D9}m"]])->syllables)->toBeNull()
        ->and(CustomWordData::validate(['pack' => 'fruit', 'text' => 'pomidor', 'syllables' => []])->syllables)->toBeNull();
});

it('refuses a word that breaks a pack-word rule, on the text', function (string $text, WordProblem $problem): void {
    expect(customWordErrors(['pack' => 'fruit', 'text' => $text]))->toBe(['text' => [WordRules::message($problem->value)]]);
})->with([
    'two words' => ['iki söz', WordProblem::Letters],
    'a sign' => ['alma!', WordProblem::Letters],
    'capitals' => ['POMIDOR', WordProblem::Casing],
    'no vowel' => ['qrz', WordProblem::NoVowel],
]);

it('refuses a broken override on the syllables', function (array $syllables): void {
    expect(customWordErrors(['pack' => 'fruit', 'text' => 'pomidor', 'syllables' => $syllables]))
        ->toBe(['syllables' => [WordRules::message(WordProblem::Split->value)]]);
})->with([
    'does not join back' => [['po', 'mi', 'do']],
    'two vowels in a part' => [['pomi', 'dor']],
    'other letters' => [['ba', 'na', 'na']],
]);

it('refuses a built-in word of the pack, whatever its case, without naming it', function (string $text): void {
    $errors = customWordErrors(['pack' => 'fruit', 'text' => $text]);

    expect($errors)->toBe(['text' => [WordRules::message('built_in')]])
        ->and(implode(' ', $errors['text']))->not->toContain('alma');
})->with(['alma', 'Alma']);

it('accepts a word that is built in only in another pack', function (): void {
    expect(customWordErrors(['pack' => 'train', 'text' => 'alma']))->toBe([])
        ->and(app(ContentRepository::class)->pack('train')->word('alma'))->toBeNull();
});

it('refuses a word that fits no word level, counting the override', function (): void {
    // ki-tab-xa-na-çı: five syllables; the word levels take one to four.
    expect(customWordErrors(['pack' => 'train', 'text' => 'kitabxanaçı']))->toBe(['text' => [WordRules::message('no_level', ['count' => 5])]])
        ->and(customWordErrors(['pack' => 'train', 'text' => 'kitabxanaçı', 'syllables' => ['ki', 'tab', 'xa', 'na', 'çı']]))->toBe(['text' => [WordRules::message('no_level', ['count' => 5])]])
        ->and(customWordErrors(['pack' => 'train', 'text' => 'televizor']))->toBe([]);
});

it('caps the length and needs a known pack', function (): void {
    config(['elifba.custom_words.max_length' => 8]);

    expect(customWordErrors(['pack' => 'fruit', 'text' => 'pomidorlar']))->toHaveKey('text')
        ->and(customWordErrors(['pack' => 'fruit', 'text' => 'pomidor']))->toBe([])
        ->and(customWordErrors(['pack' => 'vegetables', 'text' => 'pomidor']))->toHaveKeys(['pack'])
        ->and(customWordErrors(['pack' => 'vegetables', 'text' => 'pomidor']))->not->toHaveKey('text')
        ->and(customWordErrors(['text' => 'pomidor']))->toHaveKey('pack')
        ->and(customWordErrors(['pack' => 'fruit']))->toHaveKey('text');
});

it('hides only a built-in word of the pack, named in any case and normalized', function (): void {
    $hidden = HiddenWordData::validate(['pack' => 'fruit', 'word' => " ALMA\u{200B}"]);

    expect($hidden->key())->toBe('word:alma')
        ->and(HiddenWordData::validate(['pack' => 'fruit', 'word' => "\u{04D9}rik"])->key())->toBe("word:\u{0259}rik")
        ->and(fn () => HiddenWordData::validate(['pack' => 'fruit', 'word' => 'pomidor']))->toThrow(ValidationException::class)
        ->and(fn () => HiddenWordData::validate(['pack' => 'train', 'word' => 'alma']))->toThrow(ValidationException::class);
});

it('merges a teacher\'s pack: built-in words without the hidden ones, then the teacher\'s own', function (): void {
    $fruit = app(ContentRepository::class)->pack('fruit');
    $pack = TeacherPacks::merge($fruit, [WordData::fromArray(['text' => 'pomidor']), WordData::fromArray(['text' => 'kələm'])], ['word:alma', 'word:nar']);
    $texts = array_map(static fn (WordData $word): string => $word->text, $pack->words);

    expect($texts)->toHaveCount(count($fruit->words) - 2 + 2)
        ->and($texts)->not->toContain('alma')
        ->and($texts)->not->toContain('nar')
        ->and(array_slice($texts, -2))->toBe(['pomidor', 'kələm'])
        ->and($pack->word('pomidor')?->image)->toBeNull()
        ->and($pack->word('pomidor')?->syllables)->toBe(['po', 'mi', 'dor'])
        ->and($pack->sentences)->toBe($fruit->sentences)
        ->and($pack->style)->toBe($fruit->style)
        ->and($pack->version)->toBe($fruit->version)
        ->and($fruit->word('alma'))->not->toBeNull();
});

it('lets a built-in word win over a teacher word with the same letters, hidden or not', function (): void {
    $fruit = app(ContentRepository::class)->pack('fruit');
    $teacher = [WordData::fromArray(['text' => 'Alma']), WordData::fromArray(['text' => 'pomidor']), WordData::fromArray(['text' => 'Pomidor'])];
    $merged = TeacherPacks::merge($fruit, $teacher, []);
    $hidden = TeacherPacks::merge($fruit, $teacher, ['word:alma']);
    $count = static fn (array $words, string $key): int => count(array_filter($words, static fn (WordData $word): bool => $word->key() === $key));

    expect($count($merged->words, 'word:alma'))->toBe(1)
        ->and($merged->word('alma')?->text)->toBe('alma')
        ->and($merged->word('alma')?->image)->not->toBeNull()
        ->and($count($merged->words, 'word:pomidor'))->toBe(1)
        ->and($merged->word('pomidor')?->text)->toBe('pomidor')
        ->and($merged->words)->toHaveCount(count($fruit->words) + 1)
        ->and($hidden->word('alma'))->toBeNull()
        ->and($hidden->words)->toHaveCount(count($fruit->words));
});
