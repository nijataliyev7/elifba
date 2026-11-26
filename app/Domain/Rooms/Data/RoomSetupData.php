<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Content\Enums\RoomGameKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Enums\RoomComputerLevel;
use App\Domain\Rooms\Enums\RoomTable;
use InvalidArgumentException;

/**
 * The room's choice of the next game in its state (docs/architecture.md §12
 * "Games first", "Board games", ADR 026 decision 11, ADR 034 decisions 7–8
 * and 17; zod `RoomSetup`, a union by kind): the game's kind, the game and
 * its difficulty, as the host chose them and every device shows them
 * before the start — the host's, a present player's and the big screen's
 * alike — and `played`: the room's latest game is over and was played with
 * exactly this choice. A race also has its number of rounds; a board game
 * its level's clock and its two seats (`room_players.id`, or null while
 * nobody is there to sit). A page shows that game's final while `played`
 * and the start screen otherwise; it compares nothing itself. Nothing in it
 * is a person's but the seats' ids.
 *
 * From Y5 (ADR 038 decisions 11–14 and 38) a domino game's choice has its
 * level's turn time, its table, its seats in the order of the turns — each
 * a `room_players.id`, or null for the computer's — and the computer's
 * level, null when no seat is the computer's.
 *
 * The constructor holds the shape of each kind.
 *
 * @phpstan-import-type RoomClockLevelArray from RoomClockLevelData
 *
 * @phpstan-type RoomSetupArray array{kind: string, mechanic: string, difficulty: int, rounds?: int, clock?: RoomClockLevelArray, white?: string|null, black?: string|null, turn_ms?: int, table?: string, seats?: list<string|null>, computer?: int|null, played: bool}
 */
final readonly class RoomSetupData
{
    /**
     * @param  int|null  $rounds  a race's
     * @param  RoomClockLevelData|null  $clock  a board game's
     * @param  string|null  $white  a board game's seat of white
     * @param  string|null  $black  a board game's seat of black
     * @param  RoomComputerLevel|null  $computer  a board game's: the computer's level, which plays the seat that is null (K3, ADR 036); a domino game's: the level of every seat that is null (ADR 038)
     * @param  int|null  $turn_ms  a domino game's turn
     * @param  RoomTable|null  $table  a domino game's table
     * @param  list<string|null>|null  $seats  a domino game's seats, as many as its table has
     */
    public function __construct(
        public RoomMechanic $mechanic,
        public int $difficulty,
        public ?int $rounds,
        public bool $played,
        public ?RoomClockLevelData $clock = null,
        public ?string $white = null,
        public ?string $black = null,
        public ?RoomComputerLevel $computer = null,
        public ?int $turn_ms = null,
        public ?RoomTable $table = null,
        public ?array $seats = null,
    ) {
        $kind = $mechanic->kind();
        $race = $kind === RoomGameKind::Race;
        $domino = $kind === RoomGameKind::Domino;

        $wrong = match (true) {
            $race !== ($rounds !== null) || ($kind === RoomGameKind::Board) !== ($clock !== null) || ($race && ($white !== null || $black !== null || $computer !== null)) => 'A race\'s choice has its rounds; a board game\'s its clock and its seats.',
            $domino !== ($turn_ms !== null) || $domino !== ($table !== null) || $domino !== ($seats !== null) || ($domino && ($white !== null || $black !== null)) => 'A domino game\'s choice has its turn, its table and its seats, and nothing else has them.',
            $domino && (count((array) $seats) !== $table?->seats() || in_array(null, (array) $seats, true) !== ($computer !== null)) => 'A domino game\'s choice seats its table, the computer\'s level said while a seat is its.',
            default => null,
        };

        if ($wrong !== null) {
            throw new InvalidArgumentException($wrong);
        }
    }

    public static function of(RoomGameSetupData $choice, bool $played): self
    {
        return new self($choice->mechanic, $choice->difficulty, $choice->rounds, $played, $choice->clock(), $choice->white, $choice->black, $choice->computer, $choice->turnMs(), $choice->table, $choice->seats);
    }

    /**
     * @param  RoomSetupArray  $data
     */
    public static function fromArray(array $data): self
    {
        $mechanic = RoomMechanic::from($data['mechanic']);

        if ($data['kind'] !== $mechanic->kind()->value) {
            throw new InvalidArgumentException('A choice of another kind than its game\'s.');
        }

        return new self(
            $mechanic,
            $data['difficulty'],
            $data['rounds'] ?? null,
            $data['played'],
            isset($data['clock']) ? RoomClockLevelData::fromArray($data['clock']) : null,
            $data['white'] ?? null,
            $data['black'] ?? null,
            isset($data['computer']) ? RoomComputerLevel::from($data['computer']) : null,
            $data['turn_ms'] ?? null,
            isset($data['table']) ? RoomTable::from($data['table']) : null,
            $data['seats'] ?? null,
        );
    }

    /**
     * @return RoomSetupArray
     */
    public function toArray(): array
    {
        $head = ['kind' => $this->mechanic->kind()->value, 'mechanic' => $this->mechanic->value, 'difficulty' => $this->difficulty];

        return match (true) {
            $this->table !== null => [...$head, 'turn_ms' => (int) $this->turn_ms, 'table' => $this->table->value, 'seats' => (array) $this->seats, 'computer' => $this->computer?->value, 'played' => $this->played],
            $this->clock !== null => [...$head, 'clock' => $this->clock->toArray(), 'white' => $this->white, 'black' => $this->black, 'computer' => $this->computer?->value, 'played' => $this->played],
            default => [...$head, 'rounds' => (int) $this->rounds, 'played' => $this->played],
        };
    }
}
