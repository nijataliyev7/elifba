<?php

use App\Domain\Content\Data\RangeData;
use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Data\RoomShapeData;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\RoomWords;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomLayout;
use App\Domain\Rooms\Support\Mechanics\RecallOrderBuilder;
use App\Domain\Rooms\Support\RoomJudge;
use App\Domain\Rooms\Support\RoomPlayable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * «Ardıcıllığı yadda saxla» (docs/architecture.md §12 "Room games"): a row
 * of digits, letters, words or shapes to study, then the same items shuffled, to tap in
 * the order shown. The question never comes in the order shown, its ids
 * are not the row's and carry no position, and the seed alone decides
 * everything.
 */

/**
 * @return list<BuiltRoomRoundData>
 */
function recallOrderRounds(RoomLevelData $level, int $seed, int $count = 20): array
{
    return (new RecallOrderBuilder(app(ContentRepository::class)->roomShapes()))->build($level, $count, new Randomizer(new Mt19937($seed)));
}

/**
 * @param  list<RoomItemData>  $items
 * @return list<string>
 */
function recallOrderReading(array $items): array
{
    return array_map(static fn (RoomItemData $item): string => $item->says(), $items);
}

/**
 * The four levels of content/rooms/levels.json and the shortest row there
 * can be, bound: the content is read once the application has booted.
 *
 * @return array<string, array{Closure(): RoomLevelData}>
 */
function recallOrderLevels(): array
{
    $levels = [];

    foreach (range(1, 4) as $difficulty) {
        $levels["difficulty {$difficulty}"] = [fn (): RoomLevelData => app(ContentRepository::class)->roomLevel(RoomMechanic::RecallOrder, $difficulty)];
    }

    $levels['the shortest row'] = [fn (): RoomLevelData => new RoomLevelData(RoomMechanic::RecallOrder, 1, 2_000, 5_000, [RoomItemKind::Digit, RoomItemKind::Letter, RoomItemKind::Shape], new RangeData(3, 3))];
    $levels['every shape'] = [fn (): RoomLevelData => new RoomLevelData(RoomMechanic::RecallOrder, 1, 2_000, 5_000, [RoomItemKind::Shape], new RangeData(12, 12))];

    return $levels;
}

it('shows one kind per round within the level\'s length', function (RoomLevelData $level): void {
    $pools = [
        RoomItemKind::Digit->value => array_map(strval(...), range(0, 9)),
        RoomItemKind::Letter->value => array_map(Az::upper(...), Alphabet::letters()),
        RoomItemKind::Shape->value => array_map(static fn (RoomShapeData $shape): string => $shape->url(), app(ContentRepository::class)->roomShapes()),
    ];
    $kinds = [];

    foreach (range(1, 10) as $seed) {
        foreach (recallOrderRounds($level, $seed) as $round) {
            $study = $round->study;
            $row = recallOrderReading($study->items ?? []);
            $kind = $study?->items[0]->kind;
            $kinds[$kind?->value] = true;

            expect($study)->not->toBeNull()
                ->and($study?->layout)->toBe(RoomLayout::Row)
                ->and($study?->grid)->toBeNull()
                ->and(count($row))->toBeGreaterThanOrEqual($level->length->min ?? 0)->toBeLessThanOrEqual($level->length->max ?? 0)
                ->and($level->kinds)->toContain($kind)
                ->and(array_unique(array_map(static fn (RoomItemData $item): RoomItemKind => $item->kind, [...$study->items ?? [], ...$round->ask->items]), SORT_REGULAR))->toBe([$kind])
                ->and(array_diff($row, $pools[$kind?->value] ?? []))->toBe([])
                ->and($round->studyMs)->toBe($level->studyMs)
                ->and($round->answerMs)->toBe($level->answerMs);

            // The question is the same items; no item comes twice in a row.
            $asked = recallOrderReading($round->ask->items);
            sort($asked);
            $sorted = $row;
            sort($sorted);

            expect($asked)->toBe($sorted)
                ->and($round->ask->shape)->toBe(RoomAnswerShape::Sequence)
                ->and($round->ask->pick)->toBe(count($row))
                ->and($round->ask->layout)->toBe(RoomLayout::Row);

            expect(array_unique($row))->toBe($row, implode(' ', $row));
        }
    }

    // Every kind the level names comes up.
    expect(array_keys($kinds))->toEqualCanonicalizing(array_map(static fn (RoomItemKind $kind): string => $kind->value, $level->kinds));
})->with(recallOrderLevels());

