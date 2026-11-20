<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Content\Data\RoomLevelData;
use InvalidArgumentException;

/**
 * A board game's level as the state says it (docs/architecture.md §12
 * "Board games", ADR 034 decisions 6 and 17; zod `RoomClockLevel`): each
 * side's time and what a side gains with each move it makes, so a page
 * writes «5+3» without a catalog. The choice's comes from its level of
 * `content/rooms/levels.json`, a game's from its own row, copied at the
 * start.
 *
 * @phpstan-type RoomClockLevelArray array{base_ms: int, increment_ms: int}
 */
final readonly class RoomClockLevelData
{
    public function __construct(
        public int $base_ms,
        public int $increment_ms,
    ) {
        if ($base_ms < 1 || $increment_ms < 0) {
            throw new InvalidArgumentException('A clock has some time, and an increment of 0 or more.');
        }
    }

    /** A level's clock (`content/rooms/levels.json`); null for a race's level, which has none. */
    public static function ofLevel(RoomLevelData $level): ?self
    {
        return $level->clockMs === null ? null : new self($level->clockMs, $level->incrementMs ?? 0);
    }

    /**
     * @param  RoomClockLevelArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['base_ms'], $data['increment_ms']);
    }

    /**
     * @return RoomClockLevelArray
     */
    public function toArray(): array
    {
        return [
            'base_ms' => $this->base_ms,
            'increment_ms' => $this->increment_ms,
        ];
    }
}
