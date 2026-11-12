<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Family\Models\Child;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\Games;

/*
 * «Yenidən oyna» on the end screen (`POST /games/{game}/replay`,
 * docs/architecture.md §2 "Game setup"): a new game with the same setup and
 * as many rounds, for the same class or child, straight to its board.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->teacherAndParent()->create();
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '1A']);
    $this->actingAs($this->teacher);
});

function finishGame(GameSession $game, GameSessionStatus $status = GameSessionStatus::Finished): GameSession
{
    $game->forceFill(['status' => $status, 'ended_at' => now()])->save();

    return $game;
}

/**
 * The game started last, other than `$game`.
 */
function replayOf(GameSession $game): ?GameSession
{
    return GameSession::query()->whereKeyNot($game->id)->latest('started_at')->first();
}

it('starts the same class game again, with as many rounds and a new seed, and opens its board', function (GameSessionStatus $status): void {
    $game = finishGame(Games::start($this->class, ['letter_case' => 'lower', 'letters' => ['a', 'b', 'c', 'd'], 'rounds' => 15], seed: 7), $status);

    $response = $this->post(route('games.replay', $game));
    $next = replayOf($game);

    expect($next)->not->toBeNull();
    $response->assertRedirect(route('games.board', $next));

    expect($next->school_class_id)->toBe($this->class->id)
        ->and($next->child_id)->toBeNull()
        ->and($next->user_id)->toBe($this->teacher->id)
        ->and($next->status)->toBe(GameSessionStatus::Live)
        ->and($next->board_epoch)->toBe(0)
        ->and($next->mechanic)->toBe($game->mechanic)
        ->and($next->pack)->toBe('balloons')
        ->and($next->level)->toBe('letters')
        ->and($next->letter_case)->toBe(LetterCase::Lower)
        ->and($next->letters)->toBe(['a', 'b', 'c', 'd'])
        ->and($next->own_words)->toBeFalse()
        ->and($next->seed)->not->toBe(7)
        ->and(GameRound::query()->where('game_session_id', $next->id)->count())->toBe(15)
        ->and($game->refresh()->status)->toBe($status)
        ->and(GameRound::query()->where('game_session_id', $game->id)->count())->toBe(15);
})->with([GameSessionStatus::Finished, GameSessionStatus::Abandoned]);

it('starts the same home game again for the child', function (): void {
    $child = Child::factory()->for($this->teacher, 'parent')->create();
    $game = finishGame(Games::start($child, ['mechanic' => 'count', 'pack' => 'animals', 'level' => 'numbers-5', 'rounds' => 5]));

    $response = $this->post(route('games.replay', $game));
    $next = replayOf($game);

    expect($next)->not->toBeNull();
    $response->assertRedirect(route('games.board', $next));

    expect($next->child_id)->toBe($child->id)
        ->and($next->school_class_id)->toBeNull()
        ->and($next->level)->toBe('numbers-5')
        ->and(GameRound::query()->where('game_session_id', $next->id)->pluck('child_id')->unique()->all())->toBe([$child->id])
        ->and(GameRound::query()->where('game_session_id', $next->id)->count())->toBe(5);
});

it('replays a game stored before its letters were, with every learned letter', function (): void {
    $game = finishGame(Games::start($this->class, ['letters' => ['a', 'b'], 'rounds' => 5]));
    DB::table('game_sessions')->where('id', $game->id)->update(['letters' => null, 'own_words' => false]);

    $this->post(route('games.replay', $game))->assertRedirect();

    expect(replayOf($game)?->letters)->toBeNull();
});

it('sends an open game back to its board and starts nothing', function (): void {
    $game = Games::start($this->class);

    $this->post(route('games.replay', $game))->assertRedirect(route('games.board', $game));

    expect(GameSession::query()->count())->toBe(1);
});

it('sends a game that can no longer start to the setup page with the reason', function (string $for): void {
    $players = $for === 'child'
        ? Child::factory()->for($this->teacher, 'parent')->create()
        : $this->class;
    $game = finishGame(Games::start($players, ['letters' => ['a', 'b', 'c', 'd'], 'rounds' => 5]));
    $players->update(['learned_letters' => ['a', 'b', 'e', 'ə', 'i']]);

    $this->post(route('games.replay', $game))
        ->assertRedirect($for === 'child' ? route('children.games.create', $players) : route('classes.games.create', $players))
        ->assertInertiaFlash('toast', [
            'type' => 'warning',
            'message' => __('games.board.replay_refused', ['reason' => __('games.setup.errors.letter_not_learned')]),
        ]);

    expect(GameSession::query()->count())->toBe(1);
})->with(['a class' => 'class', 'a child' => 'child']);

it('finds no game of another account', function (): void {
    $game = finishGame(Games::start($this->class));

    $this->actingAs(User::factory()->teacherAndParent()->create())
        ->post(route('games.replay', $game))
        ->assertNotFound();

    expect(GameSession::query()->count())->toBe(1);
});

it('hides an archived class\'s games', function (): void {
    $game = finishGame(Games::start($this->class));
    $this->class->update(['archived_at' => now()]);

    $this->post(route('games.replay', $game))->assertNotFound();

    expect(GameSession::query()->count())->toBe(1);
});
