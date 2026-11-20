<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Enums\RoomBoardGame;
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
 *
 * Or a board game (ADR 034 decision 2): `board` names it, and the room is
 * opened for it — its round-game choice keeps the first game's defaults,
 * which a board room never starts.
 */
final readonly class OpenRoomData
{
    public function __construct(
        public RoomMechanic $mechanic,
        public ?RoomBoardGame $board = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'board' => ['nullable', 'string', Rule::enum(RoomBoardGame::class)],
            'mechanic' => ['required_without:board', 'nullable', 'string', Rule::in(array_map(
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
        /** @var array{mechanic?: string|null, board?: string|null} $validated */
        $validated = Validator::make($input, self::rules())->validate();
        $board = isset($validated['board']) ? RoomBoardGame::from($validated['board']) : null;

        if ($board !== null) {
            return new self(RoomGameSetupData::firstMechanic(), $board);
        }

        $mechanic = $validated['mechanic'] ?? null;

        if ($mechanic === null) {
            throw ValidationException::withMessages(['mechanic' => __('validation.required', ['attribute' => 'mechanic'])]);
        }

        return new self(RoomMechanic::from($mechanic));
    }
}
