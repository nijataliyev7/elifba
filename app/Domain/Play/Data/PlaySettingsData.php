<?php

namespace App\Domain\Play\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The teacher's play settings (Settings → Oyun, docs/architecture.md §4
 * "Pairing"): whether the teacher board offers the phone remote. A
 * preference, not a permission: it changes what the teacher board shows,
 * never who may pair or join a game's channel.
 */
final readonly class PlaySettingsData
{
    public function __construct(
        public bool $phoneRemote,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'phone_remote' => ['required', 'boolean'],
        ];
    }

    /**
     * The field names in validation messages, in the interface language.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        $name = __('settings.play.phone_remote.label');

        return ['phone_remote' => is_string($name) ? $name : 'phone_remote'];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        $validated = Validator::make($input, self::rules(), [], self::attributes())->validate();

        return new self(in_array($validated['phone_remote'], [true, 1, '1'], true));
    }
}
