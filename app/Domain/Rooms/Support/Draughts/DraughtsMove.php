<?php

namespace App\Domain\Rooms\Support\Draughts;

/**
 * A legal move of Russian draughts (docs/architecture.md §12 "Board
 * games", ADR 034 decision 11), as `DraughtsRules` found it: the square it
 * started on and every square it landed on, the squares of the pieces it
 * took in the order taken, and whether a man became a king on the way.
 * The state's `last` is this move; its line is `DraughtsNotation::text()`.
 */
final readonly class DraughtsMove
{
    /**
     * @param  list<string>  $path  the start and every landing
     * @param  list<string>  $captured  empty for a quiet move
     */
    public function __construct(
        public array $path,
        public array $captured,
        public bool $crowned,
    ) {}

    public function isCapture(): bool
    {
        return $this->captured !== [];
    }

    public function from(): string
    {
        return $this->path[0];
    }

    public function to(): string
    {
        return $this->path[count($this->path) - 1];
    }
}
