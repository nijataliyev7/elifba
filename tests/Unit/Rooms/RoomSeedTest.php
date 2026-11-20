<?php

use App\Domain\Content\Enums\RoomGameKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Support\RoomPlayable;
use App\Domain\Rooms\Support\RoomSeed;
use Illuminate\Support\Facades\Config;

/*
 * A room game's random source (docs/architecture.md §12 "The race"): the
 * same seed tells the same rounds on the same instance, and without the
 * instance's key the seed alone tells nothing.
 */

/**
 * @return list<array<string, mixed>>
 */
function seededRounds(int $seed, RoomMechanic $mechanic = RoomMechanic::RecallOrder): array
{
    $level = app(ContentRepository::class)->roomLevel($mechanic, 2);

    return array_map(
        static fn (BuiltRoomRoundData $round): array => [$round->payload(), $round->solution->toArray()],
        RoomPlayable::builder($mechanic)->build($level, 5, RoomSeed::random($seed)),
    );
}

it('tells the same rounds from the same seed, and other rounds under another key', function (RoomMechanic $mechanic): void {
    Config::set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    $rounds = seededRounds(1234, $mechanic);

    expect(seededRounds(1234, $mechanic))->toBe($rounds)
        ->and(seededRounds(1235, $mechanic))->not->toBe($rounds);

    Config::set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));
    expect(seededRounds(1234, $mechanic))->not->toBe($rounds);
})->with(array_values(array_filter(RoomMechanic::cases(), static fn (RoomMechanic $mechanic): bool => $mechanic->kind() === RoomGameKind::Race)));

it('draws new seeds within the column', function (): void {
    foreach (range(1, 50) as $_) {
        expect(RoomSeed::fresh())->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(2_147_483_647);
    }
});
