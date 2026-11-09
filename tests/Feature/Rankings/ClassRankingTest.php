<?php

use App\Domain\Classroom\Actions\ArchiveStudent;
use App\Domain\Classroom\Actions\DeleteStudent;
use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Domain\Rankings\Data\RankedStudentData;
use App\Domain\Rankings\Support\ClassRanking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Games;

/*
 * Daily and weekly class rankings (docs/architecture.md §5, ADR 005), with
 * points written the real way: board events through POST
 * /games/{game}/sync at a faked time, so RecordBoardEvents stamps
 * `played_on` and `week_start` by the Baku calendar. 2026-11-09 is a
 * Monday; Baku is UTC+4, so its midnight is 20:00 UTC.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
    $this->actingAs($this->teacher);
    $this->travelTo(CarbonImmutable::parse('2026-11-09 05:00:00', 'Asia/Baku'));
    $this->game = Games::start($this->class, ['rounds' => 30]);
});

/**
 * @return list<Student>
 */
function rankingStudents(SchoolClass $class, string ...$names): array
{
    return array_map(
        static fn (string $name): Student => Student::factory()->for($class)->create(['display_name' => $name]),
        $names,
    );
}

/**
 * Solves the game's next open round through /sync, after the given wrong
 * taps (0 → 3 points, 1 → 2, more → 1), credited to the student at the
 * board, or to no one for the whole class.
 */
function solveForRanking(GameSession $game, ?Student $student, int $misses = 0): GameRound
{
    $round = GameRound::query()->where('game_session_id', $game->id)->open()->orderBy('position')->firstOrFail();
    $events = [];

    for ($nth = 0; $nth < $misses; $nth++) {
        $events[] = Games::hit($round, Games::decoy($round, $nth), $student?->id);
    }

    $events[] = Games::hit($round, Games::target($round), $student?->id);

    test()->postJson(route('games.sync', $game), [
        'board_epoch' => $game->fresh()?->board_epoch,
        'events' => $events,
    ])->assertOk();

    return $round->refresh();
}

/**
 * At a moment on the Baku clock.
 */
function atBaku(string $moment): CarbonImmutable
{
    return CarbonImmutable::parse($moment, 'Asia/Baku');
}

/**
 * The rows as [display name, points, place].
 *
 * @param  list<RankedStudentData>  $rows
 * @return list<array{0: string, 1: int, 2: int|null}>
 */
function rankingRows(array $rows): array
{
    return array_map(
        static fn (RankedStudentData $row): array => [$row->student->display_name, $row->points, $row->place],
        $rows,
    );
}

describe('the Baku calendar', function (): void {
    it('counts a round solved at 19:59:59 UTC for that Baku day and one at 20:00:00 UTC for the next', function (): void {
        [$aysel, $ali] = rankingStudents($this->class, 'Aysel', 'Əli');

        $this->travelTo(CarbonImmutable::parse('2026-11-10 19:59:59', 'UTC'));
        solveForRanking($this->game, $aysel);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 20:00:00', 'UTC'));
        solveForRanking($this->game, $ali);

        expect(rankingRows(ClassRanking::day($this->class, atBaku('2026-11-10'))))->toBe([
            ['Aysel', 3, 1],
            ['Əli', 0, null],
        ])->and(rankingRows(ClassRanking::day($this->class, atBaku('2026-11-11'))))->toBe([
            ['Əli', 3, 1],
            ['Aysel', 0, null],
        ])->and(rankingRows(ClassRanking::week($this->class, atBaku('2026-11-11'))))->toBe([
            ['Aysel', 3, 1],
            ['Əli', 3, 1],
        ]);
    });

    it('puts Sunday 23:59 in Baku in its week and Monday 00:00 in the next', function (): void {
        [$aysel, $ali] = rankingStudents($this->class, 'Aysel', 'Əli');

        $this->travelTo(atBaku('2026-11-15 23:59:00'));
        solveForRanking($this->game, $aysel);
        $this->travelTo(atBaku('2026-11-16 00:00:00'));
        solveForRanking($this->game, $ali);

        // Any date of the week selects it, Monday to Sunday.
        foreach (['2026-11-09', '2026-11-12', '2026-11-15'] as $date) {
            expect(rankingRows(ClassRanking::week($this->class, atBaku($date))))->toBe([
                ['Aysel', 3, 1],
                ['Əli', 0, null],
            ]);
        }

        foreach (['2026-11-16', '2026-11-22'] as $date) {
            expect(rankingRows(ClassRanking::week($this->class, atBaku($date))))->toBe([
                ['Əli', 3, 1],
                ['Aysel', 0, null],
            ]);
        }
    });

    it('reads the stamped dates, so a moment in UTC selects its Baku day', function (): void {
        [$aysel] = rankingStudents($this->class, 'Aysel');

        $this->travelTo(atBaku('2026-11-10 00:30:00'));
        solveForRanking($this->game, $aysel);

        // 20:30 UTC on the 9th is 00:30 on the 10th in Baku.
        expect(rankingRows(ClassRanking::day($this->class, CarbonImmutable::parse('2026-11-09 20:30:00', 'UTC'))))
            ->toBe([['Aysel', 3, 1]]);
    });
});

