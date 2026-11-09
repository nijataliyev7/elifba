<?php

namespace App\Domain\Rankings\Data;

use App\Domain\Language\Support\Ordinal;

/**
 * One place of a podium (docs/architecture.md §5): the dense place, its
 * points, and every student tied on it, in Azerbaijani order as
 * `ClassRanking` sorted them.
 */
final readonly class PodiumPlaceData
{
    /**
     * @param  int<1, 3>  $place
     * @param  non-empty-list<RankedStudentData>  $students
     */
    public function __construct(
        public int $place,
        public int $points,
        public array $students,
    ) {}

    /**
     * What the teacher's podium page receives: the place label from the
     * server ("1-ci", "1st") and per student only the id, display name and
     * avatar key (§7). The share card gets its own, smaller shape
     * (`ShareCardData`).
     *
     * @return array{place: int, place_label: string, points: int, students: list<array{id: string, display_name: string, avatar: string}>}
     */
    public function toArray(string $locale): array
    {
        return [
            'place' => $this->place,
            'place_label' => Ordinal::label($this->place, $locale),
            'points' => $this->points,
            'students' => array_map(
                static fn (RankedStudentData $row): array => [
                    'id' => $row->student->id,
                    'display_name' => $row->student->display_name,
                    'avatar' => $row->student->avatar,
                ],
                $this->students,
            ),
        ];
    }
}
