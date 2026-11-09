<?php

namespace App\Domain\Classroom\Data;

use Illuminate\Support\Facades\Validator;

/**
 * The new order of a class: the ids of all its active students, first to
 * last. The ids are not route-bound, so `ReorderStudents` checks that they are
 * exactly the class's active students; that check is their isolation guard.
 */
final readonly class ReorderStudentsData
{
    public const MAX_STUDENTS = 200;

    /**
     * @param  list<string>  $student_ids
     */
    public function __construct(public array $student_ids) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'student_ids' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_STUDENTS],
            'student_ids.*' => ['required', 'string', 'max:26', 'distinct'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $ids = is_array($validated['student_ids'] ?? null) ? $validated['student_ids'] : [];

        return new self(array_values(array_filter($ids, is_string(...))));
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    public static function validate(array $input): self
    {
        return self::fromArray(Validator::make($input, self::rules())->validate());
    }
}
