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
    return DominoHand::open(new Domino101, $deal, $before->number + 1, (new Domino101)->next($before, true), 0);
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

it('gives the right among partners with the same fewest pips to the first in turn from the last play, that seat itself first (ADR 038 R8, reading; decision 63)', function (): void {
    $before = dominoPairsFewestWithin();

    // "From the seat that made the last play" counts that seat first —
    // seat 2 here, where the next in turn would be seat 0.
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
    $hand = DominoHand::open(new Domino101, dominoFour(RoomTable::Pairs), 2, (new Domino101)->next($before, false), 0);

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

it('makes the opening tile of the partner a void hand too when the opponents cannot answer it (ADR 038 R5, R9; decision 64)', function (): void {
    $opened = dominoAfter(dominoPairsFinished(), Domino::deal(RoomTable::Pairs, [
        ['1-1', '4-1', '5-1', '6-1', '3-3', '4-4', '6-2'],
        ['0-0', '2-0', '3-0', '2-2', '3-2', '4-2', '6-6'],
        ['3-1', '1-0', '2-1', '6-5', '6-4', '5-4', '6-3'],
        ['4-0', '5-0', '6-0', '5-5', '5-2', '5-3', '4-3'],
    ]));
    // After «Sən aç», "the same seat again" is the seat that had the right
    // — seat 2 — and not its partner who opened.
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

/*
 * The oracle's fixtures (ADR 038 decisions 58–59; their format is
 * `scripts/domino-oracle/README.md`'s): `cases.json`, a case or more for
 * each of R1–R26 and one marked `reading` for each row of decision 34,
 * in named groups; `hands.json`, hands at every table played out play by
 * play; `study.json`, the study's 18 replayed hands move by move. Legal
 * plays compare as sets, and every failure cites the case's rule.
 */

/** @var array<string, list<string>> the named groups of `cases.json`, each with the rules it holds */
const DOMINO_CASE_GROUPS = [
    'the tiles, the tables and the deal (ADR 038 R1–R4)' => ['R1', 'R2', 'R3', 'R4'],
    'no reply, and who opens (ADR 038 R5–R9)' => ['R5', 'R6', 'R7', 'R8', 'R9'],
    'a play (ADR 038 R10–R11)' => ['R10', 'R11'],
    'the boneyard (ADR 038 R12–R13)' => ['R12', 'R13'],
    'a pass, and the end of a hand (ADR 038 R14–R15)' => ['R14', 'R15'],
    'the count, and a finished hand (ADR 038 R16–R17)' => ['R16', 'R17'],
    'the blocked hand (ADR 038 R18)' => ['R18'],
    'the tie and the bank (ADR 038 R19, R24)' => ['R19', 'R24'],
    'the account (ADR 038 R20)' => ['R20'],
    'the 13 rule (ADR 038 R21)' => ['R21'],
    'waiting (ADR 038 R22)' => ['R22'],
    'burning (ADR 038 R22–R23)' => ['R22', 'R23'],
    'the end, and a dry win (ADR 038 R25–R26)' => ['R25', 'R26'],
];

/**
 * The cases of `cases.json` whose rule is one of `$rules`, each by its
 * index there, named by its rule and name: the test loads its entry, so
 * the run does not hold the fixture.
 *
 * @param  list<string>  $rules
 * @return array<string, array{0: int}>
 */
function dominoFixtureCases(array $rules): array
{
    $cases = [];

    foreach (Domino::fixture('cases')['cases'] as $index => $case) {
        if (in_array(substr($case['rule'], strlen('ADR 038 ')), $rules, true)) {
            $cases["{$case['rule']}: {$case['name']}"] = [$index];
        }
    }

    return $cases;
}

/**
 * Check one case of `cases.json` against the judge and the account.
 *
 * @param  array<string, mixed>  $case
 */
function dominoFixtureCase(array $case): void
{
    $cite = "{$case['rule']}: {$case['name']}";

    match ($case['kind']) {
        'tiles' => dominoFixtureTiles($case, $cite),
        'tables' => dominoFixtureTables($case, $cite),
        'deal' => dominoFixtureDeal($case, $cite),
        'deals' => dominoFixtureTries($case, $cite),
        'hand' => dominoFixtureHand($case, $cite),
        'ledger' => dominoFixtureLedger($case, $cite),
    };
}

/** @param  array<string, mixed>  $case */
function dominoFixtureTiles(array $case, string $cite): void
{
    $tiles = array_values(DominoTile::all());
    $pips = array_map(static fn (DominoTile $tile): int => $tile->pips(), $tiles);

    expect(DominoTile::names($tiles))->toBe($case['tiles'], $cite)
        ->and($pips)->toBe($case['pips'], $cite)
        ->and(DominoTile::names(array_values(array_filter($tiles, static fn (DominoTile $tile): bool => $tile->isDouble()))))->toBe($case['doubles'], $cite)
        ->and(array_sum($pips))->toBe($case['total'], $cite);
}

/** @param  array<string, mixed>  $case */
function dominoFixtureTables(array $case, string $cite): void
{
    foreach ($case['tables'] as $expected) {
        $table = RoomTable::from($expected['table']);

        expect([
            'table' => $table->value,
            'seats' => $table->seats(),
            'hand' => RoomTable::HAND,
            'boneyard' => $table->boneyard(),
            'sides' => array_map($table->seatsOf(...), range(0, $table->sides() - 1)),
        ])->toBe($expected, "{$cite} · {$table->value}");
    }
}

/** @param  array<string, mixed>  $case */
function dominoFixtureDeal(array $case, string $cite): void
{
    $deal = Domino::dealOf(RoomTable::from($case['table']), $case['deal']);

    expect(['verdict' => $deal->thrown() ? 'thrown' : 'stands', 'why' => Domino::reasonsSet($deal->reasons())])
        ->toBe(['verdict' => $case['verdict'], 'why' => Domino::reasonsSet($case['why'])], $cite);
}

/** @param  array<string, mixed>  $case */
function dominoFixtureTries(array $case, string $cite): void
{
    $table = RoomTable::from($case['table']);
    $tries = array_map(static fn (array $deal): DominoDeal => Domino::dealOf($table, $deal), $case['tries']);
    $game = Domino::game($table, Domino::source([1 => $tries]), [], Domino::STARTS);

    foreach (array_slice($tries, 0, $case['used'] + 1) as $try => $deal) {
        expect($deal->thrown())->toBe($try < $case['used'], "{$cite} · try {$try}");
    }

    expect(Domino::dealShape($game->hand()?->deal ?? throw new RuntimeException('No hand dealt.')))->toBe($case['tries'][$case['used']], $cite);
}

/** @param  array<string, mixed>  $case */
function dominoFixtureHand(array $case, string $cite): void
{
    $hand = Domino::opened($case);
    $states = [$hand];

    foreach ($case['acts'] as $index => $act) {
        $hand = Domino::act($hand, $act, $index + 1);

        expect($hand)->not->toBeNull("{$cite} · act ".($index + 1).' '.json_encode($act).' is not taken');

        $states[] = $hand;
    }

    foreach ($case['checks'] as $check) {
        $after = $check['after'];
        unset($check['after']);

        if (isset($check['legal'])) {
            $check['legal'] = Domino::asSet($check['legal']);
        }

        $actual = Domino::only(Domino::checked($states[$after], $case['open']), $check);

        if (isset($check['end'], $actual['end'])) {
            $actual['end'] = Domino::only($actual['end'], $check['end']);
        }

        expect(Domino::canon($actual))->toBe(Domino::canon($check), "{$cite} · after {$after} acts");
    }
}

/** @param  array<string, mixed>  $case */
function dominoFixtureLedger(array $case, string $cite): void
{
    $ledger = (new Domino101)->ledger($case['sides']);

    foreach ($case['results'] as $index => $result) {
        $ledger = Domino::fold($ledger, $result);
        $expected = $case['after'][$index];

        expect(Domino::canon(Domino::only(Domino::account($ledger), $expected)))->toBe(Domino::canon($expected), "{$cite} · after result ".($index + 1));
    }
}

/**
 * The hands of `hands.json`, each by its index there, named by its id and
 * what it shows: the test loads its entry, so the run does not hold the
 * fixture.
 *
 * @return array<string, array{0: int}>
 */
function dominoFixtureHands(): array
{
    $hands = [];

    foreach (Domino::fixture('hands')['hands'] as $index => $entry) {
        $hands["{$entry['id']} (".implode(', ', $entry['shows']).')'] = [$index];
    }

    return $hands;
}

/**
 * The study's hands, each by its game's index in `study.json` and its own
 * in that game — the test loads the game's hands before it.
 *
 * @return array<string, array{0: int, 1: int}>
 */
function dominoFixtureStudyHands(): array
{
    $hands = [];

    foreach (Domino::fixture('study')['games'] as $game => $entry) {
        foreach ($entry['hands'] as $index => $hand) {
            $hands[$hand['id']] = [$game, $index];
        }
    }

    return $hands;
}

/**
 * Replay a study hand's rows through the judge: each row's seat is the
 * seat to move, each play is taken — at the end that shows the number it
 * covers, the left where both do —, and each `pass` row is the next pass
 * the judge made by itself; the passes that end a blocked hand may be left
 * unwritten, as the app leaves them.
 *
 * @param  array<string, mixed>  $entry
 * @param  array{0: int, 1: RoomOpenerWhy}|null  $right
 */
function dominoFixtureStudyHand(array $entry, int $number, ?array $right): DominoHand
{
    $hand = DominoHand::open(new Domino101, Domino::dealOf(RoomTable::Pairs, $entry['deal']), $number, $right, 0);
    $passes = [];

    foreach ($entry['rows'] as $index => $row) {
        $cite = "study {$entry['id']} · row ".($index + 1).' '.json_encode($row);

        if ($row[1] === 'pass') {
            expect(array_shift($passes))->toBe($row[0], "{$cite}: the judge's own pass");

            continue;
        }

        expect($passes)->toBe([], "{$cite}: a pass the judge made is not written")
            ->and($hand->turn)->toBe($row[0], "{$cite}: the seat to move");

        if ($row[1] === 'yield') {
            $next = $hand->give($index + 1);
        } else {
            $ends = $hand->ends();
            $end = match (true) {
                ! isset($row[3]) => null,
                ($ends[DominoTile::LEFT] ?? null) === $row[3] => DominoTile::LEFT,
                ($ends[DominoTile::RIGHT] ?? null) === $row[3] => DominoTile::RIGHT,
                default => throw new RuntimeException("{$cite}: no end shows {$row[3]}."),
            };
            $next = $hand->play(DominoTile::of($row[2]), $end, $index + 1, false);
        }

        expect($next)->not->toBeNull("{$cite}: taken");

        $hand = $next;
        $passes = array_column(array_filter($hand->last['then'] ?? [], static fn (array $step): bool => $step['passed']), 'seat');
    }

    expect($hand->over())->toBeTrue("study {$entry['id']}: over");

    return $hand;
}

it('names every rule R1–R26 in a case, every row of decision 34 in a reading, and puts every case in a named group (ADR 038 decisions 33, 34, 59)', function (): void {
    $cases = Domino::fixture('cases')['cases'];
    $rules = array_unique(array_map(static fn (array $case): string => $case['rule'], $cases));
    $readings = array_unique(array_filter(array_map(static fn (array $case): ?int => $case['reading'] ?? null, $cases)));
    $grouped = array_unique(array_merge(...array_values(DOMINO_CASE_GROUPS)));

    sort($rules);
    sort($readings);
    sort($grouped);

    $all = array_map(static fn (int $rule): string => "R{$rule}", range(1, 26));
    sort($all);

    expect($rules)->toBe(array_map(static fn (string $rule): string => "ADR 038 {$rule}", $all))
        ->and($readings)->toBe(range(1, 11))
        ->and($grouped)->toBe($all);
});

foreach (DOMINO_CASE_GROUPS as $group => $rules) {
    describe($group, function () use ($rules): void {
        it('passes the case', function (int $index): void {
            dominoFixtureCase(Domino::fixture('cases')['cases'][$index]);
        })->with(dominoFixtureCases($rules));
    });
}

describe('the oracle\'s hands (hands.json)', function (): void {
    it('plays the hand as the oracle does, play by play, to the same end', function (int $index): void {
        $entry = Domino::fixture('hands')['hands'][$index];
        $cite = "hands.json {$entry['id']} (".implode(', ', $entry['shows']).')';
        $walk = Domino::walk($entry);

        foreach ($walk['steps'] as $step) {
            expect(Domino::canon($step['actual']))->toBe(Domino::canon($step['expected']), "{$cite} · turn {$step['turn']}");
        }

        $hand = $walk['hand'];
        $end = Domino::endOf($hand, $entry['open']);

        expect(['seat' => $hand->opener, 'why' => $hand->why->value])->toBe($entry['opener'], "{$cite} · the opener")
            ->and(Domino::canon($end === null ? null : Domino::only($end, $entry['end'])))->toBe(Domino::canon($entry['end']), "{$cite} · the end");
    })->with(dominoFixtureHands());

    it('holds hands at every table, each ending, and every rule a hand can show', function (): void {
        $hands = Domino::fixture('hands')['hands'];
        $tables = array_count_values(array_map(static fn (array $entry): string => $entry['table'], $hands));
        $endings = array_count_values(array_map(static fn (array $entry): string => $entry['end']['ending'], $hands));
        $shows = array_unique(array_merge(...array_map(static fn (array $entry): array => $entry['shows'], $hands)));

        ksort($tables);
        ksort($endings);
        sort($shows);

        expect(array_keys($tables))->toBe(['four', 'pairs', 'three', 'two'])
            ->and(array_keys($endings))->toBe(['blocked', 'finished', 'tied', 'void'])
            ->and($shows)->toBe(['R12', 'R12 odd tile', 'R13', 'R14', 'R16 lone 0-0', 'R17', 'R18', 'R19', 'R5', 'R6', 'R7', 'R8', 'R9']);
    });
});

describe('the study\'s hands (study.json)', function (): void {
    it('replays the hand move by move to what the app showed: the opener, the ending, the hand\'s points and the account', function (int $game, int $index): void {
        $hands = Domino::fixture('study')['games'][$game]['hands'];
        $variant = new Domino101;
        $ledger = $variant->ledger(2);
        $before = null;

        foreach (array_slice($hands, 0, $index + 1) as $number => $entry) {
            $right = $before === null ? null : $variant->next($before, $ledger->anyOpen());
            $hand = dominoFixtureStudyHand($entry, $number + 1, $right);
            $ledger = Domino::afterHand($ledger, $hand);
            $before = $hand;
        }

        $entry = $hands[$index];
        $count = $hand->count ?? throw new RuntimeException('Not counted.');
        $points = array_map(static fn (int $side): int => $side === $count['winner'] ? $count['gain'] : 0, [0, 1]);

        expect([
            'opener' => array_values(array_filter($hand->events, static fn (array $event): bool => $event['kind'] === 'play'))[0]['seat'],
            'ending' => $hand->ending?->value,
            'hand' => $points,
            'account' => array_column($ledger->sides, 'balance'),
        ])->toBe($entry['app'], "study {$entry['id']}");
    })->with(dominoFixtureStudyHands());

    it('holds the 18 hands of the three replayed games', function (): void {
        expect(array_keys(dominoFixtureStudyHands()))->toBe(['g2·1', 'g2·2', 'g2·3', 'g2·4', 'g2·5', 'g2·6', 'g3·1', 'g3·2', 'g3·3', 'g3·4', 'g3·5', 'g4·1', 'g4·2', 'g4·3', 'g4·4', 'g4·5', 'g4·6', 'g4·7']);
    });
});
