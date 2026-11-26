<?php

namespace Tests\Support;

use App\Domain\Rooms\Enums\RoomSeatStanding;
use App\Domain\Rooms\Enums\RoomTable;
use App\Domain\Rooms\Support\Domino\Domino101;
use App\Domain\Rooms\Support\Domino\DominoAct;
use App\Domain\Rooms\Support\Domino\DominoDeal;
use App\Domain\Rooms\Support\Domino\DominoGame;
use App\Domain\Rooms\Support\Domino\DominoHand;
use App\Domain\Rooms\Support\Domino\DominoTile;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Domino «101» in tests (ADR 038): deals set by hand, a hand opened and
 * played from short lines (`3-2 left`, `1-1`), a game's acts, and a game
 * played out by a simple rule for the properties every game keeps.
 */
final class Domino
{
    public const int STARTS = 1_800_000_000_000;

    public const int TURN = 30_000;

    public const int REVEAL = 10_000;

    public const int AWAY_AFTER = 2;

    /**
     * A deal set by hand: each seat's seven tiles, then the boneyard's
     * first tiles in their order — the rest of the 28 follow in the tiles'
     * fixed order.
     *
     * @param  list<list<string>>  $hands
     * @param  list<string>  $boneyard
     */
    public static function deal(RoomTable $table, array $hands, array $boneyard = []): DominoDeal
    {
        $named = [...array_merge(...$hands), ...$boneyard];
        $rest = array_values(array_diff(array_keys(DominoTile::all()), $named));

        return DominoDeal::set($table, $hands, [...$boneyard, ...$rest]);
    }

    /**
     * A source of deals by hand number, from 1: a deal, or a list of deals
     * by try.
     *
     * @param  array<int, DominoDeal|list<DominoDeal>>  $deals
     * @return Closure(int, int): DominoDeal
     */
    public static function source(array $deals): Closure
    {
        return static function (int $hand, int $try) use ($deals): DominoDeal {
            $deal = $deals[$hand] ?? throw new RuntimeException("No deal set for hand {$hand}.");

            if ($deal instanceof DominoDeal) {
                return $deal;
            }

            return $deal[$try] ?? throw new RuntimeException("No deal set for hand {$hand}, try {$try}.");
        };
    }

    /** A game's first hand dealt so, opened at 0 by R6 or R7. */
    public static function hand(DominoDeal $deal): DominoHand
    {
        return DominoHand::open(new Domino101, $deal, 1, null, false, 0);
    }

    /**
     * A hand with plays made in turn, each `tile` or `tile end`, `give` for
     * «Sən aç»; every one must be taken.
     */
    public static function play(DominoHand $hand, string ...$plays): DominoHand
    {
        foreach ($plays as $index => $play) {
            $parts = explode(' ', $play);
            $next = $play === 'give'
                ? $hand->give($index + 1)
                : $hand->play(DominoTile::of($parts[0]), $parts[1] ?? null, $index + 1, false);

            if ($next === null) {
                throw new InvalidArgumentException("Not taken: {$play} (play ".($index + 1).').');
            }

            $hand = $next;
        }

        return $hand;
    }

    /**
     * The plays a hand allows, as `tile` or `tile end` lines.
     *
     * @return list<string>
     */
    public static function legal(DominoHand $hand): array
    {
        $lines = [];

        foreach ($hand->legal() as $play) {
            if ($play['ends'] === []) {
                $lines[] = $play['tile']->name();
            }

            foreach ($play['ends'] as $end) {
                $lines[] = "{$play['tile']->name()} {$end}";
            }
        }

        return $lines;
    }

    /**
     * The line as its tiles lie, `left|right` each.
     *
     * @return list<string>
     */
    public static function line(DominoHand $hand): array
    {
        return array_map(static fn (array $placed): string => "{$placed['left']}|{$placed['right']}", $hand->line);
    }

    /**
     * The acts of plays at a ply each, `$secondsApart` seconds after `$fromMs`
     * and each other: `tile`, `tile end` or `give`.
     *
     * @param  list<string>  $plays
     * @return list<DominoAct>
     */
    public static function acts(array $plays, int $fromPly, int $fromMs, int $secondsApart = 1, bool $stoodIn = false): array
    {
        $acts = [];

        foreach ($plays as $index => $play) {
            $at = $fromMs + ($index + 1) * $secondsApart * 1000;
            $acts[] = $play === 'give'
                ? DominoAct::yield($fromPly + $index, $at)
                : DominoAct::play($fromPly + $index, explode(' ', $play), $at, $stoodIn);
        }

        return $acts;
    }

    /**
     * A game of «101» at the test's clock.
     *
     * @param  Closure(int, int): DominoDeal  $deals
     * @param  list<DominoAct>  $acts
     * @param  list<RoomSeatStanding>|null  $presence  every seat a present player by default
     */
    public static function game(RoomTable $table, Closure $deals, array $acts, int $nowMs, ?array $presence = null, ?int $endedMs = null): DominoGame
    {
        return DominoGame::replay(
            new Domino101,
            $table,
            $deals,
            $presence ?? array_fill(0, $table->seats(), RoomSeatStanding::Playing),
            self::STARTS,
            self::TURN,
            self::REVEAL,
            self::AWAY_AFTER,
            $endedMs,
            $acts,
            $nowMs,
        );
    }

    /**
     * A game played out from a deal source by the first legal play, a
     * second apart within a hand, the next hand at its opening, until it is
     * over or `$maxPlies` plays were made.
     *
     * @param  Closure(int, int): DominoDeal  $deals
     * @return array{0: DominoGame, 1: list<DominoAct>}
     */
    public static function playOut(RoomTable $table, Closure $deals, int $maxPlies = 2000, ?Closure $choose = null): array
    {
        $acts = [];
        $at = self::STARTS;
        $game = self::game($table, $deals, $acts, $at);

        while ($game->overMs === null && count($acts) < $maxPlies) {
            $until = $game->untilMs();

            if ($until !== null) {
                $at = $until;
                $game = self::game($table, $deals, $acts, $at);

                continue;
            }

            $legal = $game->legal();

            if ($legal === []) {
                throw new RuntimeException('A hand in play with no legal play.');
            }

            $play = $choose === null ? $legal[0] : $choose($legal, $game);
            $at += 1000;
            $path = $play['ends'] === [] ? [$play['tile']->name()] : [$play['tile']->name(), $play['ends'][0]];
            $acts[] = DominoAct::play($game->ply, $path, $at);
            $game = self::game($table, $deals, $acts, $at);
        }

        return [$game, $acts];
    }
}
