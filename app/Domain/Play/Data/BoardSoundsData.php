<?php

namespace App\Domain\Play\Data;

use App\Domain\Content\Enums\Sound;
use App\Domain\Content\Support\SoundChoices;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The sounds an account chose for its boards (Settings → Oyun,
 * docs/architecture.md §3 "Sounds"): for each choosable moment
 * (`Sound::choosable()`) a key of `content/sounds.json`, or null for the
 * pack's own sound. A preference: it changes what the account's boards
 * play, nothing else.
 */
final readonly class BoardSoundsData
{
    /**
     * @param  array<value-of<Sound>, string|null>  $keys  moment → chosen key, null = the pack's own
     */
    public function __construct(
        public array $keys,
    ) {}

    /**
     * A key of `sounds.json` (or nothing) for each choosable moment; a
     * moment that belongs to the pack alone (`arrive`, `leave`) is refused.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $rules = [];

        foreach (Sound::choosable() as $sound) {
            $rules[$sound->value] = ['nullable', 'string', Rule::in(SoundChoices::keys($sound))];
        }

        foreach (Sound::cases() as $sound) {
            $rules[$sound->value] ??= ['prohibited'];
        }

        return $rules;
    }

    /**
     * The field names in validation messages, in the interface language.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        $attributes = [];

        foreach (Sound::choosable() as $sound) {
            $name = __("settings.play.sounds.{$sound->value}");
            $attributes[$sound->value] = is_string($name) ? $name : $sound->value;
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
        $keys = [];

        foreach (Sound::choosable() as $sound) {
            $key = $validated[$sound->value] ?? null;
            $keys[$sound->value] = is_string($key) ? $key : null;
        }

        return new self($keys);
    }
}
