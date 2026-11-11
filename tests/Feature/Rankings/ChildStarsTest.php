<?php

use App\Domain\Family\Actions\DeleteChild;
use App\Domain\Family\Models\Child;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Domain\Rankings\Support\ChildStars;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * A child's stars (docs/architecture.md §5 "A child's stars", ADR 011):
 * the points of the child's solved rounds, derived on every read, on the
 * Baku `played_on` and `week_start` the rounds carry — today, this week, in
 * all and the last seven days. Never beside a sibling's, never a place.
 */

beforeEach(function (): void {
    $this->parent = User::factory()->parent()->create();
    $this->child = Child::factory()->for($this->parent, 'parent')->create(['display_name' => 'Firuzə']);
});

/**
 * A solved home round of the child's game, as `RecordBoardEvents` stamps it.
 */
function starRound(GameSession $game, int $points, string $playedOn, string $weekStart): GameRound
{
    return GameRound::factory()->for($game, 'game')->create([
        'solved_at' => CarbonImmutable::parse($playedOn.' 12:00:00', 'Asia/Baku')->utc(),
        'points' => $points,
        'played_on' => $playedOn,
        'week_start' => $weekStart,
    ]);
}

it('sums today, the week and all across Baku midnight and Monday', function (): void {
    // Monday 16 November 2026, 00:30 in Baku = Sunday 15th, 20:30 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-11-15 20:30:00', 'UTC'));
    $game = GameSession::factory()->forChild($this->child)->create();

    starRound($game, 3, '2026-11-16', '2026-11-16'); // today, Monday
    starRound($game, 2, '2026-11-15', '2026-11-09'); // yesterday, Sunday: last week
    starRound($game, 1, '2026-11-09', '2026-11-09'); // last Monday
    starRound($game, 3, '2026-10-01', '2026-09-28'); // long ago
    // An open and a skipped round count nothing.
    GameRound::factory()->for($game, 'game')->create();
    GameRound::factory()->for($game, 'game')->create(['skipped_at' => now()]);

    $stars = ChildStars::of($this->child, 'az');

    expect([$stars->today, $stars->week, $stars->total])->toBe([3, 3, 9])
        ->and(array_column($stars->days, 'date'))->toBe(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15', '2026-11-16'])
        ->and(array_column($stars->days, 'stars'))->toBe([0, 0, 0, 0, 0, 2, 3])
        ->and($stars->days[6]['label'])->toBe('16 noyabr 2026')
        ->and(ChildStars::of($this->child, 'en')->days[6]['label'])->toBe('16 November 2026')
        ->and(ChildStars::today($this->parent))->toBe([$this->child->id => 3]);

    // A minute before Baku midnight it was still Sunday: the week before.
    $this->travelTo(CarbonImmutable::parse('2026-11-15 19:59:00', 'UTC'));
    $sunday = ChildStars::of($this->child);

    expect([$sunday->today, $sunday->week, $sunday->total])->toBe([2, 3, 9])
        ->and($sunday->days[6])->toMatchArray(['date' => '2026-11-15', 'stars' => 2]);
});

it('keeps siblings\' stars apart', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 08:00:00', 'UTC'));
    $sibling = Child::factory()->for($this->parent, 'parent')->create(['display_name' => 'Tural']);
    $quiet = Child::factory()->for($this->parent, 'parent')->create(['display_name' => 'Nigar']);
    $stranger = Child::factory()->create(['display_name' => 'Leyla']);

    starRound(GameSession::factory()->forChild($this->child)->create(), 3, '2026-11-11', '2026-11-09');
    starRound(GameSession::factory()->forChild($sibling)->create(), 1, '2026-11-11', '2026-11-09');
    starRound(GameSession::factory()->forChild($sibling)->create(), 2, '2026-11-10', '2026-11-09');
    starRound(GameSession::factory()->forChild($stranger)->create(), 3, '2026-11-11', '2026-11-09');

    expect(ChildStars::of($this->child)->toArray())->toMatchArray(['today' => 3, 'week' => 3, 'total' => 3])
        ->and(ChildStars::of($sibling)->toArray())->toMatchArray(['today' => 1, 'week' => 3, 'total' => 3])
        ->and(ChildStars::of($quiet)->toArray())->toMatchArray(['today' => 0, 'week' => 0, 'total' => 0])
        // In the parent's order, never by stars; another parent's child is not there.
        ->and(ChildStars::today($this->parent))->toBe([$this->child->id => 3, $sibling->id => 1, $quiet->id => 0]);

    // The list sends each child's stars of today only, in the parent's order.
    $this->actingAs($this->parent)->get(route('children.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('children/index')
            ->where('children', [
                ['id' => $this->child->id, 'display_name' => 'Firuzə', 'avatar' => $this->child->avatar, 'stars_today' => 3],
                ['id' => $sibling->id, 'display_name' => 'Tural', 'avatar' => $sibling->avatar, 'stars_today' => 1],
                ['id' => $quiet->id, 'display_name' => 'Nigar', 'avatar' => $quiet->avatar, 'stars_today' => 0],
            ]));

    // A child's page has only that child's stars.
    $this->get(route('children.show', $sibling))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('children/show')
            ->where('stars.today', 1)
            ->where('stars.week', 3)
            ->where('stars.total', 3)
            ->has('stars.days', 7)
            ->missing('stars.place'));
});

it('counts nothing of a deleted child', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 08:00:00', 'UTC'));
    $sibling = Child::factory()->for($this->parent, 'parent')->create();
    starRound(GameSession::factory()->forChild($this->child)->create(), 3, '2026-11-11', '2026-11-09');
    starRound(GameSession::factory()->forChild($sibling)->create(), 2, '2026-11-11', '2026-11-09');

    app(DeleteChild::class)->handle($this->child);

    expect(GameRound::query()->where('child_id', $this->child->id)->count())->toBe(0)
        ->and(GameRound::query()->sum('points'))->toEqual(2)
        ->and(ChildStars::today($this->parent))->toBe([$sibling->id => 2])
        ->and(ChildStars::of($this->child)->toArray())->toMatchArray(['today' => 0, 'week' => 0, 'total' => 0]);
});
