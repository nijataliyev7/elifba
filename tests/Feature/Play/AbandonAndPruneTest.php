<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Play\Actions\AbandonStaleGames;
use App\Domain\Play\Actions\PruneOldGames;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Events\GameSessionTransitioned;
use App\Domain\Play\Models\GameAnswer;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Tests\Support\Games;

/*
 * The scheduled jobs of Play (docs/architecture.md §1, §7): games idle for
 * elifba.abandon_after_hours are abandoned (hourly), games older than
 * elifba.retention_days are deleted with their rounds and answers (daily).
 */

describe('abandoning stale games', function (): void {
    it('abandons live and paused games idle for longer than the setting', function (): void {
        Event::fake([GameSessionTransitioned::class]);
        $this->travelTo(now()->startOfSecond());

        $idleLive = GameSession::factory()->create(['last_activity_at' => now()->subHours(6)->subSecond()]);
        $idlePaused = GameSession::factory()->status(GameSessionStatus::Paused)->create(['last_activity_at' => now()->subDays(2)]);
        $recent = GameSession::factory()->create(['last_activity_at' => now()->subHours(6)->addSecond()]);
        $finished = GameSession::factory()->status(GameSessionStatus::Finished)->create(['last_activity_at' => now()->subDays(2)]);

        expect(app(AbandonStaleGames::class)->handle())->toBe(2)
            ->and($idleLive->fresh()?->status)->toBe(GameSessionStatus::Abandoned)
            ->and($idleLive->fresh()?->ended_at)->not->toBeNull()
            ->and($idlePaused->fresh()?->status)->toBe(GameSessionStatus::Abandoned)
            ->and($recent->fresh()?->status)->toBe(GameSessionStatus::Live)
            ->and($finished->fresh()?->status)->toBe(GameSessionStatus::Finished);

        Event::assertDispatchedTimes(GameSessionTransitioned::class, 2);
        Event::assertDispatched(GameSessionTransitioned::class, fn (GameSessionTransitioned $event): bool => $event->gameId === $idlePaused->id && $event->from === GameSessionStatus::Paused && $event->to === GameSessionStatus::Abandoned);
    });

    it('keeps a game a board synced a moment ago', function (): void {
        $teacher = User::factory()->create();
        $game = Games::start(SchoolClass::factory()->for($teacher, 'teacher')->create());
        $game->forceFill(['last_activity_at' => now()->subHours(7)])->save();
        $round = Games::round($game);

        $this->actingAs($teacher)->postJson(route('games.sync', $game), ['board_epoch' => 0, 'events' => [Games::hit($round, Games::decoy($round))]])->assertOk();

        expect(app(AbandonStaleGames::class)->handle())->toBe(0)
            ->and($game->fresh()?->status)->toBe(GameSessionStatus::Live);
    });

    it('runs hourly as play:abandon-stale', function (): void {
        GameSession::factory()->create(['last_activity_at' => now()->subDay()]);

        $this->artisan('play:abandon-stale')->expectsOutputToContain('Abandoned 1 ')->assertSuccessful();

        expect(scheduledCommand('play:abandon-stale')?->expression)->toBe('0 * * * *');
    });
});

describe('pruning old games', function (): void {
    it('deletes games started more than the retention period ago, with their rounds and answers', function (): void {
        $this->travelTo(now()->startOfSecond());
        $class = SchoolClass::factory()->create();
        $old = Games::start($class, seed: 1);
        $kept = Games::start($class, seed: 2);
        $old->forceFill(['started_at' => now()->subDays(400)->subSecond(), 'status' => GameSessionStatus::Finished])->save();
        $kept->forceFill(['started_at' => now()->subDays(400)->addSecond(), 'status' => GameSessionStatus::Finished])->save();
        GameAnswer::factory()->for(Games::round($old), 'round')->create();
        GameAnswer::factory()->for(Games::round($kept), 'round')->create();

        expect(app(PruneOldGames::class)->handle())->toBe(1)
            ->and(GameSession::query()->pluck('id')->all())->toBe([$kept->id])
            ->and(GameRound::query()->where('game_session_id', $old->id)->count())->toBe(0)
            ->and(GameRound::query()->where('game_session_id', $kept->id)->count())->toBe(5)
            ->and(GameAnswer::query()->count())->toBe(1);
    });

    it('runs daily as play:prune-old-games', function (): void {
        GameSession::factory()->create(['started_at' => now()->subDays(500)]);

        $this->artisan('play:prune-old-games')->expectsOutputToContain('Deleted 1 ')->assertSuccessful();

        expect(GameSession::query()->count())->toBe(0)
            ->and(scheduledCommand('play:prune-old-games')?->expression)->toBe('0 0 * * *');
    });
});

it('keeps the rounds and answers of a deleted student, anonymous', function (): void {
    $teacher = User::factory()->create();
    $class = SchoolClass::factory()->for($teacher, 'teacher')->create();
    $student = Student::factory()->for($class)->create();
    $game = Games::start($class);
    $round = Games::round($game);

    $this->actingAs($teacher)->postJson(route('games.sync', $game), ['board_epoch' => 0, 'events' => [Games::hit($round, Games::target($round), $student->id)]])->assertOk();
    $student->delete();

    expect($round->fresh()?->student_id)->toBeNull()
        ->and($round->fresh()?->points)->toBe(3)
        ->and(GameAnswer::query()->sole()->student_id)->toBeNull();
});

function scheduledCommand(string $command): ?Illuminate\Console\Scheduling\Event
{
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains((string) $event->command, $command)) {
            return $event;
        }
    }

    return null;
}
