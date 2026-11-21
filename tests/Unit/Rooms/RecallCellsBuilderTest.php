<?php

use App\Domain\Content\Data\GridData;
use App\Domain\Content\Data\RangeData;
use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomLayout;
use App\Domain\Rooms\Support\Mechanics\RecallCellsBuilder;
use App\Domain\Rooms\Support\RoomJudge;
use App\Domain\Rooms\Support\RoomPlayable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * «Xanaları yadda saxla» (docs/architecture.md §12 "Room games"): some
 * cells of a grid light up, then go dark; tap the cells that were lit. The
 * question is the whole dark grid, the same whichever cells were lit: its
 * ids are its own, drawn before the lit cells are, and its order is the
 * grid's reading order. Only the study, served while it is studied, shows
 * what was lit.
 */

/**
 * @return list<BuiltRoomRoundData>
 */
function recallCellsRounds(RoomLevelData $level, int $seed, int $count = 20): array
{
    return (new RecallCellsBuilder)->build($level, $count, new Randomizer(new Mt19937($seed)));
}

/**
 * @param  list<RoomItemData>  $items
 * @return list<array{int, int}>
 */
function recallCellsPlaces(array $items): array
{
    return array_map(static fn (RoomItemData $item): array => $item->cell ?? [-1, -1], $items);
}

/**
 * The four levels of content/rooms/levels.json and the edges a level may
 * reach — one cell lit, and all but one — bound: the content is read once
 * the application has booted.
 *
 * @return array<string, array{Closure(): RoomLevelData}>
 */
function recallCellsLevels(): array
{
    $levels = [];

    foreach (range(1, 4) as $difficulty) {
        $levels["difficulty {$difficulty}"] = [fn (): RoomLevelData => app(ContentRepository::class)->roomLevel(RoomMechanic::RecallCells, $difficulty)];
    }

    $levels['one cell lit'] = [fn (): RoomLevelData => new RoomLevelData(RoomMechanic::RecallCells, 1, 1_000, 5_000, grid: new GridData(2, 2), lit: new RangeData(1, 1))];
    $levels['all but one lit'] = [fn (): RoomLevelData => new RoomLevelData(RoomMechanic::RecallCells, 4, 4_000, 30_000, grid: new GridData(6, 6), lit: new RangeData(35, 35))];
    $levels['a grid wider than it is high'] = [fn (): RoomLevelData => new RoomLevelData(RoomMechanic::RecallCells, 3, 3_000, 20_000, grid: new GridData(5, 3), lit: new RangeData(4, 6))];

    return $levels;
}

