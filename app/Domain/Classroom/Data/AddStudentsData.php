<?php

namespace App\Domain\Classroom\Data;

use App\Domain\Classroom\Support\DisplayName;
use App\Domain\Language\Support\Az;
use Illuminate\Support\Facades\Validator;

/**
 * The display names the teacher confirmed in the paste preview
 * (`NameListParser`), in the order they will join the class.
 */
final readonly class AddStudentsData
{
    public const MAX_NAMES = 50;

    /**
     * What the paste flow is told of its limits (`PasteNames`): the class
     * page's and the teacher board's, the same.
     *
     * @return array{paste_length: int, names: int}
     */
    public static function limits(): array
    {
        return [
            'paste_length' => NameListData::MAX_LENGTH,
            'names' => self::MAX_NAMES,
        ];
    }

    /**
     * @var list<string>
     */
    public array $names;

    /**
     * @param  list<string>  $names
     */
    public function __construct(array $names)
    {
        $this->names = array_map(Az::normalize(...), $names);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'names' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_NAMES],
            'names.*' => ['required', 'string', new DisplayName],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function prepare(array $input): array
    {
        if (is_array($input['names'] ?? null)) {
            $input['names'] = array_map(
                static fn (mixed $name): mixed => is_string($name) ? Az::normalize($name) : $name,
                $input['names'],
            );
        }

        return $input;
    }

    /**
     * @param  array<array-key, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $names = is_array($validated['names'] ?? null) ? $validated['names'] : [];

        return new self(array_values(array_filter($names, is_string(...))));
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    public static function validate(array $input): self
    {
        return self::fromArray(Validator::make(self::prepare($input), self::rules())->validate());
    }
}
