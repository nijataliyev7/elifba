<?php

use App\Domain\Rooms\Enums\RoomHandEnding;
use App\Domain\Rooms\Enums\RoomOpenerWhy;
use App\Domain\Rooms\Enums\RoomTable;
use App\Domain\Rooms\Support\Domino\Domino101;
use App\Domain\Rooms\Support\Domino\DominoDeal;
use App\Domain\Rooms\Support\Domino\DominoHand;
use App\Domain\Rooms\Support\Domino\DominoRules;
use App\Domain\Rooms\Support\Domino\DominoTile;
use Tests\Support\Domino;

/*
 * The judge of a hand of «101» (docs/architecture.md §12 "Domino", ADR
 * 038 decisions 18 and 33–34): hand-written positions for who opens and
 * with what, a play, what follows it by itself — drawing, passing, the
 * boneyard shut for a double —, how a hand ends and how it counts. Each
 * case cites its rule; a reading of decision 34 says so.
 */

/** Two seats: seat 0 holds `1-1`; seat 1 holds no 1. */
function dominoNoOnes(array $boneyard = []): DominoDeal
{
    return Domino::deal(RoomTable::Two, [
        ['1-1', '6-5', '6-4', '5-4', '6-3', '5-3', '4-3'],
        ['0-0', '2-0', '3-0', '2-2', '3-2', '4-2', '6-6'],
    ], $boneyard);
}

/** Two seats: seat 0 can play its seven tiles in a row, seat 1 answering each, ending with `$last` in seat 1's hand. */
function dominoChain(string $last = '5-5'): DominoDeal
{
    return Domino::deal(RoomTable::Two, [
        ['1-1', '3-2', '5-4', '6-0', '4-2', '6-6', '3-0'],
        ['2-1', '4-3', '6-5', '2-0', '6-4', '6-3', $last],
    ]);
}

/** @var list<string> the chain's thirteen plays: seat 0 plays its last tile with the thirteenth */
const DOMINO_CHAIN = ['1-1', '2-1 left', '3-2 left', '4-3 left', '5-4 left', '6-5 left', '6-0 left', '2-0 left', '4-2 left', '6-4 left', '6-6 left', '6-3 left', '3-0 left'];

/** Two seats: ten plays lay every tile that pairs 3 with another number, both ends 3. */
function dominoShut(): DominoDeal
{
    return Domino::deal(RoomTable::Two, [
        ['2-2', '4-2', '4-3', '5-3', '6-5', '6-6', '5-5'],
        ['3-2', '3-0', '1-0', '3-1', '6-3', '3-3', '4-4'],
    ]);
}

/** @var list<string> */
const DOMINO_SHUT = ['2-2', '3-2 left', '4-2 right', '3-0 left', '4-3 right', '1-0 left', '5-3 right', '3-1 left', '6-5 right', '6-3 right'];

/** Four seats: seat 0 holds `1-1`, seat 1 no 1, seats 2 and 3 the rest of the ones. */
function dominoFour(RoomTable $table = RoomTable::Four): DominoDeal
{
    return Domino::deal($table, [
        ['1-1', '6-5', '6-4', '5-4', '6-3', '5-3', '4-3'],
        ['0-0', '2-0', '3-0', '2-2', '3-2', '4-2', '6-6'],
        ['2-1', '3-1', '4-1', '5-1', '3-3', '4-4', '6-2'],
        ['4-0', '5-0', '6-0', '5-5', '5-2', '1-0', '6-1'],
    ]);
}

/** Pairs: seat 0 opens with `1-1`; its opponents, seats 1 and 3, hold no 1. */
function dominoNoReply(RoomTable $table = RoomTable::Pairs): DominoDeal
{
    return Domino::deal($table, [
        ['1-1', '1-0', '2-1', '6-5', '6-4', '5-4', '6-3'],
        ['0-0', '2-0', '3-0', '2-2', '3-2', '4-2', '6-6'],
        ['3-1', '4-1', '5-1', '6-1', '3-3', '4-4', '6-2'],
        ['4-0', '5-0', '6-0', '5-5', '5-2', '5-3', '4-3'],
    ]);
}

