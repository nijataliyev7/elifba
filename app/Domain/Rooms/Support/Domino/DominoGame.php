<?php

namespace App\Domain\Rooms\Support\Domino;

use App\Domain\Rooms\Enums\RoomDominoPhase;
use App\Domain\Rooms\Enums\RoomHandEnding;
use App\Domain\Rooms\Enums\RoomOpenerWhy;
use App\Domain\Rooms\Enums\RoomSeatStanding;
use App\Domain\Rooms\Enums\RoomTable;
use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * A game of domino replayed from its start facts and its acts at a moment
 * (docs/architecture.md §12 "Domino", ADR 038 decisions 20, 23–27): every
 * hand's deal, line, ends and count, each seat's tiles, the seat to move
 * and since when, what followed each act by itself, the phase, the account,
 * who opens, the outcome. Pure — nothing it says is stored, and every
 * reader goes through it.
 *
 * Time: the first hand opens at `started_ms` — until then the phase is
 * `countdown` —, each later one `reveal_ms` after the last act of the hand
 * before — until then `sheet` —, and a hand that brings a side to the
 * target ends the game at that act, with no pause. The seat to move began
 * its turn at `since_ms` and its flag is `since_ms + turn_ms`; nothing is
 * lost at the flag, where the server plays the turn for the seat. The host's
 * end (`ended_ms`) ends the game without a result.
 *
 * An act the server would not have taken is passed over, so a replay never
 * depends on its input having been checked: a turn act outside the phase
 * `play` or at another ply; one a device sent at or after the flag; a play
 * the judge does not take; a give that is not the seat's to make or that
 * no device sent; a `back` for a seat that is not away. A play the server
 * made is taken whenever it came: its writer checked it under the room's
 * lock against the presence of that moment, which the replay is not given.
 *
 * Away (decision 26): a present player's seat is away while its last
 * `$awayAfter` turns were all played for it and no `back` came after them.
 * ADR 038 silent: the turns are counted over the whole game, across
 * hands, as `ply` is; a `back` is taken in any phase of a live game.
 *
 * @phpstan-import-type Play from DominoRules
 * @phpstan-import-type Line from DominoLedger
 */
final class DominoGame
{
    /** How many deals of one hand R4 may throw before the source is taken for broken. */
    private const int TRIES = 1000;

    /** @var list<DominoHand> every hand dealt so far, in order; the last is open or was last */
    public private(set) array $hands = [];

    /** @var array<int, list<Line>> each ended hand's lines of the account, by the hand's number */
    public private(set) array $lines = [];

    public private(set) DominoLedger $ledger;

    /** The turn acts — plays and gives — taken over the whole game. */
    public private(set) int $ply = 0;

    public private(set) RoomDominoPhase $phase = RoomDominoPhase::Countdown;

    /** The side that reached the target; null without one. */
    public private(set) ?int $winner = null;

    /** The host ended the game, or the room closed under it: over without a result. */
    public private(set) bool $stopped = false;

    /** When the game ended; null while it is live. */
    public private(set) ?int $overMs = null;

    /** @var array<int, int> each seat's turns played for it since its player's own last turn or `back` */
    private array $streaks;

    /**
     * @param  Closure(int, int): DominoDeal  $deals
     * @param  list<RoomSeatStanding>  $presence
     */
    private function __construct(
        private readonly DominoVariant $variant,
        public readonly RoomTable $table,
        private readonly Closure $deals,
        private readonly array $presence,
        /** When the first hand opens: the end of the countdown. */
        public readonly int $startsMs,
        public readonly int $turnMs,
        public readonly int $revealMs,
        private readonly int $awayAfter,
    ) {
        $this->ledger = $variant->ledger($table->sides());
        $this->streaks = array_fill(0, $table->seats(), 0);
    }

    /**
     * @param  Closure(int, int): DominoDeal  $deals  the deal of a hand (numbered from 1) at a try (from 0): `DominoDeal::source()` for a game's seed, or set by hand in a test
     * @param  list<RoomSeatStanding>  $presence  each seat's presence now: `playing` (a present player), `left` (its player left or was removed) or `computer` (nobody holds it)
     * @param  int  $startedMs  when the first hand opens
     * @param  int  $turnMs  a turn's time, the game's level
     * @param  int  $revealMs  the pause between hands
     * @param  int  $awayAfter  the turns played for a seat in a row that make it away
     * @param  int|null  $endedMs  the host ended the game, or the room closed under it
     * @param  list<DominoAct>  $acts  in the order they were received
     */
    public static function replay(
        DominoVariant $variant,
        RoomTable $table,
        Closure $deals,
        array $presence,
        int $startedMs,
        int $turnMs,
        int $revealMs,
        int $awayAfter,
        ?int $endedMs,
        array $acts,
        int $nowMs,
    ): self {
        if (count($presence) !== $table->seats() || in_array(RoomSeatStanding::Away, $presence, true)) {
            throw new InvalidArgumentException('A presence for each seat: playing, left or computer.');
        }

        $game = new self($variant, $table, $deals, $presence, $startedMs, $turnMs, $revealMs, $awayAfter);

        foreach ($acts as $act) {
            if ($act->actedMs > $nowMs || ($endedMs !== null && $endedMs < $act->actedMs)) {
                break;
            }

            $game->advance($act->actedMs);
            $game->take($act);

            if ($game->overMs !== null) {
                break;
            }
        }

        if ($game->overMs === null) {
            if ($endedMs !== null && $endedMs <= $nowMs) {
                $game->advance($endedMs);
                $game->stopped = true;
                $game->overMs = $endedMs;
            } else {
                $game->advance($nowMs);
            }
        }

        $hand = $game->hand();
        $game->phase = match (true) {
            $game->overMs !== null => RoomDominoPhase::Over,
            $hand === null => RoomDominoPhase::Countdown,
            $hand->over() => RoomDominoPhase::Sheet,
            default => RoomDominoPhase::Play,
        };

        return $game;
    }

