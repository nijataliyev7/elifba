<?php

use App\Domain\Language\Support\PersonName;

/*
 * Azerbaijani names as people write them (docs/architecture.md §7
 * "Pasting a list", §3 "Voice"): the surname read from its ending, either
 * side of the first name, and the patronymic, shared by the paste parser,
 * the display name rule and the board's voice.
 */

it('reads the first name of a full name, either way round', function (string $name, string $first): void {
    expect(PersonName::firstName($name))->toBe($first);
})->with([
    'a first name alone' => ['Nihat', 'Nihat'],
    'with a surname initial' => ['Nihat Ə.', 'Nihat'],
    'surname after' => ['Nihat Əliyev', 'Nihat'],
    'surname first, as in a register' => ['Əliyev Nihat', 'Nihat'],
    'a woman\'s surname' => ['Aysel Məmmədova', 'Aysel'],
    'a likely surname' => ['Nihat Kərimli', 'Nihat'],
    'a -zadə surname' => ['Rəsulzadə Aysel', 'Aysel'],
    'a patronymic' => ['Nihat Rəşid oğlu', 'Nihat'],
    'a surname and a patronymic' => ['Əliyeva Aysel Kamal qızı', 'Aysel'],
    'a Russian-style patronymic' => ['Aysel Kamalovna', 'Aysel'],
    'a first name that ends like a surname, alone' => ['Xosrov', 'Xosrov'],
    'extra spaces' => ['  Nihat   Əliyev ', 'Nihat'],
]);

/*
 * The owner's rule (2026-11-13, ADR 016): the first word is the first
 * name, whatever follows it is taken as the surname.
 */
it('takes the first word of a name of more words, the rest as the surname', function (string $name, string $first): void {
    expect(PersonName::firstName($name))->toBe($first);
})->with([
    'letters made up to tell two apart' => ['Nicat mp', 'Nicat'],
    'an initial without its dot' => ['Nicat M', 'Nicat'],
    'two first names' => ['Ayşən Nur', 'Ayşən'],
    'two first names with a surname' => ['Ayşən Nur Əliyeva', 'Ayşən'],
    'two first names in one' => ['Məhəmməd Əli', 'Məhəmməd'],
    'the surname first, then two first names' => ['Əliyeva Ayşən Nur', 'Ayşən'],
]);

it('has no first name for no name', function (): void {
    expect(PersonName::firstName(''))->toBeNull()
        ->and(PersonName::firstName('   '))->toBeNull();
});

it('finds a surname or a patronymic that a display name must not keep', function (string $name): void {
    expect(PersonName::hasSurname($name))->toBeTrue();
})->with([
    'surname after' => ['Nihat Əliyev'],
    'surname first' => ['Əliyeva Aysel'],
    'a patronymic' => ['Nihat Rəşid oğlu'],
    'a Russian-style patronymic' => ['Aysel Kamalovna'],
    'a first name of two words and a surname' => ['Ayşən Nur Məmmədova'],
]);

it('lets a first name pass, even one that ends like a surname', function (string $name): void {
    expect(PersonName::hasSurname($name))->toBeFalse();
})->with([
    'a first name' => ['Nihat'],
    'with a surname initial' => ['Aysel M.'],
    'a first name ending in -ov, alone' => ['Xosrov'],
    'the same with an initial' => ['Xosrov M.'],
    'a first name of two words' => ['Məhəmməd Əli'],
    'a first name ending in -zadə' => ['Şahzadə'],
    'hyphenated' => ['Gül-Ayaz'],
]);

it('scores a surname by its ending', function (string $word, int $score): void {
    expect(PersonName::surnameScore($word))->toBe($score);
})->with([
    ['Məmmədov', PersonName::SURE],
    ['Əliyeva', PersonName::SURE],
    ['Rəsulzadə', PersonName::LIKELY],
    ['Kərimli', PersonName::LIKELY],
    ['Nazlı', 0],
    ['Əli', 0],
    ['Nihat', 0],
]);