/** Pairs: seat 2 opens with `1-1` and plays its last tile with the nineteenth play. */
function dominoPairsFinished(): DominoHand
{
    return Domino::play(Domino::hand(Domino::deal(RoomTable::Pairs, [
        ['5-0', '0-0', '4-1', '4-0', '5-3', '4-3', '6-0'],
        ['6-3', '6-6', '3-1', '1-0', '3-0', '6-2', '2-0'],
        ['4-2', '5-1', '1-1', '4-4', '6-5', '2-1', '6-1'],
        ['3-3', '3-2', '5-5', '5-2', '5-4', '6-4', '2-2'],
    ])), '1-1', '4-1 left', '3-1 right', '4-4 left', '3-3 right', '4-3 right', '4-2 left', '3-2 left', '4-0 right', '1-0 right', '2-1 right', '2-2 right', '5-3 left', '2-0 right', '5-1 left', '5-0 right', '6-5 right', '6-4 right', '6-1 left');
}

/** Pairs, blocked: side 0 wins 53 to 59, while seat 3 of side 1 is left with the fewest pips. */
function dominoPairsBlocked(): DominoHand
{
    return Domino::play(Domino::hand(Domino::deal(RoomTable::Pairs, [
        ['6-3', '3-1', '6-2', '6-4', '2-0', '1-0', '4-0'],
        ['5-3', '5-4', '6-6', '3-0', '3-3', '0-0', '6-5'],
        ['4-3', '2-2', '4-4', '3-2', '4-1', '2-1', '6-0'],
        ['4-2', '5-1', '1-1', '5-0', '5-2', '5-5', '6-1'],
    ])), '1-1', '1-0 left', '3-0 left', '2-1 right', '4-2 right', '3-1 left', '5-4 right', '4-1 left', '5-1 right', '4-0 left', '0-0 left', '6-0 left', '6-1 left');
}

/** Pairs, blocked: side 1 wins 29 to 39; seats 0 and 3, one of each side, share the fewest pips. */
function dominoPairsFewestAcross(): DominoHand
{
    return Domino::play(Domino::hand(Domino::deal(RoomTable::Pairs, [
        ['3-3', '6-2', '3-1', '6-5', '3-0', '1-1', '6-3'],
        ['6-6', '5-2', '3-2', '6-0', '2-1', '5-1', '4-0'],
        ['4-4', '4-1', '4-2', '5-3', '6-1', '5-5', '2-2'],
        ['5-0', '2-0', '1-0', '4-3', '6-4', '0-0', '5-4'],
    ])), '1-1', '2-1 left', '4-2 left', '6-4 left', '6-3 left', '3-2 left', '6-1 right', '2-0 left', '6-2 right', '6-0 left', '2-2 right', '6-5 left', '5-1 left', '4-1 left', '5-4 left', '5-2 left');
}

/** Pairs, blocked by seat 2's play: side 0 wins 28 to 56; seats 0 and 2, partners, share the fewest pips. */
function dominoPairsFewestWithin(): DominoHand
{
    return Domino::play(Domino::hand(Domino::deal(RoomTable::Pairs, [
        ['3-1', '3-0', '1-1', '3-3', '2-2', '6-5', '0-0'],
        ['5-5', '3-2', '2-1', '4-0', '5-4', '6-2', '2-0'],
        ['5-1', '5-0', '1-0', '6-0', '4-4', '5-2', '4-2'],
        ['4-3', '5-3', '4-1', '6-3', '6-4', '6-6', '6-1'],
    ])), '1-1', '2-1 left', '5-2 left', '5-3 left', '3-0 left', '2-0 left', '5-1 right', '6-5 right', '3-2 left', '6-0 right', '4-3 left', '0-0 right', '4-0 left', '1-0 left', '4-1 left', '5-4 left', '5-0 left');
}

