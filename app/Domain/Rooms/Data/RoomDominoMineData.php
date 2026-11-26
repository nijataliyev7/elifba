<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Support\Domino\DominoTile;
use InvalidArgumentException;

/**
 * A seat's own part of a domino game, for that seat's player alone (docs/
 * architecture.md §12 "Hidden hands", ADR 038 decisions 6, 36 and 38):
 * the tiles it holds, in the order dealt and drawn, the tiles it drew after
 * the latest turn act, the plays it may make — only in its own turn: each
 * tile with the ends it fits, none for a hand's first tile — whether it may
 * give the opening to its partner, and whether it is away. Never in a
 * watcher's state, a host's who does not sit, or the screen's.
 *
 * @phpstan-type RoomDominoLegalArray array{tile: string, ends: list<string>}
 * @phpstan-type RoomDominoMineArray array{seat: int, tiles: list<string>, drawn: list<string>, legal: list<RoomDominoLegalArray>|null, may_yield: bool, away: bool}
 */
final readonly class RoomDominoMineData
{
    /**
     * @param  list<string>  $tiles
     * @param  list<string>  $drawn
     * @param  list<RoomDominoLegalArray>|null  $legal
     */
    public function __construct(
        public int $seat,
        public array $tiles,
        public array $drawn,
        public ?array $legal,
        public bool $may_yield,
        public bool $away,
    ) {
        $named = [...$tiles, ...array_map(static fn (array $play): string => $play['tile'], $legal ?? [])];

        $wrong = match (true) {
            array_filter($named, static fn (string $tile): bool => DominoTile::tryOf($tile) === null) !== [] => 'A tile is named as one of the 28.',
            array_diff($drawn, $tiles) !== [] || array_diff(array_map(static fn (array $play): string => $play['tile'], $legal ?? []), $tiles) !== [] => 'A seat draws and plays only tiles it holds.',
            $legal === [] => 'A seat with no play has no list of plays.',
            default => null,
        };

        if ($wrong !== null) {
            throw new InvalidArgumentException($wrong);
        }
    }

    /**
     * @param  RoomDominoMineArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['seat'], $data['tiles'], $data['drawn'], $data['legal'], $data['may_yield'], $data['away']);
    }

    /**
     * @return RoomDominoMineArray
     */
    public function toArray(): array
    {
        return [
            'seat' => $this->seat,
            'tiles' => $this->tiles,
            'drawn' => $this->drawn,
            'legal' => $this->legal === null ? null : array_map(static fn (array $play): array => ['tile' => $play['tile'], 'ends' => $play['ends']], $this->legal),
            'may_yield' => $this->may_yield,
            'away' => $this->away,
        ];
    }
}
