<?php

namespace App\Domain\Play\Data;

use App\Domain\Content\Enums\Effect;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;

/**
 * The round contract (docs/architecture.md §2, ADR 003): one shape for the
 * server, the board and the phone. `resources/js/game/contract.ts` has the
 * same shape as the zod schema `Round`; both are pinned to
 * `tests/Fixtures/contract/round.*.json`. Properties are named like their
 * JSON keys, so `toArray()` is a plain mapping. A change here changes the
 * schema and the fixtures in the same commit.
 *
 * `game_rounds.payload` stores the round without `id`, `index` and `total`
 * (`payload()`), which come from the row and its game.
 *
 * @phpstan-import-type PromptArray from PromptData
 * @phpstan-import-type StoredPromptArray from PromptData
 * @phpstan-import-type RoundItemArray from RoundItemData
 * @phpstan-import-type SolutionArray from SolutionData
 *
 * @phpstan-type RoundPayload array{mechanic: string, unit: string, pack: string, level: string, prompt: PromptArray, items: list<RoundItemArray>, slots: int, solution: SolutionArray, effect: string}
 * @phpstan-type StoredRoundPayload array{mechanic: string, unit: string, pack: string, level: string, prompt: StoredPromptArray, items: list<RoundItemArray>, slots: int, solution: SolutionArray, effect: string}
 * @phpstan-type RoundArray array{id: string, index: int, total: int, mechanic: string, unit: string, pack: string, level: string, prompt: PromptArray, items: list<RoundItemArray>, slots: int, solution: SolutionArray, effect: string}
 */
final readonly class RoundData
{
    /**
     * @param  string  $id  game_rounds.id
     * @param  int  $index  0-based position
     * @param  int  $total  rounds in the game
     * @param  list<RoundItemData>  $items  display order
     * @param  int  $slots  0 for find; the solution length for build mechanics
     */
    public function __construct(
        public string $id,
        public int $index,
        public int $total,
        public Mechanic $mechanic,
        public Unit $unit,
        public string $pack,
        public string $level,
        public PromptData $prompt,
        public array $items,
        public int $slots,
        public SolutionData $solution,
        public Effect $effect,
    ) {}

    /**
     * @param  RoundArray  $data
     */
    public static function fromArray(array $data): self
    {
        return self::fromPayload($data['id'], $data['index'], $data['total'], $data);
    }

    /**
     * A stored snapshot with the row's id, position and the game's round
     * count. A snapshot stored before R5 has no `prompt.count` (read as null).
     *
     * @param  StoredRoundPayload  $payload
     */
    public static function fromPayload(string $id, int $index, int $total, array $payload): self
    {
        return new self(
            id: $id,
            index: $index,
            total: $total,
            mechanic: Mechanic::from($payload['mechanic']),
            unit: Unit::from($payload['unit']),
            pack: $payload['pack'],
            level: $payload['level'],
            prompt: PromptData::fromArray($payload['prompt']),
            items: array_map(RoundItemData::fromArray(...), $payload['items']),
            slots: $payload['slots'],
            solution: SolutionData::fromArray($payload['solution']),
            effect: Effect::from($payload['effect']),
        );
    }

    /**
     * @return RoundArray
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'index' => $this->index,
            'total' => $this->total,
            ...$this->payload(),
        ];
    }

    /**
     * The snapshot as `game_rounds.payload` stores it.
     *
     * @return RoundPayload
     */
    public function payload(): array
    {
        return [
            'mechanic' => $this->mechanic->value,
            'unit' => $this->unit->value,
            'pack' => $this->pack,
            'level' => $this->level,
            'prompt' => $this->prompt->toArray(),
            'items' => array_map(static fn (RoundItemData $item): array => $item->toArray(), $this->items),
            'slots' => $this->slots,
            'solution' => $this->solution->toArray(),
            'effect' => $this->effect->value,
        ];
    }

    public function item(string $id): ?RoundItemData
    {
        foreach ($this->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function itemIds(): array
    {
        return array_map(static fn (RoundItemData $item): string => $item->id, $this->items);
    }
}