/** Three seats, blocked with the boneyard shut for `5-5`: seats 0 and 2 share the lowest count, 9. */
function dominoThreeTied(): DominoHand
{
    return Domino::play(Domino::hand(Domino::deal(RoomTable::Three, [
        ['2-0', '5-4', '5-2', '5-1', '6-0', '5-3', '3-0'],
        ['6-1', '4-4', '4-1', '4-0', '3-1', '6-3', '0-0'],
        ['4-3', '5-0', '6-2', '2-1', '6-6', '2-2', '3-3'],
    ], ['6-4', '4-2', '6-5', '5-5', '3-2', '1-0', '1-1'])), '2-2', '2-0 left', '0-0 left', '5-0 left', '5-2 right', '6-5 left', '6-6 left', '5-4 right', '4-1 right', '6-2 left', '5-1 right', '4-2 left', '4-3 left', '5-3 left', '5-5 left');
}

/** Four seats, blocked by seat 2's play: seat 2 has the lowest count, 18. */
function dominoFourBlocked(): DominoHand
{
    return Domino::play(Domino::hand(Domino::deal(RoomTable::Four, [
        ['3-1', '6-3', '3-3', '6-0', '4-4', '5-0', '6-2'],
        ['0-0', '4-1', '6-6', '5-5', '5-3', '4-0', '2-0'],
        ['2-2', '2-1', '5-1', '3-0', '6-1', '3-2', '6-5'],
        ['5-2', '6-4', '1-0', '1-1', '4-2', '4-3', '5-4'],
    ])), '1-1', '3-1 left', '4-1 right', '3-2 left', '6-4 right', '6-2 right', '2-0 left', '2-1 right', '1-0 left', '6-1 left', '6-3 left', '5-3 left', '5-1 left');
}

/** A hand opened as a later hand of a game with an account open, after `$before`. */
function dominoAfter(DominoHand $before, DominoDeal $deal): DominoHand
{
    return DominoHand::open(new Domino101, $deal, $before->number + 1, $before, true, 0);
}

it('turns round the seats in their order, one tile a turn (ADR 038 R3)', function (): void {
    $hand = Domino::play(Domino::hand(dominoChain()), '1-1', '2-1 left');

    expect($hand->turn)->toBe(0)
        ->and(Domino::play(Domino::hand(dominoFour()), '1-1')->turn)->toBe(2)
        ->and(Domino::play(Domino::hand(dominoFour()), '1-1', '2-1 left')->turn)->toBe(3)
        ->and(Domino::play(Domino::hand(dominoFour()), '1-1', '2-1 left', '5-2 left')->turn)->toBe(0);
});

it('opens with the lowest double of 1-1 … 6-6, and with nothing else (ADR 038 R6)', function (): void {
    $hand = Domino::hand(dominoNoOnes());

    expect($hand->opener)->toBe(0)
        ->and($hand->turn)->toBe(0)
        ->and($hand->why)->toBe(RoomOpenerWhy::LowestDouble)
        ->and(Domino::legal($hand))->toBe(['1-1'])
        ->and($hand->play(DominoTile::of('6-5'), null, 1, false))->toBeNull()
        ->and($hand->mayGive())->toBeFalse();
});

it('does not count 0-0 as the lowest double (ADR 038 R6)', function (): void {
    $hand = Domino::hand(Domino::deal(RoomTable::Two, [
        ['2-2', '6-5', '6-4', '5-4', '6-3', '5-3', '4-3'],
        ['0-0', '3-3', '2-0', '3-0', '3-2', '4-2', '6-6'],
    ], ['1-1']));

    expect($hand->opener)->toBe(0)
        ->and($hand->why)->toBe(RoomOpenerWhy::LowestDouble)
        ->and(Domino::legal($hand))->toBe(['2-2']);
});

