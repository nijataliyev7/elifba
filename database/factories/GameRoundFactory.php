<?php

namespace Database\Factories;

use App\Domain\Content\Enums\Effect;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Enums\SolutionOrder;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open `find` round of four letters (the first four of the alphabet),
 * the second one the target, appended to its game.
 *
 * @extends Factory<GameRound>
 */
class GameRoundFactory extends Factory
{
    /**
     * @var class-string<GameRound>
     */
    protected $model = GameRound::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $letters = array_slice(Alphabet::letters(), 0, 4);
        $items = [];

        foreach ($letters as $index => $letter) {
            $items[] = ['id' => chr(ord('a') + $index), 'text' => Az::upper($letter), 'image' => null, 'content_key' => "letter:{$letter}"];
        }

        return [
            'game_session_id' => GameSession::factory(),
            // Copied from the game, as StartGame does.
            'school_class_id' => fn (array $attributes): ?string => GameSession::query()->whereKey($attributes['game_session_id'])->firstOrFail()->school_class_id,
            'child_id' => fn (array $attributes): ?string => GameSession::query()->whereKey($attributes['game_session_id'])->firstOrFail()->child_id,
            'position' => fn (array $attributes): int => GameRound::query()->where('game_session_id', $attributes['game_session_id'])->count(),
            'payload' => [
                'mechanic' => Mechanic::Find->value,
                'unit' => Unit::Letter->value,
                'pack' => 'balloons',
                'level' => 'letters',
                'prompt' => ['text' => '«'.$items[1]['text'].'»', 'show' => $items[1]['text'], 'image' => null, 'audio' => null, 'tail' => null],
                'items' => $items,
                'slots' => 0,
                'solution' => ['order' => SolutionOrder::Any->value, 'item_ids' => ['b']],
                'effect' => Effect::Burst->value,
            ],
            'progress' => [],
            'misses' => 0,
            'points' => 0,
        ];
    }

    /**
     * A sequence round whose solution reuses a tile (`nə-nə` = a, a).
     *
     * @param  list<string>  $solution
     */
    public function sequenceOf(array $solution = ['a', 'a']): static
    {
        return $this->state(fn (array $attributes): array => [
            'payload' => [
                ...$attributes['payload'],
                'mechanic' => Mechanic::BuildWord->value,
                'unit' => Unit::Word->value,
                'slots' => count($solution),
                'solution' => ['order' => SolutionOrder::Sequence->value, 'item_ids' => $solution],
            ],
        ]);
    }
}
