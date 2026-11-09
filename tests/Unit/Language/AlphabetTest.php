<?php

use App\Domain\Language\Support\Alphabet;

/*
 * The alphabet from content/alphabet.json (docs/architecture.md §3): the
 * test pins the count, the order and the vowels, so an edit to the file that
 * changes them fails here.
 */

it('has the 32 letters in Azerbaijani order', function (): void {
    expect(Alphabet::count())->toBe(32)
        ->and(implode(' ', Alphabet::letters()))
        ->toBe('a b c ç d e ə f g ğ h x ı i j k q l m n o ö p r s ş t u ü v y z');
});

it('pairs every lowercase letter with its uppercase form', function (): void {
    expect(implode(' ', Alphabet::upperMap()))
        ->toBe('A B C Ç D E Ə F G Ğ H X I İ J K Q L M N O Ö P R S Ş T U Ü V Y Z')
        ->and(Alphabet::upperMap()['i'])->toBe('İ')
        ->and(Alphabet::upperMap()['ı'])->toBe('I')
        ->and(Alphabet::lowerMap()['I'])->toBe('ı')
        ->and(Alphabet::lowerMap()['İ'])->toBe('i');
});

it('knows the nine vowels, and y is a consonant', function (): void {
    expect(Alphabet::vowels())->toBe(['a', 'e', 'ə', 'ı', 'i', 'o', 'ö', 'u', 'ü'])
        ->and(Alphabet::isVowel('Ə'))->toBeTrue()
        ->and(Alphabet::isVowel('I'))->toBeTrue()
        ->and(Alphabet::isVowel('y'))->toBeFalse()
        ->and(Alphabet::isVowel('w'))->toBeFalse();
});

it('uses Ə (U+018F / U+0259), never the Cyrillic Ә or the reversed Ǝ', function (): void {
    expect(Alphabet::isLetter("\u{018F}"))->toBeTrue()
        ->and(Alphabet::isLetter("\u{0259}"))->toBeTrue()
        ->and(Alphabet::isLetter("\u{04D8}"))->toBeFalse()
        ->and(Alphabet::isLetter("\u{018E}"))->toBeFalse();
});

it('finds the place of a letter in either case', function (): void {
    expect(Alphabet::position('a'))->toBe(0)
        ->and(Alphabet::position('Ç'))->toBe(3)
        ->and(Alphabet::position('X'))->toBe(11)
        ->and(Alphabet::position('I'))->toBe(12)
        ->and(Alphabet::position('i'))->toBe(13)
        ->and(Alphabet::position('w'))->toBeNull()
        ->and(Alphabet::position('ab'))->toBeNull();
});

it('tells whether every letter of a text is in the alphabet', function (): void {
    expect(Alphabet::covers('Gül-Ayşə M.'))->toBeTrue()
        ->and(Alphabet::covers('1A sinfi'))->toBeTrue()
        ->and(Alphabet::covers('William'))->toBeFalse()
        ->and(Alphabet::covers('Алиса'))->toBeFalse();
});

it('sorts letters into alphabet order without repeats', function (): void {
    expect(Alphabet::sort(['z', 'ə', 'a', 'ı', 'i', 'a']))->toBe(['a', 'ə', 'ı', 'i', 'z']);
});

it('rejects anything that is not a lowercase letter of the alphabet', function (string $letter): void {
    Alphabet::sort(['a', $letter]);
})->throws(InvalidArgumentException::class)->with(['A', 'w', 'ab', '', "\u{04D9}"]);

it('names every letter the way children hear it spelled', function (): void {
    $names = array_map(Alphabet::name(...), Alphabet::letters());

    expect(implode(' ', $names))
        ->toBe('a be ce çe de e ə fe ge ğe he xe ı i je ke qe el em en o ö pe er se şe te u ü ve ye ze')
        ->and(Alphabet::name('Ğ'))->toBe('ğe')
        ->and(Alphabet::name('I'))->toBe('ı')
        ->and(Alphabet::name('İ'))->toBe('i');
});

it('pairs the letters children confuse', function (string $letter, string $other): void {
    expect(Alphabet::confusables($letter))->toContain($other)
        ->and(Alphabet::confusables($other))->toContain($letter);
})->with([
    ['e', 'ə'],
    ['a', 'ə'],
    ['i', 'ı'],
    ['o', 'ö'],
    ['u', 'ü'],
    ['s', 'ş'],
    ['c', 'ç'],
    ['g', 'ğ'],
    ['k', 'q'],
    ['b', 'd'],
]);

it('keeps confusables symmetric and inside the alphabet', function (): void {
    foreach (Alphabet::letters() as $letter) {
        foreach (Alphabet::confusables($letter) as $other) {
            expect(Alphabet::letters())->toContain($other)
                ->and($other)->not->toBe($letter)
                ->and(Alphabet::confusables($other))->toContain($letter);
        }
    }
});

it('answers confusables in either case, as lowercase letters', function (): void {
    expect(Alphabet::confusables('Ə'))->toBe(['e', 'a'])
        ->and(Alphabet::confusables('I'))->toBe(['i'])
        ->and(Alphabet::confusables('f'))->toBe([]);
});

it('has no name or confusables for a character outside the alphabet', function (string $character): void {
    Alphabet::name($character);
})->throws(InvalidArgumentException::class)->with(['w', "\u{04D9}", 'ab']);
