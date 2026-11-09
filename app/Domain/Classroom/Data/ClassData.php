<?php

namespace App\Domain\Classroom\Data;

use App\Domain\Language\Support\Az;
use Illuminate\Support\Facades\Validator;

/**
 * A class as the teacher creates or edits it: the teacher's own label
 * ("1A") and an optional grade 1–4.
 */
final readonly class ClassData
{
    public const NAME_MAX = 40;

    public string $name;

    public function __construct(string $name, public ?int $grade = null)
    {
        $this->name = Az::normalize($name);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.self::NAME_MAX],
            'grade' => ['nullable', 'integer', 'between:1,4'],
        ];
    }

    /**
     * Normalizes the input so that the rules check what will be stored.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function prepare(array $input): array
    {
        if (is_string($input['name'] ?? null)) {
            $input['name'] = Az::normalize($input['name']);
        }

        return $input;
    }

    /**
     * @param  array<array-key, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $name = $validated['name'] ?? '';
        $grade = $validated['grade'] ?? null;

        return new self(
            name: is_string($name) ? $name : '',
            grade: is_numeric($grade) ? (int) $grade : null,
        );
    }

    /**
     * Prepares, validates and builds in one step, for any entry point.
     *
     * @param  array<array-key, mixed>  $input
     */
    public static function validate(array $input): self
    {
        return self::fromArray(Validator::make(self::prepare($input), self::rules())->validate());
    }

    /**
     * @return array{name: string, grade: int|null}
     */
    public function toAttributes(): array
    {
        return ['name' => $this->name, 'grade' => $this->grade];
    }
}