it('never asks in the order shown', function (RoomLevelData $level): void {
    $rounds = 0;
    $sortedIntoTheRow = 0;

    foreach (range(1, 10) as $seed) {
        foreach (recallOrderRounds($level, $seed) as $round) {
            $rounds++;
            $study = $round->study->items ?? [];
            $ask = $round->ask->items;
            $row = recallOrderReading($study);
            $studyIds = array_map(static fn (RoomItemData $item): string => $item->id, $study);
            $askIds = $round->ask->ids();
            $solution = $round->solution;

            // Not in the order shown, whatever two tiles say the same.
            expect(recallOrderReading($ask))->not->toBe($row, implode(' ', $row));

            // Ids: 8 characters, none twice, none of the row's in the question.
            foreach ([...$studyIds, ...$askIds] as $id) {
                expect($id)->toMatch('/^[a-z0-9]{8}$/');
            }

            expect(array_unique([...$studyIds, ...$askIds]))->toHaveCount(2 * count($row))
                ->and(array_intersect($studyIds, $askIds))->toBe([]);

            // The solution: the question's ids in the row's order, the row as its reading.
            $byId = array_combine($askIds, recallOrderReading($ask));

            expect($solution->shape)->toBe(RoomAnswerShape::Sequence)
                ->and($solution->ids)->toEqualCanonicalizing($askIds)
                ->and(array_map(static fn (string $id): string => $byId[$id], $solution->ids))->toBe($row)
                ->and($solution->accept)->toBe([$row])
                ->and($solution->ids)->not->toBe($askIds)
                ->and(RoomJudge::correct($solution, $ask, $solution->ids))->toBeTrue()
                ->and(RoomJudge::correct($solution, $ask, $askIds))->toBeFalse();

            // Nothing a player may see holds the solution.
            $payload = $round->payload();

            expect(array_keys($payload))->toBe(['study', 'ask'])
                ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('accept')
                ->and(array_keys($payload['ask']))->toBe(['shape', 'pick', 'layout', 'grid', 'shown', 'items', 'clue', 'orders'])
                ->and($payload['ask']['shown'])->toBe([])
                // ADR 037: an anagram's clue and orders, none here.
                ->and($payload['ask']['clue'])->toBeNull()
                ->and($payload['ask']['orders'])->toBe([]);

            // The ids carry no position: sorted, they are the order to tap only by chance.
            $sorted = $askIds;
            sort($sorted);
            $sortedIntoTheRow += $sorted === $solution->ids ? 1 : 0;
        }
    }

    expect($sortedIntoTheRow)->toBeLessThan(intdiv($rounds, 2) + 1);
})->with(recallOrderLevels());

it('shows a shape as its picture, with no text, and judges a row of shapes by the pictures', function (): void {
    $level = new RoomLevelData(RoomMechanic::RecallOrder, 2, 4_500, 15_000, [RoomItemKind::Shape], new RangeData(5, 6));

    foreach (recallOrderRounds($level, 7) as $round) {
        foreach ([...$round->study->items ?? [], ...$round->ask->items] as $item) {
            expect($item->kind)->toBe(RoomItemKind::Shape)
                ->and($item->text)->toBeNull()
                ->and($item->cell)->toBeNull()
                ->and($item->asset)->toMatch('#/content/rooms/[a-z]+\.svg\?v=\d+$#');
        }

        $solution = $round->solution;

        expect(RoomJudge::correct($solution, $round->ask->items, $solution->ids))->toBeTrue()
            ->and(RoomJudge::correct($solution, $round->ask->items, [...array_slice($solution->ids, 1), $solution->ids[0]]))->toBeFalse()
            ->and(RoomJudge::correct($solution, $round->ask->items, array_reverse($solution->ids)))->toBeFalse();
    }
});

