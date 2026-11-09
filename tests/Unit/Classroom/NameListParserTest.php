<?php

use App\Domain\Classroom\Data\PastedNameData;
use App\Domain\Classroom\Enums\PastedNameFlag;
use App\Domain\Classroom\Support\DisplayName;
use App\Domain\Classroom\Support\NameListParser;

/*
 * The paste preview (docs/architecture.md §7, "Pasting a list"): pasted
 * text in, a suggested display name and flags per line out. It writes nothing.
 */

/**
 * @param  list<string>  $active
 * @return list<PastedNameData>
 */
function parseNames(string $text, array $active = []): array
{
    return (new NameListParser)->parse($text, $active);
}

/**
 * @param  list<PastedNameData>  $lines
 * @return list<string|null>
 */
function suggestions(array $lines): array
{
    return array_map(static fn (PastedNameData $line): ?string => $line->display_name, $lines);
}

/**
 * @return list<string>
 */
function flagsOf(PastedNameData $line): array
{
    return $line->toArray()['flags'];
}

it('keeps the first name and drops the surname', function (): void {
    $lines = parseNames("Əli Məmmədov\nAysel Həsənova\nNərmin");

    expect(suggestions($lines))->toBe(['Əli', 'Aysel', 'Nərmin'])
        ->and(array_map(flagsOf(...), $lines))->toBe([[], [], []]);
});

it('strips numbering and bullets', function (string $line): void {
    expect(suggestions(parseNames($line)))->toBe(['Əli']);
})->with([
    '1. Əli Məmmədov',
    '12) Əli Məmmədov',
    '3.Əli Məmmədov',
    '4 - Əli Məmmədov',
    '(5) Əli Məmmədov',
    '№6 Əli Məmmədov',
    '- Əli Məmmədov',
    '• Əli Məmmədov',
    '* Əli Məmmədov',
    '– Əli Məmmədov',
    '1. - Əli Məmmədov',
]);

it('reads tab columns pasted from Excel or Word', function (): void {
    $lines = parseNames("1\tMəmmədov\tƏli\tRəşid oğlu\t12.03.2018\n2\tHəsənova Aysel\tAZ1234567");

    expect(suggestions($lines))->toBe(['Əli', 'Aysel'])
        ->and($lines[0]->text)->toBe('Məmmədov Əli Rəşid oğlu')
        ->and($lines[1]->text)->toBe('Həsənova Aysel');
});

it('skips blank and numbering-only lines but keeps the line numbers', function (): void {
    $lines = parseNames("\n1. Əli Məmmədov\n\n2.\n---\n3. Aysel Həsənova\n");

    expect(array_map(static fn (PastedNameData $line): int => $line->line, $lines))->toBe([2, 6])
        ->and(suggestions($lines))->toBe(['Əli', 'Aysel']);
});

it('reads school-register order: surname, first name, patronymic', function (string $line, string $expected): void {
    $lines = parseNames($line);

    expect(suggestions($lines))->toBe([$expected])
        ->and($lines[0]->has(PastedNameFlag::SurnameFirst))->toBeTrue();
})->with([
    ['Məmmədov Əli Rəşid oğlu', 'Əli'],
    ['Quliyeva Leyla Kamal qızı', 'Leyla'],
    ['MƏMMƏDOVA AYSEL RAUF QIZI', 'Aysel'],
    ['Məmmədova Aysel Rəşidovna', 'Aysel'],
    ['Əliyev Əli', 'Əli'],
    ['Hüseynzadə Murad', 'Murad'],
    ['Kərimli Nazlı', 'Nazlı'],
    ['Məmmədov Əli R.', 'Əli'],
    ['Ağaoğlu Vüsal', 'Vüsal'],
]);

it('does not take a first name that ends in -li or -zadə for a surname', function (string $line, string $expected): void {
    $lines = parseNames($line);

    expect(suggestions($lines))->toBe([$expected])
        ->and($lines[0]->has(PastedNameFlag::SurnameFirst))->toBeFalse();
})->with([
    ['Əli Əliyev', 'Əli'],
    ['Vəli Kərimli', 'Vəli'],
    ['Nazlı Kərimli', 'Nazlı'],
    ['Məmmədəli Hüseynli', 'Məmmədəli'],
    ['Şahzadə Məmmədova', 'Şahzadə'],
    ['Əli Rəşid oğlu Məmmədov', 'Əli'],
]);

it('reads an ambiguous line in the order of the rest of the list', function (): void {
    $register = parseNames("Məmmədov Əli\nHəsənova Aysel\nMurad Kamran");
    $plain = parseNames("Əli Məmmədov\nAysel Həsənova\nMurad Kamran");

    expect(suggestions($register))->toBe(['Əli', 'Aysel', 'Kamran'])
        ->and(suggestions($plain))->toBe(['Əli', 'Aysel', 'Murad'])
        ->and(suggestions(parseNames('Murad Kamran')))->toBe(['Murad']);
});

