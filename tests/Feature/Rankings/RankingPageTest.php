<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Support\Avatars;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Support\SchoolCalendar;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Games;

/*
 * The teacher's rankings page, /classes/{class}/rankings
 * (docs/architecture.md §5): the day and today in Baku by default, a week
 * by its Monday–Sunday label, both interface languages, and nothing about
 * a child but the id, display name and avatar. Isolation between teachers
 * is also in TeacherIsolationTest. Today is Wednesday 11 November 2026 in
 * Baku; 2026-11-09 is a Monday.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 14:00:00', 'Asia/Baku'));
    $this->teacher = User::factory()->create();
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '1A']);
    $this->actingAs($this->teacher);
});

/**
 * A round solved at a Baku moment for the student, with its points, the way
 * RecordBoardEvents stores it (the write path itself is ClassRankingTest's).
 */
function solvedRound(GameSession $game, ?Student $student, int $points, string $bakuMoment): GameRound
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

function rankingsUrl(SchoolClass $class, array $query = []): string
{
    return route('classes.rankings', [...$query, 'class' => $class]);
}

it('shows today\'s ranking by default with only the allowed fields', function (): void {
    $aysel = Student::factory()->for($this->class)->create(['display_name' => 'Aysel', 'avatar' => Avatars::keys()[0]]);
    $ali = Student::factory()->for($this->class)->create(['display_name' => 'Əli', 'avatar' => Avatars::keys()[1]]);
    $leyla = Student::factory()->for($this->class)->create(['display_name' => 'Leyla']);
    $game = Games::start($this->class);
    solvedRound($game, $ali, 3, '2026-11-11 09:00:00');
    solvedRound($game, $aysel, 2, '2026-11-11 09:05:00');
    solvedRound($game, $leyla, 3, '2026-11-10 09:00:00');

    $this->get(rankingsUrl($this->class))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('rankings/show')
            ->where('schoolClass', ['id' => $this->class->id, 'name' => '1A', 'archived' => false])
            ->where('period', 'day')
            ->where('date', '2026-11-11')
            ->where('label', '11 noyabr 2026')
            ->where('current', true)
            ->where('previous', '2026-11-10')
            ->where('next', null)
            ->where('students', [
                ['id' => $ali->id, 'display_name' => 'Əli', 'avatar' => Avatars::keys()[1], 'points' => 3, 'place' => 1, 'place_label' => '1-ci'],
                ['id' => $aysel->id, 'display_name' => 'Aysel', 'avatar' => Avatars::keys()[0], 'points' => 2, 'place' => 2, 'place_label' => '2-ci'],
                ['id' => $leyla->id, 'display_name' => 'Leyla', 'avatar' => $leyla->avatar, 'points' => 0, 'place' => null, 'place_label' => null],
            ])
            ->has('avatars', count(Avatars::keys())));
});

