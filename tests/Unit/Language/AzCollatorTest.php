<?php

use App\Domain\Language\Support\AzCollator;

/*
 * Names in Azerbaijani alphabetical order (docs/architecture.md §9).
 */

it('sorts names by the 32-letter order', function (): void {
    $names = ['Zəhra', 'Çinarə', 'Əli', 'Cavid', 'Aysel', 'İlkin', 'Ilqar', 'Ömər', 'Orxan', 'Şəms', 'Səbinə', 'Ülvi', 'Uğur', 'Xədicə', 'Hüseyn', 'Qasım', 'Kamran', 'Elvin', 'Fərid', 'Gülnar', 'Ğ', 'Dəniz', 'Bəhruz'];

    expect(AzCollator::sort($names))->toBe([
        'Aysel', 'Bəhruz', 'Cavid', 'Çinarə', 'Dəniz', 'Elvin', 'Əli', 'Fərid', 'Gülnar', 'Ğ', 'Hüseyn', 'Xədicə',
        'Ilqar', 'İlkin', 'Kamran', 'Qasım', 'Orxan', 'Ömər', 'Səbinə', 'Şəms', 'Uğur', 'Ülvi', 'Zəhra',
    ]);
});

it('ignores case, with Azerbaijani casing', function (): void {
    expect(AzCollator::compare('İLKİN', 'ilkin'))->not->toBe(0) // the same letters: decided by bytes, never equal
        ->and(AzCollator::sort(['ilkin', 'IŞIQ', 'Ilkin']))->toBe(['Ilkin', 'IŞIQ', 'ilkin'])
        ->and(AzCollator::sort(['əli', 'Bəhruz']))->toBe(['Bəhruz', 'əli']);
});

it('puts a shorter name and a name with an initial before a longer name', function (): void {
    expect(AzCollator::sort(['Əlim', 'Əli M.', 'Əli', 'Əli H.']))->toBe(['Əli', 'Əli H.', 'Əli M.', 'Əlim']);
});

it('sorts characters outside the alphabet after it', function (): void {
    expect(AzCollator::sort(['William', 'Zəhra', 'Vüsal']))->toBe(['Vüsal', 'Zəhra', 'William']);
});

it('gives the same order for any input order', function (): void {
    $names = ['Aysel H.', 'aysel', 'Aysel', 'Aysel M.', 'Ayşən'];
    $expected = AzCollator::sort($names);

    expect(AzCollator::sort(array_reverse($names)))->toBe($expected)
        ->and($expected)->toBe(['Aysel', 'aysel', 'Aysel H.', 'Aysel M.', 'Ayşən']);
});

it('sorts items by a name', function (): void {
    $items = [['name' => 'Ülvi'], ['name' => 'Ömər'], ['name' => 'Uğur']];

    expect(array_column(AzCollator::sortBy($items, fn (array $item): string => $item['name']), 'name'))
        ->toBe(['Ömər', 'Uğur', 'Ülvi']);
});
