<?php

namespace App\Domain\Play\Data;

use App\Domain\Play\Enums\SetupMode;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «Asan» or «Normal», tapped on «Yeni oyun» (`PUT /classes/{class}/games/mode`,
 * `PUT /children/{child}/games/mode`; docs/architecture.md §2 "Remembered
 * setup").
 */
final readonly class SetupModeData
{
    public function __construct(public SetupMode $mode) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'mode' => ['required', 'string', Rule::enum(SetupMode::class)],
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

        return new self(SetupMode::from(is_string($validated['mode'] ?? null) ? $validated['mode'] : ''));
    }
}
