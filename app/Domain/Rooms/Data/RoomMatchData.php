<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomMatchColor;
use App\Domain\Rooms\Enums\RoomMatchPhase;
use InvalidArgumentException;

/**
 * A board game in the room's state, as one viewer sees it
 * (docs/architecture.md §12 "Board games", "The state's match", ADR 034
 * decision 17; zod `RoomMatch`, split by phase): the latest game of the
 * room when it is a board game, live or over. Nothing in a match is secret
 * — the board is in front of the room — so every device gets the same
 * position, moves and clocks; `legal` and `can_offer` are the viewer's own
 * only so that what a page offers and what the server takes are one rule.
 * Everything here is replayed from the game's acts (`DraughtsMatch`).
 *
 * The constructor holds the shape of each phase, as `RoomRoundData` does:
 *
 * - `countdown` (before the clock starts at `starts_ms`): white to move, no
 *   move made, no legal move offered, no offer, no result;
 * - `play`: the side to move, when it began to think (`since_ms`) and when
 *   its time runs out (`flag_ms`); the legal paths for the viewer who plays
 *   it; a standing draw offer and whether the viewer may offer;
 * - `over`: no side to move, no flag, nothing to play or offer, and the
 *   result.
 *
 * `sides` are white, then black; `moves` are every move's line in the
 * official notation, in order, so `ply` is their number; `last` is the
 * last of them, from the first move on.
 *
 * @phpstan-import-type RoomSideArray from RoomSideData
 * @phpstan-import-type RoomPieceArray from RoomPieceData
 * @phpstan-import-type RoomLastMoveArray from RoomLastMoveData
 * @phpstan-import-type RoomLegalArray from RoomLegalData
 * @phpstan-import-type RoomMatchResultArray from RoomMatchResultData
 *
 * @phpstan-type RoomMatchArray array{phase: string, starts_ms: int, sides: list<RoomSideArray>, turn: string|null, ply: int, since_ms: int, flag_ms: int|null, position: list<RoomPieceArray>, last: RoomLastMoveArray|null, moves: list<string>, legal: RoomLegalArray|null, offer: string|null, can_offer: bool, result: RoomMatchResultArray|null}
 */
final readonly class RoomMatchData
{
    /**
     * @param  list<RoomSideData>  $sides  white, then black
     * @param  list<RoomPieceData>  $position
     * @param  list<string>  $moves
     */
    public function __construct(
        public RoomMatchPhase $phase,
        public int $starts_ms,
        public array $sides,
        public ?RoomMatchColor $turn,
        public int $ply,
        public int $since_ms,
        public ?int $flag_ms,
        public array $position,
        public ?RoomLastMoveData $last,
        public array $moves,
        public ?RoomLegalData $legal,
        public ?RoomMatchColor $offer,
        public bool $can_offer,
        public ?RoomMatchResultData $result,
    ) {
        $over = $phase === RoomMatchPhase::Over;

        $wrong = match (true) {
            array_map(static fn (RoomSideData $side): RoomMatchColor => $side->color, $sides) !== [RoomMatchColor::White, RoomMatchColor::Black] => 'A match has its two sides, white and then black.',
            $sides[0]->player_id === $sides[1]->player_id => 'Two seats are two players, or a player and the computer.',
            $ply !== count($moves) || ($last === null) !== ($ply === 0) => 'The moves made are the list\'s lines, the last one marked.',
            $over !== ($result !== null) => 'A match has its result once it is over, and only then.',
            $over !== ($turn === null) || $over !== ($flag_ms === null) => 'A live match has a side to move and its flag; one that is over has neither.',
            $phase === RoomMatchPhase::Countdown && ($ply !== 0 || $turn !== RoomMatchColor::White || $offer !== null) => 'Before the clock starts no move is made.',
            $legal !== null && $phase !== RoomMatchPhase::Play => 'Legal moves are offered only while the match is played.',
            ($offer !== null || $can_offer) && $phase !== RoomMatchPhase::Play => 'A draw is offered only while the match is played.',
            $offer !== null && $offer === $turn => 'An offer stands from the side that has just moved.',
            default => null,
        };

        if ($wrong !== null) {
            throw new InvalidArgumentException($wrong);
        }
    }

    /**
     * @param  RoomMatchArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            phase: RoomMatchPhase::from($data['phase']),
            starts_ms: $data['starts_ms'],
            sides: array_map(RoomSideData::fromArray(...), $data['sides']),
            turn: $data['turn'] === null ? null : RoomMatchColor::from($data['turn']),
            ply: $data['ply'],
            since_ms: $data['since_ms'],
            flag_ms: $data['flag_ms'],
            position: array_map(RoomPieceData::fromArray(...), $data['position']),
            last: $data['last'] === null ? null : RoomLastMoveData::fromArray($data['last']),
            moves: $data['moves'],
            legal: $data['legal'] === null ? null : RoomLegalData::fromArray($data['legal']),
            offer: $data['offer'] === null ? null : RoomMatchColor::from($data['offer']),
            can_offer: $data['can_offer'],
            result: $data['result'] === null ? null : RoomMatchResultData::fromArray($data['result']),
        );
    }

    /**
     * Whether the match is live and the side to move is the computer's — the
     * seat without a player (K3, ADR 036 decision 9): what a controller
     * asks before it hands `PlayComputerMove` to `defer()`.
     */
    public function computerToMove(): bool
    {
        foreach ($this->sides as $side) {
            if ($side->color === $this->turn) {
                return $side->player_id === null;
            }
        }

        return false;
    }

    /**
     * @return RoomMatchArray
     */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase->value,
            'starts_ms' => $this->starts_ms,
            'sides' => array_map(static fn (RoomSideData $side): array => $side->toArray(), $this->sides),
            'turn' => $this->turn?->value,
            'ply' => $this->ply,
            'since_ms' => $this->since_ms,
            'flag_ms' => $this->flag_ms,
            'position' => array_map(static fn (RoomPieceData $piece): array => $piece->toArray(), $this->position),
            'last' => $this->last?->toArray(),
            'moves' => $this->moves,
            'legal' => $this->legal?->toArray(),
            'offer' => $this->offer?->value,
            'can_offer' => $this->can_offer,
            'result' => $this->result?->toArray(),
        ];
    }
}
