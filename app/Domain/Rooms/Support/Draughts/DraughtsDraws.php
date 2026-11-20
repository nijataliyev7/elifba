<?php

namespace App\Domain\Rooms\Support\Draughts;

use App\Domain\Rooms\Enums\RoomMatchColor;
use App\Domain\Rooms\Enums\RoomMatchEnding;

/**
 * The draws the server declares in a board game (docs/architecture.md §12
 * "Board games", ADR 034 decision 16), as the arbiter who may record a
 * draw without a player's claim (5.4.13.6) in a game whose record it
 * keeps. Pure: a tally of the moves made, one move at a time, whose
 * `ending` is the draw that move made, if any. A move that wins is
 * `DraughtsMatch`'s, before any draw.
 *
 * - **repetition** (5.4.13.1–2): the position after the move, with the side
 *   to move, has arisen for the 3rd time, not necessarily in a row.
 * - **three_kings** (6.1.8.3–4): a side with three kings or more, and any
 *   other pieces, against a lone king has made its 15th move since each
 *   side's men and kings last changed, and the king stands.
 * - **main_road** (6.1.8.5): a side with three pieces, none on the main road,
 *   against a lone king on it has made its 5th move in a row from such a
 *   position, and the king stands. The text's exception — "a position in
 *   which one side can win" — is an arbiter's judgement and not judged.
 * - **balance** (6.1.8.6): both sides have a king and 10, 60 or 120 plies —
 *   5, 30 or 60 moves as the record numbers them — have passed since the
 *   last capture or crowning, with 2–3, 4–5 or 6–7 pieces on the board.
 * - **kings_only** (6.1.8.8): with 4–5 or 6–7 pieces on the board, the last
 *   30 or 50 plies (15 or 25 moves) were king moves without a capture.
 */
final readonly class DraughtsDraws
{
    /** The diagonal of eight dark squares (6.1.2). */
    public const array MAIN_ROAD = ['a1', 'b2', 'c3', 'd4', 'e5', 'f6', 'g7', 'h8'];

    /**
     * @param  array<string, int>  $seen  how often each position arose since the last move that cannot be undone
     */
    private function __construct(
        private array $seen,
        private int $sinceChange,
        private int $kingRun,
        private int $whiteMoves,
        private int $blackMoves,
        private int $roadRun,
        public ?RoomMatchEnding $ending,
    ) {}

    public static function start(DraughtsPosition $position): self
    {
        return new self([$position->key() => 1], 0, 0, 0, 0, 0, null);
    }

    /**
     * The tally after a legal move of `$before`, which gave `$after`.
     */
    public function after(DraughtsPosition $before, DraughtsMove $move, DraughtsPosition $after): self
    {
        $mover = $before->turn;
        $kingMoved = $before->isKingOn($move->from());
        // The balance changes with a capture or a crowning (6.1.8.6).
        $changed = $move->isCapture() || $move->crowned;

        // A man's move or a capture can never be undone, so no position
        // before it can arise again.
        $seen = $move->isCapture() || ! $kingMoved ? [] : $this->seen;
        $seen[$after->key()] = ($seen[$after->key()] ?? 0) + 1;

        $whiteMoves = $changed ? 0 : $this->whiteMoves + ($mover === RoomMatchColor::White ? 1 : 0);
        $blackMoves = $changed ? 0 : $this->blackMoves + ($mover === RoomMatchColor::Black ? 1 : 0);
        $sinceChange = $changed ? 0 : $this->sinceChange + 1;
        $kingRun = $kingMoved && ! $move->isCapture() ? $this->kingRun + 1 : 0;

        $roadSide = self::roadSide($before);
        $roadRun = $roadSide === null ? 0 : $this->roadRun + ($mover === $roadSide ? 1 : 0);

        $ending = match (true) {
            $seen[$after->key()] >= 3 => RoomMatchEnding::Repetition,
            self::threeKings($after, $whiteMoves, $blackMoves) => RoomMatchEnding::ThreeKings,
            $mover === $roadSide && $roadRun >= 5 && $after->count($mover->other()) === 1 => RoomMatchEnding::MainRoad,
            self::balance($after, $sinceChange) => RoomMatchEnding::Balance,
            self::kingsOnly($after, $kingRun) => RoomMatchEnding::KingsOnly,
            default => null,
        };

        return new self($seen, $sinceChange, $kingRun, $whiteMoves, $blackMoves, $roadRun, $ending);
    }

    /**
     * Whether a side with three kings or more has made 15 moves against a
     * lone king since the material last changed.
     */
    private static function threeKings(DraughtsPosition $position, int $whiteMoves, int $blackMoves): bool
    {
        foreach ([[RoomMatchColor::White, $whiteMoves], [RoomMatchColor::Black, $blackMoves]] as [$strong, $moves]) {
            if ($position->kings($strong) >= 3 && self::isLoneKing($position, $strong->other()) && $moves >= 15) {
                return true;
            }
        }

        return false;
    }

    /**
     * The side with three pieces off the main road against a lone king on
     * it, or null when the position is not that one.
     */
    private static function roadSide(DraughtsPosition $position): ?RoomMatchColor
    {
        foreach (RoomMatchColor::cases() as $strong) {
            if ($position->count($strong) !== 3 || ! self::isLoneKing($position, $strong->other())) {
                continue;
            }

            foreach ($position->pieces() as $piece) {
                if (in_array($piece['square'], self::MAIN_ROAD, true) !== ($piece['color'] !== $strong)) {
                    return null;
                }
            }

            return $strong;
        }

        return null;
    }

    private static function balance(DraughtsPosition $position, int $sinceChange): bool
    {
        if ($position->kings(RoomMatchColor::White) === 0 || $position->kings(RoomMatchColor::Black) === 0) {
            return false;
        }

        $plies = match (self::pieces($position)) {
            2, 3 => 10,
            4, 5 => 60,
            6, 7 => 120,
            default => null,
        };

        return $plies !== null && $sinceChange >= $plies;
    }

    private static function kingsOnly(DraughtsPosition $position, int $kingRun): bool
    {
        $plies = match (self::pieces($position)) {
            4, 5 => 30,
            6, 7 => 50,
            default => null,
        };

        return $plies !== null && $kingRun >= $plies;
    }

    private static function isLoneKing(DraughtsPosition $position, RoomMatchColor $side): bool
    {
        return $position->count($side) === 1 && $position->kings($side) === 1;
    }

    /** The pieces on the board, both sides' (the text's «N фигурные окончания»). */
    private static function pieces(DraughtsPosition $position): int
    {
        return $position->count(RoomMatchColor::White) + $position->count(RoomMatchColor::Black);
    }
}
