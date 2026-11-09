<?php

namespace App\Domain\Classroom\Data;

use App\Domain\Classroom\Support\DisplayName;
use App\Domain\Content\Support\Avatars;
use App\Domain\Language\Support\Az;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A student as the teacher edits them: display name and avatar, nothing else
 * (docs/architecture.md §7).
 */
final readonly class StudentData
{
    public string $display_name;

    public function __construct(string $display_name, public string $avatar)
    {
        $this->display_name = Az::normalize($display_name);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'display_name' => ['required', 'string', new DisplayName],
            'avatar' => ['required', 'string', Rule::in(Avatars::keys())],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function prepare(array $input): array
    {
        if (is_string($input['display_name'] ?? null)) {
            $input['display_name'] = Az::normalize($input['display_name']);
        }

        return $input;
    }

    /**
     * @param  array<array-key, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $name = $validated['display_name'] ?? '';
        $avatar = $validated['avatar'] ?? '';

        return new self(
            display_name: is_string($name) ? $name : '',
            avatar: is_string($avatar) ? $avatar : '',
        );
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    public static function validate(array $input): self
    {
        return self::fromArray(Validator::make(self::prepare($input), self::rules())->validate());
    }
}
