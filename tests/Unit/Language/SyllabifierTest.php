<?php

use App\Domain\Language\Support\Syllabifier;

/*
 * Syllabification (docs/architecture.md §3). The words and their expected
 * syllables come from content/tests/syllables.json, written by hand from the
 * textbook rule and reviewed by the owner (O2); never regenerate them by
 * running the Syllabifier.
 */

/**
 * @return array<string, array{string, list<string>}>
 */
function syllableTestSet(): array
{
    $file = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/content/tests/syllables.json'), true, 16, JSON_THROW_ON_ERROR);
    $cases = [];

    foreach ($file['groups'] as $group => $words) {
        foreach ($words as $hyphenated) {
            $parts = explode('-', $hyphenated);
            $cases["{$group}: {$hyphenated}"] = [implode('', $parts), $parts];
        }
    }

    return $cases;
}

dataset('syllable test set', syllableTestSet(...));

it('splits every word of the test set as the textbook does', function (string $word, array $syllables): void {
    expect(Syllabifier::split($word))->toBe($syllables);
})->with('syllable test set');

it('has at least 100 words in the test set, each listed once', function (): void {
    $words = array_column(syllableTestSet(), 0);

    expect(count($words))->toBeGreaterThanOrEqual(100)
        ->and(array_unique($words))->toHaveCount(count($words));
});

it('covers every row and example of the rule table', function (string $hyphenated): void {
    $listed = array_map(static fn (array $case): string => implode('-', $case[1]), array_values(syllableTestSet()));

    expect($listed)->toContain($hyphenated);
})->with(['sa-at', 'mü-əl-lim', 'a-ta', 'a-na', 'al-ma', 'qar-pız', 'dost-luq', 'A-zər-bay-can', 'stol', 'qra-fin', 'dost', 'üst', 'bay-raq', 'qo-yun', 'a-yı', 'a-i-lə', 'şe-ir']);

it('keeps the casing of the word in its parts', function (): void {
    expect(Syllabifier::split('AZƏRBAYCAN'))->toBe(['A', 'ZƏR', 'BAY', 'CAN'])
        ->and(Syllabifier::split('İlkin'))->toBe(['İl', 'kin'])
        ->and(Syllabifier::split('IŞIQ'))->toBe(['I', 'ŞIQ']);
});

it('leaves a word without two vowels whole and an empty word empty', function (): void {
    expect(Syllabifier::split('o'))->toBe(['o'])
        ->and(Syllabifier::split('şkaf'))->toBe(['şkaf'])
        ->and(Syllabifier::split('hmm'))->toBe(['hmm'])
        ->and(Syllabifier::split(''))->toBe([]);
});

it('gives the rule split for a loanword that its pack overrides', function (): void {
    // fruit.json overrides this as qreyp-frut (§3); the rule alone does not know better
    expect(Syllabifier::split('qreypfrut'))->toBe(['qreypf', 'rut']);
});

it('names the shape of a syllable', function (string $syllable, string $shape): void {
    expect(Syllabifier::shape($syllable))->toBe($shape);
})->with([
    ['a', 'V'],
    ['ba', 'CV'],
    ['al', 'VC'],
    ['bal', 'CVC'],
    ['üst', 'VCC'],
    ['dost', 'CVCC'],
    ['qra', 'CCV'],
    ['stol', 'CCVC'],
    ['İ', 'V'],
    ['QIZ', 'CVC'],
    ['yay', 'CVC'],
]);

it('counts vowels in either case, and y is not one', function (): void {
    expect(Syllabifier::vowelCount('Azərbaycan'))->toBe(4)
        ->and(Syllabifier::vowelCount('IŞIQ'))->toBe(2)
        ->and(Syllabifier::vowelCount('yy'))->toBe(0);
});

it('accepts a split only when it joins back exactly with one vowel per part', function (array $parts, bool $valid): void {
    expect(Syllabifier::isSplitOf('qreypfrut', $parts))->toBe($valid);
})->with([
    'the override' => [['qreyp', 'frut'], true],
    'the rule' => [['qreypf', 'rut'], true],
    'a letter lost' => [['qreyp', 'fru'], false],
    'a letter changed' => [['qreyp', 'früt'], false],
    'another case' => [['Qreyp', 'frut'], false],
    'two vowels in a part' => [['qreypfrut'], false],
    'a part without a vowel' => [['qre', 'yp', 'frut'], false],
    'nothing' => [[], false],
]);