describe('places', function (): void {
    it('sums the points of the window and places them densely', function (): void {
        [$aysel, $ali, $leyla, $nigar] = rankingStudents($this->class, 'Aysel', 'Əli', 'Leyla', 'Nigar');

        // 6, 6, 5, 3 → 1, 1, 2, 3.
        foreach ([[$ali, 0], [$aysel, 0], [$leyla, 0], [$nigar, 0], [$ali, 0], [$aysel, 0], [$leyla, 1]] as [$student, $misses]) {
            solveForRanking($this->game, $student, $misses);
        }

        // A round with more than one wrong tap is worth 1, on another day.
        $this->travelTo(atBaku('2026-11-10 09:00:00'));
        solveForRanking($this->game, $nigar, 2);

        expect(rankingRows(ClassRanking::day($this->class, atBaku('2026-11-09'))))->toBe([
            ['Aysel', 6, 1],
            ['Əli', 6, 1],
            ['Leyla', 5, 2],
            ['Nigar', 3, 3],
        ])->and(rankingRows(ClassRanking::week($this->class, atBaku('2026-11-09'))))->toBe([
            ['Aysel', 6, 1],
            ['Əli', 6, 1],
            ['Leyla', 5, 2],
            ['Nigar', 4, 3],
        ]);
    });

    it('lists tied names in Azerbaijani order', function (): void {
        $students = rankingStudents($this->class, 'Şahin', 'Səbinə', 'Əli', 'Elvin', 'Çinarə', 'Ceyhun', 'Ülvi');

        foreach ($students as $student) {
            solveForRanking($this->game, $student, $student->display_name === 'Ülvi' ? 1 : 0);
        }

        // C < Ç, E < Ə, S < Ş — not the byte order (C, E, S, Ç, Ş, Ə).
        expect(rankingRows(ClassRanking::day($this->class, atBaku('2026-11-09'))))->toBe([
            ['Ceyhun', 3, 1],
            ['Çinarə', 3, 1],
            ['Elvin', 3, 1],
            ['Əli', 3, 1],
            ['Səbinə', 3, 1],
            ['Şahin', 3, 1],
            ['Ülvi', 2, 2],
        ]);
    });

    it('lists students with 0 points last, without a place, in Azerbaijani order', function (): void {
        [, , $ceyhun] = rankingStudents($this->class, 'Şahin', 'Əli', 'Ceyhun', 'Çinarə', 'Elvin');

        solveForRanking($this->game, $ceyhun, 1);

        expect(rankingRows(ClassRanking::day($this->class, atBaku('2026-11-09'))))->toBe([
            ['Ceyhun', 2, 1],
            ['Çinarə', 0, null],
            ['Elvin', 0, null],
            ['Əli', 0, null],
            ['Şahin', 0, null],
        ]);
    });

    it('is empty for a class without active students', function (): void {
        expect(ClassRanking::day($this->class, atBaku('2026-11-09')))->toBe([]);
    });
});

