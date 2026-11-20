<?php

use App\Domain\Rooms\Enums\RoomMatchEnding;
use App\Domain\Rooms\Support\Draughts\DraughtsDraws;
use App\Domain\Rooms\Support\Draughts\DraughtsRules;
use Tests\Support\Draughts;

/*
 * The draws the server declares (docs/architecture.md §12 "Board games",
 * ADR 034 decision 16): `draws.json` is written by hand from the official
 * text, a case at each boundary — and, since no earlier move of a case may
 * make a draw, just before it.
 */

/**
 * The draw each move of a case makes, from its start.
 *
 * @param  array{fen: string, moves: list<string>}  $case
 * @return list<RoomMatchEnding|null>
 */
function draughtsDraws(array $case): array
{
    $position = Draughts::position($case['fen']);
    $draws = DraughtsDraws::start($position);
    $endings = [];

    foreach ($case['moves'] as $line) {
        $move = Draughts::move($position, $line);
        $after = DraughtsRules::apply($position, $move);
        $draws = $draws->after($position, $move, $after);
        $position = $after;
        $endings[] = $draws->ending;
    }

    return $endings;
}

it('declares each draw at its boundary and not a move before', function (): void {
    foreach (Draughts::fixture('draws')['cases'] as $case) {
        $endings = draughtsDraws($case);
        $last = array_pop($endings);

        expect($endings)->each->toBeNull()
            ->and($last?->value)->toBe($case['ending'], "{$case['name']} ({$case['article']})");
    }
});

it('has a case for every draw the server declares, at every number of the rules', function (): void {
    $plies = [];

    foreach (Draughts::fixture('draws')['cases'] as $case) {
        $plies[$case['ending'] ?? 'none'][] = count($case['moves']);
    }

    expect(array_keys($plies))->toEqualCanonicalizing(['repetition', 'three_kings', 'main_road', 'balance', 'kings_only', 'none'])
        // 6.1.8.6: 5, 30 and 60 moves; 6.1.8.8: 15 and 25 moves — as plies.
        ->and($plies['balance'])->toContain(10, 60, 120)
        ->and($plies['kings_only'])->toContain(30, 50)
        // 6.1.8.4: the stronger side's 15th move, after a capture by either side.
        ->and($plies['three_kings'])->toContain(31, 30)
        // 6.1.8.5: the stronger side's 5th move.
        ->and($plies['main_road'])->toContain(9)
        // 5.4.13.1: the start, then twice more.
        ->and($plies['repetition'])->toContain(8);
});

it('counts a king\'s move without a capture towards the kings-only rule, and not a man\'s', function (): void {
    $case = collect(Draughts::fixture('draws')['cases'])->firstWhere('ending', 'kings_only');
    $reset = collect(Draughts::fixture('draws')['cases'])->first(fn (array $case): bool => str_starts_with($case['name'], 'a man\'s move'));
    $position = Draughts::position($reset['fen']);

    foreach (array_slice($reset['moves'], 0, 21) as $ply => $line) {
        expect($position->isKingOn(Draughts::path($line)[0]))->toBe($ply !== 20, $line);
        $position = DraughtsRules::apply($position, Draughts::move($position, $line));
    }

    expect(count($case['moves']))->toBe(30)
        ->and(count($reset['moves']))->toBe(21 + 30);
});
