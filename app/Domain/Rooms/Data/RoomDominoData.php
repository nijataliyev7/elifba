<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomDominoPhase;
use App\Domain\Rooms\Enums\RoomSeatStanding;
use App\Domain\Rooms\Support\Domino\DominoTile;
use InvalidArgumentException;

/**
 * A domino game in the room's state, as one viewer may see it (docs/
 * architecture.md §12 "Hidden hands", "The state's domino", ADR 038
 * decisions 6–8 and 38; zod `RoomDomino`, a strict union by phase): the
 * latest game of the room when it is a domino game, live or over. Built
 * for its viewer by `DominoDriver::part()`: every seat's tiles are a count,
 * the boneyard is a count, and a seat's own tiles — `mine` — reach that
 * seat's player alone; a watcher, a host who does not sit and the screen
 * get none. Tiles are named only where they are public: the line, the
 * latest play, and from the moment a hand is over every seat's tiles left
 * (`sheet.left`). Everything is replayed from the acts and the seed
 * (`DominoGame`), and the seed is never in it.
 *
 * The constructor holds the shape of each phase, as the zod union does:
 *
 * - `countdown` (before the first hand opens at `starts_ms`): no hand, no
 *   turn, an empty line, no act, no sheet, no result;
 * - `play`: the hand that is open, the seat to move, since when and its
 *   flag; no sheet, no result;
 * - `sheet` (the pause after a hand): the hand that ended and its sheet,
 *   with who opens next and when; no turn, no result;
 * - `over`: no turn, the result, and the last hand's sheet when the game
 *   ended at a hand's end — none when it was stopped inside one.
 *
 * @phpstan-import-type RoomDominoSeatArray from RoomDominoSeatData
 * @phpstan-import-type RoomDominoSideArray from RoomDominoSideData
 * @phpstan-import-type RoomDominoHandArray from RoomDominoHandData
 * @phpstan-import-type RoomDominoLastArray from RoomDominoLastData
 * @phpstan-import-type RoomDominoMineArray from RoomDominoMineData
 * @phpstan-import-type RoomDominoSheetArray from RoomDominoSheetData
 * @phpstan-import-type RoomDominoResultArray from RoomDominoResultData
 *
 * @phpstan-type RoomDominoPlacedArray array{tile: string, left: int, right: int}
 * @phpstan-type RoomDominoEndArray array{end: string, number: int}
 * @phpstan-type RoomDominoBoneyardArray array{tiles: int, reserve: int, closed: bool}
 * @phpstan-type RoomDominoArray array{phase: string, starts_ms: int, seats: list<RoomDominoSeatArray>, sides: list<RoomDominoSideArray>, bank: int, hand: RoomDominoHandArray|null, ply: int, turn: int|null, since_ms: int|null, flag_ms: int|null, line: list<RoomDominoPlacedArray>, first: int|null, ends: list<RoomDominoEndArray>, boneyard: RoomDominoBoneyardArray, last: RoomDominoLastArray|null, mine: RoomDominoMineArray|null, sheet: RoomDominoSheetArray|null, result: RoomDominoResultArray|null}
 */
