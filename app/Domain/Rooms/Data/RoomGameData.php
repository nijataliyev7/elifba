<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Content\Enums\RoomGameKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Enums\RoomGamePhase;
use App\Domain\Rooms\Models\RoomGame;
use InvalidArgumentException;

/**
 * The room's latest game in its state (docs/architecture.md §12 "The
 * state", "Board games"; zod `RoomGame`, a union by kind): its kind, what
 * is played — the mechanic at a difficulty — and whether it is `live` or
 * `over`. A race also says how many rounds; a board game its clock, as its
 * row keeps it (ADR 034 decision 8). Never a race's seed: the rounds it
 * builds are the server's until each is served.
 *
 * The constructor holds the shape of each kind.
 *
 * @phpstan-import-type RoomClockLevelArray from RoomClockLevelData
 *
 * @phpstan-type RoomGameArray array{kind: string, id: string, mechanic: string, difficulty: int, rounds?: int, clock?: RoomClockLevelArray, phase: string}
 */
final readonly class RoomGameData
{
    /**
     * @param  int|null  $rounds  a race's
     * @param  RoomClockLevelData|null  $clock  a board game's
     */
    public function __construct(
        public string $id,
        public RoomMechanic $mechanic,
        public int $difficulty,
        public ?int $rounds,
        public RoomGamePhase $phase,
        public ?RoomClockLevelData $clock = null,
    ) {
        $race = $this->kind() === RoomGameKind::Race;

        if ($race !== ($rounds !== null) || $race === ($clock !== null)) {
            throw new InvalidArgumentException('A race has its rounds; a board game its clock.');
        }
    }

    public static function of(RoomGame $game, RoomGamePhase $phase): self
    {
        $clock = self::clockOf($game);

        return new self($game->id, $game->mechanic, $game->difficulty, $clock === null ? $game->rounds->count() : null, $phase, $clock);
    }

    /**
     * A game's clock as its row keeps it — a board game's, copied at its
     * start; null for a race, which has none. «Keçmiş oyunlar» and a
     * closed room's games give it beside the mechanic and the difficulty.
     */
    public static function clockOf(RoomGame $game): ?RoomClockLevelData
    {
        return $game->clock_ms === null ? null : new RoomClockLevelData($game->clock_ms, $game->increment_ms ?? 0);
    }

    public function kind(): RoomGameKind
    {
        return $this->mechanic->kind();
    }

    /**
     * @param  RoomGameArray  $data
     */
    public static function fromArray(array $data): self
    {
        $mechanic = RoomMechanic::from($data['mechanic']);

        if ($data['kind'] !== $mechanic->kind()->value) {
            throw new InvalidArgumentException('A game of another kind than its mechanic\'s.');
        }

        return new self(
            $data['id'],
            $mechanic,
            $data['difficulty'],
            $data['rounds'] ?? null,
            RoomGamePhase::from($data['phase']),
            isset($data['clock']) ? RoomClockLevelData::fromArray($data['clock']) : null,
        );
    }

    /**
     * @return RoomGameArray
     */
    public function toArray(): array
    {
        $head = ['kind' => $this->kind()->value, 'id' => $this->id, 'mechanic' => $this->mechanic->value, 'difficulty' => $this->difficulty];

        return $this->clock === null
            ? [...$head, 'rounds' => (int) $this->rounds, 'phase' => $this->phase->value]
            : [...$head, 'clock' => $this->clock->toArray(), 'phase' => $this->phase->value];
    }
}
