<?php

namespace App\Domain\Rooms\Support\Draughts;

use App\Domain\Rooms\Enums\RoomActKind;
use App\Domain\Rooms\Enums\RoomMatchColor;
use App\Domain\Rooms\Enums\RoomMatchEnding;
use App\Domain\Rooms\Enums\RoomMatchOutcome;
use App\Domain\Rooms\Enums\RoomMatchPhase;

/**
 * A board game replayed from its facts (docs/architecture.md §12 "Board
 * games", ADR 034 decisions 10–16): the game's start, its clock, the host's
 * end and its acts in, at a moment; the position, the side to move, each
 * side's time, the last move, the list of moves, the standing draw offer
 * and the outcome out. Pure — nothing it says is stored, and every reader
 * goes through it.
 *
 * The outcome is the earliest of: a move that leaves the side to move
 * without a piece or a move (`no_moves`, 6.1.7), a resignation or a
 * leaving (`resigned`, `left`), a draw agreed (`agreed`, 5.4.13.1) or
 * declared by `DraughtsDraws`, the flag of the side to move (`time`,
 * 5.4.2.6) and the host's end (`stopped`, no result). An act received at
 * or after the flag counts for nothing: the game was already over.
 *
 * The draw offer (5.4.13.3–4): only the side that has just moved may
 * offer, from the first move on, while no offer stands; the other side
 * accepts, declines, or answers with a move, which ends the offer; nothing
 * withdraws one; a side whose last offer was not followed by the other's
 * may not offer again.
 *
 * An act the server would not have taken — out of turn, at another ply, a
 * path that is no legal move, an answer to no offer — is passed over, so a
 * replay never depends on its input having been checked.
 */
