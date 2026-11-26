<?php

use App\Domain\Rooms\Enums\RoomDominoPhase;
use App\Domain\Rooms\Enums\RoomHandEnding;
use App\Domain\Rooms\Enums\RoomLedgerLine;
use App\Domain\Rooms\Enums\RoomOpenerWhy;
use App\Domain\Rooms\Enums\RoomSeatStanding;
use App\Domain\Rooms\Enums\RoomTable;
use App\Domain\Rooms\Support\Domino\Domino101;
use App\Domain\Rooms\Support\Domino\DominoAct;
use App\Domain\Rooms\Support\Domino\DominoDeal;
use App\Domain\Rooms\Support\Domino\DominoGame;
use App\Domain\Rooms\Support\Domino\DominoRules;
use App\Domain\Rooms\Support\Domino\DominoTile;
use Illuminate\Support\Facades\Config;
use Tests\Support\Domino;

/*
 * A game of «101» replayed from its start facts and its acts at a moment
 * (docs/architecture.md §12 "Domino", ADR 038 decisions 16–28): the
 * phases and the pause between hands, the turn and its flag, away and
 * back, a void hand, a leaver, the host's end, the end at 101, and `ply`
 * counting plays and gives over the whole game. Nothing of it is stored.
 *
 * The game below is at pairs. Hand 1: seat 2 opens with `1-1` (R6) and
 * finishes with its nineteenth play; side 0 gains 58 and opens its account.
 * Hand 2: seat 2 has the right (R8 a); a blocked hand gives side 0 56 more
 * — 114, a dry win.
 */

/** @var list<string> hand 1's plays, by seats 2 0 1 2 3 0 2 3 0 1 2 3 0 1 2 0 2 3 2 */
const DOMINO_HAND_ONE = ['1-1', '4-1 left', '3-1 right', '4-4 left', '3-3 right', '4-3 right', '4-2 left', '3-2 left', '4-0 right', '1-0 right', '2-1 right', '2-2 right', '5-3 left', '2-0 right', '5-1 left', '5-0 right', '6-5 right', '6-4 right', '6-1 left'];

/** @var list<string> hand 2's plays, blocked by seat 0's */
const DOMINO_HAND_TWO = ['1-1', '2-1 left', '5-2 left', '5-3 left', '3-0 left', '2-0 left', '5-1 right', '6-5 right', '3-2 left', '6-0 right', '4-3 left', '0-0 right', '4-0 left', '1-0 left', '4-1 left', '5-4 left', '5-0 left'];

/** Hand 1 ends with its last play; hand 2 opens after the pause. */
const DOMINO_HAND_ONE_ENDS = Domino::STARTS + 19_000;

const DOMINO_HAND_TWO_OPENS = DOMINO_HAND_ONE_ENDS + Domino::REVEAL;

/**
 * @return Closure(int, int): DominoDeal
 */
function dominoPairsDeals(): Closure
{
    return Domino::source([
        1 => Domino::deal(RoomTable::Pairs, [
            ['5-0', '0-0', '4-1', '4-0', '5-3', '4-3', '6-0'],
            ['6-3', '6-6', '3-1', '1-0', '3-0', '6-2', '2-0'],
            ['4-2', '5-1', '1-1', '4-4', '6-5', '2-1', '6-1'],
            ['3-3', '3-2', '5-5', '5-2', '5-4', '6-4', '2-2'],
        ]),
        2 => Domino::deal(RoomTable::Pairs, [
            ['5-1', '5-0', '1-0', '6-0', '4-4', '5-2', '4-2'],
            ['4-3', '5-3', '4-1', '6-3', '6-4', '6-6', '6-1'],
            ['3-1', '3-0', '1-1', '3-3', '2-2', '6-5', '0-0'],
            ['5-5', '3-2', '2-1', '4-0', '5-4', '6-2', '2-0'],
        ]),
        3 => Domino::deal(RoomTable::Pairs, [
            ['1-1', '6-5', '6-4', '5-4', '6-3', '5-3', '4-3'],
            ['0-0', '2-0', '3-0', '2-2', '3-2', '4-2', '6-6'],
            ['2-1', '3-1', '4-1', '5-1', '3-3', '4-4', '6-2'],
            ['4-0', '5-0', '6-0', '5-5', '5-2', '1-0', '6-1'],
        ]),
    ]);
}

