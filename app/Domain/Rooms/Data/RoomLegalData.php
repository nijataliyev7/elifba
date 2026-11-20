<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Support\Draughts\DraughtsMove;
use App\Domain\Rooms\Support\Draughts\DraughtsNotation;
use InvalidArgumentException;

/**
 * The legal moves of the side to move, for the viewer who plays it
 * (docs/architecture.md §12 "Board games", ADR 034 decisions 11 and 17;
 * zod `RoomLegal`): whether capturing is compulsory now, and every path a
 * move may take — the only paths a page offers. The server's judge found
 * them; a page computes none.
 *
 * @phpstan-type RoomLegalArray array{capture: bool, paths: list<list<string>>}
 */
final readonly class RoomLegalData
{
    /**
     * @param  list<list<string>>  $paths
     */
    public function __construct(
        public bool $capture,
        public array $paths,
    ) {
        foreach ($paths as $path) {
            if (count($path) < 2 || array_filter($path, static fn (string $square): bool => ! DraughtsNotation::isSquare($square)) !== []) {
                throw new InvalidArgumentException('A legal path goes from a dark square to another, by their names.');
            }
        }

        if ($paths === []) {
            throw new InvalidArgumentException('The side to move has a move, or the match is over.');
        }
    }

    /**
     * @param  list<DraughtsMove>  $moves  at least one
     */
    public static function of(array $moves): self
    {
        return new self(
            ($moves[0] ?? null)?->isCapture() ?? false,
            array_map(static fn (DraughtsMove $move): array => $move->path, $moves),
        );
    }

    /**
     * @param  RoomLegalArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['capture'], $data['paths']);
    }

    /**
     * @return RoomLegalArray
     */
    public function toArray(): array
    {
        return [
            'capture' => $this->capture,
            'paths' => $this->paths,
        ];
    }
}
