<?php

use App\Domain\Classroom\Data\PastedNameData;
use App\Domain\Classroom\Enums\PastedNameFlag;
use App\Domain\Classroom\Support\DisplayName;
use App\Domain\Classroom\Support\NameListParser;

/*
 * The paste preview (docs/architecture.md §7, "Pasting a list"): pasted
 * text in, a suggested display name and flags per line out. It writes
 * nothing. The name is kept whole, first name first (ADR 018).
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

it('keeps the whole name, first name first', function (): void {
    $lines = parseNames("Əli Məmmədov\nAysel Həsənova\nNərmin");

    expect(suggestions($lines))->toBe(['Əli Məmmədov', 'Aysel Həsənova', 'Nərmin'])
        ->and(array_map(flagsOf(...), $lines))->toBe([[], [], []]);
});

it('strips numbering and bullets', function (string $line): void {
    expect(suggestions(parseNames($line)))->toBe(['Əli Məmmədov']);
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

    expect(suggestions($lines))->toBe(['Əli Məmmədov Rəşid oğlu', 'Aysel Həsənova'])
        ->and($lines[0]->text)->toBe('Məmmədov Əli Rəşid oğlu')
        ->and($lines[1]->text)->toBe('Həsənova Aysel');
});

it('skips blank and numbering-only lines but keeps the line numbers', function (): void {
    $lines = parseNames("\n1. Əli Məmmədov\n\n2.\n---\n3. Aysel Həsənova\n");

    expect(array_map(static fn (PastedNameData $line): int => $line->line, $lines))->toBe([2, 6])
        ->and(suggestions($lines))->toBe(['Əli Məmmədov', 'Aysel Həsənova']);
});

it('turns school-register order (surname, first name, patronymic) first name first', function (string $line, string $expected): void {
    $lines = parseNames($line);

    expect(suggestions($lines))->toBe([$expected])
        ->and($lines[0]->has(PastedNameFlag::SurnameFirst))->toBeTrue();
})->with([
    ['Məmmədov Əli Rəşid oğlu', 'Əli Məmmədov Rəşid oğlu'],
    ['Quliyeva Leyla Kamal qızı', 'Leyla Quliyeva Kamal qızı'],
    ['MƏMMƏDOVA AYSEL RAUF QIZI', 'Aysel Məmmədova Rauf qızı'],
    ['Məmmədova Aysel Rəşidovna', 'Aysel Məmmədova Rəşidovna'],
    ['Əliyev Əli', 'Əli Əliyev'],
    ['Hüseynzadə Murad', 'Murad Hüseynzadə'],
    ['Kərimli Nazlı', 'Nazlı Kərimli'],
    ['Məmmədov Əli R.', 'Əli Məmmədov'],
    ['Ağaoğlu Vüsal', 'Vüsal Ağaoğlu'],
]);

it('does not take a first name that ends in -li or -zadə for a surname', function (string $line, string $expected): void {
    $lines = parseNames($line);

    expect(suggestions($lines))->toBe([$expected])
        ->and($lines[0]->has(PastedNameFlag::SurnameFirst))->toBeFalse();
})->with([
    ['Əli Əliyev', 'Əli Əliyev'],
    ['Vəli Kərimli', 'Vəli Kərimli'],
    ['Nazlı Kərimli', 'Nazlı Kərimli'],
    ['Məmmədəli Hüseynli', 'Məmmədəli Hüseynli'],
    ['Şahzadə Məmmədova', 'Şahzadə Məmmədova'],
    ['Əli Rəşid oğlu Məmmədov', 'Əli Məmmədov Rəşid oğlu'],
]);

it('reads a surname in the middle as first name first, whatever the rest of the list', function (): void {
    $lines = parseNames("Məmmədov Əli Rəşid oğlu\nnihat eliyev veli\nƏli Həsənov Kamal");

    expect(suggestions($lines))->toBe(['Əli Məmmədov Rəşid oğlu', 'Nihat Eliyev Veli', 'Əli Həsənov Kamal'])
        ->and(flagsOf($lines[1]))->toBe([]);
});

it('reads an ambiguous line in the order of the rest of the list', function (): void {
    $register = parseNames("Məmmədov Əli\nHəsənova Aysel\nMurad Kamran");
    $plain = parseNames("Əli Məmmədov\nAysel Həsənova\nMurad Kamran");

    expect(suggestions($register))->toBe(['Əli Məmmədov', 'Aysel Həsənova', 'Kamran Murad'])
        ->and(suggestions($plain))->toBe(['Əli Məmmədov', 'Aysel Həsənova', 'Murad Kamran'])
        ->and(suggestions(parseNames('Murad Kamran')))->toBe(['Murad Kamran']);
});

it('never adds an initial: two children with one first name keep their surnames', function (): void {
    $lines = parseNames("Aysel Məmmədova\nAysel Həsənova\nƏli Quliyev");

    expect(suggestions($lines))->toBe(['Aysel Məmmədova', 'Aysel Həsənova', 'Əli Quliyev'])
        ->and(array_map(flagsOf(...), $lines))->toBe([[], [], []]);
});

it('warns when an active student has the first name, and adds the line as it is', function (): void {
    $lines = parseNames("Aysel Məmmədova\nƏli Həsənov\nNihat Həsənov", ['Aysel', 'Vüsal', 'Nihat Əliyev']);

    expect(suggestions($lines))->toBe(['Aysel Məmmədova', 'Əli Həsənov', 'Nihat Həsənov'])
        ->and(flagsOf($lines[0]))->toBe(['in_class'])
        ->and(flagsOf($lines[1]))->toBe([])
        ->and(flagsOf($lines[2]))->toBe(['in_class'])
        ->and($lines[0]->addable())->toBeTrue()
        ->and($lines[2]->addable())->toBeTrue();
});

it('compares names with Azerbaijani casing', function (): void {
    expect(flagsOf(parseNames('İLKİN Məmmədov', ['İlkin Məmmədov'])[0]))->toBe(['in_class', 'taken'])
        ->and(flagsOf(parseNames('Ilkin Məmmədov', ['İlkin Məmmədov'])[0]))->toBe([]);
});

it('flags a name that is taken, so the teacher adds the patronymic', function (): void {
    $sameName = parseNames("Əliyev Nihat\nNihat Əliyev");
    $existing = parseNames('Nihat Əliyev', ['Nihat Əliyev']);
    $firstNameOnly = parseNames('Əli', ['Əli']);
    $withPatronymic = parseNames('Nihat Əliyev Vəli oğlu', ['Nihat Əliyev']);

    expect(suggestions($sameName))->toBe(['Nihat Əliyev', 'Nihat Əliyev'])
        ->and(flagsOf($sameName[0]))->toBe(['surname_first', 'taken'])
        ->and(flagsOf($sameName[1]))->toBe(['taken'])
        ->and(flagsOf($existing[0]))->toBe(['in_class', 'taken'])
        ->and(flagsOf($firstNameOnly[0]))->toBe(['in_class', 'taken'])
        ->and($existing[0]->addable())->toBeFalse()
        ->and(suggestions($withPatronymic))->toBe(['Nihat Əliyev Vəli oğlu'])
        ->and(flagsOf($withPatronymic[0]))->toBe(['in_class'])
        ->and($withPatronymic[0]->addable())->toBeTrue();
});

it('flags exact duplicates and leaves the first one unchanged', function (): void {
    $lines = parseNames("1. Əli Məmmədov\n2. Aysel Həsənova\n3. əli  məmmədov");

    expect(suggestions($lines))->toBe(['Əli Məmmədov', 'Aysel Həsənova', 'Əli Məmmədov'])
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
    'too long' => str_repeat('a', 41),
    'too long without the patronymic too' => str_repeat('a', 24).' '.str_repeat('b', 22).' Rəşid oğlu',
]);

it('drops the patronymic only when the whole name would be too long', function (): void {
    expect(suggestions(parseNames('Abdurrəhmanova Gülnarə-Ayşən Səfərəli qızı')))->toBe(['Gülnarə-Ayşən Abdurrəhmanova'])
        ->and(suggestions(parseNames('Məmmədova Aysel Səfərəli qızı')))->toBe(['Aysel Məmmədova Səfərəli qızı']);
});

it('normalizes Cyrillic Ә and invisible characters before reading', function (): void {
    $lines = parseNames("\u{04D8}li H\u{04D9}s\u{04D9}nov\n\u{FEFF}Aysel\u{200B}  Quliyeva");

    expect(suggestions($lines))->toBe(['Əli Həsənov', 'Aysel Quliyeva'])
        ->and($lines[0]->text)->toBe('Əli Həsənov');
});

it('title-cases names typed in capitals or lowercase, the patronymic word lowercase', function (): void {
    expect(suggestions(parseNames("QULİYEV İLKİN\nnərmin həsənova\ngül-ayşə məmmədova\nməmmədov əli rəşid oglu")))
        ->toBe(['İlkin Quliyev', 'Nərmin Həsənova', 'Gül-Ayşə Məmmədova', 'Əli Məmmədov Rəşid oglu']);
});

it('drops apostrophes of the old spelling', function (): void {
    expect(suggestions(parseNames("Mə'sumə Əliyeva")))->toBe(['Məsumə Əliyeva']);
});

it('keeps an initial the teacher already wrote, with its dot', function (): void {
    expect(suggestions(parseNames("Aysel M.\nAysel H\nnicat m")))->toBe(['Aysel M.', 'Aysel H.', 'Nicat M.']);
});

it('serializes a line for the preview', function (): void {
    expect(parseNames("Məmmədova Aysel\nHəsənova Aysel")[0]->toArray())->toBe([
        'line' => 1,
        'text' => 'Məmmədova Aysel',
        'display_name' => 'Aysel Məmmədova',
        'flags' => ['surname_first'],
        'addable' => true,
    ]);
});

it('suggests only valid display names', function (): void {
    $text = "1. Məmmədov Əli Rəşid oğlu\n2) Həsənova Aysel\n- Kərimli Nazlı\n• Aysel Məmmədova\nQULİYEV İLKİN\nGül-Ayşə Əliyeva\nAysel M";

    foreach (parseNames($text) as $line) {
        expect(DisplayName::isValid((string) $line->display_name))->toBeTrue();
    }
});
