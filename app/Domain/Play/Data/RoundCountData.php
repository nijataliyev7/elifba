<?php

namespace App\Domain\Play\Data;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The body of `PATCH /games/{game}/rounds` (docs/architecture.md §4): the
 * board's epoch and the number of rounds the game is to have, within
 * `elifba.rounds.min` and `.max` like a new game's (`GameSetupData`).
 */
final readonly class RoundCountData
{
    public function __construct(
        public int $board_epoch,
        public int $rounds,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'board_epoch' => ['required', 'integer', 'min:0'],
            'rounds' => ['required', 'integer', 'between:'.Config::integer('elifba.rounds.min').','.Config::integer('elifba.rounds.max')],
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

        return new self(self::integer($validated['board_epoch'] ?? 0), self::integer($validated['rounds'] ?? 0));
    }

    private static function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
