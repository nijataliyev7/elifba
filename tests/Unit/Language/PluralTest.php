<?php

use App\Domain\Language\Support\Plural;

/*
 * Plural forms on the server in the syntax React reads ("{one}…|{other}…",
 * docs/frontend.md §2), chosen by the language's CLDR category as the
 * browser's Intl.PluralRules does.
 */

it('picks the form of the count\'s plural category', function (string $locale, int $count, string $form): void {
    expect(Plural::choose('{one}:count point|{other}:count points', $count, $locale))->toBe($form);
})->with([
    'en 1' => ['en', 1, ':count point'],
    'en 0' => ['en', 0, ':count points'],
    'en 2' => ['en', 2, ':count points'],
    'en 21' => ['en', 21, ':count points'],
    'az 1' => ['az', 1, ':count point'],
    'az 6' => ['az', 6, ':count points'],
]);

it('falls back to the other form when the language has no form of its own', function (): void {
    expect(Plural::choose('{other}:count xal', 1, 'az'))->toBe(':count xal')
        ->and(Plural::category(1, 'en'))->toBe('one')
        ->and(Plural::category(5, 'en'))->toBe('other');
});

it('returns a line without forms as it is', function (): void {
    expect(Plural::choose('Reytinq', 3, 'az'))->toBe('Reytinq')
        ->and(Plural::choose('a|b', 1, 'en'))->toBe('a|b');
});

it('reads the real points line in both languages', function (): void {
    expect(str_replace(':count', '1', Plural::choose(__('rankings.points', [], 'en'), 1, 'en')))->toBe('1 point')
        ->and(str_replace(':count', '7', Plural::choose(__('rankings.points', [], 'en'), 7, 'en')))->toBe('7 points')
        ->and(str_replace(':count', '7', Plural::choose(__('rankings.points', [], 'az'), 7, 'az')))->toBe('7 xal');
});