it('opens with the lowest tile when no seat holds a double of 1-1 … 6-6, 2-1 before 3-0 (ADR 038 R7, reading)', function (): void {
    $hand = Domino::hand(Domino::deal(RoomTable::Two, [
        ['3-0', '2-1', '6-5', '5-4', '4-3', '6-0', '5-1'],
        ['4-0', '3-2', '6-1', '5-0', '4-2', '6-2', '5-2'],
    ]));

    expect($hand->opener)->toBe(0)
        ->and($hand->why)->toBe(RoomOpenerWhy::LowestTile)
        ->and(Domino::legal($hand))->toBe(['2-1'])
        ->and(Domino101::lower(DominoTile::of('2-1'), DominoTile::of('3-0')))->toBeTrue()
        ->and(Domino101::lower(DominoTile::of('3-0'), DominoTile::of('2-1')))->toBeFalse();
});

it('opens with 0-0 when it is the lowest tile held and no seat holds another double (ADR 038 R7, reading)', function (): void {
    $hand = Domino::hand(Domino::deal(RoomTable::Two, [
        ['2-1', '3-0', '6-5', '5-4', '4-3', '6-0', '5-1'],
        ['0-0', '4-0', '3-2', '6-1', '5-0', '4-2', '6-2'],
    ]));

    expect($hand->opener)->toBe(1)
        ->and($hand->why)->toBe(RoomOpenerWhy::LowestTile)
        ->and(Domino::legal($hand))->toBe(['0-0']);
});

it('gives the right to open after a finished hand to its finisher, with any tile (ADR 038 R8 a)', function (): void {
    $before = dominoPairsFinished();
    $hand = dominoAfter($before, dominoFour(RoomTable::Pairs));

    expect((new Domino101)->next($before, true))->toBe([2, RoomOpenerWhy::Finished])
        ->and($hand->opener)->toBe(2)
        ->and($hand->turn)->toBe(2)
        ->and($hand->why)->toBe(RoomOpenerWhy::Finished)
        ->and(Domino::legal($hand))->toBe(['2-1', '3-1', '4-1', '5-1', '3-3', '4-4', '6-2']);
});

it('gives the right to open after a blocked hand to the seat with the fewest pips, whichever its side (ADR 038 R8 b)', function (): void {
    $before = dominoPairsBlocked();

    expect($before->ending)->toBe(RoomHandEnding::Blocked)
        ->and($before->count['winner'] ?? null)->toBe(0)
        ->and($before->count['left'] ?? null)->toBe([29, 37, 24, 22])
        ->and((new Domino101)->next($before, true))->toBe([3, RoomOpenerWhy::Fewest]);
});

it('gives the right among seats with the same fewest pips to the winning side first (ADR 038 R8, reading)', function (): void {
    $before = dominoPairsFewestAcross();

    expect($before->count['left'] ?? null)->toBe([13, 16, 26, 13])
        ->and($before->count['winner'] ?? null)->toBe(1)
        ->and((new Domino101)->next($before, true))->toBe([3, RoomOpenerWhy::Fewest]);
});

it('gives the right among partners with the same fewest pips to the first in turn from the last play (ADR 038 R8, reading)', function (): void {
    $before = dominoPairsFewestWithin();

    // ADR 038 silent: "from the seat that made the last play" counts that
    // seat first — seat 2 here, where the next in turn would be seat 0.
    expect($before->by)->toBe(2)
        ->and($before->count['left'] ?? null)->toBe([14, 18, 14, 38])
        ->and($before->count['winner'] ?? null)->toBe(0)
        ->and((new Domino101)->next($before, true))->toBe([2, RoomOpenerWhy::Fewest]);
});

it('gives the right to open after a tied hand to the seat that made its last play (ADR 038 R8 c)', function (): void {
    $before = dominoThreeTied();

    expect($before->ending)->toBe(RoomHandEnding::Tied)
        ->and($before->by)->toBe(1)
        ->and((new Domino101)->next($before, true))->toBe([1, RoomOpenerWhy::Blocker]);
});

it('gives the right to open after a void hand to the same seat again (ADR 038 R8 d)', function (): void {
    $opened = dominoAfter(dominoPairsFinished(), Domino::deal(RoomTable::Pairs, [
        ['3-1', '1-0', '2-1', '6-5', '6-4', '5-4', '6-3'],
        ['0-0', '2-0', '3-0', '2-2', '3-2', '4-2', '6-6'],
        ['1-1', '4-1', '5-1', '6-1', '3-3', '4-4', '6-2'],
        ['4-0', '5-0', '6-0', '5-5', '5-2', '5-3', '4-3'],
    ]));
    $void = Domino::play($opened, '1-1');

    expect($opened->entitled)->toBe(2)
        ->and($void->ending)->toBe(RoomHandEnding::Void)
        ->and((new Domino101)->next($void, true))->toBe([2, RoomOpenerWhy::Again]);
});

