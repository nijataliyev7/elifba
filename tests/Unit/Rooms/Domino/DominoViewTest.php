<?php

use App\Domain\Rooms\Enums\RoomTable;
use App\Domain\Rooms\Support\Domino\DominoDeal;
use App\Domain\Rooms\Support\Domino\DominoGame;
use App\Domain\Rooms\Support\Domino\DominoTile;
use App\Domain\Rooms\Support\Domino\DominoView;
use Tests\Support\Domino;

/*
 * What one seat has seen (docs/architecture.md §12 "Domino", ADR 038
 * decisions 9 and 54): its own tiles, the line, the counts, every play,
 * draw and pass by seat, the account — and no field for another seat's
 * tile, the boneyard's order or the seed. Two games that differ only in
 * tiles a seat has not seen give that seat an equal view; the computer,
 * deciding from a view alone (Y5), cannot peek.
 */

/**
 * A game at a table from one deal, after its first `$plays`, a second apart.
 *
 * @param  list<string>  $plays
 */
function dominoSeen(RoomTable $table, DominoDeal $deal, array $plays): DominoGame
{
    return Domino::game($table, Domino::source([1 => $deal]), Domino::acts($plays, 0, Domino::STARTS), Domino::STARTS + count($plays) * 1_000);
}

it('holds nothing but what a seat has seen: no field for another seat\'s tile, the boneyard\'s order or the seed (ADR 038 decision 54)', function (): void {
    $fields = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(DominoView::class))->getProperties(),
    );

    expect($fields)->toBe(['seat', 'table', 'hand', 'opener', 'why', 'turn', 'tiles', 'line', 'ends', 'held', 'boneyard', 'drawable', 'closed', 'events', 'legal', 'sides', 'bank']);

    foreach ((new ReflectionClass(DominoView::class))->getProperties() as $property) {
        expect((string) $property->getType())->not->toContain('Deal')->not->toContain('Hand')->not->toContain('Game');
    }
});

it('gives a seat its own tiles, every seat\'s count and the boneyard\'s, and a draw as a count (ADR 038 decision 54)', function (): void {
    $deal = Domino::deal(RoomTable::Two, [
        ['1-1', '6-5', '6-4', '5-4', '6-3', '5-3', '4-3'],
        ['0-0', '2-0', '3-0', '2-2', '3-2', '4-2', '6-6'],
    ], ['4-0', '5-0', '6-0', '3-1']);
    $game = dominoSeen(RoomTable::Two, $deal, ['1-1']);
    $mine = $game->view(0);
    $theirs = $game->view(1);

    expect(DominoTile::names($mine->tiles))->toBe(['6-5', '6-4', '5-4', '6-3', '5-3', '4-3'])
        ->and($mine->held)->toBe([6, 11])
        ->and($mine->boneyard)->toBe(10)
        ->and($mine->drawable)->toBe(8)
        ->and($mine->turn)->toBe(1)
        ->and($mine->legal)->toBe([])
        ->and($mine->events[1])->toBe(['seat' => 1, 'kind' => 'draw', 'tile' => null, 'end' => null, 'count' => 4])
        ->and($mine->ends)->toBe(['left' => 1, 'right' => 1])
        ->and($mine->side())->toBe(0)
        ->and(DominoTile::names($theirs->tiles))->toBe(['0-0', '2-0', '3-0', '2-2', '3-2', '4-2', '6-6', '4-0', '5-0', '6-0', '3-1'])
        ->and(DominoTile::names(array_column($theirs->legal, 'tile')))->toBe(['3-1']);
});