/**
 * The game at pairs at a moment.
 *
 * @param  list<DominoAct>  $acts
 * @param  list<RoomSeatStanding>|null  $presence
 */
function dominoPairs(array $acts, int $nowMs, ?array $presence = null, ?int $endedMs = null): DominoGame
{
    return Domino::game(RoomTable::Pairs, dominoPairsDeals(), $acts, $nowMs, $presence, $endedMs);
}

/**
 * Hand 1's first `$count` plays, a second apart from the start.
 *
 * @return list<DominoAct>
 */
function dominoHandOne(int $count = 19): array
{
    return Domino::acts(array_slice(DOMINO_HAND_ONE, 0, $count), 0, Domino::STARTS);
}

/**
 * Hand 1 whole, then hand 2's first `$count` plays a second apart from its opening.
 *
 * @return list<DominoAct>
 */
function dominoHandTwo(int $count = 17): array
{
    return [...dominoHandOne(), ...Domino::acts(array_slice(DOMINO_HAND_TWO, 0, $count), 19, DOMINO_HAND_TWO_OPENS)];
}

it('counts down before the first hand opens, with nothing dealt (ADR 038 decision 24)', function (): void {
    $game = dominoPairs([], Domino::STARTS - 1);

    expect($game->phase)->toBe(RoomDominoPhase::Countdown)
        ->and($game->startsMs)->toBe(Domino::STARTS)
        ->and($game->hand())->toBeNull()
        ->and($game->turn())->toBeNull()
        ->and($game->sinceMs())->toBeNull()
        ->and($game->flagMs())->toBeNull()
        ->and($game->legal())->toBe([])
        ->and($game->serverPlaysFrom(1_000))->toBeNull()
        ->and($game->ply)->toBe(0)
        ->and($game->view(0)->hand)->toBe(0)
        ->and($game->view(0)->tiles)->toBe([]);
});

it('opens the first hand at started_ms, its opener to move, the flag a turn later (ADR 038 decisions 23–24, R6)', function (): void {
    $game = dominoPairs([], Domino::STARTS);

    expect($game->phase)->toBe(RoomDominoPhase::Play)
        ->and($game->hand()?->number)->toBe(1)
        ->and($game->hand()?->opensMs)->toBe(Domino::STARTS)
        ->and($game->turn())->toBe(2)
        ->and($game->hand()?->why)->toBe(RoomOpenerWhy::LowestDouble)
        ->and($game->sinceMs())->toBe(Domino::STARTS)
        ->and($game->flagMs())->toBe(Domino::STARTS + Domino::TURN)
        ->and(DominoTile::names(array_column($game->legal(), 'tile')))->toBe(['1-1'])
        ->and($game->mayGive())->toBeFalse();
});

it('passes the turn with each play, the next turn beginning at the play (ADR 038 decisions 17, 23)', function (): void {
    $game = dominoPairs(dominoHandOne(3), Domino::STARTS + 3_500);

    // Seat 3 passed after `1-1` by itself (decision 18).
    expect($game->ply)->toBe(3)
        ->and($game->turn())->toBe(2)
        ->and($game->sinceMs())->toBe(Domino::STARTS + 3_000)
        ->and($game->flagMs())->toBe(Domino::STARTS + 3_000 + Domino::TURN)
        ->and($game->hand()?->last)->toBe(['seat' => 1, 'kind' => 'play', 'tile' => DominoTile::of('3-1'), 'end' => 'right', 'stoodIn' => false, 'then' => []])
        ->and(dominoPairs(dominoHandOne(1), Domino::STARTS + 1_000)->hand()?->last['then'])->toBe([['seat' => 3, 'drew' => 0, 'passed' => true]]);
});