    /** The hand that is open, or was last; null before the first opens. */
    public function hand(): ?DominoHand
    {
        return $this->hands === [] ? null : $this->hands[count($this->hands) - 1];
    }

    /** The seat to move while a hand is played; null otherwise. */
    public function turn(): ?int
    {
        return $this->phase === RoomDominoPhase::Play ? $this->hand()?->turn : null;
    }

    /** When the seat to move began its turn; null outside the phase `play`. */
    public function sinceMs(): ?int
    {
        return $this->phase === RoomDominoPhase::Play ? $this->hand()?->sinceMs : null;
    }

    /** When the seat to move's time runs out (decision 23); null outside the phase `play`. */
    public function flagMs(): ?int
    {
        $since = $this->sinceMs();

        return $since === null ? null : $since + $this->turnMs;
    }

    /**
     * The plays of the seat to move while a hand is played; none otherwise.
     *
     * @return list<Play>
     */
    public function legal(): array
    {
        return $this->phase === RoomDominoPhase::Play ? ($this->hand()?->legal() ?? []) : [];
    }

    /** Whether the seat to move may give the opening to its partner now (R9). */
    public function mayGive(): bool
    {
        return $this->phase === RoomDominoPhase::Play && $this->hand()?->mayGive() === true;
    }

    /** Whether a seat is away (decision 26). */
    public function away(int $seat): bool
    {
        return $this->presence[$seat] === RoomSeatStanding::Playing && $this->streaks[$seat] >= $this->awayAfter;
    }

    /** How a seat stands: its presence, or away. */
    public function standing(int $seat): RoomSeatStanding
    {
        return $this->away($seat) ? RoomSeatStanding::Away : $this->presence[$seat];
    }

    /**
     * When the server is to play the seat to move's turn (decision 28): a
     * computer's seat, a seat whose player left and an away seat `$thinkMs`
     * after the turn began, any other at its flag; null outside the phase
     * `play`.
     */
    public function serverPlaysFrom(int $thinkMs): ?int
    {
        $seat = $this->turn();
        $since = $this->sinceMs();

        if ($seat === null || $since === null) {
            return null;
        }

        return $this->standing($seat) === RoomSeatStanding::Playing ? $since + $this->turnMs : $since + $thinkMs;
    }

    /**
     * The hand whose sheet is shown — the one that just ended — in the
     * phases `sheet` and `over`; null when the game was stopped inside a
     * hand or before the first.
     */
    public function sheet(): ?DominoHand
    {
        $hand = $this->hand();

        return $this->phase !== RoomDominoPhase::Play && $hand !== null && $hand->over() ? $hand : null;
    }

    /** When the next hand opens, in the phase `sheet`; null otherwise. */
    public function untilMs(): ?int
    {
        $ended = $this->phase === RoomDominoPhase::Sheet ? $this->hand()?->endedMs : null;

        return $ended === null ? null : $ended + $this->revealMs;
    }

    /**
     * Who opens the next hand, in the phase `sheet`: a seat, or null while
     * the next deal decides it, and why (R6–R8); null otherwise.
     *
     * @return array{0: int|null, 1: RoomOpenerWhy}|null
     */
    public function next(): ?array
    {
        $hand = $this->hand();

        return $this->phase === RoomDominoPhase::Sheet && $hand !== null ? $this->variant->next($hand, $this->ledger->anyOpen()) : null;
    }

    /** A dry win (R26). */
    public function dry(): bool
    {
        return $this->winner !== null && $this->ledger->dry();
    }

    /** The tiles of the boneyard that are never drawn. */
    public function reserve(): int
    {
        return $this->variant->reserve();
    }

    public function target(): int
    {
        return $this->variant->target();
    }

