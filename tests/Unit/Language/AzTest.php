<?php

use App\Domain\Language\Support\Az;

/*
 * Azerbaijani casing and normalization (docs/architecture.md §3).
 */

it('uppercases i to İ and ı to I', function (): void {
    expect(Az::upper('ilkin'))->toBe('İLKİN')
        ->and(Az::upper('ılıq'))->toBe('ILIQ')
        ->and(Az::upper('şüşə çiçək'))->toBe('ŞÜŞƏ ÇİÇƏK')
        ->and(mb_strtoupper('ilkin'))->toBe('ILKIN'); // what mbstring alone gets wrong
});

it('lowercases İ to i and I to ı', function (): void {
    expect(Az::lower('İLKİN'))->toBe('ilkin')
        ->and(Az::lower('IŞIQ'))->toBe('ışıq')
        ->and(Az::lower('ƏLİ'))->toBe('əli')
        ->and(mb_strtolower('İ'))->toBe("i\u{0307}"); // what mbstring alone gets wrong
});

it('treats "İlkin" and "ilkin" as the same name, and "Ilkin" as another', function (): void {
    expect(Az::lower('İlkin'))->toBe(Az::lower('ilkin'))
        ->and(Az::lower('Ilkin'))->toBe('ılkin')
        ->and(Az::lower('Ilkin'))->not->toBe(Az::lower('ilkin'));
});

it('round-trips every letter', function (): void {
    $lower = 'abcçdeəfgğhxıijkqlmnoöprsştuüvyz';

    expect(Az::lower(Az::upper($lower)))->toBe($lower)
        ->and(Az::upper($lower))->toBe('ABCÇDEƏFGĞHXIİJKQLMNOÖPRSŞTUÜVYZ');
});

it('title-cases every word and hyphenated part', function (string $input, string $expected): void {
    expect(Az::title($input))->toBe($expected);
})->with([
    ['ƏLİ', 'Əli'],
    ['ilkin', 'İlkin'],
    ['ISMAYIL', 'Ismayıl'],
    ['gül-ayşə', 'Gül-Ayşə'],
    ['aysel m.', 'Aysel M.'],
]);

it('normalizes look-alike letters to Ə and i', function (string $input, string $expected): void {
    expect(Az::normalize($input))->toBe($expected);
})->with([
    'Cyrillic Ә' => ["\u{04D8}li", 'Əli'],
    'Cyrillic ә' => ["M\u{04D9}mm\u{04D9}d", 'Məmməd'],
    'reversed Ǝ' => ["\u{018E}li", 'Əli'],
    'turned ǝ' => ["v\u{01DD}li", 'vəli'],
    'i with a combining dot' => ["i\u{0307}lkin", 'ilkin'],
]);

it('composes to NFC', function (): void {
    // ş written as s + combining cedilla, ü as u + combining diaeresis
    expect(Az::normalize("s\u{0327}u\u{0308}s\u{0327}\u{0259}"))->toBe('şüşə');
});

it('trims, collapses whitespace and drops invisible characters', function (): void {
    expect(Az::normalize("  Aysel \t\u{00A0} M. \n"))->toBe('Aysel M.')
        ->and(Az::normalize("\u{FEFF}Ə\u{200B}li\u{00AD}"))->toBe('Əli')
        ->and(Az::normalize('   '))->toBe('');
});

it('repairs invalid UTF-8 instead of failing', function (): void {
    expect(Az::normalize("Əli\xC3"))->toBe('Əli?');
});

it('compares names as they are typed without the Azerbaijani letters', function (): void {
    expect(Az::plain('Lalə'))->toBe('lale')
        ->and(Az::plain('Lale'))->toBe('lale')
        ->and(Az::plain('İlkin'))->toBe(Az::plain('ILKIN'))->toBe('ilkin')
        ->and(Az::plain('Şükür Çiçək Ağa Gözəl'))->toBe('sukur cicek aga gozel')
        // a and ə stay apart: «Ali» is not «Əli».
        ->and(Az::plain('Əli'))->not->toBe(Az::plain('Ali'));
});
