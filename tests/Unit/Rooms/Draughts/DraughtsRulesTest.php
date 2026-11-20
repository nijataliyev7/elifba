<?php

use App\Domain\Rooms\Enums\RoomMatchColor;
use App\Domain\Rooms\Support\Draughts\DraughtsMove;
use App\Domain\Rooms\Support\Draughts\DraughtsNotation;
use App\Domain\Rooms\Support\Draughts\DraughtsPosition;
use App\Domain\Rooms\Support\Draughts\DraughtsRules;
use Tests\Support\Draughts;

/*
 * The rules of Russian draughts (docs/architecture.md §12 "Board games",
 * ADR 034 decisions 4, 5, 28 and 32–34): `DraughtsRules` is the one judge
 * of a move. An independent library, pydraughts 0.6.7, answered the
 * fixtures (`scripts/draughts-oracle/make.py`); the official text's own
 * examples and the contributor's twelve cases stand beside them.
 */

/**
 * @param  list<DraughtsMove>  $moves
 * @return list<string>
 */
function draughtsLines(array $moves): array
{
    $lines = array_map(DraughtsNotation::text(...), $moves);
    sort($lines, SORT_STRING);

    return $lines;
}

describe('the oracle', function (): void {
    it('counts the move sequences from the start as the library does, to depth 6', function (): void {
        $start = Draughts::fixture('perft')['start'];

        expect(Draughts::position($start['fen']))->toEqual(DraughtsPosition::initial())
            ->and(array_slice($start['counts'], 0, 6))->toBe([7, 49, 302, 1469, 7482, 37986]);

        foreach (array_slice($start['counts'], 0, 6) as $index => $count) {
            expect(Draughts::perft(DraughtsPosition::initial(), $index + 1))->toBe($count);
        }
    });

    it('counts 190 146 sequences of seven moves from the start', function (): void {
        expect(Draughts::fixture('perft')['start']['counts'][6])->toBe(190146)
            ->and(Draughts::perft(DraughtsPosition::initial(), 7))->toBe(190146);
    })->group('slow');

    it('counts the move sequences from every other position as the library does', function (): void {
        $positions = Draughts::fixture('perft')['positions'];

        expect(count($positions))->toBeGreaterThanOrEqual(20);

        foreach ($positions as $entry) {
            $position = Draughts::position($entry['fen']);

            foreach ($entry['counts'] as $index => $count) {
                expect(Draughts::perft($position, $index + 1))->toBe($count, "{$entry['name']}, depth ".($index + 1));
            }
        }
    });

    it('gives the legal moves of every position written for a rule, with the pieces each takes', function (): void {
        foreach (Draughts::fixture('positions')['named'] as $entry) {
            expect(Draughts::legal(Draughts::position($entry['fen'])))->toBe($entry['moves'], "{$entry['name']} ({$entry['article']})");
        }
    });

    it('gives the legal moves of a thousand positions reached by random play', function (): void {
        $positions = Draughts::fixture('positions')['random'];

        expect(count($positions))->toBeGreaterThanOrEqual(1000);

        foreach ($positions as $entry) {
            expect(Draughts::legal(Draughts::position($entry['fen'])))->toBe($entry['moves'], $entry['fen']);
        }
    });

    it('gives the legal moves of positions scattered at random, where kings\' long captures are common', function (): void {
        $positions = Draughts::fixture('positions')['scattered'];

        expect(count($positions))->toBeGreaterThanOrEqual(500);

        foreach ($positions as $entry) {
            expect(Draughts::legal(Draughts::position($entry['fen'])))->toBe($entry['moves'], $entry['fen']);
        }
    });

    it('plays the seeded games move by move to the same positions and the same end', function (): void {
        foreach (Draughts::fixture('games')['games'] as $game) {
            $position = DraughtsPosition::initial();

            foreach ($game['moves'] as $ply => $line) {
                $position = DraughtsRules::apply($position, Draughts::move($position, $line));

                expect($position)->toEqual(Draughts::position($game['fens'][$ply]), "game {$game['game']}, move {$line}");
            }

            expect(DraughtsRules::moves($position) === [])->toBe($game['end'] === 'no_moves', "game {$game['game']}");
        }
    });
});

