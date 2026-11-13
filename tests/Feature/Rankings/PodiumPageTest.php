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
 * The teacher's podium page, /classes/{class}/podium (docs/architecture.md
 * §5, §6): the query and redirects of the rankings page, both interface
 * languages, and the `card` prop — the only thing the share card receives —
 * holding exactly §6's list and nothing of §7's "never on a share card".
 * Isolation between teachers is in TeacherIsolationTest. Today is
 * Wednesday 11 November 2026 in Baku; 2026-11-09 is a Monday.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 14:00:00', 'Asia/Baku'));
    $this->teacher = User::factory()->create(['name' => 'Nərmin Qasımova']);
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => 'Günəşli sinif']);
    $this->actingAs($this->teacher);
});

function podiumUrl(SchoolClass $class, array $query = []): string
{
    return route('classes.podium', [...$query, 'class' => $class]);
}

/**
 * A round solved at a Baku moment for the student, with its points.
 */
function podiumPageRound(GameSession $game, ?Student $student, int $points, string $bakuMoment = '2026-11-11 09:00:00'): void
{
    $solvedAt = CarbonImmutable::parse($bakuMoment, 'Asia/Baku');
    $round = GameRound::factory()->for($game, 'game')->create([
        'solved_at' => $solvedAt,
        'points' => $points,
        ...SchoolCalendar::stamp($solvedAt),
    ]);
    $round->student_id = $student?->id;
    $round->save();
}

/**
 * Every key path of a prop, list indices as `*`, sorted and unique.
 *
 * @return list<string>
 */
function keyPaths(mixed $value, string $prefix = ''): array
{
    if (! is_array($value)) {
        return [];
    }

    $paths = [];

    foreach ($value as $key => $child) {
        $path = $prefix.(array_is_list($value) ? '*' : $key);
        $paths = [...$paths, ...(array_is_list($value) ? [] : [$path]), ...keyPaths($child, $path.'.')];
    }

    $paths = array_values(array_unique($paths));
    sort($paths);

    return $paths;
}

/**
 * Four students with points today: Əli and Aysel tied on 1st, Leyla 2nd,
 * Murad 3rd, Tural 4th (never on the card), Nigar with none.
 *
 * @return array<string, Student>
 */
function podiumPageClass(SchoolClass $class): array
{
    $game = Games::start($class);
    $students = [];

    foreach (['Əli' => 6, 'Aysel' => 6, 'Leyla' => 4, 'Murad' => 3, 'Tural' => 2, 'Nigar' => 0] as $name => $points) {
        $students[$name] = Student::factory()->for($class)->create([
            'display_name' => $name,
            'avatar' => Avatars::keys()[count($students)],
        ]);

        if ($points > 0) {
            podiumPageRound($game, $students[$name], $points);
        }
    }

    return $students;
}

it('shows today\'s podium and its card by default', function (): void {
    $students = podiumPageClass($this->class);
    $avatar = static fn (string $name): string => '/content/'.Avatars::image($students[$name]->avatar);

    $this->get(podiumUrl($this->class))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('podium')
            ->where('schoolClass', ['id' => $this->class->id, 'name' => 'Günəşli sinif', 'archived' => false])
            ->where('period', 'day')
            ->where('date', '2026-11-11')
            ->where('label', '11 noyabr 2026')
            ->where('current', true)
            ->where('previous', '2026-11-10')
            ->where('next', null)
            ->where('podium', [
                ['place' => 1, 'place_label' => '1-ci', 'points' => 6, 'students' => [
                    ['id' => $students['Aysel']->id, 'display_name' => 'Aysel', 'avatar' => $students['Aysel']->avatar],
                    ['id' => $students['Əli']->id, 'display_name' => 'Əli', 'avatar' => $students['Əli']->avatar],
                ]],
                ['place' => 2, 'place_label' => '2-ci', 'points' => 4, 'students' => [
                    ['id' => $students['Leyla']->id, 'display_name' => 'Leyla', 'avatar' => $students['Leyla']->avatar],
                ]],
                ['place' => 3, 'place_label' => '3-cü', 'points' => 3, 'students' => [
                    ['id' => $students['Murad']->id, 'display_name' => 'Murad', 'avatar' => $students['Murad']->avatar],
                ]],
            ])
            ->has('avatars', count(Avatars::keys()))
            ->where('card', [
                'title' => 'Günün qalibləri',
                'label' => '11 noyabr 2026',
                'places' => [
                    ['place' => 1, 'place_label' => '1-ci', 'points_label' => '6 xal', 'students' => [
                        ['display_name' => 'Aysel', 'avatar_url' => $avatar('Aysel')],
                        ['display_name' => 'Əli', 'avatar_url' => $avatar('Əli')],
                    ]],
                    ['place' => 2, 'place_label' => '2-ci', 'points_label' => '4 xal', 'students' => [
                        ['display_name' => 'Leyla', 'avatar_url' => $avatar('Leyla')],
                    ]],
                    ['place' => 3, 'place_label' => '3-cü', 'points_label' => '3 xal', 'students' => [
                        ['display_name' => 'Murad', 'avatar_url' => $avatar('Murad')],
                    ]],
                ],
                'wordmark' => 'Elifba',
                'domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
                'file_name' => 'elifba-gunun-qalibleri-2026-11-11.png',
            ]));
});