it('leaves the opener to the next deal while no account is open (ADR 038 R6, R8)', function (): void {
    $before = dominoPairsFinished();
    $hand = DominoHand::open(new Domino101, dominoFour(RoomTable::Pairs), 2, $before, false, 0);

    expect((new Domino101)->next($before, false))->toBe([null, RoomOpenerWhy::LowestDouble])
        ->and($hand->opener)->toBe(0)
        ->and($hand->why)->toBe(RoomOpenerWhy::LowestDouble)
        ->and(Domino::legal($hand))->toBe(['1-1']);
});

it('lets the seat with the right give the opening to its partner at pairs, once (ADR 038 R9)', function (): void {
    $hand = dominoAfter(dominoPairsFinished(), dominoFour(RoomTable::Pairs));
    $given = $hand->give(5);

    expect($hand->mayGive())->toBeTrue()
        ->and($given)->not->toBeNull()
        ->and($given?->turn)->toBe(0)
        ->and($given?->opener)->toBe(0)
        ->and($given?->entitled)->toBe(2)
        ->and($given?->why)->toBe(RoomOpenerWhy::Given)
        ->and($given?->sinceMs)->toBe(5)
        ->and($given?->last)->toBe(['seat' => 2, 'kind' => 'yield', 'tile' => null, 'end' => null, 'stoodIn' => false, 'then' => []])
        ->and($given === null ? [] : Domino::legal($given))->toBe(['1-1', '6-5', '6-4', '5-4', '6-3', '5-3', '4-3'])
        ->and($given?->mayGive())->toBeFalse()
        ->and($given?->give(6))->toBeNull();
});

it('lets nobody give under R6 or R7, nor away from pairs (ADR 038 R9)', function (): void {
    $lowest = Domino::hand(dominoFour(RoomTable::Pairs));
    $four = dominoAfter(dominoFourBlocked(), dominoFour());

    expect($lowest->mayGive())->toBeFalse()
        ->and($lowest->give(1))->toBeNull()
        ->and($four->why)->toBe(RoomOpenerWhy::Fewest)
        ->and($four->mayGive())->toBeFalse()
        ->and($four->give(1))->toBeNull();
});

it('puts a tile at the end it matches, the other number open there (ADR 038 R10)', function (): void {
    $hand = Domino::play(Domino::hand(dominoChain()), '1-1');

    // Both ends show 1: the judge lists the left alone (decision 38).
    expect(Domino::legal($hand))->toBe(['2-1 left'])
        ->and($hand->ends())->toBe(['left' => 1, 'right' => 1])
        ->and($hand->play(DominoTile::of('2-1'), DominoTile::RIGHT, 2, false))->toBeNull();

    $hand = Domino::play($hand, '2-1 left', '3-2 left');

    expect(Domino::line($hand))->toBe(['3|2', '2|1', '1|1'])
        ->and($hand->first)->toBe(2)
        ->and($hand->ends())->toBe(['left' => 3, 'right' => 1])
        ->and(Domino::legal($hand))->toBe(['4-3 left', '6-3 left']);
});

it('lists both ends for a tile that fits both, and lays it as it fits (ADR 038 R10)', function (): void {
    $hand = Domino::play(Domino::hand(dominoShut()), ...array_slice(DOMINO_SHUT, 0, 9));

    expect($hand->ends())->toBe(['left' => 3, 'right' => 6])
        ->and(Domino::legal($hand))->toBe(['6-3 left', '6-3 right', '3-3 left']);

    $right = Domino::play($hand, '6-3 right');
    $left = Domino::play($hand, '6-3 left');

    expect(array_slice(Domino::line($right), -1))->toBe(['6|3'])
        ->and($right->ends())->toBe(['left' => 3, 'right' => 3])
        ->and(Domino::line($left)[0])->toBe('6|3')
        ->and($left->ends())->toBe(['left' => 6, 'right' => 6]);
});

