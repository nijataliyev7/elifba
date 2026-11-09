<?php

use App\Domain\Play\Support\PointsRule;

it('gives 3 with no wrong hit, 2 with one and 1 with more', function (int $misses, int $points): void {
    expect(PointsRule::for($misses))->toBe($points);
})->with([
    'no miss' => [0, 3],
    'one miss' => [1, 2],
    'two misses' => [2, 1],
    'many misses' => [17, 1],
]);

it('never gives more than the maximum and never less than one', function (): void {
    foreach (range(0, 50) as $misses) {
        expect(PointsRule::for($misses))->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(PointsRule::MAX);
    }
});

it('refuses negative misses', function (): void {
    PointsRule::for(-1);
})->throws(InvalidArgumentException::class);
