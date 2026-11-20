<?php

use App\Domain\Rooms\Enums\RoomMatchColor;
use App\Domain\Rooms\Support\Draughts\DraughtsClock;

/*
 * Each side's own time with an increment (docs/architecture.md §12 "Board
 * games", ADR 034 decision 12): `left(side, p)` is the clock plus an
 * increment for each move made, less the time each move took; the side to
 * move runs out at `since(p) + left`.
 */

const DRAUGHTS_STARTS = 1_800_000_000_000;

it('gives both sides the whole clock and runs white\'s from the start', function (): void {
    $clock = DraughtsClock::start(DRAUGHTS_STARTS, 300_000, 3_000);

    expect($clock->ply)->toBe(0)
        ->and($clock->sinceMs)->toBe(DRAUGHTS_STARTS)
        ->and($clock->left(RoomMatchColor::White))->toBe(300_000)
        ->and($clock->left(RoomMatchColor::Black))->toBe(300_000)
        ->and($clock->flagMs())->toBe(DRAUGHTS_STARTS + 300_000)
        // The countdown takes nothing from the clock.
        ->and($clock->leftAt(RoomMatchColor::White, DRAUGHTS_STARTS - 2_000))->toBe(300_000);
});

it('takes the time a move took and adds the increment once it is made', function (): void {
    $clock = DraughtsClock::start(DRAUGHTS_STARTS, 300_000, 3_000)
        ->moved(DRAUGHTS_STARTS + 10_000)   // white thought 10 s
        ->moved(DRAUGHTS_STARTS + 14_000)   // black thought 4 s
        ->moved(DRAUGHTS_STARTS + 34_000);  // white thought 20 s

    expect($clock->ply)->toBe(3)
        ->and($clock->sinceMs)->toBe(DRAUGHTS_STARTS + 34_000)
        ->and($clock->left(RoomMatchColor::White))->toBe(300_000 - 10_000 - 20_000 + 2 * 3_000)
        ->and($clock->left(RoomMatchColor::Black))->toBe(300_000 - 4_000 + 3_000)
        ->and($clock->flagMs())->toBe(DRAUGHTS_STARTS + 34_000 + 299_000);
});

it('runs down only the side to move\'s time and stops it at nothing, at the flag', function (): void {
    $clock = DraughtsClock::start(DRAUGHTS_STARTS, 180_000, 2_000)->moved(DRAUGHTS_STARTS + 30_000);
    $flag = $clock->flagMs();

    expect($flag)->toBe(DRAUGHTS_STARTS + 30_000 + 180_000)
        ->and($clock->leftAt(RoomMatchColor::Black, $flag - 1))->toBe(1)
        ->and($clock->leftAt(RoomMatchColor::Black, $flag))->toBe(0)
        ->and($clock->leftAt(RoomMatchColor::Black, $flag + 5_000))->toBe(0)
        ->and($clock->leftAt(RoomMatchColor::White, $flag + 5_000))->toBe(180_000 - 30_000 + 2_000);
});

it('lets a side live on its increment alone', function (): void {
    $clock = DraughtsClock::start(DRAUGHTS_STARTS, 60_000, 5_000);
    $at = DRAUGHTS_STARTS;

    // White spends exactly its increment on each of ten moves; black answers at once.
    for ($move = 0; $move < 10; $move++) {
        $at += 5_000;
        $clock = $clock->moved($at);
        $clock = $clock->moved($at);
    }

    expect($clock->left(RoomMatchColor::White))->toBe(60_000)
        ->and($clock->left(RoomMatchColor::Black))->toBe(60_000 + 10 * 5_000);
});
