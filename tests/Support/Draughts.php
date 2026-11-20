<?php

namespace Tests\Support;

use App\Domain\Rooms\Enums\RoomActKind;
use App\Domain\Rooms\Enums\RoomMatchColor;
use App\Domain\Rooms\Support\Draughts\DraughtsAct;
use App\Domain\Rooms\Support\Draughts\DraughtsMove;
use App\Domain\Rooms\Support\Draughts\DraughtsNotation;
use App\Domain\Rooms\Support\Draughts\DraughtsPosition;
use App\Domain\Rooms\Support\Draughts\DraughtsRules;
use InvalidArgumentException;
use RuntimeException;

/**
 * Russian draughts in tests (ADR 034 decision 34): a position from its
 * FEN, as the oracle's fixtures and the hand-written cases give it
 * (`W:WKe1,c3:Bd4`, the side to move first and `K` before a king's
 * square), a move's path from its line, the fixtures under
 * `tests/Fixtures/rooms/draughts/`, perft, and acts for `DraughtsMatch`.
 */
final class Draughts
{
    public static function position(string $fen): DraughtsPosition
    {
        $parts = explode(':', $fen);

        if (count($parts) !== 3 || ! in_array($parts[0], ['W', 'B'], true) || $parts[1][0] !== 'W' || $parts[2][0] !== 'B') {
            throw new InvalidArgumentException("Not a FEN of draughts: {$fen}");
        }

        $pieces = static fn (string $list): array => array_values(array_filter(explode(',', substr($list, 1)), static fn (string $piece): bool => $piece !== ''));

        return DraughtsPosition::of($parts[0] === 'W' ? RoomMatchColor::White : RoomMatchColor::Black, $pieces($parts[1]), $pieces($parts[2]));
    }

    /**
     * A move's squares from its line: `c3-d4`, `c3:e5:g7`.
     *
     * @return list<string>
     */
    public static function path(string $line): array
    {
        return preg_split('/[-:]/', $line) ?: [];
    }

    /**
     * Every legal move as its line, with the squares it took, sorted by
     * line — the form of the oracle's fixtures.
     *
     * @return array<string, list<string>>
     */
    public static function legal(DraughtsPosition $position): array
    {
        $moves = [];

        foreach (DraughtsRules::moves($position) as $move) {
            $moves[DraughtsNotation::text($move)] = $move->captured;
        }

        ksort($moves, SORT_STRING);

        return $moves;
    }

    /** The legal move a line names in a position; a test fails without one. */
    public static function move(DraughtsPosition $position, string $line): DraughtsMove
    {
        return DraughtsRules::find($position, self::path($line))
            ?? throw new RuntimeException("{$line} is no legal move here.");
    }

    /** The number of move sequences of a depth (perft). */
    public static function perft(DraughtsPosition $position, int $depth): int
    {
        $moves = DraughtsRules::moves($position);

        if ($depth === 1) {
            return count($moves);
        }

        $count = 0;

        foreach ($moves as $move) {
            $count += self::perft(DraughtsRules::apply($position, $move), $depth - 1);
        }

        return $count;
    }

    /**
     * A fixture of the oracle's or a hand-written one.
     *
     * @return array<string, mixed>
     */
    public static function fixture(string $name): array
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__)."/Fixtures/rooms/draughts/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : throw new RuntimeException("{$name}.json is no object.");
    }

    /**
     * Acts that play a game's lines from its start, one every `$everyMs`
     * after `$startsMs`, each by the side to move.
     *
     * @param  list<string>  $lines
     * @return list<DraughtsAct>
     */
    public static function moves(array $lines, int $startsMs, int $everyMs = 1000): array
    {
        $acts = [];

        foreach ($lines as $ply => $line) {
            $acts[] = self::act(RoomActKind::Move, RoomMatchColor::toMove($ply), $ply, $startsMs + ($ply + 1) * $everyMs, self::path($line));
        }

        return $acts;
    }

    /**
     * @param  list<string>|null  $path
     */
    public static function act(RoomActKind $kind, RoomMatchColor $side, int $ply, int $actedMs, ?array $path = null): DraughtsAct
    {
        return new DraughtsAct($kind, $side, $ply, $path, $actedMs);
    }
}
