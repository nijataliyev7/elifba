<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Content\Enums\RoomGameKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Enums\RoomComputerLevel;
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
 * The constructor holds the shape of each kind.
 *
 * @phpstan-import-type RoomClockLevelArray from RoomClockLevelData
 *
 * @phpstan-type RoomSetupArray array{kind: string, mechanic: string, difficulty: int, rounds?: int, clock?: RoomClockLevelArray, white?: string|null, black?: string|null, computer?: int|null, played: bool}
 */
final readonly class RoomSetupData
{
    /**
     * @param  int|null  $rounds  a race's
     * @param  RoomClockLevelData|null  $clock  a board game's
     * @param  string|null  $white  a board game's seat of white
     * @param  string|null  $black  a board game's seat of black
     * @param  RoomComputerLevel|null  $computer  a board game's: the computer's level, which plays the seat that is null (K3, ADR 036)
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
    ) {
        $race = $mechanic->kind() === RoomGameKind::Race;

        if ($race !== ($rounds !== null) || $race === ($clock !== null) || ($race && ($white !== null || $black !== null || $computer !== null))) {
            throw new InvalidArgumentException('A race\'s choice has its rounds; a board game\'s its clock and its seats.');
        }
    }

    public static function of(RoomGameSetupData $choice, bool $played): self
    {
        return new self($choice->mechanic, $choice->difficulty, $choice->rounds, $played, $choice->clock(), $choice->white, $choice->black, $choice->computer);
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
        );
    }

    /**
     * @return RoomSetupArray
     */
    public function toArray(): array
    {
        $head = ['kind' => $this->mechanic->kind()->value, 'mechanic' => $this->mechanic->value, 'difficulty' => $this->difficulty];

        return $this->clock === null
            ? [...$head, 'rounds' => (int) $this->rounds, 'played' => $this->played]
            : [...$head, 'clock' => $this->clock->toArray(), 'white' => $this->white, 'black' => $this->black, 'computer' => $this->computer?->value, 'played' => $this->played];
    }
}
