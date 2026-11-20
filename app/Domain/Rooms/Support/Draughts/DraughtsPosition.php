<?php

namespace App\Domain\Rooms\Support\Draughts;

use App\Domain\Rooms\Enums\RoomMatchColor;
use InvalidArgumentException;

/**
 * A position of Russian draughts (docs/architecture.md §12 "Board games",
 * ADR 034 decisions 4–5): where every piece stands and which side moves.
 * Never stored — `DraughtsMatch` replays it from the moves.
 *
 * A place is `rank × 8 + file`: `a1` is 0, `h8` 63, files and ranks from
 * white's lower left as the official names have them; only the dark
 * squares, file + rank even, are played. `board` holds a character per
 * place: `.` empty, `w` and `b` a man, `W` and `B` a king. Only
 * `DraughtsNotation` turns a place into a square's name, and only
 * `DraughtsRules` moves a piece.
 */
final readonly class DraughtsPosition
{
    public const string EMPTY = '.';

    private function __construct(
        public string $board,
        public RoomMatchColor $turn,
    ) {}

    /**
     * Twelve men a side on the dark squares of their first three rows, white
     * to move (6.1.3.1, 6.1.4.1, 6.1.6.2).
     */
    public static function initial(): self
    {
        $board = str_repeat(self::EMPTY, 64);

        for ($place = 0; $place < 64; $place++) {
            if (self::isDark($place)) {
                $board[$place] = match (true) {
                    self::rank($place) < 3 => 'w',
                    self::rank($place) > 4 => 'b',
                    default => self::EMPTY,
                };
            }
        }

        return new self($board, RoomMatchColor::White);
    }

    /**
     * A position from its pieces, each a square's name with `K` before a
     * king's — the lists of PDN's FEN, `W:Wa1,Kc3:Bb6`.
     *
     * @param  list<string>  $white
     * @param  list<string>  $black
     */
    public static function of(RoomMatchColor $turn, array $white, array $black): self
    {
        $board = str_repeat(self::EMPTY, 64);

        foreach ([[$white, 'w'], [$black, 'b']] as [$pieces, $man]) {
            foreach ($pieces as $piece) {
                $king = str_starts_with($piece, 'K');
                $place = DraughtsNotation::place($king ? substr($piece, 1) : $piece);

                if ($board[$place] !== self::EMPTY) {
                    throw new InvalidArgumentException('A square holds one piece.');
                }

                $board[$place] = $king ? strtoupper($man) : $man;
            }
        }

        return new self($board, $turn);
    }

    /**
     * The position after a piece moved from one place to another — a king
     * when `$king` — with the pieces it captured gone and the other side
     * to move. `DraughtsRules::apply()` alone calls it, with a legal move.
     *
     * @param  list<int>  $captured
     */
    public function after(int $from, int $to, array $captured, bool $king): self
    {
        $board = $this->board;
        $piece = $board[$from];
        $board[$from] = self::EMPTY;

        foreach ($captured as $place) {
            $board[$place] = self::EMPTY;
        }

        $board[$to] = $king ? strtoupper($piece) : $piece;

        return new self($board, $this->turn->other());
    }

    /**
     * Every piece, by square as the official text lists them: file by file,
     * each from rank 1 up.
     *
     * @return list<array{square: string, color: RoomMatchColor, king: bool}>
     */
    public function pieces(): array
    {
        $pieces = [];

        for ($file = 0; $file < 8; $file++) {
            for ($rank = 0; $rank < 8; $rank++) {
                $place = $rank * 8 + $file;
                $color = self::colorOf($this->board[$place]);

                if ($color !== null) {
                    $pieces[] = ['square' => DraughtsNotation::square($place), 'color' => $color, 'king' => self::isKing($this->board[$place])];
                }
            }
        }

        return $pieces;
    }

    /** A side's pieces, men and kings together. */
    public function count(RoomMatchColor $color): int
    {
        $man = $color === RoomMatchColor::White ? 'w' : 'b';

        return substr_count($this->board, $man) + substr_count($this->board, strtoupper($man));
    }

    public function kings(RoomMatchColor $color): int
    {
        return substr_count($this->board, $color === RoomMatchColor::White ? 'W' : 'B');
    }

    /** Whether a king stands on the square. */
    public function isKingOn(string $square): bool
    {
        return self::isKing($this->board[DraughtsNotation::place($square)]);
    }

    /**
     * The same placement of the pieces with the same side to move gives the
     * same key (5.4.13.1).
     */
    public function key(): string
    {
        return $this->turn->value[0].$this->board;
    }

    /** The place of a file and a rank, 0–7 each, or null off the board. */
    public static function place(int $file, int $rank): ?int
    {
        return $file >= 0 && $file < 8 && $rank >= 0 && $rank < 8 ? $rank * 8 + $file : null;
    }

    public static function file(int $place): int
    {
        return $place % 8;
    }

    public static function rank(int $place): int
    {
        return intdiv($place, 8);
    }

    public static function isDark(int $place): bool
    {
        return (self::file($place) + self::rank($place)) % 2 === 0;
    }

    /** The side a piece belongs to, or null for an empty place. */
    public static function colorOf(string $piece): ?RoomMatchColor
    {
        return match ($piece) {
            'w', 'W' => RoomMatchColor::White,
            'b', 'B' => RoomMatchColor::Black,
            default => null,
        };
    }

    public static function isKing(string $piece): bool
    {
        return $piece === 'W' || $piece === 'B';
    }
}
