<?php

use App\Domain\Rooms\Enums\RoomComputerLevel;
use App\Domain\Rooms\Enums\RoomMatchColor;
use App\Domain\Rooms\Support\Draughts\DraughtsEngine;
use App\Domain\Rooms\Support\Draughts\DraughtsMove;
use App\Domain\Rooms\Support\Draughts\DraughtsNotation;
use App\Domain\Rooms\Support\Draughts\DraughtsPosition;
use App\Domain\Rooms\Support\Draughts\DraughtsRules;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Tests\Support\Draughts;

/*
 * The computer's choice of a move (docs/architecture.md §12 "The
 * computer's seat", ADR 036 decisions 4–7): `DraughtsEngine` judges
 * nothing — it takes one of `DraughtsRules::moves()` — and the copy of the
 * rules its search runs on answers as the judge does on the oracle's
 * fixtures.
 */

function engineMove(DraughtsPosition $position, RoomComputerLevel $level, int $seed = 1): ?DraughtsMove
{
    return DraughtsEngine::choose($position, $level->depth(), $level->nodes(), $level->margin(), new Randomizer(new Xoshiro256StarStar($seed)));
}

/**
 * The judge's moves in the form the search sees its own.
 *
 * @return list<array{0: int, 1: int, 2: list<int>, 3: bool}>
 */
function judgedMoves(DraughtsPosition $position): array
{
    return array_map(static fn (DraughtsMove $move): array => [
        DraughtsNotation::place($move->from()),
        DraughtsNotation::place($move->to()),
        array_map(DraughtsNotation::place(...), $move->captured),
        $move->crowned || $position->isKingOn($move->from()),
    ], DraughtsRules::moves($position));
}

describe('the search\'s own rules', function (): void {
    it('sees the judge\'s moves in every position of the oracle', function (): void {
        $fixture = Draughts::fixture('positions');
        $entries = [...$fixture['named'], ...$fixture['random'], ...$fixture['scattered']];

        expect(count($entries))->toBeGreaterThan(1800);

        foreach ($entries as $entry) {
            $position = Draughts::position($entry['fen']);

            expect(DraughtsEngine::seen($position))->toBe(judgedMoves($position), $entry['fen']);
        }
    });

    it('sees the judge\'s moves three moves deep from every position counted', function (): void {
        $walk = function (DraughtsPosition $position, int $depth) use (&$walk): void {
            expect(DraughtsEngine::seen($position))->toBe(judgedMoves($position), $position->key());

            if ($depth > 1) {
                foreach (DraughtsRules::moves($position) as $move) {
                    $walk(DraughtsRules::apply($position, $move), $depth - 1);
                }
            }
        };

        foreach (Draughts::fixture('perft')['positions'] as $entry) {
            $walk(Draughts::position($entry['fen']), 3);
        }
    });
});

describe('the choice', function (): void {
    it('has no move where the side to move has none', function (): void {
        // 6.1.7: every piece blocked.
        $blocked = DraughtsPosition::of(RoomMatchColor::White, ['a1'], ['b2', 'c3']);

        expect(DraughtsRules::moves($blocked))->toBe([])
            ->and(engineMove($blocked, RoomComputerLevel::Hard))->toBeNull();
    });

    it('takes the only move there is', function (): void {
        $position = DraughtsPosition::of(RoomMatchColor::White, ['c3'], ['d4', 'h8']);

        expect(DraughtsNotation::text(engineMove($position, RoomComputerLevel::Easy)))->toBe('c3:e5');
    });

    it('takes one of the judge\'s moves at every level, through a whole game', function (RoomComputerLevel $level): void {
        $position = DraughtsPosition::initial();
        $random = new Randomizer(new Xoshiro256StarStar(7));

        for ($ply = 0; $ply < 40; $ply++) {
            $move = DraughtsEngine::choose($position, $level->depth(), min($level->nodes(), 3_000), $level->margin(), $random);

            if ($move === null) {
                break;
            }

            expect(DraughtsRules::among(DraughtsRules::moves($position), $move->path))->not->toBeNull();

            $position = DraughtsRules::apply($position, $move);
        }

        expect($ply)->toBeGreaterThan(10);
    })->with(RoomComputerLevel::cases());

    it('answers the same position, level and random source with the same move', function (RoomComputerLevel $level): void {
        $position = Draughts::position('W:Wa5,a3,c3,e3,d2,c1:Bb8,d8,f8,d6,c5,g3');

        expect(engineMove($position, $level, 3))->toEqual(engineMove($position, $level, 3));
    })->with(RoomComputerLevel::cases());

    it('gives a man to take two', function (RoomComputerLevel $level): void {
        // e3-d4, c5:e3 compulsory, d2:f4:h2.
        $position = Draughts::position('W:Wa5,a3,c3,e3,d2,c1:Bb8,d8,f8,d6,c5,g3');

        foreach ([1, 2, 3, 4] as $seed) {
            expect(DraughtsNotation::text(engineMove($position, $level, $seed)))->toBe('e3-d4');
        }
    })->with([RoomComputerLevel::Medium, RoomComputerLevel::Hard]);

    it('gives a man to take three and crown', function (): void {
        // d6-e7, f8:d6 compulsory, h6:f8:c5:a7.
        $position = Draughts::position('W:Wd6,h6,e5,f4,b2,c1:Bb8,f8,g7,b6,a5');

        expect(DraughtsNotation::text(engineMove($position, RoomComputerLevel::Hard)))->toBe('d6-e7');
    });

    it('takes a win it can see', function (): void {
        // c3:e5 takes black's last piece.
        $position = DraughtsPosition::of(RoomMatchColor::White, ['c3', 'a1'], ['d4']);

        foreach (RoomComputerLevel::cases() as $level) {
            expect(DraughtsNotation::text(engineMove($position, $level)))->toBe('c3:e5');
        }
    });

    it('plays differently at the easy level from one random source to another', function (): void {
        $position = Draughts::position('W:Wa5,a3,c3,e3,d2,c1:Bb8,d8,f8,d6,c5,g3');
        $lines = [];

        foreach (range(1, 12) as $seed) {
            $lines[DraughtsNotation::text(engineMove($position, RoomComputerLevel::Easy, $seed))] = true;
        }

        expect(count($lines))->toBeGreaterThan(1);
    });

    it('wins at the hard level against the easy one', function (): void {
        $levels = [RoomComputerLevel::Hard, RoomComputerLevel::Easy];

        foreach ([0, 1] as $game) {
            $position = DraughtsPosition::initial();
            $random = new Randomizer(new Xoshiro256StarStar(100 + $game));
            $hard = $game === 0 ? RoomMatchColor::White : RoomMatchColor::Black;
            $loser = null;

            for ($ply = 0; $ply < 300; $ply++) {
                $level = $position->turn === $hard ? $levels[0] : $levels[1];
                $move = DraughtsEngine::choose($position, $level->depth(), $level->nodes(), $level->margin(), $random);

                if ($move === null) {
                    $loser = $position->turn;

                    break;
                }

                $position = DraughtsRules::apply($position, $move);
            }

            expect($loser)->toBe($hard->other());
        }
    })->group('slow');
});
