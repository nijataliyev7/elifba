<?php

namespace App\Domain\Rooms\Support\Domino;

use App\Domain\Rooms\Enums\RoomHandEnding;
use App\Domain\Rooms\Enums\RoomOpenerWhy;
use App\Domain\Rooms\Enums\RoomTable;

/**
 * One hand of domino folded from its deal and its turn acts (docs/
 * architecture.md §12 "Domino", ADR 038 decisions 4, 18, 38): the line and
 * its ends, each seat's tiles, the boneyard, the seat to move and since
 * when, what followed the latest act by itself, and once it is over how it
 * ended and how it counts. Immutable: a play or a give is a new hand, or
 * null when the judge does not take it. Nothing of it is stored, and it
 * holds tiles no device may see: what a seat may know of it is
 * `DominoView`'s.
 *
 * @phpstan-import-type Placed from DominoRules
 * @phpstan-import-type Play from DominoRules
 * @phpstan-import-type Then from DominoRules
 * @phpstan-import-type Count from DominoVariant
 *
 * @phpstan-type Event array{seat: int, kind: 'play'|'yield'|'draw'|'pass', tile: DominoTile|null, end: string|null, count: int}
 * @phpstan-type Last array{seat: int, kind: 'play'|'yield', tile: DominoTile|null, end: string|null, stoodIn: bool, then: list<Then>}
 */
final class DominoHand
{
    /** @var list<list<DominoTile>> each seat's tiles, in the order dealt and drawn */
    public private(set) array $tiles;

    /** @var list<DominoTile> the tiles nobody holds, in the order they are drawn */
    public private(set) array $boneyard;

    /** @var list<Placed> from the left end to the right, each tile as it lies */
    public private(set) array $line = [];

    /** The index in `line` of the hand's first tile; null before it. */
    public private(set) ?int $first = null;

    /** The seat that opens: the seat with the right, or its partner after «Sən aç». */
    public private(set) int $opener;

    public private(set) RoomOpenerWhy $why;

    /** The seat to move; null once the hand is over. */
    public private(set) ?int $turn;

    /** When the seat to move began its turn: the act before, or the hand's opening. */
    public private(set) int $sinceMs;

    /** @var list<Event> every play, give, draw and pass of the hand, in order */
    public private(set) array $events = [];

    /** @var Last|null the latest turn act and what followed it by itself */
    public private(set) ?array $last = null;

    /** @var array<int, list<DominoTile>> the tiles each seat drew after the latest turn act */
    public private(set) array $drawn = [];

    public private(set) ?RoomHandEnding $ending = null;

    /** Who finished; who made the last play of a blocked or tied hand; a void hand's opener. */
    public private(set) ?int $by = null;

    /** The moment of the hand's last act; null while it is open. */
    public private(set) ?int $endedMs = null;

    /** @var Count|null how the hand counts, once it is over */
    public private(set) ?array $count = null;

    /**
     * @param  int  $number  the hand's number in the game, from 1
     * @param  int  $entitled  the seat that had the right to open, before any «Sən aç»
     */
    private function __construct(
        private readonly DominoVariant $variant,
        public readonly RoomTable $table,
        public readonly int $number,
        public readonly DominoDeal $deal,
        public readonly int $entitled,
        public readonly int $opensMs,
        RoomOpenerWhy $why,
    ) {
        $this->tiles = $deal->hands;
        $this->boneyard = $deal->boneyard;
        $this->opener = $entitled;
        $this->why = $why;
        $this->turn = $entitled;
        $this->sinceMs = $opensMs;
    }

    /**
     * A hand dealt and opened at `$opensMs`, its opener asked of the
     * variant from the hand before and the account (R6–R8).
     */
    public static function open(DominoVariant $variant, DominoDeal $deal, int $number, ?self $before, bool $anyOpen, int $opensMs): self
    {
        [$seat, $why] = $variant->opener($deal->table, $deal, $before, $anyOpen);

        return new self($variant, $deal->table, $number, $deal, $seat, $opensMs, $why);
    }

    /**
     * The number open at each end, by name; none before the first tile.
     *
     * @return array<string, int>
     */
    public function ends(): array
    {
        return DominoRules::ends($this->line);
    }

    /**
     * The plays of the seat to move, in the order it holds its tiles; none
     * once the hand is over.
     *
     * @return list<Play>
     */
    public function legal(): array
    {
        if ($this->turn === null) {
            return [];
        }

        return DominoRules::plays($this->tiles[$this->turn], $this->ends(), $this->variant->openings($this));
    }

    /** Whether the seat to move may give the opening to its partner (R9). */
    public function mayGive(): bool
    {
        return $this->turn !== null && $this->variant->mayGive($this);
    }