describe('the official text', function (): void {
    it('stops a king on d4: c3 is taken once and stays on the board (6.1.5.17, figure 5)', function (): void {
        $lines = draughtsLines(DraughtsRules::moves(Draughts::position('W:WKe1:Bb6,c3,c5,e5,e7')));

        expect($lines)->toContain('e1:a5:d8:f6:d4')
            ->and(array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'e1:a5:d8:f6:d4:')))->toBe([]);
    });

    it('stops the new king on c3 either way round, and white then takes five (6.1.5.17, figure 6)', function (): void {
        $position = Draughts::position('B:Wa1,b2,b4,d2,d4,f2,f4:Ba5,c5,c7,e7,g7');
        $lines = draughtsLines(DraughtsRules::moves($position));

        expect($lines)->toContain('a5:c3:e1:g3:e5:c3')
            ->and($lines)->toContain('a5:c3:e5:g3:e1:c3');

        $move = Draughts::move($position, 'a5:c3:e1:g3:e5:c3');
        $after = DraughtsRules::apply($position, $move);

        expect($move->crowned)->toBeTrue()
            ->and($move->captured)->toBe(['b4', 'd2', 'f2', 'f4', 'd4'])
            ->and($after)->toEqual(Draughts::position('W:Wa1,b2:BKc3,c5,c7,e7,g7'))
            ->and(draughtsLines(DraughtsRules::moves($after)))->toContain('b2:d4:b6:d8:f6:h8');
    });

    it('names the squares a1 to h8 from white\'s lower left, a1 dark (6.1.6.1–2)', function (): void {
        expect(DraughtsNotation::square(0))->toBe('a1')
            ->and(DraughtsNotation::square(63))->toBe('h8')
            ->and(DraughtsNotation::place('c3'))->toBe(18)
            ->and(DraughtsNotation::isSquare('a1'))->toBeTrue()
            ->and(DraughtsNotation::isSquare('h8'))->toBeTrue()
            ->and(DraughtsNotation::isSquare('a2'))->toBeFalse()
            ->and(DraughtsNotation::isSquare('i1'))->toBeFalse()
            ->and(DraughtsNotation::isSquare('a9'))->toBeFalse()
            ->and(DraughtsNotation::isSquare('A1'))->toBeFalse()
            ->and(fn () => DraughtsNotation::place('b1'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => DraughtsNotation::square(1))->toThrow(InvalidArgumentException::class);

        for ($place = 0; $place < 64; $place++) {
            if (DraughtsPosition::isDark($place)) {
                expect(DraughtsNotation::place(DraughtsNotation::square($place)))->toBe($place);
            }
        }
    });

    it('sets the pieces on the squares the text lists, white to move (6.1.6.2)', function (): void {
        $white = array_values(array_filter(DraughtsPosition::initial()->pieces(), static fn (array $piece): bool => $piece['color'] === RoomMatchColor::White));
        $black = array_values(array_filter(DraughtsPosition::initial()->pieces(), static fn (array $piece): bool => $piece['color'] === RoomMatchColor::Black));

        expect(array_column($white, 'square'))->toBe(['a1', 'a3', 'b2', 'c1', 'c3', 'd2', 'e1', 'e3', 'f2', 'g1', 'g3', 'h2'])
            ->and(array_column($black, 'square'))->toBe(['a7', 'b6', 'b8', 'c7', 'd6', 'd8', 'e7', 'f6', 'f8', 'g7', 'h6', 'h8'])
            ->and(DraughtsPosition::initial()->turn)->toBe(RoomMatchColor::White);
    });

    it('writes a quiet move with a dash and a capture with every landing (6.1.6.2, ADR 034 decision 28)', function (): void {
        expect(DraughtsNotation::text(Draughts::move(DraughtsPosition::initial(), 'c3-d4')))->toBe('c3-d4')
            ->and(DraughtsNotation::text(Draughts::move(Draughts::position('W:Wb2:Bc3,c5'), 'b2:d4:b6')))->toBe('b2:d4:b6');
    });
});

