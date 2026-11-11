<?php

namespace Database\Factories;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Family\Models\Child;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Models\GameSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A live `find` game with no rounds, for a class unless `forChild()` makes
 * it a home game. A test that plays uses `StartGame` (real content, seeded)
 * or adds rounds with `GameRoundFactory`.
 *
 * @extends Factory<GameSession>
 */
class GameSessionFactory extends Factory
{
    /**
     * @var class-string<GameSession>
     */
    protected $model = GameSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_class_id' => SchoolClass::factory(),
            'child_id' => null,
            // The owner is always the class's teacher or the child's parent.
            'user_id' => fn (array $attributes): int => $attributes['school_class_id'] !== null
                ? SchoolClass::query()->whereKey($attributes['school_class_id'])->firstOrFail()->user_id
                : Child::query()->whereKey($attributes['child_id'])->firstOrFail()->user_id,
            'mechanic' => Mechanic::Find,
            'pack' => 'balloons',
            'pack_version' => 1,
            'level' => 'letters',
            'letter_case' => LetterCase::Upper,
            'seed' => fake()->numberBetween(1, 2_147_483_647),
            'status' => GameSessionStatus::Live,
            'board_epoch' => 0,
            'started_at' => now(),
            'ended_at' => null,
            'last_activity_at' => now(),
        ];
    }

    /**
     * A home game for a parent's child (ADR 011): no class.
     */
    public function forChild(?Child $child = null): static
    {
        return $this->state(fn (): array => [
            'school_class_id' => null,
            'child_id' => $child ?? Child::factory(),
        ]);
    }

    public function status(GameSessionStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'ended_at' => $status->isFinal() ? now() : null,
        ]);
    }
}
