<?php

use App\Domain\Rooms\Support\Checkers;
use Tests\Support\Boards;

/*
 * The rules of Russian draughts (docs/architecture.md §12 "Board games",
 * ADR 034 decision 4): `Checkers` is the one judge of a match. Squares are
 * `row * 8 + col`, row 0 at the top as white sees it.
 */

/**
 * @param  list<array{path: list<int>, captured: list<int>}>  $moves
 * @return list<list<int>>
 */
function checkersPaths(array $moves): array
{
    $paths = array_map(static fn (array $move): array => $move['path'], $moves);
    sort($paths);

    return $paths;
}

it('starts with twelve pieces a side on the dark squares, and white has seven moves', function (): void {
    $board = Checkers::initial();

    expect(substr_count($board, 'w'))->toBe(12)
        ->and(substr_count($board, 'b'))->toBe(12);

    for ($square = 0; $square < 64; $square++) {
        if ($board[$square] !== Checkers::EMPTY) {
            expect((intdiv($square, 8) + $square % 8) % 2)->toBe(1);
        }
    }

    expect(Checkers::moves($board, 'w'))->toHaveCount(7)
        ->and(Checkers::moves($board, 'b'))->toHaveCount(7);
});

it('moves a man one square forward only', function (): void {
    $board = Boards::of(['w' => [Boards::sq(5, 2)], 'b' => [Boards::sq(2, 5)]]);

    expect(checkersPaths(Checkers::moves($board, 'w')))->toBe([[Boards::sq(5, 2), Boards::sq(4, 1)], [Boards::sq(5, 2), Boards::sq(4, 3)]])
        ->and(checkersPaths(Checkers::moves($board, 'b')))->toBe([[Boards::sq(2, 5), Boards::sq(3, 4)], [Boards::sq(2, 5), Boards::sq(3, 6)]]);
});

it('makes capturing compulsory', function (): void {
    $board = Boards::of(['w' => [Boards::sq(5, 2), Boards::sq(5, 6)], 'b' => [Boards::sq(4, 3)]]);

    expect(Checkers::moves($board, 'w'))->toBe([
        ['path' => [Boards::sq(5, 2), Boards::sq(3, 4)], 'captured' => [Boards::sq(4, 3)]],
    ]);
});

it('lets a man capture backwards', function (): void {
    $board = Boards::of(['w' => [Boards::sq(3, 2)], 'b' => [Boards::sq(4, 3)]]);

    expect(Checkers::moves($board, 'w'))->toBe([
        ['path' => [Boards::sq(3, 2), Boards::sq(5, 4)], 'captured' => [Boards::sq(4, 3)]],
    ]);
});

it('goes on capturing while it can', function (): void {
    $board = Boards::of(['w' => [Boards::sq(6, 1)], 'b' => [Boards::sq(5, 2), Boards::sq(3, 2)]]);

    expect(Checkers::moves($board, 'w'))->toBe([
        ['path' => [Boards::sq(6, 1), Boards::sq(4, 3), Boards::sq(2, 1)], 'captured' => [Boards::sq(5, 2), Boards::sq(3, 2)]],
    ]);
});

it('crowns a man on the far row in the middle of a capture, which goes on as a king\'s', function (): void {
    $board = Boards::of(['w' => [Boards::sq(2, 1)], 'b' => [Boards::sq(1, 2), Boards::sq(2, 5)]]);
    $moves = Checkers::moves($board, 'w');

    expect(checkersPaths($moves))->toBe([[Boards::sq(2, 1), Boards::sq(0, 3), Boards::sq(3, 6)], [Boards::sq(2, 1), Boards::sq(0, 3), Boards::sq(4, 7)]]);

    $after = Checkers::apply($board, $moves[0]);

    expect($after[$moves[0]['path'][2]])->toBe('W')
        ->and($after[Boards::sq(1, 2)])->toBe(Checkers::EMPTY)
        ->and($after[Boards::sq(2, 5)])->toBe(Checkers::EMPTY)
        ->and($after[Boards::sq(2, 1)])->toBe(Checkers::EMPTY);
});

it('crowns a man that steps onto the far row', function (): void {
    $board = Boards::of(['w' => [Boards::sq(1, 2)], 'b' => [Boards::sq(7, 0)]]);
    $move = Checkers::find($board, 'w', [Boards::sq(1, 2), Boards::sq(0, 1)]);

    expect($move)->not->toBeNull()
        ->and(Checkers::apply($board, $move)[Boards::sq(0, 1)])->toBe('W');
});

it('flies a king and lets it land on any empty square beyond the piece it captures', function (): void {
    $board = Boards::of(['W' => [Boards::sq(7, 0)], 'b' => [Boards::sq(4, 3)]]);

    expect(checkersPaths(Checkers::moves($board, 'w')))->toBe([
        [Boards::sq(7, 0), Boards::sq(0, 7)],
        [Boards::sq(7, 0), Boards::sq(1, 6)],
        [Boards::sq(7, 0), Boards::sq(2, 5)],
        [Boards::sq(7, 0), Boards::sq(3, 4)],
    ]);
});

it('makes a king land where it can go on capturing', function (): void {
    $board = Boards::of(['W' => [Boards::sq(7, 0)], 'b' => [Boards::sq(4, 3), Boards::sq(1, 4)]]);

    expect(Checkers::moves($board, 'w'))->toBe([
        ['path' => [Boards::sq(7, 0), Boards::sq(2, 5), Boards::sq(0, 3)], 'captured' => [Boards::sq(4, 3), Boards::sq(1, 4)]],
    ]);
});

it('never jumps a captured piece twice', function (): void {
    // Four pieces round a square: a king could loop round them and jump the first again.
    $board = Boards::of(['W' => [Boards::sq(6, 1)], 'b' => [Boards::sq(5, 2), Boards::sq(3, 2), Boards::sq(3, 4), Boards::sq(5, 4)]]);

    foreach (Checkers::moves($board, 'w') as $move) {
        expect($move['captured'])->toBe(array_values(array_unique($move['captured'])));
    }

    expect(Checkers::moves($board, 'w'))->not->toBeEmpty();
});

it('finds only a legal path', function (): void {
    $board = Checkers::initial();

    expect(Checkers::find($board, 'w', [Boards::sq(5, 0), Boards::sq(4, 1)]))->not->toBeNull()
        ->and(Checkers::find($board, 'w', [Boards::sq(5, 0), Boards::sq(3, 2)]))->toBeNull()
        ->and(Checkers::find($board, 'b', [Boards::sq(5, 0), Boards::sq(4, 1)]))->toBeNull();
});

it('counts a king\'s move without a capture as quiet, and nothing else', function (): void {
    $board = Boards::of(['W' => [Boards::sq(7, 0)], 'w' => [Boards::sq(6, 3)], 'b' => [Boards::sq(0, 1)]]);

    expect(Checkers::isQuiet($board, ['path' => [Boards::sq(7, 0), Boards::sq(6, 1)], 'captured' => []]))->toBeTrue()
        ->and(Checkers::isQuiet($board, ['path' => [Boards::sq(6, 3), Boards::sq(5, 2)], 'captured' => []]))->toBeFalse();
});
