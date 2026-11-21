<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomLetterPrice;
use InvalidArgumentException;

/**
 * The letters the viewer bought in the round open for answers
 * (docs/architecture.md §12 "«Anaqram»: a clue, a shuffle and a bought
 * letter (ADR 037)", ADR 037 decisions 14–16; zod `RoomLetters`), served
 * only in the buyer's own state, in the phase `answer`, for a present
 * player of a round that sells letters — null for everyone else, the
 * screen included:
 *
 * - `bought`: the solution's first k ids, in the drawn word's order — the
 *   one exception to "no solution before the close";
 * - `orders`: the buyer's own orders of the tiles left, which «Qarışdır»
 *   cycles after a purchase; `[]` while nothing is bought (the question's
 *   `orders` serve) and once every letter is (no tile is left);
 * - `price`: what the next letter costs, as a word
 *   (`RoomPointsRule::letterPrice()`); null once every letter is bought.
 *
 * @phpstan-type RoomLettersArray array{bought: list<string>, orders: list<list<string>>, price: string|null}
 */
final readonly class RoomLettersData
{
    /**
     * @param  list<string>  $bought
     * @param  list<list<string>>  $orders
     */
    public function __construct(
        public array $bought,
        public array $orders,
        public ?RoomLetterPrice $price,
    ) {
        if ($bought === [] && $orders !== []) {
            throw new InvalidArgumentException('Before a purchase the question\'s orders serve.');
        }

        if ($bought === [] && $price !== RoomLetterPrice::Half) {
            throw new InvalidArgumentException('The first letter leaves half.');
        }

        foreach ($orders as $order) {
            if (array_intersect($order, $bought) !== [] || count(array_unique($order)) !== count($order)) {
                throw new InvalidArgumentException('An order lays out the tiles left, each once.');
            }
        }
    }

    /**
     * @param  RoomLettersArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['bought'], $data['orders'], $data['price'] === null ? null : RoomLetterPrice::from($data['price']));
    }

    /**
     * @return RoomLettersArray
     */
    public function toArray(): array
    {
        return [
            'bought' => $this->bought,
            'orders' => $this->orders,
            'price' => $this->price?->value,
        ];
    }
}
