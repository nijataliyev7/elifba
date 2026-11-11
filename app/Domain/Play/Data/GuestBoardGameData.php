<?php

namespace App\Domain\Play\Data;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Family\Models\Child;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What the owner's phone sends to a guest board (docs/architecture.md §4):
 * one of the account's live or paused games (`game`), or a new game for one
 * of its classes (`class`) or children (`child`, ADR 011) plus the setup,
 * validated by `GameSetupData::rules()` exactly like the setup page. Exactly
 * one of the three. Another account's game, class or child fails the same
 * `exists` rule as an unknown id.
 */
final readonly class GuestBoardGameData
{
    private function __construct(
        public ?GameSession $game,
        public ?GamePlayers $players,
        public ?GameSetupData $setup,
    ) {}

    public static function existing(GameSession $game): self
    {
        return new self($game, null, null);
    }

    public static function new(GamePlayers $players, GameSetupData $setup): self
    {
        return new self(null, $players, $setup);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(User $owner): array
    {
        return [
            'game' => [
                'nullable', 'required_without_all:class,child', 'prohibits:class,child', 'string',
                Rule::exists('game_sessions', 'id')
                    ->where('user_id', $owner->id)
                    ->whereIn('status', array_map(static fn (GameSessionStatus $status): string => $status->value, GameSessionStatus::open())),
            ],
            'class' => [
                'nullable', 'required_without_all:game,child', 'prohibits:child', 'string',
                Rule::exists('school_classes', 'id')->where('user_id', $owner->id)->whereNull('archived_at'),
            ],
            'child' => [
                'nullable', 'required_without_all:game,class', 'string',
                Rule::exists('children', 'id')->where('user_id', $owner->id),
            ],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input, User $owner): self
    {
        $validated = Validator::make($input, self::rules($owner))->validate();

        if (is_string($validated['game'] ?? null)) {
            return self::existing(GameSession::query()->whereKey($validated['game'])->where('user_id', $owner->id)->firstOrFail());
        }

        $players = is_string($validated['child'] ?? null)
            ? GamePlayers::forChild(Child::query()->whereKey($validated['child'])->where('user_id', $owner->id)->firstOrFail()->setRelation('parent', $owner))
            : GamePlayers::forClass(SchoolClass::query()->whereKey($validated['class'] ?? null)->where('user_id', $owner->id)->firstOrFail()->setRelation('teacher', $owner));

        return self::new($players, GameSetupData::validate($input, $players));
    }
}