it('lights fewer cells than the grid has', function (RoomLevelData $level): void {
    $grid = $level->grid;
    $lit = $level->lit;
    $counts = [];

    foreach (range(1, 10) as $seed) {
        foreach (recallCellsRounds($level, $seed) as $round) {
            $study = $round->study;
            $places = recallCellsPlaces($study->items ?? []);
            $counts[count($places)] = true;

            expect($study)->not->toBeNull()
                ->and($study?->layout)->toBe(RoomLayout::Grid)
                ->and($study?->grid)->toBe($grid?->toArray())
                ->and(count($places))->toBeGreaterThanOrEqual($lit->min ?? 0)->toBeLessThanOrEqual($lit->max ?? 0)
                ->and(count($places))->toBeLessThan($grid?->cells() ?? 0)
                ->and($round->studyMs)->toBe($level->studyMs)
                ->and($round->answerMs)->toBe($level->answerMs);

            // Cells of the grid, each once, in reading order; nothing but a cell.
            $sorted = $places;
            usort($sorted, static fn (array $a, array $b): int => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

            expect($places)->toBe($sorted)
                ->and(array_unique(array_map(json_encode(...), $places)))->toHaveCount(count($places));

            foreach ($study->items ?? [] as $item) {
                expect($item->kind)->toBe(RoomItemKind::Cell)
                    ->and($item->text)->toBeNull()
                    ->and($item->asset)->toBeNull()
                    ->and($item->cell[0] ?? -1)->toBeGreaterThanOrEqual(0)->toBeLessThan($grid->cols ?? 0)
                    ->and($item->cell[1] ?? -1)->toBeGreaterThanOrEqual(0)->toBeLessThan($grid->rows ?? 0);
            }
        }
    }

    // Every count the level allows comes up.
    expect(array_keys($counts))->toEqualCanonicalizing(range($lit->min ?? 0, $lit->max ?? 0));
})->with(recallCellsLevels());

it('asks for exactly the lit cells', function (RoomLevelData $level): void {
    $grid = $level->grid;
    $everyCell = [];

    for ($row = 0; $row < ($grid->rows ?? 0); $row++) {
        for ($col = 0; $col < ($grid->cols ?? 0); $col++) {
            $everyCell[] = [$col, $row];
        }
    }

    foreach (range(1, 10) as $seed) {
        foreach (recallCellsRounds($level, $seed) as $round) {
            $study = $round->study->items ?? [];
            $ask = $round->ask;
            $solution = $round->solution;
            $studyIds = array_map(static fn (RoomItemData $item): string => $item->id, $study);
            $askIds = $ask->ids();
            $byId = array_combine($askIds, recallCellsPlaces($ask->items));

            // The question is the whole dark grid in reading order, asking for as many as were lit.
            expect($ask->shape)->toBe(RoomAnswerShape::Set)
                ->and($ask->layout)->toBe(RoomLayout::Grid)
                ->and($ask->grid)->toBe($grid?->toArray())
                ->and($ask->pick)->toBe(count($study))
                ->and(recallCellsPlaces($ask->items))->toBe($everyCell);

            foreach ($ask->items as $item) {
                expect($item->kind)->toBe(RoomItemKind::Cell)
                    ->and($item->text)->toBeNull()
                    ->and($item->asset)->toBeNull();
            }

            // Ids: 8 characters, none twice, none of the study's in the question.
            foreach ([...$studyIds, ...$askIds] as $id) {
                expect($id)->toMatch('/^[a-z0-9]{8}$/');
            }

            expect(array_unique([...$studyIds, ...$askIds]))->toHaveCount(count($study) + count($everyCell))
                ->and(array_intersect($studyIds, $askIds))->toBe([]);

            // The solution: the question's ids of exactly the lit cells.
            expect($solution->shape)->toBe(RoomAnswerShape::Set)
                ->and($solution->accept)->toBe([])
                ->and(array_map(static fn (string $id): array => $byId[$id], $solution->ids))->toBe(recallCellsPlaces($study))
                ->and(RoomJudge::correct($solution, $ask->items, $solution->ids))->toBeTrue()
                ->and(RoomJudge::correct($solution, $ask->items, array_reverse($solution->ids)))->toBeTrue();

            // One cell off is wrong, one too few is wrong.
            $dark = array_values(array_diff($askIds, $solution->ids));
            $swapped = [...array_slice($solution->ids, 1), $dark[0]];

            expect(RoomJudge::correct($solution, $ask->items, $swapped))->toBeFalse()
                ->and(RoomJudge::correct($solution, $ask->items, $dark))->toBeFalse();

            if (count($solution->ids) > 1) {
                expect(RoomJudge::correct($solution, $ask->items, array_slice($solution->ids, 1)))->toBeFalse();
            }

            // Nothing a player may see holds the solution.
            $payload = $round->payload();

            expect(array_keys($payload))->toBe(['study', 'ask'])
                ->and(array_keys($payload['ask']))->toBe(['shape', 'pick', 'layout', 'grid', 'shown', 'items', 'clue', 'orders'])
                ->and($payload['ask']['shown'])->toBe([])
                // ADR 037: an anagram's clue and orders, none here.
                ->and($payload['ask']['clue'])->toBeNull()
                ->and($payload['ask']['orders'])->toBe([]);
        }
    }
})->with(recallCellsLevels());

it('asks the same question whichever cells were lit', function (): void {
    $level = app(ContentRepository::class)->roomLevel(RoomMechanic::RecallCells, 2);
    $questions = [];
    $lit = [];

    // One round from each seed: the question's ids come from the seed, the
    // rest of it — the cells, their order, the layout — never changes, and
    // nothing in it follows the lit cells.
    foreach (range(1, 200) as $seed) {
        $round = recallCellsRounds($level, $seed, 1)[0];
        $ask = $round->ask->toArray();
        $questions[json_encode([$ask['shape'], $ask['layout'], $ask['grid'], array_map(static fn (array $item): array => [$item['kind'], $item['text'], $item['asset'], $item['cell']], $ask['items'])])] = true;
        $lit[json_encode(recallCellsPlaces($round->study->items ?? []))] = true;

        // The ids sorted are the lit cells' only by chance.
        $sorted = $round->ask->ids();
        sort($sorted);
        expect(array_slice($sorted, 0, $round->ask->pick))->not->toBe($round->solution->ids);
    }

    expect($questions)->toHaveCount(1)
        ->and(count($lit))->toBeGreaterThan(150);

    // The same seed with a level that lights other cells draws the same question ids.
    $one = recallCellsRounds(new RoomLevelData(RoomMechanic::RecallCells, 1, 1_000, 5_000, grid: new GridData(4, 4), lit: new RangeData(2, 2)), 42, 1)[0];
    $other = recallCellsRounds(new RoomLevelData(RoomMechanic::RecallCells, 1, 1_000, 5_000, grid: new GridData(4, 4), lit: new RangeData(9, 9)), 42, 1)[0];

    expect($one->ask->ids())->toBe($other->ask->ids())
        ->and($one->solution->ids)->not->toBe($other->solution->ids);
});

it('builds the same rounds from the same seed', function (): void {
    $level = app(ContentRepository::class)->roomLevel(RoomMechanic::RecallCells, 3);
    $first = recallCellsRounds($level, 482_913);

    expect(recallCellsRounds($level, 482_913))->toEqual($first)
        ->and(recallCellsRounds($level, 482_914))->not->toEqual($first)
        ->and($first)->toHaveCount(20)
        ->and(RoomPlayable::builder(RoomMechanic::RecallCells))->toBeInstanceOf(RecallCellsBuilder::class);
});

it('builds only a level of its own with a cell left dark', function (RoomLevelData $level): void {
    expect(fn () => recallCellsRounds($level, 1))->toThrow(InvalidArgumentException::class);
})->with([
    'no grid' => [new RoomLevelData(RoomMechanic::RecallCells, 1, 1_000, 5_000, lit: new RangeData(1, 2))],
    'nothing lit' => [new RoomLevelData(RoomMechanic::RecallCells, 1, 1_000, 5_000, grid: new GridData(3, 3))],
    'no cell to light' => [new RoomLevelData(RoomMechanic::RecallCells, 1, 1_000, 5_000, grid: new GridData(3, 3), lit: new RangeData(0, 2))],
    'every cell lit' => [new RoomLevelData(RoomMechanic::RecallCells, 1, 1_000, 5_000, grid: new GridData(3, 3), lit: new RangeData(8, 9))],
    'nothing to study' => [new RoomLevelData(RoomMechanic::RecallCells, 1, 0, 5_000, grid: new GridData(3, 3), lit: new RangeData(2, 3))],
]);
