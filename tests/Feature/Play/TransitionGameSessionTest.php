<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Play\Actions\TransitionGameSession;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Events\GameSessionTransitioned;
use App\Domain\Play\Events\RoundSolved;
use App\Domain\Play\Exceptions\InvalidTransition;
use App\Domain\Play\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Games;

/*
 * Statuses change only through TransitionGameSession, and facts are events
 * dispatched after the commit: a listener never runs inside the writing
 * transaction (docs/architecture.md §1). No fakes here — real listeners
 * record the transaction level they run at.
 */

beforeEach(function (): void {
    $this->game = GameSession::factory()->create();
    $this->baseline = DB::transactionLevel(); // RefreshDatabase's own transaction
});

it('changes the status along the lifecycle, after the commit', function (): void {
    $seen = [];
    Event::listen(GameSessionTransitioned::class, function (GameSessionTransitioned $event) use (&$seen): void {
        $seen[] = [$event->from->value, $event->to->value, DB::transactionLevel(), DB::table('game_sessions')->where('id', $event->gameId)->value('status')];
    });

    $transition = app(TransitionGameSession::class);
    $transition->handle($this->game, GameSessionStatus::Paused);
    $transition->handle($this->game, GameSessionStatus::Live);
    $transition->handle($this->game, GameSessionStatus::Finished);

    expect($seen)->toBe([
        ['live', 'paused', $this->baseline, 'paused'],
        ['paused', 'live', $this->baseline, 'live'],
        ['live', 'finished', $this->baseline, 'finished'],
    ])->and($this->game->status)->toBe(GameSessionStatus::Finished);
});

it('dispatches nothing when the transaction rolls back', function (): void {
    $seen = 0;
    Event::listen(GameSessionTransitioned::class, function () use (&$seen): void {
        $seen++;
    });

    try {
        DB::transaction(function (): void {
            app(TransitionGameSession::class)->handle($this->game, GameSessionStatus::Paused);

            throw new RuntimeException('Something later failed.');
        });
    } catch (RuntimeException) {
    }

    expect($seen)->toBe(0)
        ->and($this->game->fresh()?->status)->toBe(GameSessionStatus::Live);
});

it('dispatches RoundSolved after the commit too', function (): void {
    $teacher = User::factory()->create();
    $class = SchoolClass::factory()->for($teacher, 'teacher')->create();
    $game = Games::start($class);
    $round = Games::round($game);
    $levels = [];

    Event::listen(RoundSolved::class, function () use (&$levels, $round): void {
        $levels[] = [DB::transactionLevel(), $round->fresh()?->solved_at !== null];
    });

    $this->actingAs($teacher)
        ->postJson(route('games.sync', $game), ['board_epoch' => 0, 'events' => [Games::hit($round, Games::target($round))]])
        ->assertOk();

    expect($levels)->toBe([[$this->baseline, true]]);
});

it('ends a game for good: ended_at set, join code cleared', function (GameSessionStatus $to): void {
    $this->game->forceFill(['join_code' => '482913', 'join_code_expires_at' => now()->addMinutes(10)])->save();

    app(TransitionGameSession::class)->handle($this->game, $to);

    $game = $this->game->fresh();
    expect($game?->status)->toBe($to)
        ->and($game?->ended_at)->not->toBeNull()
        ->and($game?->join_code)->toBeNull()
        ->and($game?->join_code_expires_at)->toBeNull();
})->with([GameSessionStatus::Finished, GameSessionStatus::Abandoned]);

it('refuses a change the lifecycle does not allow', function (GameSessionStatus $from, GameSessionStatus $to): void {
    Event::fake([GameSessionTransitioned::class]);
    $this->game->forceFill(['status' => $from])->save();

    expect(fn () => app(TransitionGameSession::class)->handle($this->game, $to))->toThrow(InvalidTransition::class);

    expect($this->game->fresh()?->status)->toBe($from);
    Event::assertNotDispatched(GameSessionTransitioned::class);
})->with([
    'finished → live' => [GameSessionStatus::Finished, GameSessionStatus::Live],
    'finished → paused' => [GameSessionStatus::Finished, GameSessionStatus::Paused],
    'abandoned → live' => [GameSessionStatus::Abandoned, GameSessionStatus::Live],
    'abandoned → finished' => [GameSessionStatus::Abandoned, GameSessionStatus::Finished],
    'finished → abandoned' => [GameSessionStatus::Finished, GameSessionStatus::Abandoned],
]);

it('changes nothing when the game already has the status', function (): void {
    Event::fake([GameSessionTransitioned::class]);

    app(TransitionGameSession::class)->handle($this->game, GameSessionStatus::Live);

    Event::assertNotDispatched(GameSessionTransitioned::class);
});
