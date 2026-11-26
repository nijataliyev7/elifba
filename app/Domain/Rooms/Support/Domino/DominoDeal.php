<?php

namespace App\Domain\Rooms\Support\Domino;

use App\Domain\Rooms\Enums\RoomTable;
use App\Domain\Rooms\Support\RoomSeed;
use Closure;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * One deal of domino (docs/architecture.md §12 "Domino", ADR 038 decision
 * 5, R2, R4): each seat's seven tiles in the order they were dealt and the
 * boneyard in the order it will be drawn — 14 tiles at `two`, 7 at
 * `three`, none at `four` and `pairs`. From a random source (the 28 tiles
 * shuffled: the first seven to seat 0, the next seven to seat 1 and so
 * on, the rest the boneyard), or set by hand in a fixture. Nothing stores
 * a deal: a game's deals follow from its seed (`source()`).
 */
final readonly class DominoDeal
{
    /**
     * @param  list<list<DominoTile>>  $hands  each seat's tiles, in the order of the seats
     * @param  list<DominoTile>  $boneyard  in the order it is drawn
     */
    private function __construct(
        public RoomTable $table,
        public array $hands,
        public array $boneyard,
    ) {}

    /** The 28 tiles shuffled by a random source and dealt round the table. */
    public static function shuffled(RoomTable $table, Randomizer $random): self
    {
        /** @var list<DominoTile> $tiles */
        $tiles = $random->shuffleArray(array_values(DominoTile::all()));

        $hands = [];

        foreach (range(0, $table->seats() - 1) as $seat) {
            $hands[] = array_slice($tiles, $seat * RoomTable::HAND, RoomTable::HAND);
        }

        return new self($table, $hands, array_slice($tiles, $table->seats() * RoomTable::HAND));
    }

    /**
     * A deal set by hand, by the tiles' names: every seat's seven and the
     * boneyard in its order, the 28 tiles each once.
     *
     * @param  list<list<string>>  $hands
     * @param  list<string>  $boneyard
     */
    public static function set(RoomTable $table, array $hands, array $boneyard): self
    {
        if (count($hands) !== $table->seats()) {
            throw new InvalidArgumentException('A deal has a hand for each seat.');
        }

        foreach ($hands as $hand) {
            if (count($hand) !== RoomTable::HAND) {
                throw new InvalidArgumentException('A deal gives each seat seven tiles.');
            }
        }

        $names = [...array_merge(...$hands), ...$boneyard];

        if (count($names) !== 28 || count(array_unique($names)) !== 28) {
            throw new InvalidArgumentException('A deal holds the 28 tiles, each once.');
        }

        return new self(
            $table,
            array_map(DominoTile::list(...), $hands),
            DominoTile::list($boneyard),
        );
    }

    /**
     * The deals of a game with this seed, by hand and try (ADR 038 decision
     * 5): each from its own keyed source, `RoomSeed::deal()`. A replay asks
     * the next try of a hand while R4 throws its deal away.
     *
     * @return Closure(int, int): self
     */
    public static function source(RoomTable $table, int $seed): Closure
    {
        return static fn (int $hand, int $try): self => self::shuffled($table, RoomSeed::deal($seed, $hand, $try));
    }

    /**
     * Whether R4 throws the deal away before anyone is shown it: some seat
     * holds five doubles or more, or five or more of the six tiles that pair
     * one number with another.
     */
    public function thrown(): bool
    {
        return $this->reasons() !== [];
    }

    /**
     * Why R4 throws the deal away, a seat at a time — its doubles when it
     * holds five or more, then each number 0–6 of which it holds five or
     * more of the six tiles that pair it with another —, or nothing for a
     * deal that stands. ADR 038 decision 34, reading R4: the number's
     * double is not counted either way.
     *
     * @return list<array{seat: int, doubles: int}|array{seat: int, number: int, tiles: int}>
     */
    public function reasons(): array
    {
        $reasons = [];

        foreach ($this->hands as $seat => $hand) {
            $doubles = count(array_filter($hand, static fn (DominoTile $tile): bool => $tile->isDouble()));

            if ($doubles >= 5) {
                $reasons[] = ['seat' => $seat, 'doubles' => $doubles];
            }

            foreach (range(0, 6) as $number) {
                $of = count(array_filter($hand, static fn (DominoTile $tile): bool => ! $tile->isDouble() && $tile->has($number)));

                if ($of >= 5) {
                    $reasons[] = ['seat' => $seat, 'number' => $number, 'tiles' => $of];
                }
            }
        }

        return $reasons;
    }
}
