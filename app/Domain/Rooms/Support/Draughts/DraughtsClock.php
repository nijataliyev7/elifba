<?php

namespace App\Domain\Rooms\Support\Draughts;

use App\Domain\Rooms\Enums\RoomMatchColor;

/**
 * Each side's own time in a board game, with an increment, on the
 * server's clock (docs/architecture.md §12 "Board games", ADR 034 decision
 * 12). Pure: the clock as the moves made so far left it, one move at a
 * time, by the moment each was received under the room's lock — never a
 * time a device says.
 *
 *     starts          = room_games.started_ms (the end of the countdown)
 *     since(p)        = p = 0 ? starts : acted_ms(move p − 1)
 *     left(side, p)   = clock_ms + increment_ms × (the side's moves before p)
 *                       − Σ (acted_ms(q) − since(q)) over the side's moves q < p
 *     flag(p)         = since(p) + left(side to move at p, p)
 *
 * The increment is added once the move is made («плюс N секунд на ход»,
 * 6.1.9). At its flag the side to move has lost (5.4.2.6); `DraughtsMatch`
 * reads that, nothing writes it.
 */
final readonly class DraughtsClock
{
    private function __construct(
        /** The moves made. */
        public int $ply,
        /** When the side to move began to think. */
        public int $sinceMs,
        private int $whiteMs,
        private int $blackMs,
        private int $incrementMs,
    ) {}

    public static function start(int $startsMs, int $clockMs, int $incrementMs): self
    {
        return new self(0, $startsMs, $clockMs, $clockMs, $incrementMs);
    }

    /**
     * The clock after the side to move made its move, received at
     * `$movedMs`: the time it thought is gone, the increment added, the
     * other side's clock running from that moment.
     */
    public function moved(int $movedMs): self
    {
        $spent = max(0, $movedMs - $this->sinceMs);
        $white = RoomMatchColor::toMove($this->ply) === RoomMatchColor::White;

        return new self(
            $this->ply + 1,
            max($movedMs, $this->sinceMs),
            $white ? $this->whiteMs - $spent + $this->incrementMs : $this->whiteMs,
            $white ? $this->blackMs : $this->blackMs - $spent + $this->incrementMs,
            $this->incrementMs,
        );
    }

    /** A side's time when the side to move began to think. */
    public function left(RoomMatchColor $side): int
    {
        return $side === RoomMatchColor::White ? $this->whiteMs : $this->blackMs;
    }

    /** When the side to move runs out of time. */
    public function flagMs(): int
    {
        return $this->sinceMs + $this->left(RoomMatchColor::toMove($this->ply));
    }

    /**
     * A side's time at a moment of this ply: the side to move's runs down
     * from `sinceMs` and stops at 0; the other side's stands.
     */
    public function leftAt(RoomMatchColor $side, int $atMs): int
    {
        if ($side !== RoomMatchColor::toMove($this->ply)) {
            return $this->left($side);
        }

        return max(0, $this->left($side) - max(0, $atMs - $this->sinceMs));
    }
}
