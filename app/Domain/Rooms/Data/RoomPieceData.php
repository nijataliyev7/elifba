<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomMatchColor;
use App\Domain\Rooms\Support\Draughts\DraughtsNotation;
use InvalidArgumentException;

/**
 * A piece on a match's board (docs/architecture.md §12 "Board games",
 * ADR 034 decisions 5 and 17; zod `RoomPiece`): the dark square it stands
 * on, by its official name, its colour and whether it is a king.
 *
 * @phpstan-type RoomPieceArray array{square: string, color: string, king: bool}
 */
final readonly class RoomPieceData
{
    public function __construct(
        public string $square,
        public RoomMatchColor $color,
        public bool $king,
    ) {
        if (! DraughtsNotation::isSquare($square)) {
            throw new InvalidArgumentException('A piece stands on a dark square, by its name.');
        }
    }

    /**
     * @param  RoomPieceArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['square'], RoomMatchColor::from($data['color']), $data['king']);
    }

    /**
     * @return RoomPieceArray
     */
    public function toArray(): array
    {
        return [
            'square' => $this->square,
            'color' => $this->color->value,
            'king' => $this->king,
        ];
    }
}