it('adds a surname initial only when two first names collide', function (): void {
    $lines = parseNames("Aysel Məmmədova\nAysel Həsənova\nƏli Quliyev");

    expect(suggestions($lines))->toBe(['Aysel M.', 'Aysel H.', 'Əli'])
        ->and(flagsOf($lines[0]))->toBe(['initial_added'])
        ->and(flagsOf($lines[1]))->toBe(['initial_added'])
        ->and(flagsOf($lines[2]))->toBe([]);
});

it('takes the initial from the surname in either order', function (): void {
    expect(suggestions(parseNames("Məmmədova Aysel Rauf qızı\nHəsənova Aysel Kamal qızı")))
        ->toBe(['Aysel M.', 'Aysel H.']);
});

it('adds an initial when an active student of the class has the first name', function (): void {
    $lines = parseNames("Aysel Məmmədova\nƏli Həsənov", ['Aysel', 'Vüsal']);

    expect(suggestions($lines))->toBe(['Aysel M.', 'Əli'])
        ->and(flagsOf($lines[0]))->toBe(['initial_added', 'in_class'])
        ->and($lines[0]->addable())->toBeTrue();
});

it('compares first names with Azerbaijani casing', function (): void {
    expect(suggestions(parseNames("İLKİN Məmmədov\nilkin Həsənov")))->toBe(['İlkin M.', 'İlkin H.'])
        ->and(suggestions(parseNames("Ilkin Məmmədov\nİlkin Həsənov")))->toBe(['Ilkin', 'İlkin']);
});

it('flags a suggestion that is still taken', function (): void {
    $sameInitial = parseNames("Aysel Məmmədova\nAysel Mustafayeva");
    $noSurname = parseNames('Əli', ['Əli']);
    $existingInitial = parseNames('Aysel Məmmədova', ['Aysel M.']);

    expect(suggestions($sameInitial))->toBe(['Aysel M.', 'Aysel M.'])
        ->and(flagsOf($sameInitial[0]))->toBe(['initial_added', 'taken'])
        ->and(flagsOf($sameInitial[1]))->toBe(['initial_added', 'taken'])
        ->and(flagsOf($noSurname[0]))->toBe(['in_class', 'taken'])
        ->and(flagsOf($existingInitial[0]))->toBe(['initial_added', 'in_class', 'taken'])
        ->and($noSurname[0]->addable())->toBeFalse();
});

it('flags exact duplicates and leaves the first one unchanged', function (): void {
    $lines = parseNames("1. Əli Məmmədov\n2. Aysel Həsənova\n3. əli  məmmədov");

    expect(suggestions($lines))->toBe(['Əli', 'Aysel', 'Əli'])
        ->and(flagsOf($lines[0]))->toBe([])
        ->and(flagsOf($lines[2]))->toBe(['duplicate'])
        ->and($lines[2]->addable())->toBeFalse();
});

it('flags lines without a usable first name as invalid', function (string $line): void {
    $lines = parseNames($line);

    expect(suggestions($lines))->toBe([null])
        ->and(flagsOf($lines[0]))->toBe(['invalid'])
        ->and($lines[0]->addable())->toBeFalse();
})->with([
    'a letter outside the alphabet' => 'William Smith',
    'Cyrillic' => 'Алиса Иванова',
    'only a surname' => 'Məmmədov',
    'only initials and a surname' => 'Ə. Məmmədov',
    'odd characters inside a word' => 'Əli/Vəli',
    'too long' => str_repeat('a', 40),
]);

it('normalizes Cyrillic Ә and invisible characters before reading', function (): void {
    $lines = parseNames("\u{04D8}li H\u{04D9}s\u{04D9}nov\n\u{FEFF}Aysel\u{200B}  Quliyeva");

    expect(suggestions($lines))->toBe(['Əli', 'Aysel'])
        ->and($lines[0]->text)->toBe('Əli Həsənov');
});

it('title-cases names typed in capitals or lowercase', function (): void {
    expect(suggestions(parseNames("QULİYEV İLKİN\nnərmin həsənova\ngül-ayşə məmmədova")))
        ->toBe(['İlkin', 'Nərmin', 'Gül-Ayşə']);
});

it('drops apostrophes of the old spelling', function (): void {
    expect(suggestions(parseNames("Mə'sumə Əliyeva")))->toBe(['Məsumə']);
});

it('keeps an initial the teacher already wrote', function (): void {
    expect(suggestions(parseNames("Aysel M.\nAysel H.")))->toBe(['Aysel M.', 'Aysel H.']);
});

it('serializes a line for the preview', function (): void {
    expect(parseNames("Məmmədova Aysel\nHəsənova Aysel")[0]->toArray())->toBe([
        'line' => 1,
        'text' => 'Məmmədova Aysel',
        'display_name' => 'Aysel M.',
        'flags' => ['surname_first', 'initial_added'],
        'addable' => true,
    ]);
});

it('suggests only valid display names', function (): void {
    $text = "1. Məmmədov Əli Rəşid oğlu\n2) Həsənova Aysel\n- Kərimli Nazlı\n• Aysel Məmmədova\nQULİYEV İLKİN\nGül-Ayşə Əliyeva";

    foreach (parseNames($text) as $line) {
        expect(DisplayName::isValid((string) $line->display_name))->toBeTrue();
    }
});