it('passes over a turn act at another ply, at or after the flag, or the judge does not take (ADR 038 decisions 16, 20, 23)', function (DominoAct $act): void {
    $game = dominoPairs([...dominoHandOne(1), $act], Domino::STARTS + 60_000);

    expect($game->ply)->toBe(1)
        ->and($game->turn())->toBe(0)
        ->and($game->hand()?->line)->toHaveCount(1);
})->with([
    'a stale ply' => [fn (): DominoAct => DominoAct::play(0, ['4-1', 'left'], Domino::STARTS + 2_000)],
    'a ply ahead' => [fn (): DominoAct => DominoAct::play(2, ['4-1', 'left'], Domino::STARTS + 2_000)],
    'at the flag' => [fn (): DominoAct => DominoAct::play(1, ['4-1', 'left'], Domino::STARTS + 1_000 + Domino::TURN)],
    'after the flag' => [fn (): DominoAct => DominoAct::play(1, ['4-1', 'left'], Domino::STARTS + 1_000 + Domino::TURN + 5_000)],
    'a tile the seat does not hold' => [fn (): DominoAct => DominoAct::play(1, ['2-1', 'left'], Domino::STARTS + 2_000)],
    'an end the tile does not fit' => [fn (): DominoAct => DominoAct::play(1, ['4-0', 'left'], Domino::STARTS + 2_000)],
    'the right where both ends show one number' => [fn (): DominoAct => DominoAct::play(1, ['4-1', 'right'], Domino::STARTS + 2_000)],
    'no end after the first tile' => [fn (): DominoAct => DominoAct::play(1, ['4-1'], Domino::STARTS + 2_000)],
    'an end that is no end' => [fn (): DominoAct => DominoAct::play(1, ['4-1', 'middle'], Domino::STARTS + 2_000)],
    'a tile written the other way' => [fn (): DominoAct => DominoAct::play(1, ['1-4', 'left'], Domino::STARTS + 2_000)],
    'no path' => [fn (): DominoAct => new DominoAct(DominoAct::PLAY, 1, null, null, false, Domino::STARTS + 2_000)],
    'a give under R6' => [fn (): DominoAct => DominoAct::yield(1, Domino::STARTS + 2_000)],
]);

it('takes a play just before the flag, and the server\'s play after it (ADR 038 decisions 23, 25)', function (): void {
    $flag = Domino::STARTS + 1_000 + Domino::TURN;
    $device = dominoPairs([...dominoHandOne(1), DominoAct::play(1, ['4-1', 'left'], $flag - 1)], $flag);
    $server = dominoPairs([...dominoHandOne(1), DominoAct::play(1, ['4-1', 'left'], $flag + 400, stoodIn: true)], $flag + 500);

    expect($device->ply)->toBe(2)
        ->and($device->hand()?->last['stoodIn'] ?? null)->toBeFalse()
        ->and($server->ply)->toBe(2)
        ->and($server->hand()?->last['stoodIn'] ?? null)->toBeTrue()
        ->and($server->sinceMs())->toBe($flag + 400);
});

it('shows the sheet in the pause after a hand, with nothing to play, then opens the next (ADR 038 decisions 24, 38, R8 a)', function (): void {
    $sheet = dominoPairs(dominoHandOne(), DOMINO_HAND_ONE_ENDS + 500);

    expect($sheet->phase)->toBe(RoomDominoPhase::Sheet)
        ->and($sheet->sheet()?->number)->toBe(1)
        ->and($sheet->sheet()?->ending)->toBe(RoomHandEnding::Finished)
        ->and($sheet->sheet()?->by)->toBe(2)
        ->and($sheet->untilMs())->toBe(DOMINO_HAND_TWO_OPENS)
        ->and($sheet->lines[1])->toBe([['side' => 0, 'line' => RoomLedgerLine::Opened, 'points' => 58]])
        ->and($sheet->ledger->sides[0]['balance'])->toBe(58)
        ->and($sheet->next())->toBe([2, RoomOpenerWhy::Finished])
        ->and($sheet->turn())->toBeNull()
        ->and($sheet->flagMs())->toBeNull()
        ->and($sheet->legal())->toBe([])
        ->and($sheet->ply)->toBe(19);

    $next = dominoPairs(dominoHandOne(), DOMINO_HAND_TWO_OPENS);

    expect($next->phase)->toBe(RoomDominoPhase::Play)
        ->and($next->hand()?->number)->toBe(2)
        ->and($next->hand()?->opensMs)->toBe(DOMINO_HAND_TWO_OPENS)
        ->and($next->turn())->toBe(2)
        ->and($next->hand()?->why)->toBe(RoomOpenerWhy::Finished)
        ->and($next->sinceMs())->toBe(DOMINO_HAND_TWO_OPENS)
        ->and($next->legal())->toHaveCount(7)
        ->and($next->mayGive())->toBeTrue()
        ->and($next->sheet())->toBeNull()
        ->and($next->untilMs())->toBeNull();
});