it('shows the week that holds the date, labelled Monday to Sunday', function (): void {
    $aysel = Student::factory()->for($this->class)->create(['display_name' => 'Aysel']);
    $game = Games::start($this->class);
    solvedRound($game, $aysel, 3, '2026-11-02 08:00:00');
    solvedRound($game, $aysel, 1, '2026-11-08 23:59:00');
    solvedRound($game, $aysel, 2, '2026-11-09 00:00:00');

    $this->get(rankingsUrl($this->class, ['period' => 'week', 'date' => '2026-11-05']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('period', 'week')
            ->where('date', '2026-11-05')
            ->where('label', '2–8 noyabr 2026')
            ->where('current', false)
            ->where('previous', '2026-10-26')
            ->where('next', '2026-11-09')
            ->where('students.0.points', 4)
            ->where('students.0.place_label', '1-ci'));

    $this->get(rankingsUrl($this->class, ['period' => 'week']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('date', '2026-11-11')
            ->where('label', '9–15 noyabr 2026')
            ->where('current', true)
            ->where('previous', '2026-11-02')
            ->where('next', null)
            ->where('students.0.points', 2));

    $this->get(rankingsUrl($this->class, ['period' => 'week', 'date' => '2026-10-28']))
        ->assertInertia(fn (Assert $page) => $page->where('label', '26 oktyabr – 1 noyabr 2026'));
});

it('formats the labels in the teacher\'s language', function (): void {
    $this->teacher->forceFill(['locale' => 'en'])->save();
    $aysel = Student::factory()->for($this->class)->create(['display_name' => 'Aysel']);
    $ali = Student::factory()->for($this->class)->create(['display_name' => 'Əli']);
    $game = Games::start($this->class);
    solvedRound($game, $aysel, 3, '2026-11-09 10:00:00');
    solvedRound($game, $ali, 1, '2026-11-10 10:00:00');

    $this->get(rankingsUrl($this->class, ['period' => 'week']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            ->where('label', '9–15 November 2026')
            ->where('students.0.place_label', '1st')
            ->where('students.1.place_label', '2nd')
            ->where('translations', fn ($translations): bool => $translations['rankings.title'] === 'Rankings'));

    $this->get(rankingsUrl($this->class, ['date' => '2026-11-09']))
        ->assertInertia(fn (Assert $page) => $page->where('label', '9 November 2026'));

    $this->teacher->forceFill(['locale' => 'az'])->save();

    $this->get(rankingsUrl($this->class, ['period' => 'week']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('label', '9–15 noyabr 2026')
            ->where('students.1.place_label', '2-ci')
            ->where('translations', fn ($translations): bool => $translations['rankings.title'] === 'Reytinq'));
});

it('redirects an invalid or future period or date to the query without it', function (array $query, array $valid): void {
    $this->get(rankingsUrl($this->class, $query))->assertRedirect(rankingsUrl($this->class, $valid));
})->with([
    'an unknown period' => [['period' => 'month'], []],
    'an unknown period with a date' => [['period' => 'year', 'date' => '2026-11-03'], ['date' => '2026-11-03']],
    'tomorrow in Baku' => [['period' => 'week', 'date' => '2026-11-12'], ['period' => 'week']],
    'a date far ahead' => [['date' => '2099-01-01'], []],
    'a date that does not exist' => [['date' => '2026-02-30'], []],
    'a date in another format' => [['date' => '11.11.2026'], []],
    'an empty date' => [['date' => ''], []],
    'an array' => [['date' => ['2026-11-03']], []],
]);

it('follows the Baku calendar for today', function (): void {
    // 20:30 UTC is already Thursday in Baku: Thursday is today, not the future.
    $this->travelTo(CarbonImmutable::parse('2026-11-11 20:30:00', 'UTC'));

    $this->get(rankingsUrl($this->class, ['date' => '2026-11-12']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('date', '2026-11-12')->where('current', true)->where('next', null));
});

it('opens an archived class\'s ranking, as its class page opens', function (): void {
    $class = SchoolClass::factory()->for($this->teacher, 'teacher')->archived()->create();
    $student = Student::factory()->for($class)->create();
    solvedRound(GameSession::factory()->for($class, 'schoolClass')->create(), $student, 3, '2026-11-11 09:00:00');

    $this->get(rankingsUrl($class))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('schoolClass.archived', true)
            ->where('students.0.points', 3));
});

it('shows an empty ranking for a class without students', function (): void {
    $this->get(rankingsUrl($this->class))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('students', []));
});

it('is not found for another teacher', function (): void {
    Student::factory()->for($this->class)->create(['display_name' => 'Aysel']);

    $this->actingAs(User::factory()->create())
        ->get(rankingsUrl($this->class))
        ->assertNotFound();
});

it('needs a verified teacher', function (): void {
    $teacher = User::factory()->unverified()->create();

    $this->actingAs($teacher)
        ->get(rankingsUrl(SchoolClass::factory()->for($teacher, 'teacher')->create()))
        ->assertRedirect(route('verification.notice'));
});