it('gives the card exactly §6\'s list and nothing §7 keeps off it', function (): void {
    $students = podiumPageClass($this->class);

    $card = $this->get(podiumUrl($this->class, ['period' => 'week']))->assertOk()->inertiaProps('card');

    expect(keyPaths($card))->toBe([
        'domain',
        'file_name',
        'label',
        'places',
        'places.*.place',
        'places.*.place_label',
        'places.*.points_label',
        'places.*.students',
        'places.*.students.*.avatar_url',
        'places.*.students.*.display_name',
        'title',
        'wordmark',
    ]);

    $json = json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    foreach ([
        'the class name' => 'Günəşli sinif',
        'the class id' => $this->class->id,
        'the teacher\'s name' => 'Nərmin',
        'the teacher\'s e-mail' => $this->teacher->email,
        'the 4th place' => 'Tural',
        'a student without points' => 'Nigar',
        'a link' => 'http',
        'a time' => '14:00',
    ] as $what => $needle) {
        expect($json)->not->toContain($needle, "The card holds {$what}.");
    }

    foreach ($students as $student) {
        expect($json)->not->toContain($student->id);
    }

    expect($card['places'][0]['students'][0]['avatar_url'])->toStartWith('/content/avatars/');
});

it('shows the week that holds the date, with its own title and file name', function (): void {
    $aysel = Student::factory()->for($this->class)->create(['display_name' => 'Aysel']);
    $game = Games::start($this->class);
    podiumPageRound($game, $aysel, 3, '2026-11-02 08:00:00');
    podiumPageRound($game, $aysel, 1, '2026-11-08 23:59:00');
    podiumPageRound($game, $aysel, 2, '2026-11-09 00:00:00');

    $this->get(podiumUrl($this->class, ['period' => 'week', 'date' => '2026-11-05']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('period', 'week')
            ->where('date', '2026-11-05')
            ->where('label', '2–8 noyabr 2026')
            ->where('current', false)
            ->where('previous', '2026-10-26')
            ->where('next', '2026-11-09')
            ->where('podium.0.points', 4)
            ->where('card.title', 'Həftənin qalibləri')
            ->where('card.label', '2–8 noyabr 2026')
            ->where('card.places.0.points_label', '4 xal')
            // The week's file is named after its Monday.
            ->where('card.file_name', 'elifba-heftenin-qalibleri-2026-11-02.png'));

    $this->get(podiumUrl($this->class, ['period' => 'week']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('label', '9–15 noyabr 2026')
            ->where('card.file_name', 'elifba-heftenin-qalibleri-2026-11-09.png'));
});

it('shows a full name on the podium and only the first name on the card (ADR 018)', function (): void {
    $nihat = Student::factory()->for($this->class)->create(['display_name' => 'Nihat Əliyev Vəli oğlu']);
    $register = Student::factory()->for($this->class)->create(['display_name' => 'Məmmədova Aysel']);
    $game = Games::start($this->class);
    podiumPageRound($game, $nihat, 3);
    podiumPageRound($game, $register, 2);

    $this->get(podiumUrl($this->class))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('podium.0.students.0.display_name', 'Nihat Əliyev Vəli oğlu')
            ->where('podium.1.students.0.display_name', 'Məmmədova Aysel')
            ->where('card.places.0.students.0.display_name', 'Nihat')
            ->where('card.places.1.students.0.display_name', 'Aysel'));
});

it('writes the card in the teacher\'s language', function (): void {
    $this->teacher->forceFill(['locale' => 'en'])->save();
    $aysel = Student::factory()->for($this->class)->create(['display_name' => 'Aysel']);
    $ali = Student::factory()->for($this->class)->create(['display_name' => 'Əli']);
    $game = Games::start($this->class);
    podiumPageRound($game, $aysel, 3, '2026-11-09 10:00:00');
    podiumPageRound($game, $ali, 1, '2026-11-10 10:00:00');

    $this->get(podiumUrl($this->class, ['period' => 'week']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            ->where('label', '9–15 November 2026')
            ->where('podium.0.place_label', '1st')
            ->where('card.title', 'Winners of the week')
            ->where('card.label', '9–15 November 2026')
            ->where('card.places.0.place_label', '1st')
            ->where('card.places.0.points_label', '3 points')
            ->where('card.places.1.place_label', '2nd')
            ->where('card.places.1.points_label', '1 point')
            // Content and file names stay Azerbaijani.
            ->where('card.places.1.students.0.display_name', 'Əli')
            ->where('card.file_name', 'elifba-heftenin-qalibleri-2026-11-09.png')
            ->where('translations', fn ($translations): bool => $translations['podium.title'] === 'Winners'));

    $this->teacher->forceFill(['locale' => 'az'])->save();

    $this->get(podiumUrl($this->class, ['date' => '2026-11-10']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('card.title', 'Günün qalibləri')
            ->where('card.label', '10 noyabr 2026')
            ->where('card.places.0.students.0.display_name', 'Əli')
            ->where('card.places.0.place_label', '1-ci')
            ->where('card.places.0.points_label', '1 xal'));
});

it('redirects an invalid or future period or date to the query without it', function (array $query, array $valid): void {
    $this->get(podiumUrl($this->class, $query))->assertRedirect(podiumUrl($this->class, $valid));
})->with([
    'an unknown period' => [['period' => 'month'], []],
    'an unknown period with a date' => [['period' => 'year', 'date' => '2026-11-03'], ['date' => '2026-11-03']],
    'tomorrow in Baku' => [['period' => 'week', 'date' => '2026-11-12'], ['period' => 'week']],
    'a date far ahead' => [['date' => '2099-01-01'], []],
    'a date that does not exist' => [['date' => '2026-02-30'], []],
    'a date in another format' => [['date' => '11.11.2026'], []],
    'an array' => [['date' => ['2026-11-03']], []],
]);

it('has no card when nobody has points', function (): void {
    Student::factory()->for($this->class)->create(['display_name' => 'Aysel']);

    $this->get(podiumUrl($this->class))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('podium', [])
            ->where('card', null));
});

it('opens an archived class\'s podium, as its class page opens', function (): void {
    $class = SchoolClass::factory()->for($this->teacher, 'teacher')->archived()->create();
    $student = Student::factory()->for($class)->create(['display_name' => 'Aysel']);
    podiumPageRound(GameSession::factory()->for($class, 'schoolClass')->create(), $student, 3);

    $this->get(podiumUrl($class))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('schoolClass.archived', true)
            ->where('card.places.0.students.0.display_name', 'Aysel'));
});

it('draws a student whose avatar is gone without a picture', function (): void {
    $student = Student::factory()->for($this->class)->create(['display_name' => 'Aysel']);
    $student->forceFill(['avatar' => 'gone'])->save();
    podiumPageRound(Games::start($this->class), $student, 2);

    $this->get(podiumUrl($this->class))
        ->assertInertia(fn (Assert $page) => $page
            ->where('podium.0.students.0.avatar', 'gone')
            ->where('card.places.0.students.0.avatar_url', null));
});

it('loads the card\'s pictures from the page\'s own origin, whatever host the server saw', function (): void {
    podiumPageClass($this->class);

    $card = $this->get('http://tunnel.example'.route('classes.podium', ['class' => $this->class], false))
        ->assertOk()
        ->inertiaProps('card');

    expect($card['places'][0]['students'][0]['avatar_url'])->toStartWith('/content/avatars/')
        ->and($card['domain'])->toBe(parse_url((string) config('app.url'), PHP_URL_HOST));
});

it('is not found for another teacher', function (): void {
    podiumPageClass($this->class);

    $this->actingAs(User::factory()->create())
        ->get(podiumUrl($this->class))
        ->assertNotFound();
});
