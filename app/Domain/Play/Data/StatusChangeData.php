<?php

namespace App\Domain\Play\Data;

use App\Domain\Play\Enums\GameSessionStatus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The body of `PATCH /games/{game}/status` (docs/architecture.md §4): pause
 * (`paused`), resume (`live`) or end (`finished`) a game. `abandoned` is the
 * scheduler's alone.
 */
final readonly class StatusChangeData
{
    public function __construct(public GameSessionStatus $status) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(GameSessionStatus::class)->except(GameSessionStatus::Abandoned)],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        $status = Validator::make($input, self::rules())->validate()['status'] ?? null;

        return new self(GameSessionStatus::from(is_string($status) ? $status : ''));
    }
}
