<?php

use App\Domain\Content\Support\Avatars;
use App\Domain\Family\Models\Child;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Games;

/*
 * The parent's pages (docs/architecture.md §1, §5 "A child's stars", §7
 * "Parents and their children", ADR 011, ADR 013, R4): «Uşaqlarım» in the
 * parent's own order with today's stars only, a child's page with the
 * child's own stars, and nothing about a child but the first name, the
 * avatar, the learned letters and the stars. No page of the parent area
 * gets a ranking, a place, a podium or a total beside a sibling's.
 */

beforeEach(function (): void {
    // Wednesday 11 November 2026, 12:00 in Baku.
    $this->travelTo(CarbonImmutable::parse('2026-11-11 08:00:00', 'UTC'));
    $this->parent = User::factory()->parent()->create();
    $this->actingAs($this->parent);
});

/**
 * A solved round of a home game, as `RecordBoardEvents` stamps it.
 */
function solvedHomeRound(Child $child, int $points, string $playedOn, string $weekStart): GameRound
{
    return GameRound::factory()->for(GameSession::factory()->forChild($child)->create(), 'game')->create([
        'solved_at' => CarbonImmutable::parse($playedOn.' 12:00:00', 'Asia/Baku')->utc(),
        'points' => $points,
        'played_on' => $playedOn,
        'week_start' => $weekStart,
    ]);
}

/**
 * Every key of a page's props at any depth, but the interface's
 * translations (which name every page of the app), so a test can say what
 * must never be among them.
 *
 * @param  array<array-key, mixed>  $props
 * @return list<string>
 */
function parentPropKeys(array $props): array
{
    $keys = [];

    foreach ($props as $key => $value) {
        if ($key === 'translations') {
            continue;
        }

        $keys[] = (string) $key;

        if (is_array($value)) {
            array_push($keys, ...parentPropKeys($value));
        }
    }

    return $keys;
}

/** Keys of the class side that never reach a parent's page. */
const PARENT_PAGES_NEVER = ['ranking', 'rankings', 'place', 'places', 'podium', 'rank', 'best', 'leader', 'students', 'schoolClass', 'classes'];

