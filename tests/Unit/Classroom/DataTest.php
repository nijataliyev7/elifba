<?php

use App\Domain\Classroom\Data\AddStudentsData;
use App\Domain\Classroom\Data\ClassData;
use App\Domain\Classroom\Data\LearnedLettersData;
use App\Domain\Classroom\Data\NameListData;
use App\Domain\Classroom\Data\ReorderStudentsData;
use App\Domain\Classroom\Data\StudentData;
use App\Domain\Classroom\Support\DisplayName;
use App\Domain\Content\Support\Avatars;
use Illuminate\Validation\ValidationException;

/*
 * `*Data::rules()` and the values the Data objects carry. Every name is
 * normalized before it is checked or stored.
 */

/**
 * @param  callable(): mixed  $build
 * @return array<string, list<string>>
 */
function validationErrors(callable $build): array
{
    try {
        $build();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('accepts display names of Azerbaijani letters', function (string $name): void {
    expect(DisplayName::isValid($name))->toBeTrue();
})->with(['Əli', 'Aysel M.', 'Gül-Ayşə', 'Əli Murad', 'İlkin', 'Ismayıl', str_repeat('a', 40)]);

it('rejects other display names', function (string $name): void {
    expect(DisplayName::isValid($name))->toBeFalse();
})->with(['', 'M.', 'Aysel.', 'Aysel Mə.', 'Əli  Vəli', 'Əli-', '-Əli', 'Əli2', 'William', 'Алиса', "Mə'sumə", str_repeat('a', 41)]);

it('normalizes a class name and checks the grade', function (): void {
    $data = ClassData::validate(['name' => "  1A \u{00A0} sinfi ", 'grade' => '2']);

    expect($data->name)->toBe('1A sinfi')
        ->and($data->grade)->toBe(2)
        ->and(validationErrors(fn () => ClassData::validate(['name' => '   ', 'grade' => 5])))->toHaveKeys(['name', 'grade'])
        ->and(validationErrors(fn () => ClassData::validate(['name' => str_repeat('a', 41)])))->toHaveKey('name')
        ->and(ClassData::validate(['name' => str_repeat('a', 40).'   '])->grade)->toBeNull();
});

it('normalizes a student name before checking it', function (): void {
    $data = StudentData::validate(['display_name' => "  \u{04D8}li   M. ", 'avatar' => Avatars::keys()[0]]);

    expect($data->display_name)->toBe('Əli M.')
        ->and((new StudentData(' Әli ', 'panda'))->display_name)->toBe('Əli')
        ->and(validationErrors(fn () => StudentData::validate(['display_name' => 'William', 'avatar' => 'unicorn'])))
        ->toHaveKeys(['display_name', 'avatar']);
});

it('validates a confirmed list of names', function (): void {
    $data = AddStudentsData::validate(['names' => [' Əli ', "\u{04D8}ysel"]]);
    $errors = validationErrors(fn () => AddStudentsData::validate(['names' => ['Əli', 'Əli2', '']]));

    expect($data->names)->toBe(['Əli', 'Əysel'])
        ->and($errors)->toHaveKeys(['names.1', 'names.2'])
        ->and(validationErrors(fn () => AddStudentsData::validate(['names' => []])))->toHaveKey('names')
        ->and(validationErrors(fn () => AddStudentsData::validate(['names' => array_fill(0, AddStudentsData::MAX_NAMES + 1, 'Əli')])))->toHaveKey('names');
});

it('keeps names out of validation messages', function (): void {
    $errors = validationErrors(fn () => AddStudentsData::validate(['names' => ['Wilhelmina']]));

    expect(implode(' ', $errors['names.0']))->not->toContain('Wilhelmina');
});

it('stores learned letters lowercase in alphabet order, and all 32 as null', function (): void {
    $all = 'a b c ç d e ə f g ğ h x ı i j k q l m n o ö p r s ş t u ü v y z';

    expect(LearnedLettersData::validate(['letters' => ['z', 'ə', 'a', 'i', 'ı']])->letters)->toBe(['a', 'ə', 'ı', 'i', 'z'])
        ->and(LearnedLettersData::validate(['letters' => explode(' ', $all)])->letters)->toBeNull()
        ->and(LearnedLettersData::validate(['letters' => null])->letters)->toBeNull()
        ->and(LearnedLettersData::validate(['letters' => []])->letters)->toBe([]);
});

it('rejects learned letters outside the alphabet', function (array $letters): void {
    expect(validationErrors(fn () => LearnedLettersData::validate(['letters' => $letters])))->not->toBeEmpty();
})->with([
    'uppercase' => [['A']],
    'not a letter of the alphabet' => [['w']],
    'Cyrillic ә' => [["\u{04D9}"]],
    'two letters' => [['ab']],
    'a repeat' => [['a', 'a']],
]);

it('refuses to build learned letters outside the alphabet', function (): void {
    new LearnedLettersData(['a', 'w']);
})->throws(InvalidArgumentException::class);

it('requires the learned letters key', function (): void {
    expect(validationErrors(fn () => LearnedLettersData::validate([])))->toHaveKey('letters');
});

it('validates an order and a pasted text', function (): void {
    expect(ReorderStudentsData::validate(['student_ids' => ['01a', '01b']])->student_ids)->toBe(['01a', '01b'])
        ->and(validationErrors(fn () => ReorderStudentsData::validate(['student_ids' => ['01a', '01a']])))->not->toBeEmpty()
        ->and(validationErrors(fn () => ReorderStudentsData::validate(['student_ids' => []])))->toHaveKey('student_ids')
        ->and(NameListData::validate(['text' => "Əli\nAysel"])->text)->toBe("Əli\nAysel")
        ->and(validationErrors(fn () => NameListData::validate(['text' => str_repeat('a', NameListData::MAX_LENGTH + 1)])))->toHaveKey('text');
});
