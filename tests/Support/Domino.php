<?php

namespace Tests\Support;

use App\Domain\Rooms\Enums\RoomHandEnding;
use App\Domain\Rooms\Enums\RoomOpenerWhy;
use App\Domain\Rooms\Enums\RoomSeatStanding;
use App\Domain\Rooms\Enums\RoomTable;
use App\Domain\Rooms\Support\Domino\Domino101;
use App\Domain\Rooms\Support\Domino\DominoAct;
use App\Domain\Rooms\Support\Domino\DominoDeal;
use App\Domain\Rooms\Support\Domino\DominoGame;
use App\Domain\Rooms\Support\Domino\DominoHand;
use App\Domain\Rooms\Support\Domino\DominoLedger;
use App\Domain\Rooms\Support\Domino\DominoTile;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Domino «101» in tests (ADR 038): deals set by hand, a hand opened and
 * played from short lines (`3-2 left`, `1-1`), a game's acts, a game
 * played out by a simple rule for the properties every game keeps — and
 * the oracle's fixtures under `tests/Fixtures/rooms/domino/` (decision 58;
 * their format is `scripts/domino-oracle/README.md`'s) read into the
 * judge's terms and its answers written back in the fixtures' shapes.
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
        return DominoHand::open(new Domino101, $deal, 1, null, 0);
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

    /**
     * A fixture of the oracle's (`hands`, `ledger`, `games`) or written by
     * hand (`cases`, `study`).
     *
     * @return array<string, mixed>
     */
    public static function fixture(string $name): array
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__)."/Fixtures/rooms/domino/{$name}.json"), true, flags: JSON_THROW_ON_ERROR);

        return is_array($data) ? $data : throw new RuntimeException("{$name}.json is no object.");
    }

    /**
     * A fixture's deal, `{"seats": [[7 tiles] …], "boneyard": [tiles]}`.
     *
     * @param  array{seats: list<list<string>>, boneyard: list<string>}  $deal
     */
    public static function dealOf(RoomTable $table, array $deal): DominoDeal
    {
        return DominoDeal::set($table, $deal['seats'], $deal['boneyard']);
    }

    /**
     * A deal in the fixtures' shape.
     *
     * @return array{seats: list<list<string>>, boneyard: list<string>}
     */
    public static function dealShape(DominoDeal $deal): array
    {
        return ['seats' => array_map(DominoTile::names(...), $deal->hands), 'boneyard' => DominoTile::names($deal->boneyard)];
    }

    /**
     * A fixture's `right` — `{"seat", "why"}`, or null while no account is
     * open — as the hand before's `next()` gives it.
     *
     * @param  array{seat: int, why: string}|null  $right
     * @return array{0: int, 1: RoomOpenerWhy}|null
     */
    public static function rightOf(?array $right): ?array
    {
        return $right === null ? null : [$right['seat'], RoomOpenerWhy::from($right['why'])];
    }

    /**
     * A hand as a fixture opens it: its table, its deal and the seat with
     * the right (R8), or the deal's opener while no account is open.
     *
     * @param  array{table: string, deal: array{seats: list<list<string>>, boneyard: list<string>}, right: array{seat: int, why: string}|null}  $entry
     */
    public static function opened(array $entry, int $number = 1): DominoHand
    {
        $table = RoomTable::from($entry['table']);

        return DominoHand::open(new Domino101, self::dealOf($table, $entry['deal']), $number, self::rightOf($entry['right']), 0);
    }

    /**
     * A fixture's act on a hand — `["play", tile]`, `["play", tile, end]`
     * or `["yield"]` — at `$atMs`; null when the judge does not take it.
     *
     * @param  list<string>  $act
     */
    public static function act(DominoHand $hand, array $act, int $atMs): ?DominoHand
    {
        return match ($act[0]) {
            'play' => $hand->play(DominoTile::of($act[1]), $act[2] ?? null, $atMs, false),
            'yield' => $hand->give($atMs),
            default => throw new InvalidArgumentException("No act {$act[0]}."),
        };
    }

    /**
     * The plays of the seat to move as a set in the fixtures' shape —
     * `[[tile, [ends]], …]`, sorted by tile, each tile's ends sorted —, so
     * an order that is only the oracle's choice compares equal.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    public static function legalSet(DominoHand $hand): array
    {
        return self::asSet(array_map(
            static fn (array $play): array => [$play['tile']->name(), $play['ends']],
            $hand->legal(),
        ));
    }

    /**
     * A fixture's `legal`, as a set.
     *
     * @param  list<array{0: string, 1: list<string>}>  $legal
     * @return list<array{0: string, 1: list<string>}>
     */
    public static function asSet(array $legal): array
    {
        $legal = array_map(static function (array $play): array {
            $ends = $play[1];
            sort($ends);

            return [$play[0], $ends];
        }, $legal);
        usort($legal, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $legal;
    }

    /**
     * What followed the latest act by itself, as the fixtures write it:
     * `[{seat, drew: [tiles in order], passed}, …]`.
     *
     * @return list<array{seat: int, drew: list<string>, passed: bool}>
     */
    public static function thenOf(DominoHand $hand): array
    {
        return array_map(static function (array $step) use ($hand): array {
            $drew = DominoTile::names($hand->drawn[$step['seat']] ?? []);

            if (count($drew) !== $step['drew']) {
                throw new RuntimeException("Seat {$step['seat']} drew {$step['drew']}, its tiles say ".count($drew).'.');
            }

            return ['seat' => $step['seat'], 'drew' => $drew, 'passed' => $step['passed']];
        }, $hand->last['then'] ?? []);
    }

    /**
     * The line as the fixtures write it: `[[tile, left, right], …]` from
     * the left end to the right.
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    public static function lineOf(DominoHand $hand): array
    {
        return array_map(static fn (array $placed): array => [$placed['tile']->name(), $placed['left'], $placed['right']], $hand->line);
    }

    /**
     * The state of a hand in a fixture's terms: who is to move, its plays
     * as a set, whether it may give, the line, its ends, the boneyard and
     * whether it is shut.
     *
     * @return array{turn: int|null, legal: list<array{0: string, 1: list<string>}>, may_yield: bool, line: list<array{0: string, 1: int, 2: int}>, ends: list<int>, boneyard: int, closed: bool}
     */
    public static function stateOf(DominoHand $hand): array
    {
        return [
            'turn' => $hand->turn,
            'legal' => self::legalSet($hand),
            'may_yield' => $hand->mayGive(),
            'line' => self::lineOf($hand),
            'ends' => array_values($hand->ends()),
            'boneyard' => count($hand->boneyard),
            'closed' => $hand->closed(),
        ];
    }

    /**
     * The account as `open` says it stands before a hand: the sides marked
     * open have opened theirs (a win of 13 each, R21), the others are
     * closed with nothing waiting.
     *
     * @param  list<bool>  $open
     */
    public static function ledgerOpen(array $open): DominoLedger
    {
        $ledger = (new Domino101)->ledger(count($open));

        foreach ($open as $side => $isOpen) {
            if ($isOpen) {
                $ledger = $ledger->win($side, Domino101::OPENS_AT);
            }
        }

        return $ledger;
    }

    /** The account after a hand that ended: its win, its tie or nothing (R19–R24). */
    public static function afterHand(DominoLedger $ledger, DominoHand $hand): DominoLedger
    {
        $count = $hand->count ?? throw new RuntimeException('The hand is not over.');
        $ending = $hand->ending ?? throw new RuntimeException('The hand is not over.');

        return $ledger->after($ending, $count['winner'], $ending === RoomHandEnding::Tied ? $count['bank'] : $count['gain']);
    }

    /**
     * A hand's end as the fixtures write it — how it ended, by whom, the
     * winning side, every seat's tiles left and their pips (R16), each
     * side's count, the gain and who opens the next hand —, with the
     * accounts open before it as `open` says; null while it goes on.
     *
     * @param  list<bool>  $open
     * @return array{ending: string, by: int|null, winner: int|null, left: list<array{seat: int, tiles: list<string>, pips: int}>, counts: list<array{side: int, pips: int}>, gain: int, next: array{seat: int|null, why: string}, line: list<array{0: string, 1: int, 2: int}>}|null
     */
    public static function endOf(DominoHand $hand, array $open): ?array
    {
        if ($hand->ending === null || $hand->count === null) {
            return null;
        }

        $variant = new Domino101;
        [$seat, $why] = $variant->next($hand, self::afterHand(self::ledgerOpen($open), $hand)->anyOpen());

        return [
            'ending' => $hand->ending->value,
            'by' => $hand->by,
            'winner' => $hand->count['winner'],
            'left' => array_map(
                static fn (int $seat): array => ['seat' => $seat, 'tiles' => DominoTile::names($hand->tiles[$seat]), 'pips' => $hand->count['left'][$seat]],
                array_keys($hand->tiles),
            ),
            'counts' => array_map(
                static fn (int $side): array => ['side' => $side, 'pips' => $hand->count['counts'][$side]],
                array_keys($hand->count['counts']),
            ),
            'gain' => $hand->count['gain'],
            'next' => ['seat' => $seat, 'why' => $why->value],
            'line' => self::lineOf($hand),
        ];
    }

    /**
     * Walk a hand of `hands.json` or `games.json` turn by turn: before each
     * act the seat to move, its plays and whether it may give; after it
     * what followed by itself, the line (where the fixture gives it), the
     * ends, the boneyard and whether it is shut. Each step is the fixture's
     * turn beside the judge's answer in the same keys; a step whose act the
     * judge does not take ends the walk with the answer `taken: false`.
     *
     * @param  array<string, mixed>  $entry
     * @return array{hand: DominoHand, steps: list<array{turn: int, expected: array<string, mixed>, actual: array<string, mixed>}>}
     */
    public static function walk(array $entry, int $number = 1): array
    {
        $hand = self::opened($entry, $number);
        $steps = [];

        foreach ($entry['turns'] as $index => $turn) {
            $before = self::stateOf($hand);
            $expected = [
                'seat' => $turn['seat'],
                'legal' => self::asSet($turn['legal']),
                'may_yield' => $turn['may_yield'],
                'taken' => true,
                'then' => $turn['then'],
                'ends' => $turn['ends'],
                'boneyard' => $turn['boneyard'],
                'closed' => $turn['closed'],
            ];
            $next = self::act($hand, $turn['act'], $index + 1);
            $actual = [
                'seat' => $before['turn'],
                'legal' => $before['legal'],
                'may_yield' => $before['may_yield'],
                'taken' => $next !== null,
            ];

            if ($next !== null) {
                $state = self::stateOf($next);
                $actual += ['then' => self::thenOf($next), 'ends' => $state['ends'], 'boneyard' => $state['boneyard'], 'closed' => $state['closed']];

                if (array_key_exists('line', $turn)) {
                    $expected['line'] = $turn['line'];
                    $actual['line'] = $state['line'];
                }
            }

            $steps[] = ['turn' => $index + 1, 'expected' => $expected, 'actual' => $actual];

            if ($next === null) {
                break;
            }

            $hand = $next;
        }

        return ['hand' => $hand, 'steps' => $steps];
    }

    /**
     * A hand's result as the ledger reads it — `{ending, winner, gain}`, and
     * `pot` for a tied hand — folded into the account. A result whose
     * `ending` the study does not know is a win, or a tie when it has no
     * winner (`scripts/domino-oracle/README.md`).
     *
     * @param  array{ending?: string, winner: int|null, gain: int, pot?: int}  $result
     */
    public static function fold(DominoLedger $ledger, array $result): DominoLedger
    {
        $ending = isset($result['ending'])
            ? RoomHandEnding::from($result['ending'])
            : ($result['winner'] === null ? RoomHandEnding::Tied : RoomHandEnding::Finished);

        return $ledger->after($ending, $result['winner'], $ending === RoomHandEnding::Tied ? ($result['pot'] ?? 0) : $result['gain']);
    }

    /**
     * The account in the fixtures' shape: every side's balance, whether it
     * is open, its waiting points and tries; the bank; the lines the last
     * hand wrote; whether the game is over, its winner and a dry win.
     *
     * @return array{sides: list<array{balance: int, open: bool, waiting: int, tries: int}>, bank: int, lines: list<array{side: int|null, line: string, points: int}>, over: bool, winner: int|null, dry: bool}
     */
    public static function account(DominoLedger $ledger): array
    {
        return [
            'sides' => array_map(
                static fn (array $side): array => ['balance' => $side['balance'], 'open' => $side['open'], 'waiting' => $side['waiting'], 'tries' => $side['tries']],
                $ledger->sides,
            ),
            'bank' => $ledger->bank,
            'lines' => array_map(
                static fn (array $line): array => ['side' => $line['side'], 'line' => $line['line']->value, 'points' => $line['points']],
                $ledger->lines,
            ),
            'over' => $ledger->winner() !== null,
            'winner' => $ledger->winner(),
            'dry' => $ledger->dry(),
        ];
    }

    /**
     * Only the keys a fixture gives, so a check that names some of them
     * compares those.
     *
     * @param  array<string, mixed>  $actual
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>
     */
    public static function only(array $actual, array $expected): array
    {
        return array_intersect_key($actual, $expected);
    }

    /**
     * Everything a `cases.json` check may name of a hand after some acts:
     * who is to move, its plays as a set, whether it may give, who opens
     * and why, what followed the latest act, the line, its ends, the
     * boneyard, whether it is shut, and its end (null while it goes on).
     *
     * @param  list<bool>  $open
     * @return array<string, mixed>
     */
    public static function checked(DominoHand $hand, array $open): array
    {
        $end = self::endOf($hand, $open);

        if ($end !== null) {
            unset($end['line']);
        }

        return [
            ...self::stateOf($hand),
            'opener' => ['seat' => $hand->opener, 'why' => $hand->why->value],
            'then' => self::thenOf($hand),
            'end' => $end,
        ];
    }

    /**
     * A value with every map's keys sorted, at every depth, so two answers
     * compare strictly whatever order their keys were written in; lists
     * keep their order.
     */
    public static function canon(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canon(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * R4's reasons as a set, so their order — the oracle's choice — does
     * not count.
     *
     * @param  list<array<string, int>>  $reasons
     * @return list<array<string, int>>
     */
    public static function reasonsSet(array $reasons): array
    {
        $reasons = array_map(static function (array $reason): array {
            ksort($reason);

            return $reason;
        }, $reasons);
        usort($reasons, static fn (array $a, array $b): int => json_encode($a) <=> json_encode($b));

        return $reasons;
    }
}
