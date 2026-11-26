<?php

namespace App\Domain\Rooms\Support\Domino;

use InvalidArgumentException;

/**
 * One of the 28 tiles of domino and the one notation (docs/architecture.md
 * §12 "Domino", ADR 038 decision 15, R1): a tile is its two numbers, the
 * larger first, with a hyphen — `6-5`, `1-1`, `3-0`, `0-0` — in a stored
 * act, the state, a fixture and the oracle's output, never an index; this
 * class alone turns a name into numbers and back. An end of the line is
 * `left` or `right`.
 *
 * There is one instance of each tile (`of()`), so two tiles of one name
 * are the same object and compare with `===`.
 */
final class DominoTile
{
    public const string LEFT = 'left';

    public const string RIGHT = 'right';

    /** @var array<string, self> */
    private static array $tiles = [];

    private function __construct(
        /** The larger number. */
        public readonly int $high,
        /** The smaller number. */
        public readonly int $low,
    ) {}

    /** The tile of a name; anything else is refused. */
    public static function of(string $name): self
    {
        return self::tryOf($name) ?? throw new InvalidArgumentException("Not a domino tile: {$name}");
    }

    /** The tile of a name, or null when the name is not one of the 28 written the one way. */
    public static function tryOf(string $name): ?self
    {
        return self::all()[$name] ?? null;
    }

    /** The tile of two numbers, in either order. */
    public static function pair(int $a, int $b): self
    {
        return self::of(max($a, $b).'-'.min($a, $b));
    }

    /**
     * The 28 tiles by name, in one fixed order: by the larger number, then
     * the smaller (`0-0`, `1-0`, `1-1`, `2-0` … `6-6`).
     *
     * @return array<string, self>
     */
    public static function all(): array
    {
        if (self::$tiles === []) {
            foreach (range(0, 6) as $high) {
                foreach (range(0, $high) as $low) {
                    self::$tiles["{$high}-{$low}"] = new self($high, $low);
                }
            }
        }

        return self::$tiles;
    }

    /**
     * The tiles of names, in their order.
     *
     * @param  list<string>  $names
     * @return list<self>
     */
    public static function list(array $names): array
    {
        return array_map(self::of(...), $names);
    }

    /**
     * The names of tiles, in their order.
     *
     * @param  list<self>  $tiles
     * @return list<string>
     */
    public static function names(array $tiles): array
    {
        return array_map(static fn (self $tile): string => $tile->name(), $tiles);
    }

    /** Whether a name is an end of the line. */
    public static function isEnd(string $end): bool
    {
        return $end === self::LEFT || $end === self::RIGHT;
    }

    public function name(): string
    {
        return "{$this->high}-{$this->low}";
    }

    /** The sum of its two numbers (R1). */
    public function pips(): int
    {
        return $this->high + $this->low;
    }

    /** Both numbers the same (R1). */
    public function isDouble(): bool
    {
        return $this->high === $this->low;
    }

    /** Whether one of its numbers is `$number`. */
    public function has(int $number): bool
    {
        return $this->high === $number || $this->low === $number;
    }

    /** Its number other than `$number`, which it must have. */
    public function other(int $number): int
    {
        return $this->high === $number ? $this->low : $this->high;
    }
}