    /**
     * What one seat has seen of the hand open or last (decision 54): its
     * own tiles, the line, every seat's count of tiles and the boneyard's,
     * every play, give, draw and pass by seat, the account — and no tile it
     * has not seen.
     */
    public function view(int $seat): DominoView
    {
        if (! isset($this->presence[$seat])) {
            throw new InvalidArgumentException("No seat {$seat}.");
        }

        $hand = $this->hand();
        $turn = $this->turn();

        return new DominoView(
            seat: $seat,
            table: $this->table,
            hand: $hand === null ? 0 : $hand->number,
            opener: $hand?->opener,
            why: $hand?->why,
            turn: $turn,
            tiles: $hand === null ? [] : $hand->tiles[$seat],
            line: $hand === null ? [] : $hand->line,
            ends: $hand === null ? [] : $hand->ends(),
            held: $hand === null ? array_fill(0, $this->table->seats(), 0) : array_map(count(...), $hand->tiles),
            boneyard: $hand === null ? 0 : count($hand->boneyard),
            drawable: $hand === null ? 0 : $hand->drawable(),
            closed: $hand !== null && $hand->closed(),
            events: $hand === null ? [] : $hand->events,
            legal: $turn === $seat ? $this->legal() : [],
            sides: $this->ledger->sides,
            bank: $this->ledger->bank,
        );
    }

    /** Deal every hand whose opening has come by `$atMs`. */
    private function advance(int $atMs): void
    {
        if ($this->overMs !== null) {
            return;
        }

        $hand = $this->hand();

        if ($hand === null) {
            if ($atMs >= $this->startsMs) {
                $this->deal($this->startsMs, null);
            }

            return;
        }

        if ($hand->endedMs !== null && $atMs >= $hand->endedMs + $this->revealMs) {
            $this->deal($hand->endedMs + $this->revealMs, $hand);
        }
    }

    /**
     * Open the next hand: its deal is the first try R4 does not throw away
     * (ADR 038 decision 34, reading R4: dealt again before anyone sees it).
     * ADR 038 silent: hands are numbered from 1 and tries from 0, as
     * `RoomSeed::deal()` is keyed; a void hand is a hand of its own — its
     * sheet names it — so the deal after it is the next hand's, not another
     * try of the void one.
     */
    private function deal(int $opensMs, ?DominoHand $before): void
    {
        $number = count($this->hands) + 1;

        foreach (range(0, self::TRIES - 1) as $try) {
            $deal = ($this->deals)($number, $try);

            if ($deal->table !== $this->table) {
                throw new LogicException('A deal for another table.');
            }

            if (! $deal->thrown()) {
                $this->hands[] = DominoHand::open($this->variant, $deal, $number, $before, $this->ledger->anyOpen(), $opensMs);

                return;
            }
        }

        throw new LogicException("Hand {$number} has no deal R4 keeps.");
    }

    private function take(DominoAct $act): void
    {
        if ($act->kind === DominoAct::BACK) {
            if ($act->seat !== null && ($this->streaks[$act->seat] ?? 0) >= $this->awayAfter) {
                $this->streaks[$act->seat] = 0;
            }

            return;
        }

        $hand = $this->hand();
        $seat = $hand?->turn;

        if ($hand === null || $seat === null || $act->ply !== $this->ply) {
            return;
        }

        if (! $act->stoodIn && $act->actedMs >= $hand->sinceMs + $this->turnMs) {
            return;
        }

        $next = match ($act->kind) {
            DominoAct::PLAY => self::played($hand, $act),
            DominoAct::YIELD => $act->stoodIn ? null : $hand->give($act->actedMs),
        };

        if ($next === null) {
            return;
        }

        $this->ply++;
        $this->streaks[$seat] = $act->stoodIn ? $this->streaks[$seat] + 1 : 0;
        $hands = $this->hands;
        array_pop($hands);
        $hands[] = $next;
        $this->hands = $hands;

        if ($next->count === null || $next->ending === null) {
            return;
        }

        $this->ledger = $this->ledger->after(
            $next->ending,
            $next->count['winner'],
            $next->ending === RoomHandEnding::Tied ? $next->count['bank'] : $next->count['gain'],
        );
        $this->lines[$next->number] = $this->ledger->lines;
        $this->winner = $this->ledger->winner();

        if ($this->winner !== null) {
            $this->overMs = $act->actedMs;
        }
    }

    /** A play as stored, folded into the hand; null when the judge does not take it. */
    private static function played(DominoHand $hand, DominoAct $act): ?DominoHand
    {
        $path = $act->path ?? [];
        $tile = DominoTile::tryOf($path[0] ?? '');

        if ($tile === null || count($path) !== ($hand->line === [] ? 1 : 2)) {
            return null;
        }

        $end = $path[1] ?? null;

        if ($end !== null && ! DominoTile::isEnd($end)) {
            return null;
        }

        return $hand->play($tile, $end, $act->actedMs, $act->stoodIn);
    }
}
