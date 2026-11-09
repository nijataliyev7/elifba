<?php

namespace App\Domain\Language\Support;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * The 32-letter Azerbaijani alphabet from `content/alphabet.json`
 * (docs/architecture.md §3): case pairs, vowels, names and confusables.
 * Letters are identified by their lowercase form, the way content keys,
 * learned letters and the judge name them.
 *
 * The file is read once per process; nothing here hard-codes a letter.
 */
final class Alphabet
{
    /**
     * @var array{letters: list<array{upper: string, lower: string, vowel: bool, name: string, confusables: list<string>}>, lookalikes: array<string, string>, positions: array<string, int>, upper: array<string, string>, lower: array<string, string>}|null
     */
    private static ?array $data = null;

    /**
     * The lowercase letters in alphabet order.
     *
     * @return list<string>
     */
    public static function letters(): array
    {
        return array_column(self::data()['letters'], 'lower');
    }

    /**
     * The lowercase vowels in alphabet order.
     *
     * @return list<string>
     */
    public static function vowels(): array
    {
        return array_column(
            array_filter(self::data()['letters'], static fn (array $letter): bool => $letter['vowel']),
            'lower',
        );
    }

    public static function count(): int
    {
        return count(self::data()['letters']);
    }

    /**
     * Whether a single character, in either case, is one of the 32 letters.
     */
    public static function isLetter(string $character): bool
    {
        return self::position($character) !== null;
    }

    /**
     * Whether a letter, in either case, is a vowel (`y` is a consonant).
     */
    public static function isVowel(string $letter): bool
    {
        $position = self::position($letter);

        return $position !== null && self::data()['letters'][$position]['vowel'];
    }

    /**
     * The Azerbaijani name of a letter in either case ("be" for b, "el" for
     * l), as children hear it spelled.
     *
     * @throws InvalidArgumentException when it is not a letter of the alphabet
     */
    public static function name(string $letter): string
    {
        return self::entry($letter)['name'];
    }

    /**
     * The lowercase letters a child easily mistakes for this one (e ↔ ə,
     * i ↔ ı, b ↔ d…), most confusable first. Letter decoys are taken from
     * here first (docs/architecture.md §2). The relation is symmetric.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException when it is not a letter of the alphabet
     */
    public static function confusables(string $letter): array
    {
        return self::entry($letter)['confusables'];
    }

    /**
     * The 0-based place of a letter (either case) in the alphabet, or null.
     */
    public static function position(string $character): ?int
    {
        $data = self::data();

        return $data['positions'][$character] ?? $data['positions'][$data['lower'][$character] ?? ''] ?? null;
    }

    /**
     * Whether every letter of the text is one of the 32; anything else
     * (spaces, digits, punctuation) is ignored.
     */
    public static function covers(string $text): bool
    {
        foreach (mb_str_split($text) as $character) {
            if (preg_match('/^\p{L}$/u', $character) === 1 && ! self::isLetter($character)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lowercase letters, without repeats, in alphabet order.
     *
     * @param  iterable<string>  $letters  lowercase letters
     * @return list<string>
     *
     * @throws InvalidArgumentException when one is not a lowercase letter of the alphabet
     */
    public static function sort(iterable $letters): array
    {
        $positions = self::data()['positions'];
        $sorted = [];

        foreach ($letters as $letter) {
            if (! isset($positions[$letter])) {
                throw new InvalidArgumentException(sprintf('"%s" is not a lowercase letter of the alphabet.', $letter));
            }

            $sorted[$positions[$letter]] = $letter;
        }

        ksort($sorted);

        return array_values($sorted);
    }

    /**
     * Lowercase → uppercase for every letter (i → İ, ı → I).
     *
     * @return array<string, string>
     */
    public static function upperMap(): array
    {
        return self::data()['upper'];
    }

    /**
     * Uppercase → lowercase for every letter (İ → i, I → ı).
     *
     * @return array<string, string>
     */
    public static function lowerMap(): array
    {
        return self::data()['lower'];
    }

    /**
     * Characters and sequences that look like a letter but are not it,
     * mapped to the letter (Cyrillic Ә → Ə, i + combining dot → i).
     *
     * @return array<string, string>
     */
    public static function lookalikes(): array
    {
        return self::data()['lookalikes'];
    }

    /**
     * @return array{upper: string, lower: string, vowel: bool, name: string, confusables: list<string>}
     */
    private static function entry(string $letter): array
    {
        $position = self::position($letter);

        if ($position === null) {
            throw new InvalidArgumentException(sprintf('"%s" is not a letter of the alphabet.', $letter));
        }

        return self::data()['letters'][$position];
    }

    /**
     * @return array{letters: list<array{upper: string, lower: string, vowel: bool, name: string, confusables: list<string>}>, lookalikes: array<string, string>, positions: array<string, int>, upper: array<string, string>, lower: array<string, string>}
     */
    private static function data(): array
    {
        return self::$data ??= self::load(base_path('content/alphabet.json'));
    }

    /**
     * @return array{letters: list<array{upper: string, lower: string, vowel: bool, name: string, confusables: list<string>}>, lookalikes: array<string, string>, positions: array<string, int>, upper: array<string, string>, lower: array<string, string>}
     */
    private static function load(string $path): array
    {
        $json = @file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("{$path} is not valid JSON.", previous: $exception);
        }

        if (! is_array($decoded) || ! is_array($decoded['letters'] ?? null)) {
            throw new RuntimeException("{$path} has no letters.");
        }

        $letters = [];
        $positions = [];
        $upper = [];
        $lower = [];

        foreach (array_values($decoded['letters']) as $index => $entry) {
            if (! is_array($entry) || ! is_string($entry['upper'] ?? null) || ! is_string($entry['lower'] ?? null) || ! is_bool($entry['vowel'] ?? null)) {
                throw new RuntimeException("{$path}: letter #{$index} needs `upper`, `lower` and `vowel`.");
            }

            $confusables = $entry['confusables'] ?? null;

            if (! is_string($entry['name'] ?? null) || ! is_array($confusables) || ! array_is_list($confusables) || array_filter($confusables, is_string(...)) !== $confusables) {
                throw new RuntimeException("{$path}: letter #{$index} needs a `name` and a list of `confusables`.");
            }

            /** @var list<string> $confusables */
            $letters[] = ['upper' => $entry['upper'], 'lower' => $entry['lower'], 'vowel' => $entry['vowel'], 'name' => $entry['name'], 'confusables' => $confusables];
            $positions[$entry['lower']] = $index;
            $upper[$entry['lower']] = $entry['upper'];
            $lower[$entry['upper']] = $entry['lower'];
        }

        $lookalikes = [];

        foreach (is_array($decoded['lookalikes'] ?? null) ? $decoded['lookalikes'] : [] as $from => $to) {
            if (is_string($to)) {
                $lookalikes[(string) $from] = $to;
            }
        }

        return [
            'letters' => $letters,
            'lookalikes' => $lookalikes,
            'positions' => $positions,
            'upper' => $upper,
            'lower' => $lower,
        ];
    }
}