it('plays a double as one tile like any other, scoring nothing (ADR 038 R10)', function (): void {
    $hand = Domino::play(Domino::hand(dominoChain()), ...array_slice(DOMINO_CHAIN, 0, 10));

    expect($hand->ends())->toBe(['left' => 6, 'right' => 1]);

    $hand = Domino::play($hand, '6-6 left');

    expect(Domino::line($hand)[0])->toBe('6|6')
        ->and($hand->ends())->toBe(['left' => 6, 'right' => 1])
        ->and($hand->turn)->toBe(1)
        ->and($hand->over())->toBeFalse();
});

it('makes a seat that holds a fitting tile play, never draw (ADR 038 R11)', function (): void {
    $hand = Domino::play(Domino::hand(dominoChain()), '1-1');

    expect($hand->turn)->toBe(1)
        ->and($hand->boneyard)->toHaveCount(14)
        ->and($hand->last['then'] ?? null)->toBe([])
        ->and($hand->play(DominoTile::of('5-5'), DominoTile::LEFT, 2, false))->toBeNull()
        ->and($hand->play(DominoTile::of('3-2'), DominoTile::LEFT, 2, false))->toBeNull();
});

it('draws two at a time until a tile fits (ADR 038 R12)', function (array $boneyard, int $drew): void {
    $hand = Domino::play(Domino::hand(dominoNoOnes($boneyard)), '1-1');

    expect($hand->turn)->toBe(1)
        ->and($hand->last['then'] ?? null)->toBe([['seat' => 1, 'drew' => $drew, 'passed' => false]])
        ->and(DominoTile::names($hand->drawn[1]))->toBe(array_slice($boneyard, 0, $drew))
        ->and(DominoTile::names(array_slice($hand->tiles[1], 7)))->toBe(array_slice($boneyard, 0, $drew))
        ->and($hand->boneyard)->toHaveCount(14 - $drew)
        ->and($hand->events)->toBe([
            ['seat' => 0, 'kind' => 'play', 'tile' => DominoTile::of('1-1'), 'end' => null, 'count' => 1],
            ['seat' => 1, 'kind' => 'draw', 'tile' => null, 'end' => null, 'count' => $drew],
        ]);
})->with([
    'the second fits' => [['4-0', '2-1'], 2],
    'the first fits, and both are drawn' => [['2-1', '4-0'], 2],
    'the fourth fits' => [['4-0', '5-0', '6-0', '3-1'], 4],
]);

it('never draws the last two tiles, and draws one when three are left (ADR 038 R12, reading)', function (): void {
    $hand = Domino::play(Domino::hand(Domino::deal(RoomTable::Three, [
        ['1-1', '6-5', '6-4', '5-4', '6-3', '5-3', '4-3'],
        ['0-0', '2-0', '3-0', '2-2', '3-2', '4-2', '6-6'],
        ['2-1', '3-1', '4-1', '5-1', '3-3', '4-4', '6-2'],
    ], ['4-0', '5-0', '6-0', '5-5', '5-2', '1-0', '6-1'])), '1-1');

    expect($hand->last['then'] ?? null)->toBe([['seat' => 1, 'drew' => 5, 'passed' => true]])
        ->and($hand->tiles[1])->toHaveCount(12)
        ->and(DominoTile::names($hand->boneyard))->toBe(['1-0', '6-1'])
        ->and($hand->drawable())->toBe(0)
        ->and($hand->turn)->toBe(2);
});

