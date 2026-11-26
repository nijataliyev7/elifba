<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Content\Enums\RoomGameKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Enums\RoomComputerLevel;
use App\Domain\Rooms\Enums\RoomGamePhase;
use App\Domain\Rooms\Enums\RoomTable;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Support\Drivers\DominoDriver;
use InvalidArgumentException;

/**
 * The room's latest game in its state (docs/architecture.md §12 "The
 * state", "Board games"; zod `RoomGame`, a union by kind): its kind, what
 * is played — the mechanic at a difficulty — and whether it is `live` or
 * `over`. A race also says how many rounds; a board game its clock, as its
 * row keeps it (ADR 034 decision 8). Never a race's seed: the rounds it
 * builds are the server's until each is served.
 *
 * From Y5 (ADR 038 decision 38) a domino game says its turn's time, its
 * table, the balance that ends it (`target`) and the computer's level, null
 * when no seat is the computer's — never its seed.
 *
 * The constructor holds the shape of each kind.
 *
 * @phpstan-import-type RoomClockLevelArray from RoomClockLevelData
 *
 * @phpstan-type RoomGameArray array{kind: string, id: string, mechanic: string, difficulty: int, rounds?: int, clock?: RoomClockLevelArray, turn_ms?: int, table?: string, target?: int, computer?: int|null, phase: string}
 */
final readonly class RoomGameData
{
    /**
     * @param  int|null  $rounds  a race's
     * @param  RoomClockLevelData|null  $clock  a board game's
     * @param  RoomComputerLevel|null  $computer  a board game's: the level of the computer that plays one of its seats (K3, ADR 036); null: two players. A domino game's: the level of its seats without a player (ADR 038)
     * @param  int|null  $turn_ms  a domino game's turn
     * @param  RoomTable|null  $table  a domino game's table
     * @param  int|null  $target  a domino game's: the balance that ends it
     */
    public function __construct(
        public string $id,
        public RoomMechanic $mechanic,
        public int $difficulty,
        public ?int $rounds,
        public RoomGamePhase $phase,
        public ?RoomClockLevelData $clock = null,
        public ?RoomComputerLevel $computer = null,
        public ?int $turn_ms = null,
        public ?RoomTable $table = null,
        public ?int $target = null,
    ) {
        $kind = $this->kind();
        $race = $kind === RoomGameKind::Race;
        $domino = $kind === RoomGameKind::Domino;

        if ($race !== ($rounds !== null) || ($kind === RoomGameKind::Board) !== ($clock !== null) || ($race && $computer !== null)) {
            throw new InvalidArgumentException('A race has its rounds; a board game its clock.');
        }

        if ($domino !== ($turn_ms !== null) || $domino !== ($table !== null) || $domino !== ($target !== null)) {
            throw new InvalidArgumentException('A domino game has its turn, its table and its target, and nothing else has them.');
        }
    }

    public static function of(RoomGame $game, RoomGamePhase $phase): self
    {
        return match ($game->mechanic->kind()) {
            RoomGameKind::Race => new self($game->id, $game->mechanic, $game->difficulty, $game->rounds->count(), $phase),
            RoomGameKind::Board => new self($game->id, $game->mechanic, $game->difficulty, null, $phase, self::clockOf($game), $game->computer),
            RoomGameKind::Domino => new self($game->id, $game->mechanic, $game->difficulty, null, $phase, null, $game->computer, $game->turn_ms, $game->dominoTable(), DominoDriver::variantOf($game)->target()),
        };
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
            isset($data['computer']) ? RoomComputerLevel::from($data['computer']) : null,
            $data['turn_ms'] ?? null,
            isset($data['table']) ? RoomTable::from($data['table']) : null,
            $data['target'] ?? null,
        );
    }

    /**
     * @return RoomGameArray
     */
    public function toArray(): array
    {
        $head = ['kind' => $this->kind()->value, 'id' => $this->id, 'mechanic' => $this->mechanic->value, 'difficulty' => $this->difficulty];

        return match (true) {
            $this->table !== null => [...$head, 'turn_ms' => (int) $this->turn_ms, 'table' => $this->table->value, 'target' => (int) $this->target, 'computer' => $this->computer?->value, 'phase' => $this->phase->value],
            $this->clock !== null => [...$head, 'clock' => $this->clock->toArray(), 'computer' => $this->computer?->value, 'phase' => $this->phase->value],
            default => [...$head, 'rounds' => (int) $this->rounds, 'phase' => $this->phase->value],
        };
    }
}
