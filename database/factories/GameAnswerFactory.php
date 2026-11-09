<?php

namespace Database\Factories;

use App\Domain\Language\Support\Alphabet;
use App\Domain\Play\Enums\HitSource;
use App\Domain\Play\Models\GameAnswer;
use App\Domain\Play\Models\GameRound;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A correct board hit on item "b", the target of `GameRoundFactory` (the second letter).
 *
 * @extends Factory<GameAnswer>
 */
class GameAnswerFactory extends Factory
{
    /**
     * @var class-string<GameAnswer>
     */
    protected $model = GameAnswer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::ulid(),
            'game_round_id' => GameRound::factory(),
            'student_id' => null,
            'item_id' => 'b',
            'content_key' => 'letter:'.Alphabet::letters()[1],
            'correct' => true,
            'source' => HitSource::Board,
            'board_epoch' => 1,
            'client_at' => null,
        ];
    }
}