describe('the contributor\'s cases', function (): void {
    it('starts with twelve pieces a side on the dark squares, and each side has seven moves', function (): void {
        $start = DraughtsPosition::initial();

        expect($start->count(RoomMatchColor::White))->toBe(12)
            ->and($start->count(RoomMatchColor::Black))->toBe(12)
            ->and(DraughtsRules::moves($start))->toHaveCount(7)
            ->and(DraughtsRules::moves(Draughts::position('B:Wa1,a3,b2,c1,c3,d2,e1,e3,f2,g1,g3,h2:Ba7,b6,b8,c7,d6,d8,e7,f6,f8,g7,h6,h8')))->toHaveCount(7);
    });

    it('moves a man one square forward only', function (): void {
        expect(draughtsLines(DraughtsRules::moves(Draughts::position('W:Wc3:Bf6'))))->toBe(['c3-b4', 'c3-d4'])
            ->and(draughtsLines(DraughtsRules::moves(Draughts::position('B:Wc3:Bf6'))))->toBe(['f6-e5', 'f6-g5']);
    });

    it('makes capturing compulsory', function (): void {
        expect(DraughtsRules::moves(Draughts::position('W:Wc3,g3:Bd4')))->toEqual([new DraughtsMove(['c3', 'e5'], ['d4'], false)]);
    });

    it('lets a man capture backwards', function (): void {
        expect(DraughtsRules::moves(Draughts::position('W:Wc5:Bd4')))->toEqual([new DraughtsMove(['c5', 'e3'], ['d4'], false)]);
    });

    it('goes on capturing while it can', function (): void {
        expect(DraughtsRules::moves(Draughts::position('W:Wb2:Bc3,c5')))->toEqual([new DraughtsMove(['b2', 'd4', 'b6'], ['c3', 'c5'], false)]);
    });

    it('crowns a man on the last row in the middle of a capture, which goes on as a king\'s', function (): void {
        $position = Draughts::position('W:Wb6:Bc7,f6');

        expect(draughtsLines(DraughtsRules::moves($position)))->toBe(['b6:d8:g5', 'b6:d8:h4']);

        $move = Draughts::move($position, 'b6:d8:g5');

        expect($move->crowned)->toBeTrue()
            ->and(DraughtsRules::apply($position, $move))->toEqual(Draughts::position('B:WKg5:B'));
    });

    it('crowns a man that steps onto the last row', function (): void {
        $position = Draughts::position('W:Wc7:Ba1');
        $move = Draughts::move($position, 'c7-d8');

        expect($move->crowned)->toBeTrue()
            ->and(DraughtsRules::apply($position, $move)->isKingOn('d8'))->toBeTrue()
            ->and(Draughts::move($position, 'c7-b8')->crowned)->toBeTrue()
            ->and(Draughts::move(DraughtsPosition::initial(), 'c3-d4')->crowned)->toBeFalse();
    });

    it('flies a king and lets it land on any free square beyond the piece it captures', function (): void {
        expect(draughtsLines(DraughtsRules::moves(Draughts::position('W:WKa1:Bd4'))))->toBe(['a1:e5', 'a1:f6', 'a1:g7', 'a1:h8']);
    });

    it('makes a king land where it can go on capturing', function (): void {
        expect(DraughtsRules::moves(Draughts::position('W:WKa1:Bd4,e7')))->toEqual([new DraughtsMove(['a1', 'f6', 'd8'], ['d4', 'e7'], false)]);
    });

    it('never jumps a captured piece twice', function (): void {
        $moves = DraughtsRules::moves(Draughts::position('W:WKb2:Bc3,c5,e3,e5'));

        expect($moves)->not->toBeEmpty();

        foreach ($moves as $move) {
            expect($move->captured)->toBe(array_values(array_unique($move->captured)));
        }
    });

    it('finds only a legal path', function (): void {
        $start = DraughtsPosition::initial();

        expect(DraughtsRules::find($start, ['a3', 'b4']))->not->toBeNull()
            ->and(DraughtsRules::find($start, ['a3', 'c5']))->toBeNull()
            ->and(DraughtsRules::find($start, ['b6', 'a5']))->toBeNull()
            ->and(DraughtsRules::find($start, ['a3']))->toBeNull()
            ->and(DraughtsRules::find($start, []))->toBeNull();
    });
});

it('ends a move with the taken pieces gone and the other side to move', function (): void {
    $position = Draughts::position('W:Wc3:Bb4,b6,d4,d6');
    $move = Draughts::move($position, 'c3:a5:c7:e5:c3');

    expect($move->captured)->toBe(['b4', 'b6', 'd6', 'd4'])
        ->and(DraughtsRules::apply($position, $move))->toEqual(Draughts::position('B:Wc3:B'));
});

it('builds a position only from dark squares, one piece each', function (): void {
    expect(fn () => DraughtsPosition::of(RoomMatchColor::White, ['a2'], []))->toThrow(InvalidArgumentException::class)
        ->and(fn () => DraughtsPosition::of(RoomMatchColor::White, ['c3'], ['c3']))->toThrow(InvalidArgumentException::class);
});