it('shows a row of short words of the list, as the list writes them', function (): void {
    // B6: words may be a kind of row; no level names them yet (§12, B6 "As built").
    $content = app(ContentRepository::class);
    $short = RoomWords::of($content->roomWords(), $content->names()->names)->forRow();
    $level = new RoomLevelData(RoomMechanic::RecallOrder, 2, 4_500, 15_000, [RoomItemKind::Word], new RangeData(5, 6));
    $rounds = RoomPlayable::builder(RoomMechanic::RecallOrder)->build($level, 20, new Randomizer(new Mt19937(11)));

    foreach ($rounds as $round) {
        $row = recallOrderReading($round->study->items ?? []);

        expect(array_diff($row, $short))->toBe([])
            ->and(array_unique($row))->toBe($row)
            ->and(recallOrderReading($round->ask->items))->not->toBe($row)
            ->and(RoomJudge::correct($round->solution, $round->ask->items, $round->solution->ids))->toBeTrue();

        foreach ([...$round->study->items ?? [], ...$round->ask->items] as $item) {
            expect($item->kind)->toBe(RoomItemKind::Word)
                ->and(mb_strlen((string) $item->text))->toBeLessThanOrEqual(RoomWords::ROW_LETTERS)
                ->and(Az::lower((string) $item->text))->toBe($item->text);
        }
    }

    expect(RoomMechanic::RecallOrder->kinds())->toContain(RoomItemKind::Word);
});

it('builds the same rounds from the same seed', function (): void {
    $level = app(ContentRepository::class)->roomLevel(RoomMechanic::RecallOrder, 3);
    $first = recallOrderRounds($level, 482_913);

    expect(recallOrderRounds($level, 482_913))->toEqual($first)
        ->and(recallOrderRounds($level, 482_914))->not->toEqual($first)
        ->and($first)->toHaveCount(20)
        ->and(recallOrderRounds($level, 1, 5))->toHaveCount(5);

    // The same rows, ids and order, as stored.
    expect(array_map(static fn (BuiltRoomRoundData $round): array => [$round->payload(), $round->solution->toArray()], recallOrderRounds($level, 482_913)))
        ->toBe(array_map(static fn (BuiltRoomRoundData $round): array => [$round->payload(), $round->solution->toArray()], $first));
});

it('builds only a level of its own with rows it can shuffle', function (RoomLevelData $level): void {
    expect(fn () => recallOrderRounds($level, 1))->toThrow(InvalidArgumentException::class);
})->with([
    'a row of one' => [new RoomLevelData(RoomMechanic::RecallOrder, 1, 2_000, 5_000, [RoomItemKind::Digit], new RangeData(1, 3))],
    // Asked in another order, two items could only be reversed: the question would be the answer.
    'a row of two' => [new RoomLevelData(RoomMechanic::RecallOrder, 1, 2_000, 5_000, [RoomItemKind::Digit], new RangeData(2, 3))],
    'more digits than there are' => [new RoomLevelData(RoomMechanic::RecallOrder, 1, 2_000, 5_000, [RoomItemKind::Digit], new RangeData(11, 11))],
    'no length' => [new RoomLevelData(RoomMechanic::RecallOrder, 1, 2_000, 5_000, [RoomItemKind::Digit])],
    'nothing to study' => [new RoomLevelData(RoomMechanic::RecallOrder, 1, 0, 5_000, [RoomItemKind::Digit], new RangeData(3, 4))],
    // `recallOrderRounds()` gives the builder the shapes and no words.
    'words without the word list' => [new RoomLevelData(RoomMechanic::RecallOrder, 1, 2_000, 5_000, [RoomItemKind::Word], new RangeData(3, 4))],
]);
