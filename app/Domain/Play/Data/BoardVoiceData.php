<?php

namespace App\Domain\Play\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * What the account's boards say (Settings → Oyun, docs/architecture.md §3
 * "Voice", ADR 015): the prompt of each round («Tapşırığı səslə de») and
 * the praise after a solved round with the retry phrase after a wrong tap
 * («Tərif səsi»). Both are on for a new account; a teacher who reads the
 * prompt aloud turns the first off. A preference: it changes what the
 * account's boards play, nothing else.
 */
final readonly class BoardVoiceData
{
    public function __construct(
        public bool $prompt,
        public bool $praise,
    ) {}

    /**
     * The choice stored in `users.board_voice` (null: both on).
     *
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromStored(?array $stored): self
    {
        return new self(($stored['prompt'] ?? true) !== false, ($stored['praise'] ?? true) !== false);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'prompt' => ['required', 'boolean'],
            'praise' => ['required', 'boolean'],
        ];
    }

    /**
     * The field names in validation messages, in the interface language.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        $attributes = [];

        foreach (['prompt', 'praise'] as $field) {
            $name = __("settings.play.voice.{$field}");
            $attributes[$field] = is_string($name) ? $name : $field;
        }

        return $attributes;
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        $validated = Validator::make($input, self::rules(), [], self::attributes())->validate();
        $on = static fn (mixed $value): bool => in_array($value, [true, 1, '1'], true);

        return new self($on($validated['prompt']), $on($validated['praise']));
    }

    /**
     * @return array{prompt: bool, praise: bool}
     */
    public function toArray(): array
    {
        return ['prompt' => $this->prompt, 'praise' => $this->praise];
    }
}
