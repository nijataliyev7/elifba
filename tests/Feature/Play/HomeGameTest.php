<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Family\Models\Child;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Events\RoundSolved;
use App\Domain\Play\Models\GameAnswer;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Models\GuestBoard;
use App\Domain\Play\Support\SchoolCalendar;
use App\Domain\Rankings\Data\PeriodData;
use App\Domain\Rankings\Enums\Period;
use App\Domain\Rankings\Support\ClassRanking;
use App\Domain\Rankings\Support\Podium;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Games;
use Tests\Support\GuestBoards;

/*
 * A parent's home game (docs/architecture.md §1, §2 "Game setup", §4 "At
 * home", ADR 011): set up for one child from the child's learned letters,
 * played and recorded by the same board protocol, every solved round
 * credited to the game's child and never to a student, kept out of every
 * class ranking, and sent to a guest board like a class's game.
 */

beforeEach(function (): void {
    // Both roles: the parent is also a teacher with a class, so a student
    // id the board might send exists — and must still never be credited.
    $this->parent = User::factory()->teacherAndParent()->create();
    $this->class = SchoolClass::factory()->for($this->parent, 'teacher')->create();
    $this->student = Student::factory()->for($this->class)->create(['display_name' => 'Nərgiz']);
    $this->child = Child::factory()->for($this->parent, 'parent')->create(['display_name' => 'Firuzə']);
});

/**
 * @param  list<array<string, mixed>>  $events
 */
function homeSync(GameSession $game, array $events): TestResponse
{
    return test()->postJson(route('games.sync', $game), [
        'board_epoch' => $game->fresh()?->board_epoch,
        'events' => $events,
    ]);
}

it('starts a game for a child from the child\'s learned letters', function (): void {
    $learned = ['a', 'b', 'd', 'ə'];
    $this->child->update(['learned_letters' => $learned]);
    // The class has learned other letters: they never reach the home game.
    $this->class->update(['learned_letters' => ['o', 'p', 'r', 's']]);

    $this->actingAs($this->parent)->get(route('children.games.create', $this->child))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('games/setup')
            ->where('schoolClass', null)
            ->where('child', ['id' => $this->child->id, 'display_name' => 'Firuzə'])
            ->where('letters', array_map(static fn (string $letter): array => ['lower' => $letter, 'upper' => Az::upper($letter)], $learned)));

    $response = $this->post(route('children.games.store', $this->child), ['mechanic' => 'find', 'pack' => 'balloons', 'level' => 'letters', 'rounds' => 6]);
    $game = GameSession::query()->sole();

    $response->assertRedirect(route('games.board', $game));
    expect($game->only(['user_id', 'school_class_id', 'child_id']))->toBe([
        'user_id' => $this->parent->id,
        'school_class_id' => null,
        'child_id' => $this->child->id,
    ]);

    $rounds = Games::rounds($game);
    expect($rounds)->toHaveCount(6);

    foreach ($rounds as $round) {
        expect([$round->school_class_id, $round->child_id])->toBe([null, $this->child->id]);

        foreach ($round->payload['items'] as $item) {
            expect(array_map(static fn (string $letter): string => "letter:{$letter}", $learned))->toContain($item['content_key']);
        }
    }

    // A letter the child has not learned is refused, as for a class.
    $this->post(route('children.games.store', $this->child), ['mechanic' => 'find', 'pack' => 'balloons', 'level' => 'letters', 'letters' => ['a', 'o']])
        ->assertSessionHasErrors('letters.1');
    expect(GameSession::query()->count())->toBe(1);
});

it('starts a picture game and a counting game for a child, whatever letters the child has learned', function (string $pack): void {
    $this->child->update(['learned_letters' => ['a']]);

    $this->actingAs($this->parent)->get(route('children.games.create', $this->child))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('options', fn ($options): bool => collect($options->all())
                ->where('pack', $pack)
                ->whereIn('level', ['pictures-easy', 'pictures', 'numbers-5', 'numbers-10'])
                ->every(fn (array $option): bool => $option['startable'] && $option['own'] === null)));

    foreach ([['find', 'pictures'], ['pop', 'pictures-easy'], ['count', 'numbers-10']] as [$mechanic, $level]) {
        $this->post(route('children.games.store', $this->child), ['mechanic' => $mechanic, 'pack' => $pack, 'level' => $level, 'rounds' => 6])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $game = GameSession::query()->where('mechanic', $mechanic)->sole();
        $rounds = array_map(static fn (GameRound $round) => $round->snapshot(6), Games::rounds($game));

        expect($game->only(['user_id', 'school_class_id', 'child_id', 'pack', 'level']))->toBe([
            'user_id' => $this->parent->id,
            'school_class_id' => null,
            'child_id' => $this->child->id,
            'pack' => $pack,
            'level' => $level,
        ])->and($rounds)->toHaveCount(6);

        foreach ($rounds as $round) {
            expect($round->unit->value)->toBe($mechanic === 'count' ? 'number' : 'picture')
                ->and($round->prompt->count !== null)->toBe($mechanic === 'count')
                ->and($round->prompt->image !== null)->toBe($mechanic === 'count');
        }
    }

    // A child's solved count round gives stars as any other (no student id is stored).
    $game = GameSession::query()->where('mechanic', 'count')->sole();
    $round = Games::round($game);

    homeSync($game, [Games::hit($round, Games::target($round))])
        ->assertOk()
        ->assertJsonPath('results.0.points', 3);

    expect($round->fresh()?->student_id)->toBeNull()
        ->and($round->fresh()?->child_id)->toBe($this->child->id);
})->with(['animals', 'fruit']);

