<?php

namespace App\Domain\Rooms\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The host starts a board match (ADR 034 decision 3): which of the room's
 * players has white and which black, by `room_players.id`. `StartRoomMatch`
 * checks that both are present players of the room.
 */
final readonly class StartRoomMatchData
{
    public function __construct(
        public string $white,
        public string $black,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'white' => ['required', 'string', 'ulid'],
            'black' => ['required', 'string', 'ulid', 'different:white'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        /** @var array{white: string, black: string} $validated */
        $validated = Validator::make($input, self::rules())->validate();

        return new self(strtolower($validated['white']), strtolower($validated['black']));
    }
}
