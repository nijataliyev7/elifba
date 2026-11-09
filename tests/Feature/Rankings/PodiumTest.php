<?php

use App\Domain\Classroom\Actions\ArchiveStudent;
use App\Domain\Classroom\Actions\DeleteStudent;
use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Support\SchoolCalendar;
use App\Domain\Rankings\Data\PeriodData;
use App\Domain\Rankings\Data\PodiumPlaceData;
use App\Domain\Rankings\Data\RankedStudentData;
use App\Domain\Rankings\Enums\Period;
use App\Domain\Rankings\Support\Podium;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Support\Games;

/*
 * The podium (docs/architecture.md §5): places 1–3 of ClassRanking with
 * every tied student, dense, in Azerbaijani order; nothing when nobody has
 * points. How points are written and stamped is ClassRankingTest's; here
 * rounds are stored as RecordBoardEvents stores them. Today is Wednesday
 * 11 November 2026 in Baku.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 14:00:00', 'Asia/Baku'));
    $this->class = SchoolClass::factory()->for(User::factory(), 'teacher')->create();
    $this->game = Games::start($this->class);
});

/**
 * A round solved at a Baku moment, credited to the student (or to the
 * whole class), with its points.
 */
function podiumRound(GameSession $game, ?Student $student, int $points, string $bakuMoment = '2026-11-11 09:00:00'): GameRound
{
    $solvedAt = CarbonImmutable::parse($bakuMoment, 'Asia/Baku');
    $round = GameRound::factory()->for($game, 'game')->create([
        'solved_at' => $solvedAt,
        'points' => $points,
        ...SchoolCalendar::stamp($solvedAt),
    ]);
    $round->student_id = $student?->id;
    $round->save();

    return $round;
}

/**
 * Students of the class with these points today, in the given order.
 *
 * @param  array<string, int>  $points  display name → points (0: no round)
 * @return array<string, Student>
 */
function podiumClass(SchoolClass $class, GameSession $game, array $points): array
{
    $students = [];

    foreach ($points as $name => $total) {
        $students[$name] = Student::factory()->for($class)->create(['display_name' => $name]);

        if ($total > 0) {
            podiumRound($game, $students[$name], $total);
        }
    }

    return $students;
}

/**
 * The podium as [place, points, [names]].
 *
 * @param  list<PodiumPlaceData>  $podium
 * @return list<array{0: int, 1: int, 2: list<string>}>
 */
function podiumRows(array $podium): array
{
    return array_map(
        static fn (PodiumPlaceData $place): array => [
            $place->place,
            $place->points,
            array_map(static fn (RankedStudentData $row): string => $row->student->display_name, $place->students),
        ],
        $podium,
    );
}

function todaysPodium(SchoolClass $class): array
{
    return podiumRows(Podium::for($class, PeriodData::today()));
}

it('keeps places 1–3 with every tied student, dense, and leaves out the 4th', function (): void {
    podiumClass($this->class, $this->game, [
        'Zəhra' => 6, 'Aysel' => 6, 'Əli' => 4, 'Murad' => 3, 'Leyla' => 3, 'Tural' => 2, 'Nigar' => 0,
    ]);

    expect(todaysPodium($this->class))->toBe([
        [1, 6, ['Aysel', 'Zəhra']],
        // 2nd right after a tie on 1st, never 3rd.
        [2, 4, ['Əli']],
        [3, 3, ['Leyla', 'Murad']],
    ]);
});

it('holds the whole class when everyone is tied', function (): void {
    podiumClass($this->class, $this->game, ['Vüsal' => 3, 'Aysel' => 3, 'İlkin' => 3, 'Əli' => 3, 'Ceyhun' => 3, 'Çinarə' => 3, 'Bəxtiyar' => 3, 'Şəms' => 3]);

    expect(todaysPodium($this->class))->toBe([
        [1, 3, ['Aysel', 'Bəxtiyar', 'Ceyhun', 'Çinarə', 'Əli', 'İlkin', 'Şəms', 'Vüsal']],
    ]);
});

it('has only the places that someone holds', function (): void {
    podiumClass($this->class, $this->game, ['Aysel' => 3, 'Əli' => 1, 'Leyla' => 0]);

    expect(todaysPodium($this->class))->toBe([
        [1, 3, ['Aysel']],
        [2, 1, ['Əli']],
    ]);
});

it('is empty when nobody has points', function (): void {
    podiumClass($this->class, $this->game, ['Aysel' => 0, 'Əli' => 0]);
    // Yesterday's points are not today's.
    podiumRound($this->game, Student::query()->firstOrFail(), 3, '2026-11-10 12:00:00');

    expect(Podium::for($this->class, PeriodData::today()))->toBe([])
        ->and(Podium::for($this->class, PeriodData::today(Period::Week)))->toHaveCount(1);
});

it('is empty for a class without students', function (): void {
    expect(Podium::for($this->class, PeriodData::today()))->toBe([]);
});

it('leaves out archived and deleted students and rounds the whole class solved', function (): void {
    $students = podiumClass($this->class, $this->game, ['Aysel' => 9, 'Əli' => 8, 'Leyla' => 2, 'Murad' => 1]);
    app(ArchiveStudent::class)->handle($students['Aysel']);
    app(DeleteStudent::class)->handle($students['Əli']);
    // The whole class at the board: points stored, credited to no one.
    podiumRound($this->game, null, 3);

    expect(todaysPodium($this->class))->toBe([
        [1, 2, ['Leyla']],
        [2, 1, ['Murad']],
    ]);
});

it('counts only its own class', function (): void {
    podiumClass($this->class, $this->game, ['Aysel' => 2]);
    $other = SchoolClass::factory()->for(User::factory(), 'teacher')->create();
    podiumClass($other, Games::start($other), ['Əli' => 5]);

    expect(todaysPodium($this->class))->toBe([[1, 2, ['Aysel']]]);
});

it('builds on the ranking it is given', function (): void {
    $students = podiumClass($this->class, $this->game, ['Aysel' => 0, 'Əli' => 0, 'Leyla' => 0, 'Murad' => 0]);
    $row = static fn (string $name, int $points, ?int $place): RankedStudentData => new RankedStudentData($students[$name], $points, $place);

    expect(podiumRows(Podium::of([
        $row('Aysel', 5, 1),
        $row('Əli', 4, 2),
        $row('Leyla', 1, 4),
        $row('Murad', 0, null),
    ])))->toBe([
        [1, 5, ['Aysel']],
        [2, 4, ['Əli']],
    ]);
});
