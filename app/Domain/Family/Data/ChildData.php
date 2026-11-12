<?php

namespace App\Domain\Family\Data;

use App\Domain\Classroom\Support\DisplayName;
use App\Domain\Content\Support\Avatars;
use App\Domain\Language\Support\Az;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A child as the parent adds or edits them: a first name and an avatar,
 * nothing else (docs/architecture.md §7 "Parents and their children",
 * ADR 013). The name follows the display-name rule without the surname
 * initial. The avatar is optional: `AddChild` gives the next one not yet
 * used among the parent's children, `UpdateChild` keeps the current one.
 */
final readonly class ChildData
{
    public string $display_name;

    public function __construct(string $display_name, public ?string $avatar = null)
    {
        $this->display_name = Az::normalize($display_name);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'display_name' => ['required', 'string', new DisplayName(initial: false, message: 'children.errors.invalid_name', surnameMessage: 'children.errors.surname')],
            'avatar' => ['sometimes', 'nullable', 'string', Rule::in(Avatars::keys())],
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
        $avatar = $validated['avatar'] ?? null;

        return new self(
            display_name: is_string($name) ? $name : '',
            avatar: is_string($avatar) && $avatar !== '' ? $avatar : null,
        );
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        return self::fromArray(Validator::make(self::prepare($input), self::rules())->validate());
    }
}
