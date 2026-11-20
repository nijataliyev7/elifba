<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Content\Enums\RoomMechanic;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Choosing a game on «Oyunlar» (docs/architecture.md §12 "Games first",
 * ADR 026 decision 7): the game, one of the mechanics a room plays that
 * have levels (`RoomGameSetupData::mechanics()`). The room opens behind it,
 * or the host's open room takes it; its difficulty and rounds are derived
 * (`RoomDefaults`) or kept. A room has no name, title or anything else
 * typed, and the host is always its first player (`SetHostPlays` steps
 * out).
 */
final readonly class OpenRoomData
{
    public function __construct(
        public RoomMechanic $mechanic,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'mechanic' => ['required', 'string', Rule::in(array_map(
                static fn (RoomMechanic $mechanic): string => $mechanic->value,
                RoomGameSetupData::mechanics(),
            ))],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        /** @var array{mechanic: string} $validated */
        $validated = Validator::make($input, self::rules())->validate();

        return new self(RoomMechanic::from($validated['mechanic']));
    }
}
