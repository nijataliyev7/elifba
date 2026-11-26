<?php

namespace App\Domain\Rooms\Data;

use InvalidArgumentException;

/**
 * How a domino game ended, in the state of its phase `over` (docs/
 * architecture.md §12 "The state's domino", "Points and taunts", ADR 038
 * decisions 27, 30–32 and 38): reaching the target, or stopped by the host
 * or a close without a result; the winning side and whether it was a dry
 * win (R26); each seat's row — its player, null for the computer's, its
 * side, the points the game gave it less its counted taunts, and its
 * side's place, null without a result — the winning side first, then by
 * the sides' places and the seats; and the taunts counted.
 *
 * @phpstan-import-type RoomSpentArray from RoomSpentData
 *
 * @phpstan-type RoomDominoStandingArray array{player_id: string|null, seat: int, side: int, points: int, place: int|null}
 * @phpstan-type RoomDominoResultArray array{ending: string, winner: int|null, dry: bool, standing: list<RoomDominoStandingArray>, spent: list<RoomSpentArray>}
 */
final readonly class RoomDominoResultData
{
    public const string TARGET = 'target';

    public const string STOPPED = 'stopped';

    /**
     * @param  string  $ending  `target` or `stopped`
     * @param  list<RoomDominoStandingArray>  $standing
     * @param  list<RoomSpentData>  $spent
     */
    public function __construct(
        public string $ending,
        public ?int $winner,
        public bool $dry,
        public array $standing,
        public array $spent,
    ) {
        $won = $ending === self::TARGET;

        $wrong = match (true) {
            ! in_array($ending, [self::TARGET, self::STOPPED], true) => 'A domino game ends at its target or stopped.',
            $won !== ($winner !== null) || ($dry && ! $won) => 'A game that reached its target has a winner, and only it may be dry.',
            ! $won && array_filter($standing, static fn (array $row): bool => $row['place'] !== null || $row['points'] !== 0) !== [] => 'A stopped game places nobody and gives nothing.',
            array_filter($standing, static fn (array $row): bool => $row['player_id'] === null && $row['points'] !== 0) !== [] => 'The computer earns nothing.',
            default => null,
        };

        if ($wrong !== null) {
            throw new InvalidArgumentException($wrong);
        }
    }

    /**
     * @param  RoomDominoResultArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['ending'], $data['winner'], $data['dry'], $data['standing'], array_map(RoomSpentData::fromArray(...), $data['spent']));
    }

    /**
     * @return RoomDominoResultArray
     */
    public function toArray(): array
    {
        return [
            'ending' => $this->ending,
            'winner' => $this->winner,
            'dry' => $this->dry,
            'standing' => array_map(static fn (array $row): array => ['player_id' => $row['player_id'], 'seat' => $row['seat'], 'side' => $row['side'], 'points' => $row['points'], 'place' => $row['place']], $this->standing),
            'spent' => array_map(static fn (RoomSpentData $spent): array => $spent->toArray(), $this->spent),
        ];
    }
}