it('passes over a turn act in the pause (ADR 038 decision 24)', function (): void {
    $game = dominoPairs([...dominoHandOne(), DominoAct::play(19, ['1-1'], DOMINO_HAND_TWO_OPENS - 1)], DOMINO_HAND_TWO_OPENS + 1_000);

    expect($game->ply)->toBe(19)
        ->and($game->hand()?->number)->toBe(2)
        ->and($game->hand()?->line)->toBe([]);
});

it('counts a give as a turn act, the partner then opening (ADR 038 decision 16, R9)', function (): void {
    $acts = [...dominoHandOne(), DominoAct::yield(19, DOMINO_HAND_TWO_OPENS + 2_000)];
    $game = dominoPairs($acts, DOMINO_HAND_TWO_OPENS + 2_500);

    expect($game->ply)->toBe(20)
        ->and($game->turn())->toBe(0)
        ->and($game->hand()?->why)->toBe(RoomOpenerWhy::Given)
        ->and($game->sinceMs())->toBe(DOMINO_HAND_TWO_OPENS + 2_000)
        ->and($game->mayGive())->toBeFalse()
        ->and($game->legal())->toHaveCount(7)
        ->and(dominoPairs([...$acts, DominoAct::play(20, ['5-1'], DOMINO_HAND_TWO_OPENS + 3_000)], DOMINO_HAND_TWO_OPENS + 3_000)->ply)->toBe(21);
});

it('never takes a give the server made: it only plays (ADR 038 decision 28)', function (): void {
    $acts = [...dominoHandOne(), new DominoAct(DominoAct::YIELD, 19, null, null, true, DOMINO_HAND_TWO_OPENS + 2_000)];

    expect(dominoPairs($acts, DOMINO_HAND_TWO_OPENS + 3_000)->ply)->toBe(19);
});

it('ends the game at the act that brings a side to 101, with no pause: a dry win (ADR 038 decision 24, R25, R26)', function (): void {
    $last = DOMINO_HAND_TWO_OPENS + 17_000;
    $game = dominoPairs(dominoHandTwo(), $last + 60_000);

    expect($game->phase)->toBe(RoomDominoPhase::Over)
        ->and($game->winner)->toBe(0)
        ->and($game->dry())->toBeTrue()
        ->and($game->stopped)->toBeFalse()
        ->and($game->overMs)->toBe($last)
        ->and($game->ply)->toBe(36)
        ->and($game->hands)->toHaveCount(2)
        ->and($game->sheet()?->ending)->toBe(RoomHandEnding::Blocked)
        ->and($game->sheet()?->by)->toBe(0)
        ->and($game->lines[2])->toBe([['side' => 0, 'line' => RoomLedgerLine::Gained, 'points' => 56]])
        ->and($game->ledger->sides[0]['balance'])->toBe(114)
        ->and($game->ledger->sides[1]['balance'])->toBe(0)
        ->and($game->untilMs())->toBeNull()
        ->and($game->next())->toBeNull()
        ->and($game->turn())->toBeNull()
        ->and($game->serverPlaysFrom(1_000))->toBeNull()
        ->and(dominoPairs(dominoHandTwo(16), $last - 500)->phase)->toBe(RoomDominoPhase::Play);
});

