<?php

namespace App\Domain\Classroom\Data;

use Illuminate\Support\Facades\Validator;
use SensitiveParameter;

/**
 * A pasted list of names for the preview (`NameListParser`). The text holds
 * surnames and patronymics; it is parsed and returned to the teacher, never
 * stored or logged (docs/architecture.md §7).
 */
final readonly class NameListData
{
    public const MAX_LENGTH = 10000;

    public function __construct(#[SensitiveParameter] public string $text) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $text = $validated['text'] ?? '';

        return new self(is_string($text) ? $text : '');
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    public static function validate(array $input): self
    {
        return self::fromArray(Validator::make($input, self::rules())->validate());
    }
}