final readonly class DraughtsMatch
{
    /**
     * @param  list<string>  $moves  every move's line, in order
     * @param  list<DraughtsMove>  $legal  the side to move's legal moves in the position reached
     */
    private function __construct(
        public RoomMatchPhase $phase,
        public int $startsMs,
        public DraughtsPosition $position,
        public DraughtsClock $clock,
        public ?DraughtsMove $last,
        public array $moves,
        public ?RoomMatchColor $offer,
        private ?RoomMatchColor $lastOffer,
        private array $legal,
        public ?RoomMatchOutcome $outcome,
        public ?RoomMatchEnding $ending,
        /** When the game ended; null while it is live. */
        public ?int $overMs,
        private int $nowMs,
    ) {}

    /**
     * @param  int  $startsMs  when the clock starts: the end of the countdown
     * @param  int|null  $endedMs  the host ended the game, or the room closed under it
     * @param  list<DraughtsAct>  $acts  in the order they were received
     */
    public static function replay(int $startsMs, int $clockMs, int $incrementMs, ?int $endedMs, array $acts, int $nowMs): self
    {
        $position = DraughtsPosition::initial();
        $clock = DraughtsClock::start($startsMs, $clockMs, $incrementMs);
        $draws = DraughtsDraws::start($position);
        $legal = DraughtsRules::moves($position);
        $last = null;
        $moves = [];
        $offer = null;
        $lastOffer = null;
        $end = null;

        foreach ($acts as $act) {
            if ($act->actedMs > $nowMs) {
                break;
            }

            $end = self::lapse($clock, $endedMs, $act->actedMs, true);

            if ($end !== null) {
                break;
            }

            $answersOffer = $offer === $act->side->other() && $act->ply === $clock->ply;

            switch ($act->kind) {
                case RoomActKind::Move:
                    $move = $act->actedMs >= $startsMs && $act->side === $position->turn && $act->ply === $clock->ply
                        ? DraughtsRules::among($legal, $act->path ?? [])
                        : null;

                    if ($move === null) {
                        break;
                    }

                    $before = $position;
                    $position = DraughtsRules::apply($before, $move);
                    $draws = $draws->after($before, $move, $position);
                    $clock = $clock->moved($act->actedMs);
                    $last = $move;
                    $moves[] = DraughtsNotation::text($move);
                    $offer = null;
                    $legal = DraughtsRules::moves($position);

                    if ($legal === []) {
                        $end = [RoomMatchOutcome::won($act->side), RoomMatchEnding::NoMoves, $act->actedMs];
                    } elseif ($draws->ending !== null) {
                        $end = [RoomMatchOutcome::Draw, $draws->ending, $act->actedMs];
                    }

                    break;
                case RoomActKind::Offer:
                    if ($act->actedMs >= $startsMs && $act->ply === $clock->ply
                        && self::mayOffer($act->side, $position->turn, $clock->ply, $offer, $lastOffer)) {
                        $offer = $lastOffer = $act->side;
                    }

                    break;
                case RoomActKind::Accept:
                    if ($answersOffer) {
                        $end = [RoomMatchOutcome::Draw, RoomMatchEnding::Agreed, $act->actedMs];
                    }

                    break;
                case RoomActKind::Decline:
                    if ($answersOffer) {
                        $offer = null;
                    }

                    break;
                case RoomActKind::Resign:
                    $end = [RoomMatchOutcome::won($act->side->other()), RoomMatchEnding::Resigned, $act->actedMs];

                    break;
                case RoomActKind::Leave:
                    $end = [RoomMatchOutcome::won($act->side->other()), RoomMatchEnding::Left, $act->actedMs];

                    break;
            }

            if ($end !== null) {
                break;
            }
        }

        $end ??= self::lapse($clock, $endedMs, $nowMs, false);

        $phase = match (true) {
            $end !== null => RoomMatchPhase::Over,
            $nowMs < $startsMs => RoomMatchPhase::Countdown,
            default => RoomMatchPhase::Play,
        };

        return new self(
            $phase,
            $startsMs,
            $position,
            $clock,
            $last,
            $moves,
            $end === null ? $offer : null,
            $lastOffer,
            $legal,
            $end[0] ?? null,
            $end[1] ?? null,
            $end[2] ?? null,
            $nowMs,
        );
    }

    /** The moves made. */
    public function ply(): int
    {
        return $this->clock->ply;
    }

    /** The side to move; null once the game is over. */
    public function turn(): ?RoomMatchColor
    {
        return $this->phase === RoomMatchPhase::Over ? null : $this->position->turn;
    }

    /** When the side to move began to think. */
    public function sinceMs(): int
    {
        return $this->clock->sinceMs;
    }

    /** When the side to move runs out of time; null once the game is over. */
    public function flagMs(): ?int
    {
        return $this->phase === RoomMatchPhase::Over ? null : $this->clock->flagMs();
    }

    /** A side's time at the moment read, or when the game ended. */
    public function left(RoomMatchColor $side): int
    {
        return $this->clock->leftAt($side, $this->overMs ?? $this->nowMs);
    }

    /**
     * The legal moves of the side to move while the game is played; none in
     * the countdown or once it is over.
     *
     * @return list<DraughtsMove>
     */
    public function legal(): array
    {
        return $this->phase === RoomMatchPhase::Play ? $this->legal : [];
    }

    /** Whether a side may offer a draw now (5.4.13.3–4). */
    public function canOffer(RoomMatchColor $side): bool
    {
        return $this->phase === RoomMatchPhase::Play
            && self::mayOffer($side, $this->position->turn, $this->ply(), $this->offer, $this->lastOffer);
    }

    private static function mayOffer(RoomMatchColor $side, RoomMatchColor $turn, int $ply, ?RoomMatchColor $offer, ?RoomMatchColor $lastOffer): bool
    {
        return $side !== $turn && $ply >= 1 && $offer === null && $lastOffer !== $side;
    }

    /**
     * The flag or the host's end, when one of them comes first: before an
     * act received at `$atMs` — the flag at it too, since a move at the
     * flag is late — or by the moment the game is read.
     *
     * @return array{0: RoomMatchOutcome, 1: RoomMatchEnding, 2: int}|null
     */
    private static function lapse(DraughtsClock $clock, ?int $endedMs, int $atMs, bool $beforeAct): ?array
    {
        $flagMs = $clock->flagMs();

        if ($endedMs !== null && $endedMs < $flagMs && ($beforeAct ? $endedMs < $atMs : $endedMs <= $atMs)) {
            return [RoomMatchOutcome::None, RoomMatchEnding::Stopped, $endedMs];
        }

        if ($flagMs <= $atMs) {
            return [RoomMatchOutcome::won(RoomMatchColor::toMove($clock->ply)->other()), RoomMatchEnding::Time, $flagMs];
        }

        return null;
    }
}