it('passes over every act after the game is over (ADR 038 decision 20)', function (): void {
    $acts = [...dominoHandTwo(), DominoAct::play(36, ['1-1'], DOMINO_HAND_TWO_OPENS + 27_000)];
    $game = dominoPairs($acts, DOMINO_HAND_TWO_OPENS + 60_000);

    expect($game->ply)->toBe(36)
        ->and($game->hands)->toHaveCount(2)
        ->and($game->overMs)->toBe(DOMINO_HAND_TWO_OPENS + 17_000);
});

it('counts ply over the whole game, plays and gives, never a back (ADR 038 decision 16)', function (): void {
    $acts = [
        ...dominoHandOne(),
        DominoAct::back(1, 19, DOMINO_HAND_TWO_OPENS + 500),
        DominoAct::yield(19, DOMINO_HAND_TWO_OPENS + 1_000),
        DominoAct::play(20, ['5-1'], DOMINO_HAND_TWO_OPENS + 2_000),
    ];

    expect(dominoPairs($acts, DOMINO_HAND_TWO_OPENS + 2_500)->ply)->toBe(21);
});

it('makes a seat away when its last two turns were played for it, until it comes back (ADR 038 decision 26)', function (): void {
    // Seat 0's turns are plies 1 and 5; seat 1's, 2 and 9.
    $stood = static fn (int $from, int $to): array => array_map(
        static fn (DominoAct $act): DominoAct => in_array($act->ply, [1, 5], true) ? new DominoAct($act->kind, $act->ply, $act->path, null, true, $act->actedMs) : $act,
        array_slice(dominoHandOne(), $from, $to - $from),
    );
    $once = dominoPairs($stood(0, 2), Domino::STARTS + 2_500);
    $away = dominoPairs($stood(0, 8), Domino::STARTS + 8_500);

    expect($once->away(0))->toBeFalse()
        ->and($once->standing(0))->toBe(RoomSeatStanding::Playing)
        ->and($away->away(0))->toBeTrue()
        ->and($away->standing(0))->toBe(RoomSeatStanding::Away)
        ->and($away->turn())->toBe(0)
        ->and($away->serverPlaysFrom(1_200))->toBe(Domino::STARTS + 8_000 + 1_200)
        ->and($away->view(0)->tiles)->not->toBe([]);

    $back = dominoPairs([...$stood(0, 8), DominoAct::back(0, 8, Domino::STARTS + 8_200)], Domino::STARTS + 8_500);

    expect($back->away(0))->toBeFalse()
        ->and($back->standing(0))->toBe(RoomSeatStanding::Playing)
        ->and($back->ply)->toBe(8)
        ->and($back->serverPlaysFrom(1_200))->toBe(Domino::STARTS + 8_000 + Domino::TURN);

    // A seat's own play ends it as well.
    $played = dominoPairs($stood(0, 9), Domino::STARTS + 9_500);

    expect($played->away(0))->toBeFalse();
});

it('passes over a back from a seat that is not away (ADR 038 decision 16)', function (): void {
    $acts = array_map(
        static fn (DominoAct $act): DominoAct => in_array($act->ply, [2, 9], true) ? new DominoAct($act->kind, $act->ply, $act->path, null, true, $act->actedMs) : $act,
        dominoHandOne(10),
    );
    // Seat 1's back after one turn played for it changes nothing: its next is the second.
    array_splice($acts, 3, 0, [DominoAct::back(1, 3, Domino::STARTS + 3_200)]);

    expect(dominoPairs($acts, Domino::STARTS + 10_500)->away(1))->toBeTrue();
});

it('plays on for a seat whose player left, at the computer\'s pace (ADR 038 decisions 27, 28)', function (): void {
    $presence = [RoomSeatStanding::Playing, RoomSeatStanding::Left, RoomSeatStanding::Playing, RoomSeatStanding::Playing];
    $before = dominoPairs(dominoHandOne(2), Domino::STARTS + 2_100, $presence);
    $acts = [...dominoHandOne(2), DominoAct::play(2, ['3-1', 'right'], Domino::STARTS + 3_000, stoodIn: true)];
    $after = dominoPairs($acts, Domino::STARTS + 3_100, $presence);

    expect($before->turn())->toBe(1)
        ->and($before->standing(1))->toBe(RoomSeatStanding::Left)
        ->and($before->away(1))->toBeFalse()
        ->and($before->serverPlaysFrom(900))->toBe(Domino::STARTS + 2_000 + 900)
        ->and($before->serverPlaysFrom(900))->toBeLessThan($before->flagMs() ?? 0)
        ->and($after->ply)->toBe(3)
        ->and($after->phase)->toBe(RoomDominoPhase::Play)
        ->and($after->standing(1))->toBe(RoomSeatStanding::Left);
});