it('shuts the boneyard while only a double could fit an end, and the double is still played (ADR 038 R13, reading)', function (): void {
    $before = Domino::play(Domino::hand(dominoShut()), ...array_slice(DOMINO_SHUT, 0, 9));
    $hand = Domino::play($before, DOMINO_SHUT[9]);

    expect($before->closed())->toBeFalse()
        ->and($hand->ends())->toBe(['left' => 3, 'right' => 3])
        ->and($hand->closed())->toBeTrue()
        ->and($hand->drawable())->toBe(0)
        ->and($hand->boneyard)->toHaveCount(14)
        ->and($hand->last['then'] ?? null)->toBe([['seat' => 0, 'drew' => 0, 'passed' => true]])
        ->and($hand->turn)->toBe(1)
        ->and(Domino::legal($hand))->toBe(['3-3 left']);
});

it('makes a seat that cannot play or draw pass, at a table without a boneyard (ADR 038 R14)', function (): void {
    $hand = Domino::play(Domino::hand(dominoFour()), '1-1');

    expect($hand->last['then'] ?? null)->toBe([['seat' => 1, 'drew' => 0, 'passed' => true]])
        ->and($hand->turn)->toBe(2)
        ->and($hand->events[1])->toBe(['seat' => 1, 'kind' => 'pass', 'tile' => null, 'end' => null, 'count' => 0]);
});

it('ends a hand when a seat plays its last tile: finished (ADR 038 R15, R17)', function (): void {
    $hand = Domino::play(Domino::hand(dominoChain()), ...DOMINO_CHAIN);

    expect($hand->over())->toBeTrue()
        ->and($hand->ending)->toBe(RoomHandEnding::Finished)
        ->and($hand->by)->toBe(0)
        ->and($hand->turn)->toBeNull()
        ->and($hand->endedMs)->toBe(13)
        ->and($hand->tiles[0])->toBe([])
        ->and($hand->count)->toBe(['winner' => 0, 'tied' => false, 'left' => [0, 10], 'counts' => [0, 10], 'gain' => 10, 'bank' => 0])
        ->and($hand->play(DominoTile::of('5-5'), DominoTile::LEFT, 14, false))->toBeNull();
});

it('counts a hand that holds 0-0 alone as 10, and 0-0 among others as nothing (ADR 038 R16)', function (): void {
    $hand = Domino::play(Domino::hand(dominoChain('0-0')), ...DOMINO_CHAIN);
    $rules = new Domino101;

    expect($hand->count['left'] ?? null)->toBe([0, 10])
        ->and($hand->count['gain'] ?? null)->toBe(10)
        ->and($rules->pips(DominoTile::list(['0-0'])))->toBe(10)
        ->and($rules->pips(DominoTile::list(['0-0', '1-0'])))->toBe(1)
        ->and($rules->pips(DominoTile::list(['6-6', '5-4'])))->toBe(21)
        ->and($rules->pips([]))->toBe(0);
});

it('gives the finisher\'s side the pips of every seat not of its side, a partner\'s not counted (ADR 038 R17)', function (): void {
    $hand = dominoPairsFinished();

    expect($hand->ending)->toBe(RoomHandEnding::Finished)
        ->and($hand->by)->toBe(2)
        ->and(array_map(DominoTile::names(...), $hand->tiles))->toBe([['0-0', '6-0'], ['6-3', '6-6', '3-0', '6-2'], [], ['5-5', '5-2', '5-4']])
        ->and($hand->count)->toBe(['winner' => 0, 'tied' => false, 'left' => [6, 32, 0, 26], 'counts' => [6, 58], 'gain' => 58, 'bank' => 0]);
});

it('gives a blocked hand to the side with the lowest count, which gains the others\' counts (ADR 038 R15, R18)', function (DominoHand $hand, int $by, array $left, array $counts, int $winner, int $gain): void {
    expect($hand->ending)->toBe(RoomHandEnding::Blocked)
        ->and($hand->turn)->toBeNull()
        ->and($hand->by)->toBe($by)
        ->and($hand->count)->toBe(['winner' => $winner, 'tied' => false, 'left' => $left, 'counts' => $counts, 'gain' => $gain, 'bank' => 0]);
})->with([
    'two seats, the boneyard counting for nobody' => [fn (): DominoHand => Domino::play(Domino::hand(dominoShut()), ...DOMINO_SHUT, ...['3-3 left']), 1, [22, 8], [22, 8], 1, 22],
    'four seats' => [fn (): DominoHand => dominoFourBlocked(), 2, [25, 26, 18, 29], [25, 26, 18, 29], 2, 80],
    'pairs' => [fn (): DominoHand => dominoPairsBlocked(), 3, [29, 37, 24, 22], [53, 59], 0, 59],
]);

