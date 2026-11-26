<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Support\Domino\DominoTile;
use InvalidArgumentException;

/**
 * The latest turn act of a domino hand and what followed it by itself, in
 * the state (docs/architecture.md §12 "The state's domino", ADR 038
 * decisions 18, 38 and 68): whose it was, a play's tile and end (null for
 * a hand's first tile's end, and for a «Sən aç»), whether the server played
 * it for the seat (`stood_in`), and each seat that then drew — how many,
 * never which — or passed, in the order of the turns.
 *
 * @phpstan-type RoomDominoThenArray array{seat: int, drew: int, passed: bool}
 * @phpstan-type RoomDominoLastArray array{seat: int, kind: string, tile: string|null, end: string|null, stood_in: bool, then: list<RoomDominoThenArray>}
 */
final readonly class RoomDominoLastData
{
    public const string PLAY = 'play';

    public const string YIELD = 'yield';

    /**
     * @param  string  $kind  `play` or `yield`
     * @param  list<RoomDominoThenArray>  $then
     */
    public function __construct(
        public int $seat,
        public string $kind,
        public ?string $tile,
        public ?string $end,
        public bool $stood_in,
        public array $then,
    ) {
        $play = $kind === self::PLAY;

        $wrong = match (true) {
            ! in_array($kind, [self::PLAY, self::YIELD], true) => 'A turn act is a play or a give.',
            $play !== ($tile !== null) || ($tile !== null && DominoTile::tryOf($tile) === null) => 'A play names its tile, and a give none.',
            $end !== null && (! $play || ! DominoTile::isEnd($end)) => 'Only a play names an end, left or right.',
            ! $play && $then !== [] => 'Nothing follows a give by itself.',
            default => null,
        };

        if ($wrong !== null) {
            throw new InvalidArgumentException($wrong);
        }
    }

    /**
     * @param  RoomDominoLastArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['seat'], $data['kind'], $data['tile'], $data['end'], $data['stood_in'], $data['then']);
    }

    /**
     * @return RoomDominoLastArray
     */
    public function toArray(): array
    {
        return [
            'seat' => $this->seat,
            'kind' => $this->kind,
            'tile' => $this->tile,
            'end' => $this->end,
            'stood_in' => $this->stood_in,
            'then' => array_map(static fn (array $step): array => ['seat' => $step['seat'], 'drew' => $step['drew'], 'passed' => $step['passed']], $this->then),
        ];
    }
}