it('plays a computer\'s seat at the computer\'s pace, and never calls it away (ADR 038 decision 28)', function (): void {
    $presence = [RoomSeatStanding::Playing, RoomSeatStanding::Playing, RoomSeatStanding::Computer, RoomSeatStanding::Computer];
    $acts = array_map(
        static fn (DominoAct $act): DominoAct => in_array($act->ply, [0, 3, 4, 6, 7], true) ? new DominoAct($act->kind, $act->ply, $act->path, null, true, $act->actedMs) : $act,
        dominoHandOne(8),
    );
    $game = dominoPairs($acts, Domino::STARTS + 8_100, $presence);

    expect($game->turn())->toBe(0)
        ->and($game->standing(2))->toBe(RoomSeatStanding::Computer)
        ->and($game->away(2))->toBeFalse()
        ->and($game->serverPlaysFrom(900))->toBe(Domino::STARTS + 8_000 + Domino::TURN)
        ->and(dominoPairs(dominoHandOne(), Domino::STARTS, $presence)->serverPlaysFrom(900))->toBe(Domino::STARTS + 900);
});

it('refuses a presence that is not one per seat, or that says away', function (array $presence): void {
    expect(fn () => dominoPairs([], Domino::STARTS, $presence))->toThrow(InvalidArgumentException::class);
})->with([
    'three at four' => [[RoomSeatStanding::Playing, RoomSeatStanding::Playing, RoomSeatStanding::Playing]],
    'away' => [[RoomSeatStanding::Playing, RoomSeatStanding::Away, RoomSeatStanding::Playing, RoomSeatStanding::Playing]],
]);

it('ends the game without a result at ended_ms: inside a hand, no sheet (ADR 038 decision 27)', function (): void {
    $ended = Domino::STARTS + 5_500;
    $game = dominoPairs(dominoHandOne(8), $ended + 60_000, endedMs: $ended);

    expect($game->phase)->toBe(RoomDominoPhase::Over)
        ->and($game->stopped)->toBeTrue()
        ->and($game->winner)->toBeNull()
        ->and($game->dry())->toBeFalse()
        ->and($game->overMs)->toBe($ended)
        ->and($game->ply)->toBe(5)
        ->and($game->sheet())->toBeNull()
        ->and($game->turn())->toBeNull()
        ->and(dominoPairs(dominoHandOne(8), $ended - 1, endedMs: $ended)->phase)->toBe(RoomDominoPhase::Play);
});

it('ends the game without a result at ended_ms: in the pause, the last sheet stays (ADR 038 decision 27)', function (): void {
    $ended = DOMINO_HAND_ONE_ENDS + 3_000;
    $game = dominoPairs(dominoHandOne(), $ended + 60_000, endedMs: $ended);

    expect($game->phase)->toBe(RoomDominoPhase::Over)
        ->and($game->stopped)->toBeTrue()
        ->and($game->hands)->toHaveCount(1)
        ->and($game->sheet()?->number)->toBe(1)
        ->and($game->untilMs())->toBeNull()
        ->and($game->next())->toBeNull();
});

it('ends the game without a result at ended_ms in the countdown, with no hand (ADR 038 decision 27)', function (): void {
    $game = dominoPairs([], Domino::STARTS + 60_000, endedMs: Domino::STARTS - 1_000);

    expect($game->phase)->toBe(RoomDominoPhase::Over)
        ->and($game->stopped)->toBeTrue()
        ->and($game->hand())->toBeNull()
        ->and($game->sheet())->toBeNull();
});

