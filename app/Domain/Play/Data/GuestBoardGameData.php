<?php

namespace App\Domain\Play\Data;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What the teacher's phone sends to a guest board (docs/architecture.md §4):
 * one of the teacher's live or paused games (`game`), or a new game for one
 * of their classes (`class` plus the setup, validated by
 * `GameSetupData::rules()` exactly like the setup page). Another teacher's
 * game or class fails the same `exists` rule as an unknown id.
 */
final readonly class GuestBoardGameData
{
    private function __construct(
        public ?GameSession $game,
        public ?SchoolClass $class,
        public ?GameSetupData $setup,
    ) {}

    public static function existing(GameSession $game): self
    {
        return new self($game, null, null);
    }

    public static function new(SchoolClass $class, GameSetupData $setup): self
    {
        return new self(null, $class, $setup);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(User $teacher): array
    {
        return [
            'game' => [
                'nullable', 'required_without:class', 'prohibits:class', 'string',
                Rule::exists('game_sessions', 'id')
                    ->where('user_id', $teacher->id)
                    ->whereIn('status', array_map(static fn (GameSessionStatus $status): string => $status->value, GameSessionStatus::open())),
            ],
            'class' => [
                'nullable', 'required_without:game', 'string',
                Rule::exists('school_classes', 'id')->where('user_id', $teacher->id)->whereNull('archived_at'),
            ],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input, User $teacher): self
    {
        $validated = Validator::make($input, self::rules($teacher))->validate();

        if (is_string($validated['game'] ?? null)) {
            return self::existing(GameSession::query()->whereKey($validated['game'])->where('user_id', $teacher->id)->firstOrFail());
        }

        $class = SchoolClass::query()->whereKey($validated['class'] ?? null)->where('user_id', $teacher->id)->firstOrFail();

        return self::new($class, GameSetupData::validate($input, $class));
    }
}
