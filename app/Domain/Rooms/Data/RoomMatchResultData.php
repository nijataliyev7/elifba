<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomMatchEnding;
use App\Domain\Rooms\Enums\RoomMatchOutcome;
use InvalidArgumentException;

/**
 * How a match came out, once it is over (docs/architecture.md §12 "Board
 * games", ADR 034 decisions 14, 22 and 25; zod `RoomMatchResult`): the
 * outcome and its reason, the match's own standing — its two seats, the
 * winner first, or both first in a draw, white before black where they
 * share a place — and the taunts counted at its end, as a reveal's
 * `spent`. A game the host stopped has the outcome `none`, the ending
 * `stopped` and no places.
 *
 * @phpstan-import-type RoomMatchStandingArray from RoomMatchStandingData
 * @phpstan-import-type RoomSpentArray from RoomSpentData
 *
 * @phpstan-type RoomMatchResultArray array{outcome: string, ending: string, standing: list<RoomMatchStandingArray>, spent: list<RoomSpentArray>}
 */
final readonly class RoomMatchResultData
{
    /**
     * @param  list<RoomMatchStandingData>  $standing
     * @param  list<RoomSpentData>  $spent
     */
    public function __construct(
        public RoomMatchOutcome $outcome,
        public RoomMatchEnding $ending,
        public array $standing,
        public array $spent,
    ) {
        $places = array_map(static fn (RoomMatchStandingData $row): ?int => $row->place, $standing);

        $wrong = match (true) {
            ($outcome === RoomMatchOutcome::None) !== ($ending === RoomMatchEnding::Stopped) => 'Only a stopped game has no result.',
            ($outcome === RoomMatchOutcome::Draw) !== $ending->draws() => 'A draw ends by a draw\'s reason, and a win by a win\'s.',
            count($standing) !== 2 || $standing[0]->player_id === $standing[1]->player_id => 'A match\'s standing is its two seats.',
            $places !== match ($outcome) {
                RoomMatchOutcome::None => [null, null],
                RoomMatchOutcome::Draw => [1, 1],
                RoomMatchOutcome::White, RoomMatchOutcome::Black => [1, 2],
            } => 'The places follow the outcome: the winner first, both first in a draw, none without a result.',
            default => null,
        };

        if ($wrong !== null) {
            throw new InvalidArgumentException($wrong);
        }
    }

    /**
     * @param  RoomMatchResultArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            RoomMatchOutcome::from($data['outcome']),
            RoomMatchEnding::from($data['ending']),
            array_map(RoomMatchStandingData::fromArray(...), $data['standing']),
            array_map(RoomSpentData::fromArray(...), $data['spent']),
        );
    }

    /**
     * @return RoomMatchResultArray
     */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'ending' => $this->ending->value,
            'standing' => array_map(static fn (RoomMatchStandingData $row): array => $row->toArray(), $this->standing),
            'spent' => array_map(static fn (RoomSpentData $row): array => $row->toArray(), $this->spent),
        ];
    }
}