it('opens the board of a home game with the child and no students', function (): void {
    $earlier = Games::start($this->child, seed: 1);
    $round = Games::round($earlier);
    $this->actingAs($this->parent);
    homeSync($earlier, [Games::hit($round, Games::target($round))])->assertOk();
    $game = Games::start($this->child, seed: 2);

    $this->get(route('games.board', $game))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('games/board')
            ->where('schoolClass', null)
            ->where('child.id', $this->child->id)
            ->where('child.display_name', 'Firuzə')
            ->where('child.avatar.key', $this->child->avatar)
            // The child's total before this game, for the result screen.
            ->where('child.stars', 3)
            ->where('students', []));
});

it('credits every solved round to the game\'s child, whatever student id the board sends', function (): void {
    Event::fake([RoundSolved::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-11 08:00:00', 'UTC'));
    $game = Games::start($this->child);
    [$first, $second, $third] = Games::rounds($game);
    $this->actingAs($this->parent);

    $results = homeSync($game, [
        // A student of the parent's own class, an unknown id and none.
        Games::hit($first, Games::target($first), $this->student->id),
        Games::hit($second, Games::decoy($second), (string) Str::ulid()),
        Games::hit($second, Games::target($second), (string) Str::ulid()),
        Games::hit($third, Games::target($third), null, source: 'remote'),
    ])->assertOk()->json('results');

    expect(array_column($results, 'status'))->toBe(['correct', 'miss', 'correct', 'correct'])
        ->and(array_column($results, 'points'))->toBe([3, null, 2, 3]);

    foreach ([$first, $second, $third] as $round) {
        $round->refresh();
        expect($round->child_id)->toBe($this->child->id)
            ->and($round->student_id)->toBeNull()
            ->and($round->school_class_id)->toBeNull()
            ->and($round->played_on)->toBe('2026-11-11')
            ->and($round->week_start)->toBe('2026-11-09');
    }

    Event::assertDispatchedTimes(RoundSolved::class, 3);
    Event::assertDispatched(RoundSolved::class, fn (RoundSolved $event): bool => $event->roundId === $first->id
        && $event->childId === $this->child->id
        && $event->schoolClassId === null
        && $event->studentId === null
        && $event->points === 3);
});

it('stores no student on a home round or answer', function (): void {
    $game = Games::start($this->child);
    $board = GuestBoards::showing($game);
    $rounds = Games::rounds($game);
    $events = [];

    foreach ($rounds as $index => $round) {
        $events[] = Games::hit($round, Games::decoy($round), $this->student->id, source: $index % 2 === 0 ? 'board' : 'remote');
        $events[] = Games::hit($round, Games::target($round), $this->student->id);
    }

    $this->postJson(route('games.board.claim', $game), [], GuestBoards::bearer($board['token']))->assertOk();
    $this->postJson(route('games.sync', $game), ['board_epoch' => 1, 'events' => $events], GuestBoards::bearer($board['token']))
        ->assertOk()
        ->assertJsonPath('game.status', 'finished');

    expect(GameAnswer::query()->count())->toBe(10)
        ->and(GameAnswer::query()->whereNotNull('student_id')->count())->toBe(0)
        ->and(GameRound::query()->whereNotNull('student_id')->count())->toBe(0)
        ->and(GameRound::query()->where('child_id', $this->child->id)->sum('points'))->toEqual(10);
});

it('keeps a home round out of every class ranking and podium', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 08:00:00', 'UTC'));
    $game = Games::start($this->child);
    $this->actingAs($this->parent);
    homeSync($game, array_map(
        fn (GameRound $round): array => Games::hit($round, Games::target($round), $this->student->id),
        Games::rounds($game),
    ))->assertOk();

    expect(GameRound::query()->sum('points'))->toEqual(15);

    foreach ([Period::Day, Period::Week] as $period) {
        $window = new PeriodData($period, SchoolCalendar::today());
        $ranking = ClassRanking::for($this->class, $window);

        expect(array_map(static fn ($row): array => [$row->student->id, $row->points, $row->place], $ranking))
            ->toBe([[$this->student->id, 0, null]])
            ->and(Podium::for($this->class, $window))->toBe([]);
    }

    $this->get(route('classes.rankings', $this->class))
        ->assertOk()
        ->assertDontSee('Firuzə')
        ->assertInertia(fn (Assert $page) => $page->where('students.0.points', 0));
    $this->get(route('classes.podium', $this->class))
        ->assertOk()
        ->assertDontSee('Firuzə')
        ->assertInertia(fn (Assert $page) => $page->where('card', null));

    // No child route leads to a ranking or a podium.
    $childRoutes = array_filter(
        Route::getRoutes()->getRoutes(),
        static fn ($route): bool => in_array('child', $route->parameterNames(), true),
    );
    expect(array_filter($childRoutes, static fn ($route): bool => str_contains((string) $route->getActionName(), 'Rankings')))->toBe([]);
});

it('sends a home game to a guest board from the parent\'s phone', function (): void {
    $parent = User::factory()->parent()->create();
    $child = Child::factory()->for($parent, 'parent')->create(['display_name' => 'Tural', 'learned_letters' => ['a', 'b', 'd', 'ə']]);
    $foreign = Child::factory()->create();
    $opened = GuestBoards::open();
    $code = (string) $opened['board']->code;
    $setup = ['mechanic' => 'find', 'pack' => 'balloons', 'level' => 'letters', 'rounds' => 5];

    $this->actingAs($parent)->get(route('pult.code', $code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('pult/guest-board')
            ->where('classes', [])
            ->where('children', [['id' => $child->id, 'display_name' => 'Tural']])
            ->has("setup.children.{$child->id}.options")
            ->where("setup.children.{$child->id}.letters.3", ['lower' => 'ə', 'upper' => 'Ə'])
            ->where('setup.classes', []));

    // Another parent's child is not found, like an unknown id.
    $this->from(route('pult.code', $code))->post(route('pult.claim', $code), ['child' => $foreign->id, ...$setup])
        ->assertSessionHasErrors('child');
    expect(GameSession::query()->count())->toBe(0);

    $response = $this->post(route('pult.claim', $code), ['child' => $child->id, ...$setup]);
    $game = GameSession::query()->sole();
    $board = GuestBoard::query()->sole();

    $response->assertRedirect(route('games.remote', $game));
    expect([$game->user_id, $game->child_id, $game->school_class_id])->toBe([$parent->id, $child->id, null])
        ->and($board->game_session_id)->toBe($game->id)
        ->and($board->user_id)->toBe($parent->id);

    // The next game, without a new code; a class and a child at once is refused.
    $this->from(route('guest-boards.game.edit', $board))
        ->put(route('guest-boards.game.update', $board), ['child' => $child->id, 'class' => $this->class->id, ...$setup])
        ->assertSessionHasErrors('class');
    $next = $this->put(route('guest-boards.game.update', $board), ['child' => $child->id, ...$setup, 'rounds' => 6]);
    $second = GameSession::query()->whereKeyNot($game->id)->sole();

    $next->assertRedirect(route('games.remote', $second));
    expect($board->refresh()->game_session_id)->toBe($second->id)
        ->and($second->child_id)->toBe($child->id);

    // The pult lists both games under the child's name.
    $this->get(route('pult', ['list' => 1]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('games.0.child', ['id' => $child->id, 'display_name' => 'Tural'])
            ->where('games.0.schoolClass', null));
});

it('lets the bearer reach only the home game its board shows', function (): void {
    $shown = Games::start($this->child, seed: 1);
    $other = Games::start($this->child, seed: 2);
    $foreign = Games::start(Child::factory()->create(['display_name' => 'Leyla']), seed: 3);
    $board = GuestBoards::showing($shown);
    $bearer = GuestBoards::bearer($board['token']);
    $round = Games::round($shown);

    $this->postJson(route('games.board.claim', $shown), [], $bearer)->assertOk();
    $this->getJson(route('games.state', $shown), $bearer)->assertOk()->assertDontSee('Firuzə');
    $this->postJson(route('games.sync', $shown), ['board_epoch' => 1, 'events' => [Games::hit($round, Games::target($round))]], $bearer)
        ->assertOk()
        ->assertJsonPath('results.0.status', 'correct');

    // The child's name reaches the bearer only in the board props of the game it shows.
    $this->getJson(route('guest-boards.show', $board['board']), $bearer)
        ->assertOk()
        ->assertJsonPath('props.child.display_name', 'Firuzə')
        ->assertJsonPath('props.students', [])
        ->assertJsonPath('props.schoolClass', null);

    foreach ([$other, $foreign] as $game) {
        $this->postJson(route('games.board.claim', $game), [], $bearer)->assertNotFound();
        $this->getJson(route('games.state', $game), $bearer)->assertNotFound();
        $this->patchJson(route('games.status.update', $game), ['status' => 'finished'], $bearer)->assertNotFound();
    }

    // The parent's pages answer the bearer like a visitor.
    $this->getJson(route('children.index'), $bearer)->assertUnauthorized();
    $this->getJson(route('children.show', $this->child), $bearer)->assertUnauthorized();
    $this->postJson(route('children.games.store', $this->child), ['mechanic' => 'find', 'pack' => 'balloons', 'level' => 'letters'], $bearer)->assertUnauthorized();

    expect($other->refresh()->board_epoch)->toBe(0)
        ->and($foreign->refresh()->board_epoch)->toBe(0)
        ->and(GameSession::query()->count())->toBe(3);
});
