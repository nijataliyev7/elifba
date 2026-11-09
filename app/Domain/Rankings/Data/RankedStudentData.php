<?php

namespace App\Domain\Rankings\Data;

use App\Domain\Classroom\Models\Student;
use App\Domain\Language\Support\Ordinal;

/**
 * One student's row of a class ranking (docs/architecture.md §5): the
 * points in the window and the dense place, or no place with 0 points.
 */
final readonly class RankedStudentData
{
    public function __construct(
        public Student $student,
        public int $points,
        public ?int $place,
    ) {}

    /**
     * What a page receives: the student's id, display name and avatar and
     * nothing more about the child (§7), with the place label built on the
     * server in the interface language ("1-ci", "1st").
     *
     * @return array{id: string, display_name: string, avatar: string, points: int, place: int|null, place_label: string|null}
     */
    public function toArray(string $locale): array
    {
        return [
            'id' => $this->student->id,
            'display_name' => $this->student->display_name,
            'avatar' => $this->student->avatar,
            'points' => $this->points,
            'place' => $this->place,
            'place_label' => $this->place === null ? null : Ordinal::label($this->place, $locale),
        ];
    }
}
