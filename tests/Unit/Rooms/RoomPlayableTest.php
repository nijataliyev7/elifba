<?php

use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomGameKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\ContentValidator;
use App\Domain\Rooms\Support\RoomPlayable;
use App\Domain\Rooms\Support\RoomSeed;
use Illuminate\Support\Facades\Config;

/*
 * What a room can play (docs/architecture.md §12 "Room games", "Room
 * content"): the validator, which may not name Rooms, gives a mechanic all
 * four difficulties or none; this test gives every mechanic RoomPlayable
 * lists its levels, so it plays at every difficulty, and a builder. A board
 * game (D3, ADR 034 decision 6) has two to four levels, each its clock,
 * and builds no rounds; so has a domino game (Y5, ADR 038 decision 22),
 * each level a turn's time.
 */

/**
 * The mechanics a room plays of one kind.
 *
 * @return list<RoomMechanic>
 */
function playableOf(RoomGameKind $kind): array
{
    return array_values(array_filter(RoomPlayable::mechanics(), static fn (RoomMechanic $mechanic): bool => $mechanic->kind() === $kind));
}

/**
 * @return list<int>
 */
function playableDifficulties(RoomMechanic $mechanic): array
{
    $difficulties = array_map(
        static fn (RoomLevelData $level): int => $level->difficulty,
        array_values(array_filter(app(ContentRepository::class)->roomLevels(), static fn (RoomLevelData $level): bool => $level->mechanic === $mechanic)),
    );
    sort($difficulties);

    return $difficulties;
}

it('lists only mechanics with all four levels', function (): void {
    expect(playableOf(RoomGameKind::Race))->not->toBeEmpty();

    foreach (playableOf(RoomGameKind::Race) as $mechanic) {
        expect(playableDifficulties($mechanic))->toBe(range(1, ContentValidator::ROOM_DIFFICULTIES), $mechanic->value)
            ->and(RoomPlayable::plays($mechanic))->toBeTrue();
    }
});

it('lists a board game with its clocks, two to four of them from 1, each shorter than the one before', function (): void {
    expect(playableOf(RoomGameKind::Board))->toBe([RoomMechanic::Draughts]);

    foreach (playableOf(RoomGameKind::Board) as $mechanic) {
        $difficulties = playableDifficulties($mechanic);
        $clocks = array_map(static fn (int $difficulty): ?int => app(ContentRepository::class)->roomLevel($mechanic, $difficulty)->clockMs, $difficulties);
        $faster = $clocks;
        rsort($faster);

        expect($difficulties)->toBe(range(1, count($difficulties)))
            ->and(count($difficulties))->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(ContentValidator::ROOM_DIFFICULTIES)
            ->and($clocks)->each->toBeInt()
            ->and($clocks)->toBe(array_values(array_unique($clocks)))
            ->and($clocks)->toBe($faster);
    }

    // 10+5, 5+3, 3+2 (ADR 034 decision 6).
    expect(array_map(static fn (int $difficulty): array => [app(ContentRepository::class)->roomLevel(RoomMechanic::Draughts, $difficulty)->clockMs, app(ContentRepository::class)->roomLevel(RoomMechanic::Draughts, $difficulty)->incrementMs], [1, 2, 3]))
        ->toBe([[600_000, 5_000], [300_000, 3_000], [180_000, 2_000]]);
});

it('offers «101», with its turns, two to four of them from 1, each shorter than the one before', function (): void {
    // Y6 draws it and lists it (ADR 038 decision 73): every instance offers it.
    expect(RoomPlayable::plays(RoomMechanic::Domino101))->toBeTrue()
        ->and(playableOf(RoomGameKind::Domino))->toBe([RoomMechanic::Domino101]);

    $difficulties = playableDifficulties(RoomMechanic::Domino101);
    $turns = array_map(static fn (int $difficulty): ?int => app(ContentRepository::class)->roomLevel(RoomMechanic::Domino101, $difficulty)->turnMs, $difficulties);

    // 60, 30 and 15 seconds (ADR 038 decision 22); no clock, no rounds.
    expect($difficulties)->toBe([1, 2, 3])
        ->and($turns)->toBe([60_000, 30_000, 15_000])
        ->and(app(ContentRepository::class)->roomLevel(RoomMechanic::Domino101, 2)->clockMs)->toBeNull()
        ->and(app(ContentRepository::class)->roomLevel(RoomMechanic::Domino101, 2)->answerMs)->toBe(0);
});

it('has a builder for every race, and none for a board game or a domino game', function (): void {
    foreach (RoomMechanic::cases() as $mechanic) {
        if ($mechanic->kind() === RoomGameKind::Race) {
            expect(RoomPlayable::builder($mechanic))->not->toBeNull();
        } else {
            expect(fn () => RoomPlayable::builder($mechanic))->toThrow(InvalidArgumentException::class);
        }
    }

    expect(RoomPlayable::mechanics())->toBe([RoomMechanic::RecallOrder, RoomMechanic::RecallCells, RoomMechanic::NextInSequence, RoomMechanic::OddOneOut, RoomMechanic::Anagram, RoomMechanic::Spelling, RoomMechanic::Draughts, RoomMechanic::Domino101]);
});

// The validator may not reach a builder's arithmetic — whether a rule of
// `next_in_sequence` fits its range, say — so every level of the content
// is built here, a full game of the most rounds from several seeds.
it('builds a game at every level of every mechanic it lists', function (): void {
    $content = app(ContentRepository::class);

    foreach (playableOf(RoomGameKind::Race) as $mechanic) {
        foreach (range(1, ContentValidator::ROOM_DIFFICULTIES) as $difficulty) {
            $level = $content->roomLevel($mechanic, $difficulty);

            foreach (range(1, 5) as $seed) {
                $rounds = RoomPlayable::builder($mechanic)->build($level, Config::integer('elifba.rooms.rounds.max'), RoomSeed::random($seed));

                expect($rounds)->toHaveCount(Config::integer('elifba.rooms.rounds.max'));

                foreach ($rounds as $round) {
                    expect($round->studyMs)->toBe($level->studyMs)
                        ->and($round->study === null)->toBe(! $mechanic->studies());
                }
            }
        }
    }
});
