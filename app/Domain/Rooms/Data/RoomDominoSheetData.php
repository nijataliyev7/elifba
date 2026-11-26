<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomHandEnding;
use App\Domain\Rooms\Enums\RoomLedgerLine;
use App\Domain\Rooms\Enums\RoomOpenerWhy;
use App\Domain\Rooms\Support\Domino\DominoTile;
use InvalidArgumentException;

/**
 * The sheet of the hand that just ended, in the state (docs/architecture.md
 * §12 "The state's domino", ADR 038 decisions 8, 38, 49 and 65–66): how it
 * ended and by whom, the winning side, every seat's tiles left — public
 * from the moment the hand is over — with their pips (R16), each side's
 * count, what the winner gained, every line of the account in the order it
 * changed, who opens the next hand and why, and when it opens. `next` and
 * `until_ms` are null when the game is over: no hand follows.
 *
 * @phpstan-type RoomDominoLeftArray array{seat: int, tiles: list<string>, pips: int}
 * @phpstan-type RoomDominoCountArray array{side: int, pips: int}
 * @phpstan-type RoomDominoLineArray array{side: int|null, line: string, points: int}
 * @phpstan-type RoomDominoNextArray array{seat: int|null, why: string}
 * @phpstan-type RoomDominoSheetArray array{hand: int, ending: string, by: int, winner: int|null, left: list<RoomDominoLeftArray>, counts: list<RoomDominoCountArray>, gain: int, lines: list<RoomDominoLineArray>, next: RoomDominoNextArray|null, until_ms: int|null}
 */
final readonly class RoomDominoSheetData
{
    /**
     * @param  list<RoomDominoLeftArray>  $left
     * @param  list<RoomDominoCountArray>  $counts
     * @param  list<array{side: int|null, line: RoomLedgerLine, points: int}>  $lines
     * @param  array{seat: int|null, why: RoomOpenerWhy}|null  $next
     */
    public function __construct(
        public int $hand,
        public RoomHandEnding $ending,
        public int $by,
        public ?int $winner,
        public array $left,
        public array $counts,
        public int $gain,
        public array $lines,
        public ?array $next,
        public ?int $until_ms,
    ) {
        $named = array_merge([], ...array_map(static fn (array $seat): array => $seat['tiles'], $left));
        $won = $ending === RoomHandEnding::Finished || $ending === RoomHandEnding::Blocked;

        $wrong = match (true) {
            array_filter($named, static fn (string $tile): bool => DominoTile::tryOf($tile) === null) !== [] => 'A tile is named as one of the 28.',
            $won !== ($winner !== null) => 'A finished or blocked hand has its winner, a tied or void hand none.',
            ($next === null) !== ($until_ms === null) => 'The next hand is named exactly while one follows.',
            $ending === RoomHandEnding::Void && ($gain !== 0 || $lines !== []) => 'A void hand counts nothing.',
            default => null,
        };

        if ($wrong !== null) {
            throw new InvalidArgumentException($wrong);
        }
    }

    /**
     * @param  RoomDominoSheetArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            hand: $data['hand'],
            ending: RoomHandEnding::from($data['ending']),
            by: $data['by'],
            winner: $data['winner'],
            left: $data['left'],
            counts: $data['counts'],
            gain: $data['gain'],
            lines: array_map(static fn (array $line): array => ['side' => $line['side'], 'line' => RoomLedgerLine::from($line['line']), 'points' => $line['points']], $data['lines']),
            next: $data['next'] === null ? null : ['seat' => $data['next']['seat'], 'why' => RoomOpenerWhy::from($data['next']['why'])],
            until_ms: $data['until_ms'],
        );
    }

    /**
     * @return RoomDominoSheetArray
     */
    public function toArray(): array
    {
        return [
            'hand' => $this->hand,
            'ending' => $this->ending->value,
            'by' => $this->by,
            'winner' => $this->winner,
            'left' => array_map(static fn (array $seat): array => ['seat' => $seat['seat'], 'tiles' => $seat['tiles'], 'pips' => $seat['pips']], $this->left),
            'counts' => array_map(static fn (array $count): array => ['side' => $count['side'], 'pips' => $count['pips']], $this->counts),
            'gain' => $this->gain,
            'lines' => array_map(static fn (array $line): array => ['side' => $line['side'], 'line' => $line['line']->value, 'points' => $line['points']], $this->lines),
            'next' => $this->next === null ? null : ['seat' => $this->next['seat'], 'why' => $this->next['why']->value],
            'until_ms' => $this->until_ms,
        ];
    }
}