it('gives a seat an equal view of two games whose boneyards differ in tiles it has not seen (ADR 038 decisions 9, 54)', function (): void {
    $hands = [
        ['1-1', '6-5', '6-4', '5-4', '6-3', '5-3', '4-3'],
        ['0-0', '2-0', '3-0', '2-2', '3-2', '4-2', '6-6'],
    ];
    // Seat 1 draws two in both — a different pair — and the rest of the
    // boneyard lies in another order.
    $one = dominoSeen(RoomTable::Two, Domino::deal(RoomTable::Two, $hands, ['4-0', '2-1', '5-5', '3-3']), ['1-1']);
    $other = dominoSeen(RoomTable::Two, Domino::deal(RoomTable::Two, $hands, ['5-0', '3-1', '3-3', '5-5']), ['1-1']);

    expect($one->view(0))->toEqual($other->view(0))
        ->and($one->view(1))->not->toEqual($other->view(1));
});

it('gives a seat an equal view of two games whose other hands differ in tiles it has not seen, play after play (ADR 038 decisions 9, 54)', function (): void {
    $hands = static fn (string $one, string $three): array => [
        ['5-0', '0-0', '4-1', '4-0', '5-3', '4-3', '6-0'],
        ['6-3', $one, '3-1', '1-0', '3-0', '6-2', '2-0'],
        ['4-2', '5-1', '1-1', '4-4', '6-5', '2-1', '6-1'],
        ['3-3', '3-2', $three, '5-2', '5-4', '6-4', '2-2'],
    ];
    $plays = ['1-1', '4-1 left', '3-1 right', '4-4 left', '3-3 right', '4-3 right', '4-2 left', '3-2 left', '4-0 right', '1-0 right', '2-1 right', '2-2 right', '5-3 left', '2-0 right', '5-1 left'];
    $one = Domino::deal(RoomTable::Pairs, $hands('6-6', '5-5'));
    $other = Domino::deal(RoomTable::Pairs, $hands('5-5', '6-6'));

    foreach (range(0, count($plays)) as $count) {
        $a = dominoSeen(RoomTable::Pairs, $one, array_slice($plays, 0, $count));
        $b = dominoSeen(RoomTable::Pairs, $other, array_slice($plays, 0, $count));

        expect($a->ply)->toBe($count)
            ->and($b->ply)->toBe($count)
            ->and($a->view(0))->toEqual($b->view(0))
            ->and($a->view(2))->toEqual($b->view(2))
            ->and($a->view(1))->not->toEqual($b->view(1))
            ->and($a->view(3))->not->toEqual($b->view(3));
    }
});

it('gives the seats that did not draw an equal view of two games where another seat drew in another order (ADR 038 decisions 9, 54)', function (): void {
    $hands = [
        ['2-0', '5-4', '5-2', '5-1', '6-0', '5-3', '3-0'],
        ['6-1', '4-4', '4-1', '4-0', '3-1', '6-3', '0-0'],
        ['4-3', '5-0', '6-2', '2-1', '6-6', '2-2', '3-3'],
    ];
    $plays = ['2-2', '2-0 left', '0-0 left', '5-0 left', '5-2 right', '6-5 left', '6-6 left', '5-4 right', '4-1 right', '6-2 left', '5-1 right', '4-2 left', '4-3 left', '5-3 left', '5-5 left'];
    $one = Domino::deal(RoomTable::Three, $hands, ['6-4', '4-2', '6-5', '5-5', '3-2', '1-0', '1-1']);
    $other = Domino::deal(RoomTable::Three, $hands, ['4-2', '6-4', '6-5', '5-5', '1-1', '3-2', '1-0']);

    foreach ([5, 6, 11, 15] as $count) {
        $a = dominoSeen(RoomTable::Three, $one, array_slice($plays, 0, $count));
        $b = dominoSeen(RoomTable::Three, $other, array_slice($plays, 0, $count));

        expect($a->view(0))->toEqual($b->view(0))
            ->and($a->view(2))->toEqual($b->view(2));

        // Seat 1 holds both tiles it drew in another order until it plays `4-2`.
        if ($count < 12) {
            expect($a->view(1))->not->toEqual($b->view(1));
        }
    }
});