it('makes a hand void, counts nothing and leaves the opener to the next deal (ADR 038 R5, R8)', function (): void {
    $deals = Domino::source([
        1 => Domino::deal(RoomTable::Pairs, [
            ['1-1', '1-0', '2-1', '6-5', '6-4', '5-4', '6-3'],
            ['0-0', '2-0', '3-0', '2-2', '3-2', '4-2', '6-6'],
            ['3-1', '4-1', '5-1', '6-1', '3-3', '4-4', '6-2'],
            ['4-0', '5-0', '6-0', '5-5', '5-2', '5-3', '4-3'],
        ]),
        2 => Domino::deal(RoomTable::Pairs, [
            ['0-0', '2-0', '3-0', '2-2', '3-2', '4-2', '6-6'],
            ['1-1', '6-5', '6-4', '5-4', '6-3', '5-3', '4-3'],
            ['4-0', '5-0', '6-0', '5-5', '5-2', '1-0', '6-1'],
            ['2-1', '3-1', '4-1', '5-1', '3-3', '4-4', '6-2'],
        ]),
    ]);
    $acts = [DominoAct::play(0, ['1-1'], Domino::STARTS + 4_000)];
    $sheet = Domino::game(RoomTable::Pairs, $deals, $acts, Domino::STARTS + 4_100);
    $next = Domino::game(RoomTable::Pairs, $deals, $acts, Domino::STARTS + 4_000 + Domino::REVEAL);

    expect($sheet->phase)->toBe(RoomDominoPhase::Sheet)
        ->and($sheet->sheet()?->ending)->toBe(RoomHandEnding::Void)
        ->and($sheet->sheet()?->by)->toBe(0)
        ->and($sheet->lines[1])->toBe([])
        ->and($sheet->ledger->sides)->toBe((new Domino101)->ledger(2)->sides)
        ->and($sheet->untilMs())->toBe(Domino::STARTS + 4_000 + Domino::REVEAL)
        ->and($sheet->next())->toBe([null, RoomOpenerWhy::LowestDouble])
        ->and($sheet->ply)->toBe(1)
        ->and($next->hand()?->number)->toBe(2)
        ->and($next->turn())->toBe(1)
        ->and($next->hand()?->why)->toBe(RoomOpenerWhy::LowestDouble);
});

/**
 * Every game played out at a table keeps the tiles, ends at 101 and never
 * leaves a hand in play with nothing to play.
 */
it('plays seeded games to 101 at every table (ADR 038 R1–R26)', function (RoomTable $table): void {
    Config::set('app.key', 'base64:'.base64_encode(str_repeat('d', 32)));

    foreach ([7, 8] as $seed) {
        [$game, $acts] = Domino::playOut($table, DominoDeal::source($table, $seed));

        expect($game->phase)->toBe(RoomDominoPhase::Over)
            ->and($game->winner)->not->toBeNull()
            ->and($game->ledger->sides[$game->winner ?? 0]['balance'])->toBeGreaterThanOrEqual(101)
            ->and($game->ply)->toBe(count($acts))
            ->and($game->overMs)->toBe($acts[count($acts) - 1]->actedMs);

        foreach ($game->hands as $hand) {
            $held = array_sum(array_map(count(...), $hand->tiles));

            expect($held + count($hand->boneyard) + count($hand->line))->toBe(28)
                ->and($hand->over())->toBeTrue();

            if ($hand->ending === RoomHandEnding::Finished) {
                $winner = $table->sideOf($hand->by ?? 0);
                $others = array_sum(array_filter($hand->count['left'] ?? [], static fn (int $seat): bool => $table->sideOf($seat) !== $winner, ARRAY_FILTER_USE_KEY));

                expect($hand->count['gain'] ?? null)->toBe($others);
            }

            if (in_array($hand->ending, [RoomHandEnding::Blocked, RoomHandEnding::Tied], true)) {
                expect($hand->drawable())->toBe(0);

                foreach ($hand->tiles as $tiles) {
                    expect(DominoRules::fits($tiles, $hand->ends()))->toBeFalse();
                }
            }
        }
    }
})->with(RoomTable::cases());