describe('who is ranked', function (): void {
    it('leaves out an archived student, even with points', function (): void {
        [$aysel, $ali] = rankingStudents($this->class, 'Aysel', 'Əli');

        solveForRanking($this->game, $aysel);
        solveForRanking($this->game, $aysel);
        solveForRanking($this->game, $ali);
        app(ArchiveStudent::class)->handle($aysel);

        expect(rankingRows(ClassRanking::day($this->class, atBaku('2026-11-09'))))->toBe([['Əli', 3, 1]])
            ->and(rankingRows(ClassRanking::week($this->class, atBaku('2026-11-09'))))->toBe([['Əli', 3, 1]])
            ->and(GameRound::query()->where('student_id', $aysel->id)->sum('points'))->toEqual(6);
    });

    it('leaves out a deleted student\'s rounds', function (): void {
        [$aysel, $ali] = rankingStudents($this->class, 'Aysel', 'Əli');

        $round = solveForRanking($this->game, $aysel);
        solveForRanking($this->game, $ali, 1);
        app(DeleteStudent::class)->handle($aysel);

        // The round stays as anonymous class practice, with its points.
        expect($round->refresh())->student_id->toBeNull()->points->toBe(3)
            ->and(rankingRows(ClassRanking::day($this->class, atBaku('2026-11-09'))))->toBe([['Əli', 2, 1]]);
    });

    it('credits no one for a round the whole class solved', function (): void {
        [$aysel] = rankingStudents($this->class, 'Aysel');

        $round = solveForRanking($this->game, null);

        expect($round)->student_id->toBeNull()->points->toBe(3)->played_on->toBe('2026-11-09')
            ->and(rankingRows(ClassRanking::day($this->class, atBaku('2026-11-09'))))->toBe([['Aysel', 0, null]])
            ->and(rankingRows(ClassRanking::week($this->class, atBaku('2026-11-09'))))->toBe([['Aysel', 0, null]]);
    });

    it('never counts another class\'s rounds', function (): void {
        [$aysel] = rankingStudents($this->class, 'Aysel');
        $sameTeacher = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
        [$leyla] = rankingStudents($sameTeacher, 'Leyla');
        $otherTeacher = User::factory()->create();
        $foreign = SchoolClass::factory()->for($otherTeacher, 'teacher')->create();
        [$nigar] = rankingStudents($foreign, 'Nigar');

        solveForRanking($this->game, $aysel, 2);
        solveForRanking(Games::start($sameTeacher), $leyla);
        $this->actingAs($otherTeacher);
        solveForRanking(Games::start($foreign), $nigar);

        // A student id of another class sent to this game credits no one.
        $this->actingAs($this->teacher);
        solveForRanking($this->game, $leyla);

        expect(rankingRows(ClassRanking::day($this->class, atBaku('2026-11-09'))))->toBe([['Aysel', 1, 1]])
            ->and(rankingRows(ClassRanking::day($sameTeacher, atBaku('2026-11-09'))))->toBe([['Leyla', 3, 1]])
            ->and(rankingRows(ClassRanking::week($foreign, atBaku('2026-11-09'))))->toBe([['Nigar', 3, 1]]);
    });

    it('reads the rankings with one grouped query and stores nothing', function (): void {
        [$aysel, $ali] = rankingStudents($this->class, 'Aysel', 'Əli');
        solveForRanking($this->game, $aysel);
        solveForRanking($this->game, $ali);
        $this->class->refresh();

        DB::enableQueryLog();
        ClassRanking::week($this->class, atBaku('2026-11-09'));
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $rounds = array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'game_rounds')));

        expect($queries)->toHaveCount(2)
            ->and($rounds)->toHaveCount(1)
            ->and(strtolower($rounds[0]))->toContain('group by')->toContain('week_start')->not->toContain('played_on')
            ->and(array_filter($queries, static fn (string $sql): bool => ! str_starts_with(strtolower($sql), 'select')))->toBe([]);
    });
});
