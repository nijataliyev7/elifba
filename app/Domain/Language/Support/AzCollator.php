<?php

namespace App\Domain\Language\Support;

/**
 * Sorts names in Azerbaijani alphabetical order (docs/architecture.md §9):
 * A B C Ç D E Ə F G Ğ H X I İ J K Q L M N O Ö P R S Ş T U Ü V Y Z, ignoring
 * case. Spaces, hyphens and dots sort before every letter ("Əli" < "Əli M."
 * < "Əlim"); characters outside the alphabet sort after it, by code point.
 * Names that differ only in case are ordered by their bytes, so the result
 * never depends on the input order.
 */
final class AzCollator
{
    private const SEPARATOR = 0;

    private const OUTSIDE = 1000;

    public static function compare(string $a, string $b): int
    {
        if ($a === $b) {
            return 0;
        }

        $left = self::weights($a);
        $right = self::weights($b);
        $length = min(count($left), count($right));

        for ($i = 0; $i < $length; $i++) {
            if ($left[$i] !== $right[$i]) {
                return $left[$i] <=> $right[$i];
            }
        }

        return (count($left) <=> count($right)) ?: strcmp($a, $b);
    }

    /**
     * @param  iterable<string>  $names
     * @return list<string>
     */
    public static function sort(iterable $names): array
    {
        $sorted = [...$names];
        usort($sorted, self::compare(...));

        return $sorted;
    }

    /**
     * @template T
     *
     * @param  iterable<T>  $items
     * @param  callable(T): string  $name
     * @return list<T>
     */
    public static function sortBy(iterable $items, callable $name): array
    {
        $sorted = [...$items];
        usort($sorted, static fn (mixed $a, mixed $b): int => self::compare($name($a), $name($b)));

        return $sorted;
    }

    /**
     * @return list<int>
     */
    private static function weights(string $name): array
    {
        $weights = [];

        foreach (mb_str_split(Az::lower($name)) as $character) {
            $position = Alphabet::position($character);

            $weights[] = match (true) {
                $position !== null => $position + 1,
                preg_match('/^[\s\p{P}]$/u', $character) === 1 => self::SEPARATOR,
                default => self::OUTSIDE + (mb_ord($character) ?: 0),
            };
        }

        return $weights;
    }
}
