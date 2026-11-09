<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Play\Actions\AbandonStaleGames;
use App\Domain\Play\Actions\TransitionGameSession;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Exceptions\SessionStatusNotBroadcast;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Support\Games;

/*
 * `session.status` on the game's channel (docs/architecture.md §4): sent
 * at once (no queue) after a status change commits, with ids only. Reverb
 * being down never fails the change. Nothing here reaches a real Reverb:
 * tests/Support/FakeReverb records what would have been published.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
    Student::factory()->for($this->class)->create(['display_name' => 'Aysel']);
    $this->game = Games::start($this->class);
});

it('publishes {v: 1, status} on the game\'s private channel, only after the commit', function (): void {
    DB::transaction(function (): void {
        app(TransitionGameSession::class)->handle($this->game, GameSessionStatus::Paused);

        expect($this->reverb->sent)->toBe([]);
    });

    expect($this->reverb->sent)->toBe([[
        'channels' => ['private-game.'.$this->game->id],
        'event' => 'session.status',
        'data' => ['v' => 1, 'status' => 'paused'],
    ]]);
});

it('publishes nothing when the transaction rolls back', function (): void {
    try {
        DB::transaction(function (): void {
            app(TransitionGameSession::class)->handle($this->game, GameSessionStatus::Paused);

            throw new RuntimeException('Rolled back.');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($this->reverb->sent)->toBe([])
        ->and($this->game->refresh()->status)->toBe(GameSessionStatus::Live);
});

it('publishes every change made through HTTP or by the scheduler, and never a name', function (): void {
    $this->actingAs($this->teacher);

    $this->patchJson(route('games.status.update', $this->game), ['status' => 'paused'])->assertOk();
    $this->patchJson(route('games.status.update', $this->game), ['status' => 'paused'])->assertOk();
    $this->patchJson(route('games.status.update', $this->game), ['status' => 'live'])->assertOk();

    $this->travel(config('elifba.abandon_after_hours') + 1)->hours();
    app(AbandonStaleGames::class)->handle();

    expect(array_column(array_column($this->reverb->sent, 'data'), 'status'))->toBe(['paused', 'live', 'abandoned'])
        ->and(json_encode($this->reverb->sent, JSON_UNESCAPED_UNICODE))->not->toContain('Aysel');
});

it('publishes `finished` when the last round is solved through /sync', function (): void {
    $this->actingAs($this->teacher);
    $game = Games::start($this->class, ['rounds' => 5], seed: 7);
    $this->postJson(route('games.board.claim', $game))->assertOk();

    $events = array_map(static fn ($round): array => Games::skip($round), Games::rounds($game));
    $this->postJson(route('games.sync', $game), ['board_epoch' => 1, 'events' => $events])
        ->assertOk()
        ->assertJsonPath('game.status', 'finished');

    expect($this->reverb->sent)->toHaveCount(1)
        ->and($this->reverb->sent[0]['channels'])->toBe(['private-game.'.$game->id])
        ->and($this->reverb->sent[0]['data'])->toBe(['v' => 1, 'status' => 'finished']);
});

it('still changes the status when Reverb is down, and reports it with ids only', function (): void {
    Exceptions::fake();
    $this->reverb->down();

    $this->actingAs($this->teacher)
        ->patchJson(route('games.status.update', $this->game), ['status' => 'finished'])
        ->assertOk()
        ->assertExactJson(['game' => ['status' => 'finished', 'board_epoch' => 0]]);

    expect($this->game->refresh()->status)->toBe(GameSessionStatus::Finished)
        ->and($this->reverb->sent)->toBe([]);

    Exceptions::assertReported(fn (SessionStatusNotBroadcast $e): bool => $e->gameId === $this->game->id
        && $e->status === GameSessionStatus::Finished
        && $e->getPrevious() === null
        && ! str_contains($e->getMessage(), 'Aysel')
        && str_contains($e->getMessage(), $this->game->id));
});
