<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomLayout;
use App\Domain\Rooms\Support\Mechanics\AnagramOrders;
use InvalidArgumentException;

/**
 * A room round's question (docs/architecture.md §12 "The state",
 * `RoomRound.ask`), stored in `room_rounds.payload.ask` and served from the
 * phase `answer`: the answer's shape, how many items to pick, the layout,
 * what is shown with the question and cannot be picked (`shown`, B5: the
 * row a `next_in_sequence` round continues; empty for every other
 * mechanic) and the items an answer is made of (`items`, all that
 * `ids()` gives and `RecordRoomAnswer` accepts). Never the solution: for a
 * `sequence`, the items are never in the order to tap; for a `choice`, the
 * right one's place among them is drawn. A round stored before B5 has no
 * `shown` and reads as `[]`.
 *
 * From ADR 037 an anagram's question also carries its word's clue
 * (`clue`, as `content/rooms/clues.json` writes it) and the orders a
 * phone's «Qarışdır» cycles through (`orders`, `AnagramOrders`): lists of
 * the items' ids, each every item once, the first the order of `items`, no
 * two reading alike. Every other mechanic, and an anagram round stored
 * before them, has `clue` null and `orders` `[]`.
 *
 * @phpstan-import-type RoomItemArray from RoomItemData
 * @phpstan-import-type RoomGridArray from RoomStudyData
 *
 * @phpstan-type RoomAskArray array{shape: string, pick: int, layout: string, grid: RoomGridArray|null, shown?: list<RoomItemArray>, items: list<RoomItemArray>, clue?: string|null, orders?: list<list<string>>}
 */
final readonly class RoomAskData
{
    /**
     * @param  int  $pick  how many items an answer holds: 1 for a choice, the row's length for a sequence
     * @param  RoomGridArray|null  $grid
     * @param  list<RoomItemData>  $items  what an answer is made of
     * @param  list<RoomItemData>  $shown  shown with the question, never picked
     * @param  string|null  $clue  an anagram's clue (ADR 037)
     * @param  list<list<string>>  $orders  an anagram's orders of the items' ids, the first theirs (ADR 037)
     */
    public function __construct(
        public RoomAnswerShape $shape,
        public int $pick,
        public RoomLayout $layout,
        public ?array $grid,
        public array $items,
        public array $shown = [],
        public ?string $clue = null,
        public array $orders = [],
    ) {}

    /**
     * @param  RoomAskArray  $data
     */
    public static function fromArray(array $data): self
    {
        $ask = new self(
            RoomAnswerShape::from($data['shape']),
            $data['pick'],
            RoomLayout::from($data['layout']),
            $data['grid'],
            array_map(RoomItemData::fromArray(...), $data['items']),
            array_map(RoomItemData::fromArray(...), $data['shown'] ?? []),
            $data['clue'] ?? null,
            $data['orders'] ?? [],
        );

        $ask->checkOrders();

        return $ask;
    }

    /**
     * The ids an answer may pick: the items', never the shown ones'.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        return array_map(static fn (RoomItemData $item): string => $item->id, $this->items);
    }

    /**
     * The orders as the contract has them, as zod checks them too: none or
     * at most `AnagramOrders::MAX`; each every item once; the first the
     * order of `items`; no two reading alike.
     *
     * @throws InvalidArgumentException
     */
    private function checkOrders(): void
    {
        if ($this->orders === []) {
            return;
        }

        $ids = $this->ids();
        $says = [];

        foreach ($this->items as $item) {
            $says[$item->id] = $item->says();
        }

        if (count($this->orders) > AnagramOrders::MAX || $this->orders[0] !== $ids) {
            throw new InvalidArgumentException('A question\'s orders are at most '.AnagramOrders::MAX.', the first its items\' own.');
        }

        $sorted = $ids;
        sort($sorted);
        $readings = [];

        foreach ($this->orders as $order) {
            $copy = $order;
            sort($copy);

            if ($copy !== $sorted) {
                throw new InvalidArgumentException('Each of a question\'s orders holds every item once.');
            }

            $readings[] = implode("\0", array_map(static fn (string $id): string => $says[$id], $order));
        }

        if (count(array_unique($readings)) !== count($readings)) {
            throw new InvalidArgumentException('No two of a question\'s orders read alike.');
        }
    }

    /**
     * @return RoomAskArray
     */
    public function toArray(): array
    {
        return [
            'shape' => $this->shape->value,
            'pick' => $this->pick,
            'layout' => $this->layout->value,
            'grid' => $this->grid,
            'shown' => array_map(static fn (RoomItemData $item): array => $item->toArray(), $this->shown),
            'items' => array_map(static fn (RoomItemData $item): array => $item->toArray(), $this->items),
            'clue' => $this->clue,
            'orders' => $this->orders,
        ];
    }
}
