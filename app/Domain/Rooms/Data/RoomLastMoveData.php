<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Support\Draughts\DraughtsMove;
use App\Domain\Rooms\Support\Draughts\DraughtsNotation;
use InvalidArgumentException;

/**
 * A match's last move in the state (docs/architecture.md §12 "Board
 * games", ADR 034 decisions 17 and 30; zod `RoomLastMove`): the square it
 * started on and every square it landed on, the squares of the pieces it
 * took, in the order taken, and whether a man was crowned on the way — what
 * a page marks and moves along, never parsing a line of the list.
 *
 * @phpstan-type RoomLastMoveArray array{path: list<string>, captured: list<string>, crowned: bool}
 */
final readonly class RoomLastMoveData
{
    /**
     * @param  list<string>  $path
     * @param  list<string>  $captured
     */
    public function __construct(
        public array $path,
        public array $captured,
        public bool $crowned,
    ) {
        $squares = [...$path, ...$captured];

        if (count($path) < 2 || count($path) > 13 || array_filter($squares, static fn (string $square): bool => ! DraughtsNotation::isSquare($square)) !== []) {
            throw new InvalidArgumentException('A move goes from a dark square over 2 to 13 of them, by their names.');
        }
    }

    public static function of(DraughtsMove $move): self
    {
        return new self($move->path, $move->captured, $move->crowned);
    }

    /**
     * @param  RoomLastMoveArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['path'], $data['captured'], $data['crowned']);
    }

    /**
     * @return RoomLastMoveArray
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'captured' => $this->captured,
            'crowned' => $this->crowned,
        ];
    }
}
