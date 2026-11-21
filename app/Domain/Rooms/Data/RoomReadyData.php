<?php

namespace App\Domain\Rooms\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A player's «Hazıram» between games, or taking it back
 * (docs/architecture.md §12 "Ready for the next game", ADR 035 decision 4):
 * `PUT /rooms/{room}/ready` `{on}`.
 */
final readonly class RoomReadyData
{
    public function __construct(
        public bool $on,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'on' => ['required', 'boolean'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        $validated = Validator::make($input, self::rules())->validate();

        return new self(in_array($validated['on'], [true, 1, '1'], true));
    }
}
