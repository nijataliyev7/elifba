<?php

use App\Domain\Rooms\Enums\RoomLetterPrice;
use App\Domain\Rooms\Support\RoomPointsRule;

/*
 * The points of a room answer (docs/architecture.md §12 "Scoring and what
 * is kept", ADR 022 decision 1): 10 for a right answer plus 0–10 for speed,
 * ceil(10 × (answer_ms − elapsed_ms) ÷ answer_ms); 0 for a wrong one.
 * From ADR 037 (decision 17) an anagram's answer after the letters its
 * player bought: half with one, nothing with two or more.
 */

it('gives 20 at once and 10 at the bell', function (int $elapsed, int $answer, int $points): void {
    expect(RoomPointsRule::points(true, $elapsed, $answer))->toBe($points);
})->with([
    'at once' => [0, 10_000, 20],
    'a millisecond in' => [1, 10_000, 20],
    'a tenth gone' => [1_000, 10_000, 19],
    'just past a tenth' => [1_001, 10_000, 19],
    'half way' => [5_000, 10_000, 15],
    'just past half way' => [5_001, 10_000, 15],
    'a step before the bell' => [9_000, 10_000, 11],
    'a millisecond before the bell' => [9_999, 10_000, 11],
    'at the bell' => [10_000, 10_000, 10],
    'a long round, a third gone' => [20_000, 60_000, 17],
    'the shortest round at once' => [0, 5_000, 20],
]);

it('gives nothing for a wrong answer', function (int $elapsed): void {
    expect(RoomPointsRule::points(false, $elapsed, 10_000))->toBe(0);
})->with([0, 2_500, 10_000]);

it('never goes below zero or above 20', function (): void {
    foreach ([5_000, 7_000, 10_000, 25_000, 60_000] as $answer) {
        foreach ([-5_000, -1, 0, 1, 777, $answer - 1, $answer, $answer + 1, $answer * 3] as $elapsed) {
            $right = RoomPointsRule::points(true, $elapsed, $answer);

            expect($right)->toBeGreaterThanOrEqual(10)->toBeLessThanOrEqual(20)
                ->and(RoomPointsRule::points(false, $elapsed, $answer))->toBe(0);
        }

        // Before the question counts as at once, after the bell as at the bell.
        expect(RoomPointsRule::points(true, -1, $answer))->toBe(20)
            ->and(RoomPointsRule::points(true, $answer * 3, $answer))->toBe(10);
    }

    expect(fn () => RoomPointsRule::points(true, 0, 0))->toThrow(InvalidArgumentException::class);
});

it('leaves an answer all its points with no letter, half with one and nothing with two or more', function (int $points, int $bought, int $left): void {
    expect(RoomPointsRule::afterLetters($points, $bought))->toBe($left);
})->with([
    'no letter, at once' => [20, 0, 20],
    'no letter, at the bell' => [10, 0, 10],
    'one letter, at once' => [20, 1, 10],
    'one letter, 17' => [17, 1, 8],
    'one letter, 13' => [13, 1, 6],
    'one letter, 12' => [12, 1, 6],
    'one letter, at the bell' => [10, 1, 5],
    'two letters' => [20, 2, 0],
    'three letters' => [20, 3, 0],
    'every letter of eight' => [20, 8, 0],
    'a wrong answer with a letter' => [0, 1, 0],
    'a wrong answer with two' => [0, 2, 0],
]);

it('names the next letter\'s price as a word: half, then the rest, then nothing to lose', function (): void {
    expect(RoomPointsRule::letterPrice(0))->toBe(RoomLetterPrice::Half)
        ->and(RoomPointsRule::letterPrice(1))->toBe(RoomLetterPrice::Rest)
        ->and(RoomPointsRule::letterPrice(2))->toBe(RoomLetterPrice::None)
        ->and(RoomPointsRule::letterPrice(7))->toBe(RoomLetterPrice::None)
        ->and(RoomPointsRule::LETTER_DIVISOR)->toBe(2)
        ->and(RoomPointsRule::LETTERS_TO_NOTHING)->toBe(2);

    // The word says what the next letter leaves: half after the first, nothing after the second.
    foreach (range(10, 20) as $points) {
        expect(RoomPointsRule::afterLetters($points, 1))->toBe(intdiv($points, 2))
            ->and(RoomPointsRule::afterLetters($points, 2))->toBe(0);
    }

    expect(fn () => RoomPointsRule::afterLetters(20, -1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RoomPointsRule::letterPrice(-1))->toThrow(InvalidArgumentException::class);
});