it('lists the parent\'s children in their own order with today\'s stars', function (): void {
    $tural = Child::factory()->for($this->parent, 'parent')->create(['display_name' => 'Tural', 'avatar' => 'panda']);
    $aysel = Child::factory()->for($this->parent, 'parent')->create(['display_name' => 'Aysel', 'avatar' => 'rabbit']);
    $nigar = Child::factory()->for($this->parent, 'parent')->create(['display_name' => 'Nigar', 'avatar' => 'owl']);
    // The parent's order, not the alphabet's or the stars'.
    $nigar->update(['position' => 0]);
    Child::factory()->create(['display_name' => 'Leyla']);

    solvedHomeRound($aysel, 3, '2026-11-11', '2026-11-09');
    solvedHomeRound($aysel, 2, '2026-11-11', '2026-11-09');
    solvedHomeRound($aysel, 3, '2026-11-10', '2026-11-09'); // yesterday: not today
    solvedHomeRound($tural, 1, '2026-11-11', '2026-11-09');

    $this->get(route('children.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('children/index')
            ->where('children', [
                ['id' => $nigar->id, 'display_name' => 'Nigar', 'avatar' => 'owl', 'stars_today' => 0],
                ['id' => $tural->id, 'display_name' => 'Tural', 'avatar' => 'panda', 'stars_today' => 1],
                ['id' => $aysel->id, 'display_name' => 'Aysel', 'avatar' => 'rabbit', 'stars_today' => 5],
            ])
            ->where('avatars', Avatars::all())
            ->where('limits', ['max' => 10]));
});

it('never sends a total or an order by stars to the list', function (): void {
    $few = Child::factory()->for($this->parent, 'parent')->create(['display_name' => 'Aysel']);
    $many = Child::factory()->for($this->parent, 'parent')->create(['display_name' => 'Tural']);
    solvedHomeRound($few, 1, '2026-11-11', '2026-11-09');
    solvedHomeRound($many, 3, '2026-11-11', '2026-11-09');
    solvedHomeRound($many, 3, '2026-11-11', '2026-11-09');
    solvedHomeRound($many, 3, '2026-11-02', '2026-11-02');

    $this->get(route('children.index'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($few, $many): void {
            $page->component('children/index')
                // The parent's order stands, though the second child has more stars.
                ->where('children.0.id', $few->id)
                ->where('children.1.id', $many->id);

            $props = $page->toArray()['props'];

            foreach ($props['children'] as $child) {
                expect(array_keys($child))->toBe(['id', 'display_name', 'avatar', 'stars_today']);
            }

            expect(array_keys($props))->not->toContain('total', 'stars', 'week')
                ->and(array_intersect(parentPropKeys($props), PARENT_PAGES_NEVER))->toBe([]);
        });
});

it('shows a child\'s stars and seven days', function (): void {
    $child = Child::factory()->for($this->parent, 'parent')->create(['display_name' => 'Aysel']);
    $sibling = Child::factory()->for($this->parent, 'parent')->create(['display_name' => 'Tural']);
    solvedHomeRound($child, 3, '2026-11-11', '2026-11-09');
    solvedHomeRound($child, 2, '2026-11-09', '2026-11-09');
    solvedHomeRound($child, 1, '2026-11-05', '2026-11-02');
    solvedHomeRound($child, 3, '2026-10-01', '2026-09-28');
    solvedHomeRound($sibling, 3, '2026-11-11', '2026-11-09');

    $this->get(route('children.show', $child))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('children/show')
            ->where('stars.today', 3)
            ->where('stars.week', 5)
            ->where('stars.total', 9)
            ->where('stars.days', [
                ['date' => '2026-11-05', 'label' => '5 noyabr 2026', 'stars' => 1],
                ['date' => '2026-11-06', 'label' => '6 noyabr 2026', 'stars' => 0],
                ['date' => '2026-11-07', 'label' => '7 noyabr 2026', 'stars' => 0],
                ['date' => '2026-11-08', 'label' => '8 noyabr 2026', 'stars' => 0],
                ['date' => '2026-11-09', 'label' => '9 noyabr 2026', 'stars' => 2],
                ['date' => '2026-11-10', 'label' => '10 noyabr 2026', 'stars' => 0],
                ['date' => '2026-11-11', 'label' => '11 noyabr 2026', 'stars' => 3],
            ])
            ->where('retentionDays', 400));
});

it('sends no field about a child but name, avatar, letters and stars', function (): void {
    $child = Child::factory()->for($this->parent, 'parent')->create([
        'display_name' => 'Aysel',
        'avatar' => 'rabbit',
        'learned_letters' => ['a', 'b', 'ə'],
    ]);

    $this->get(route('children.show', $child))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($child): void {
            $page->component('children/show')
                ->where('child', [
                    'id' => $child->id,
                    'display_name' => 'Aysel',
                    'avatar' => 'rabbit',
                    'learned_letters' => ['a', 'b', 'ə'],
                ])
                ->has('alphabet', count(Alphabet::letters()))
                ->has('avatars');

            $props = $page->toArray()['props'];
            $own = array_diff(array_keys($props), ['errors', 'auth', 'locale', 'locales', 'translations', 'sidebarOpen', 'name', 'flash']);

            expect(array_values($own))->toEqualCanonicalizing(['child', 'stars', 'alphabet', 'avatars', 'retentionDays'])
                ->and(array_keys($props['stars']))->toBe(['today', 'week', 'total', 'days'])
                ->and(array_intersect(parentPropKeys($props), PARENT_PAGES_NEVER))->toBe([]);
        });
});

it('sends no ranking or podium to a page of the parent area', function (): void {
    $child = Child::factory()->for($this->parent, 'parent')->create(['display_name' => 'Aysel']);
    solvedHomeRound($child, 3, '2026-11-11', '2026-11-09');
    $game = Games::start($child);

    foreach ([
        route('children.index'),
        route('children.show', $child),
        route('children.games.create', $child),
        route('games.board', $game),
        route('games.remote', $game),
    ] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($url): void {
                $props = $page->toArray()['props'];
                // A home game's props name the class only to say there is none.
                $keys = array_diff(parentPropKeys($props), ['schoolClass', 'students', 'classes']);

                expect(array_intersect($keys, PARENT_PAGES_NEVER))->toBe([], $url)
                    ->and($props['schoolClass'] ?? null)->toBeNull()
                    ->and($props['students'] ?? [])->toBe([]);
            });
    }
});
