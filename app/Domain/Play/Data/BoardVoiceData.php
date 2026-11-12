<?php

namespace App\Domain\Play\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * What the account's boards say (Settings → Oyun, docs/architecture.md §3
 * "Voice", ADR 015, ADR 016): the prompt of each round («Tapşırığı səslə
 * de»); the praise after a solved round with the retry phrase after a wrong
 * tap, the greeting and the end phrase («Tərif səsi»); and a player's first
 * name from the built-in name list with them («Adı səslə de», V2). All
 * three are on for a new account and for one stored before V2; a teacher
 * who reads the prompt aloud turns the first off. With praise off nothing
 * but the prompt is said, so no name either. A preference: it changes what
 * the account's boards play, nothing else.
 */
final readonly class BoardVoiceData
{
    public function __construct(
        public bool $prompt,
        public bool $praise,
        public bool $names = true,
    ) {}

    /**
     * The choice stored in `users.board_voice` (null, or a key missing: on).
     *
     * @param  array<string, mixed>|null  $stored
     */
    public static function fromStored(?array $stored): self
    {
        return new self(
            ($stored['prompt'] ?? true) !== false,
            ($stored['praise'] ?? true) !== false,
            ($stored['names'] ?? true) !== false,
        );
    }

    /**
     * Whether the boards say a player's name: the name switch and praise,
     * which governs everything said besides the prompt.
     */
    public function saysNames(): bool
    {
        return $this->praise && $this->names;
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'prompt' => ['required', 'boolean'],
            'praise' => ['required', 'boolean'],
            // Sent by the form from V2; a request without it leaves names on.
            'names' => ['sometimes', 'boolean'],
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

        foreach (['prompt', 'praise', 'names'] as $field) {
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

        return new self($on($validated['prompt']), $on($validated['praise']), ! array_key_exists('names', $validated) || $on($validated['names']));
    }

    /**
     * @return array{prompt: bool, praise: bool, names: bool}
     */
    public function toArray(): array
    {
        return ['prompt' => $this->prompt, 'praise' => $this->praise, 'names' => $this->names];
    }
}
