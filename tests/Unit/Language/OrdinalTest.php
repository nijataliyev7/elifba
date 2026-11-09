<?php

use App\Domain\Language\Support\Ordinal;

/*
 * Ordinals with vowel harmony (docs/architecture.md §3). The table is
 * written by hand: units 1-ci 2-ci 3-cü 4-cü 5-ci 6-cı 7-ci 8-ci 9-cu; tens
 * on → cu, iyirmi → ci, otuz → cu, qırx → cı, əlli → ci, altmış → cı,
 * yetmiş → ci, səksən → ci, doxsan → cı; yüz → cü.
 */

const ORDINALS_1_TO_100 = <<<'TXT'
1-ci 2-ci 3-cü 4-cü 5-ci 6-cı 7-ci 8-ci 9-cu 10-cu
11-ci 12-ci 13-cü 14-cü 15-ci 16-cı 17-ci 18-ci 19-cu 20-ci
21-ci 22-ci 23-cü 24-cü 25-ci 26-cı 27-ci 28-ci 29-cu 30-cu
31-ci 32-ci 33-cü 34-cü 35-ci 36-cı 37-ci 38-ci 39-cu 40-cı
41-ci 42-ci 43-cü 44-cü 45-ci 46-cı 47-ci 48-ci 49-cu 50-ci
51-ci 52-ci 53-cü 54-cü 55-ci 56-cı 57-ci 58-ci 59-cu 60-cı
61-ci 62-ci 63-cü 64-cü 65-ci 66-cı 67-ci 68-ci 69-cu 70-ci
71-ci 72-ci 73-cü 74-cü 75-ci 76-cı 77-ci 78-ci 79-cu 80-ci
81-ci 82-ci 83-cü 84-cü 85-ci 86-cı 87-ci 88-ci 89-cu 90-cı
91-ci 92-ci 93-cü 94-cü 95-ci 96-cı 97-ci 98-ci 99-cu 100-cü
TXT;

it('builds every ordinal from 1 to 100', function (): void {
    $expected = preg_split('/\s+/', ORDINALS_1_TO_100) ?: [];

    expect(array_map(Ordinal::make(...), range(1, 100)))->toBe($expected);
});

it('follows the last number word said aloud above 100', function (int $number, string $ordinal): void {
    expect(Ordinal::make($number))->toBe($ordinal);
})->with([
    'yüz on' => [110, '110-cu'],
    'min' => [1000, '1000-ci'],
    'yüz bir' => [101, '101-ci'],
    'iki yüz' => [200, '200-cü'],
    'yüz qırx' => [140, '140-cı'],
    'iki min' => [2000, '2000-ci'],
    'iki min beş yüz' => [2500, '2500-cü'],
    'min altı' => [1006, '1006-cı'],
    'yüz min' => [100_000, '100000-ci'],
    'bir milyon' => [1_000_000, '1000000-cu'],
    'bir milyard' => [1_000_000_000, '1000000000-cı'],
]);

it('gives the bare suffix', function (): void {
    expect(Ordinal::suffix(3))->toBe('cü')
        ->and(Ordinal::suffix(6))->toBe('cı')
        ->and(Ordinal::suffix(9))->toBe('cu')
        ->and(Ordinal::suffix(1))->toBe('ci');
});

it('starts places at 1', function (int $number): void {
    Ordinal::make($number);
})->throws(InvalidArgumentException::class)->with([0, -1]);

it('labels a place in the interface language', function (int $number, string $az, string $en): void {
    expect(Ordinal::label($number, 'az'))->toBe($az)
        ->and(Ordinal::label($number, 'en'))->toBe($en);
})->with([
    [1, '1-ci', '1st'],
    [2, '2-ci', '2nd'],
    [3, '3-cü', '3rd'],
    [4, '4-cü', '4th'],
    [6, '6-cı', '6th'],
    [11, '11-ci', '11th'],
    [12, '12-ci', '12th'],
    [13, '13-cü', '13th'],
    [21, '21-ci', '21st'],
    [22, '22-ci', '22nd'],
    [40, '40-cı', '40th'],
    [101, '101-ci', '101st'],
    [111, '111-ci', '111th'],
    [112, '112-ci', '112th'],
]);

it('starts English places at 1 too', function (int $number): void {
    Ordinal::label($number, 'en');
})->throws(InvalidArgumentException::class)->with([0, -1]);