it('sends every seat\'s pips to the bank when sides share the lowest count, at three sides too (ADR 038 R19, reading)', function (): void {
    $hand = dominoThreeTied();

    expect($hand->ending)->toBe(RoomHandEnding::Tied)
        ->and($hand->by)->toBe(1)
        ->and($hand->closed())->toBeTrue()
        ->and($hand->boneyard)->toHaveCount(3)
        ->and(array_map(static fn (array $then): bool => $then['passed'], $hand->last['then'] ?? []))->toBe([true, true, true])
        ->and($hand->count)->toBe(['winner' => null, 'tied' => true, 'left' => [9, 42, 9], 'counts' => [9, 42, 9], 'gain' => 0, 'bank' => 60]);
});

it('makes a hand void at pairs when neither opponent can answer the first tile (ADR 038 R5, reading)', function (): void {
    $void = Domino::play(Domino::hand(dominoNoReply()), '1-1');
    $four = Domino::play(Domino::hand(dominoNoReply(RoomTable::Four)), '1-1');

    expect($void->ending)->toBe(RoomHandEnding::Void)
        ->and($void->by)->toBe(0)
        ->and($void->turn)->toBeNull()
        ->and(Domino::line($void))->toBe(['1|1'])
        ->and($void->count)->toMatchArray(['winner' => null, 'tied' => false, 'gain' => 0, 'bank' => 0])
        ->and($four->ending)->toBeNull()
        ->and($four->turn)->toBe(2);
});

it('keeps the hand when one opponent can answer the first tile (ADR 038 R5)', function (): void {
    $hand = Domino::play(Domino::hand(dominoFour(RoomTable::Pairs)), '1-1');

    expect($hand->ending)->toBeNull()
        ->and($hand->turn)->toBe(2);
});

it('makes the opening tile of the partner a void hand too when the opponents cannot answer it (ADR 038 R5, R9)', function (): void {
    $opened = dominoAfter(dominoPairsFinished(), Domino::deal(RoomTable::Pairs, [
        ['1-1', '4-1', '5-1', '6-1', '3-3', '4-4', '6-2'],
        ['0-0', '2-0', '3-0', '2-2', '3-2', '4-2', '6-6'],
        ['3-1', '1-0', '2-1', '6-5', '6-4', '5-4', '6-3'],
        ['4-0', '5-0', '6-0', '5-5', '5-2', '5-3', '4-3'],
    ]));
    // ADR 038 silent: after «Sən aç», "the same seat again" is the seat
    // that had the right — seat 2 — and not its partner who opened.
    $void = Domino::play($opened, 'give', '1-1');

    expect($void->ending)->toBe(RoomHandEnding::Void)
        ->and($void->by)->toBe(0)
        ->and($void->entitled)->toBe(2)
        ->and((new Domino101)->next($void, true))->toBe([2, RoomOpenerWhy::Again]);
});

it('lists the ends a tile fits, the left alone where both show one number (ADR 038 R10, decision 38)', function (): void {
    expect(DominoRules::endsFor(DominoTile::of('3-1'), ['left' => 3, 'right' => 1]))->toBe(['left', 'right'])
        ->and(DominoRules::endsFor(DominoTile::of('3-1'), ['left' => 3, 'right' => 3]))->toBe(['left'])
        ->and(DominoRules::endsFor(DominoTile::of('3-3'), ['left' => 3, 'right' => 3]))->toBe(['left'])
        ->and(DominoRules::endsFor(DominoTile::of('5-4'), ['left' => 3, 'right' => 1]))->toBe([])
        ->and(DominoRules::endsFor(DominoTile::of('6-1'), ['left' => 3, 'right' => 1]))->toBe(['right']);
});