    /** Whether the boneyard is shut by the line (R13). */
    public function closed(): bool
    {
        return $this->variant->closed($this);
    }

    /** The tiles in the boneyard that may still be drawn (R12). */
    public function drawable(): int
    {
        return $this->closed() ? 0 : max(0, count($this->boneyard) - $this->variant->reserve());
    }

    /** The pips a seat's tiles count (R16). */
    public function pips(int $seat): int
    {
        return $this->variant->pips($this->tiles[$seat]);
    }

    /**
     * The seat to move puts a tile at an end: `$end` null for the hand's
     * first tile. Null when the judge does not take it — no turn, a tile
     * the seat does not hold, an end it does not fit, an end named for the
     * first tile or none for a later one. Then the hand is finished (R15),
     * void (R5), or what follows by itself is folded in (decision 18).
     */
    public function play(DominoTile $tile, ?string $end, int $atMs, bool $stoodIn): ?self
    {
        $seat = $this->turn;
        $play = null;

        foreach ($this->legal() as $legal) {
            if ($legal['tile'] === $tile) {
                $play = $legal;
            }
        }

        if ($seat === null || $play === null || ($play['ends'] === [] ? $end !== null : ! in_array($end, $play['ends'], true))) {
            return null;
        }

        $next = clone $this;
        $next->first = $this->line === [] ? 0 : ($end === DominoTile::LEFT ? (int) $this->first + 1 : $this->first);
        $next->line = DominoRules::place($this->line, $tile, $end);
        $next->tiles[$seat] = array_values(array_filter($this->tiles[$seat], static fn (DominoTile $held): bool => $held !== $tile));
        $next->events[] = ['seat' => $seat, 'kind' => 'play', 'tile' => $tile, 'end' => $end, 'count' => 1];
        $next->sinceMs = $atMs;
        $next->drawn = [];
        $then = [];

        if ($next->tiles[$seat] === []) {
            $next->finish(RoomHandEnding::Finished, $seat, $atMs);
        } elseif ($this->line === [] && $this->variant->void($next)) {
            $next->finish(RoomHandEnding::Void, $seat, $atMs);
        } else {
            $follow = DominoRules::follow($next->tiles, $next->boneyard, $next->ends(), $next->closed(), $seat, $this->variant->drawSize(), $this->variant->reserve());
            $next->tiles = $follow['tiles'];
            $next->boneyard = $follow['boneyard'];
            $next->drawn = $follow['drawn'];
            $then = $follow['then'];

            foreach ($then as $step) {
                if ($step['drew'] > 0) {
                    $next->events[] = ['seat' => $step['seat'], 'kind' => 'draw', 'tile' => null, 'end' => null, 'count' => $step['drew']];
                }

                if ($step['passed']) {
                    $next->events[] = ['seat' => $step['seat'], 'kind' => 'pass', 'tile' => null, 'end' => null, 'count' => 0];
                }
            }

            $next->turn = $follow['turn'];

            if ($follow['turn'] === null) {
                $next->finish(RoomHandEnding::Blocked, $seat, $atMs);
            }
        }

        $next->last = ['seat' => $seat, 'kind' => 'play', 'tile' => $tile, 'end' => $end, 'stoodIn' => $stoodIn, 'then' => $then];

        return $next;
    }

    /**
     * «Sən aç» (R9): the seat to move gives the opening to its partner, who
     * opens with any tile. Null when it may not.
     */
    public function give(int $atMs, bool $stoodIn = false): ?self
    {
        $seat = $this->turn;
        $partner = $seat === null ? null : $this->table->partnerOf($seat);

        if ($seat === null || $partner === null || ! $this->mayGive()) {
            return null;
        }

        $next = clone $this;
        $next->opener = $partner;
        $next->why = RoomOpenerWhy::Given;
        $next->turn = $partner;
        $next->sinceMs = $atMs;
        $next->drawn = [];
        $next->events[] = ['seat' => $seat, 'kind' => 'yield', 'tile' => null, 'end' => null, 'count' => 0];
        $next->last = ['seat' => $seat, 'kind' => 'yield', 'tile' => null, 'end' => null, 'stoodIn' => $stoodIn, 'then' => []];

        return $next;
    }

    public function over(): bool
    {
        return $this->ending !== null;
    }

    private function finish(RoomHandEnding $ending, int $by, int $atMs): void
    {
        $this->turn = null;
        $this->by = $by;
        $this->endedMs = $atMs;
        $this->ending = $ending;
        $this->count = $this->variant->count($this);

        if ($this->count['tied']) {
            $this->ending = RoomHandEnding::Tied;
        }
    }
}