final readonly class RoomDominoData
{
    /**
     * @param  list<RoomDominoSeatData>  $seats  in the order of the turns
     * @param  list<RoomDominoSideData>  $sides  in the server's order
     * @param  list<RoomDominoPlacedArray>  $line  from the left end to the right
     * @param  list<RoomDominoEndArray>  $ends  empty before the first tile
     * @param  RoomDominoBoneyardArray  $boneyard
     */
    public function __construct(
        public RoomDominoPhase $phase,
        public int $starts_ms,
        public array $seats,
        public array $sides,
        public int $bank,
        public ?RoomDominoHandData $hand,
        public int $ply,
        public ?int $turn,
        public ?int $since_ms,
        public ?int $flag_ms,
        public array $line,
        public ?int $first,
        public array $ends,
        public array $boneyard,
        public ?RoomDominoLastData $last,
        public ?RoomDominoMineData $mine,
        public ?RoomDominoSheetData $sheet,
        public ?RoomDominoResultData $result,
    ) {
        $playing = $phase === RoomDominoPhase::Play;
        $over = $phase === RoomDominoPhase::Over;

        $wrong = match (true) {
            array_map(static fn (RoomDominoSeatData $seat): int => $seat->seat, $seats) !== range(0, max(0, count($seats) - 1)) || count($seats) < 2 => 'A table has two to four seats, in the order of the turns.',
            array_filter($line, static fn (array $placed): bool => DominoTile::tryOf($placed['tile']) === null) !== [] => 'A tile is named as one of the 28.',
            ($line === []) !== ($first === null) || ($line === []) !== ($ends === []) => 'A line has its first tile and its two ends once a tile lies.',
            $playing !== ($turn !== null) || $playing !== ($since_ms !== null) || $playing !== ($flag_ms !== null) => 'Only a hand being played has a seat to move, since when and its flag.',
            $phase === RoomDominoPhase::Countdown && ($hand !== null || $line !== [] || $last !== null) => 'Before the first hand nothing is dealt or played.',
            $phase !== RoomDominoPhase::Countdown && ! $over && $hand === null => 'A hand is open, or was last.',
            ($phase === RoomDominoPhase::Countdown || $playing) && $sheet !== null => 'A sheet shows only between hands and at the end.',
            $phase === RoomDominoPhase::Sheet && $sheet?->until_ms === null => 'The pause between hands has the sheet of the hand that ended, and the next opening.',
            $over && $sheet?->until_ms !== null => 'No hand follows a game that is over.',
            $over !== ($result !== null) => 'A domino game has its result once it is over, and only then.',
            $mine?->legal !== null && ! ($playing && $mine->seat === $turn) => 'Plays are offered to the seat to move alone, in its turn.',
            $mine !== null && $mine->may_yield && ! ($playing && $mine->seat === $turn) => 'Only the seat to move may give the opening.',
            default => null,
        };

        if ($wrong !== null) {
            throw new InvalidArgumentException($wrong);
        }
    }

    /**
     * Whether the server may be to play a turn now or soon — a seat without
     * a player, a seat whose player left or is away, a flag within
     * `$withinMs` of `$nowMs`, or a hand that opens within it, whose opener
     * may be one the server plays for: what a controller asks before it
     * hands `PlayComputerMove` to `defer()`, which decides (ADR 038
     * decisions 25, 76 and 83).
     */
    public function serverToPlay(int $nowMs, int $withinMs): bool
    {
        $opens = match ($this->phase) {
            RoomDominoPhase::Countdown => $this->starts_ms,
            RoomDominoPhase::Sheet => $this->sheet?->until_ms,
            default => null,
        };

        if ($opens !== null) {
            return $opens - $nowMs <= $withinMs;
        }

        if ($this->phase !== RoomDominoPhase::Play || $this->turn === null || $this->flag_ms === null) {
            return false;
        }

        $seat = $this->seats[$this->turn] ?? null;

        return $seat === null || $seat->standing !== RoomSeatStanding::Playing || $this->flag_ms - $nowMs <= $withinMs;
    }

    /**
     * @param  array<string, mixed>  $data  a `RoomDominoArray`, as the state's own `fromArray()` passes it
     */
    public static function fromArray(array $data): self
    {
        /** @var RoomDominoArray $data */
        return new self(
            phase: RoomDominoPhase::from($data['phase']),
            starts_ms: $data['starts_ms'],
            seats: array_map(RoomDominoSeatData::fromArray(...), $data['seats']),
            sides: array_map(RoomDominoSideData::fromArray(...), $data['sides']),
            bank: $data['bank'],
            hand: $data['hand'] === null ? null : RoomDominoHandData::fromArray($data['hand']),
            ply: $data['ply'],
            turn: $data['turn'],
            since_ms: $data['since_ms'],
            flag_ms: $data['flag_ms'],
            line: $data['line'],
            first: $data['first'],
            ends: $data['ends'],
            boneyard: $data['boneyard'],
            last: $data['last'] === null ? null : RoomDominoLastData::fromArray($data['last']),
            mine: $data['mine'] === null ? null : RoomDominoMineData::fromArray($data['mine']),
            sheet: $data['sheet'] === null ? null : RoomDominoSheetData::fromArray($data['sheet']),
            result: $data['result'] === null ? null : RoomDominoResultData::fromArray($data['result']),
        );
    }

    /**
     * @return RoomDominoArray
     */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase->value,
            'starts_ms' => $this->starts_ms,
            'seats' => array_map(static fn (RoomDominoSeatData $seat): array => $seat->toArray(), $this->seats),
            'sides' => array_map(static fn (RoomDominoSideData $side): array => $side->toArray(), $this->sides),
            'bank' => $this->bank,
            'hand' => $this->hand?->toArray(),
            'ply' => $this->ply,
            'turn' => $this->turn,
            'since_ms' => $this->since_ms,
            'flag_ms' => $this->flag_ms,
            'line' => array_map(static fn (array $placed): array => ['tile' => $placed['tile'], 'left' => $placed['left'], 'right' => $placed['right']], $this->line),
            'first' => $this->first,
            'ends' => array_map(static fn (array $end): array => ['end' => $end['end'], 'number' => $end['number']], $this->ends),
            'boneyard' => ['tiles' => $this->boneyard['tiles'], 'reserve' => $this->boneyard['reserve'], 'closed' => $this->boneyard['closed']],
            'last' => $this->last?->toArray(),
            'mine' => $this->mine?->toArray(),
            'sheet' => $this->sheet?->toArray(),
            'result' => $this->result?->toArray(),
        ];
    }
}
