<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Family\Models\Child;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Models\GameAnswer;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Models\User;
use Tests\Support\Games;
use Tests\Support\GuestBoards;

/*
 * The round count under «Başla» (docs/architecture.md §4 "Round count"):
 * `PATCH /games/{game}/rounds` builds every round again with another count
 * — the same setup, pack and seed — while nothing is answered, for the
 * board that holds the game's epoch, and answers the new `GameState`.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->teacherAndParent()->create();
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '1A']);
    $this->actingAs($this->teacher);
});

/**
 * Claims the board as its page load does; the epoch it then holds.
 */
function claimForRounds(GameSession $game): int
{
    return (int) test()->postJson(route('games.board.claim', $game))->assertOk()->json('game.board_epoch');
}

/**
 * Every round row of the game, as stored, to tell that nothing changed.
 *
 * @return list<array<string, mixed>>
 */
function storedRounds(GameSession $game): array
{
    return array_values(GameRound::query()->where('game_session_id', $game->id)->orderBy('position')->get()
        ->map(static fn (GameRound $round): array => $round->getAttributes())->all());
}

it('builds the rounds again with the new count, the same setup and seed, and keeps the epoch', function (): void {
    $setup = ['mechanic' => 'find', 'pack' => 'balloons', 'level' => 'letters', 'letter_case' => 'lower', 'letters' => ['a', 'b', 'c', 'd'], 'rounds' => 10];
    $game = Games::start($this->class, $setup, seed: 5);
    $old = array_map(static fn (GameRound $round): string => $round->id, Games::rounds($game));
    $epoch = claimForRounds($game);

    $response = $this->patchJson(route('games.rounds.update', $game), ['board_epoch' => $epoch, 'rounds' => 15])->assertOk();

    // A game started with 15 rounds and the same seed has the same rounds (only the ids differ).
    $same = Games::start($this->class, [...$setup, 'rounds' => 15], seed: 5);
    $payloads = static fn (GameSession $game): array => array_map(static fn (GameRound $round): array => $round->payload, Games::rounds($game));
    $rounds = Games::rounds($game);

    expect($response->json('rounds'))->toHaveCount(15)
        ->and(array_unique(array_column($response->json('rounds'), 'total')))->toBe([15])
        ->and(array_column($response->json('rounds'), 'id'))->toBe(array_map(static fn (GameRound $round): string => $round->id, $rounds))
        ->and(array_intersect($old, array_column($response->json('rounds'), 'id')))->toBe([])
        ->and($response->json('game.board_epoch'))->toBe($epoch)
        ->and($payloads($game))->toBe($payloads($same))
        ->and(array_unique(array_map(static fn (GameRound $round): ?string => $round->school_class_id, $rounds)))->toBe([$this->class->id]);

    $game->refresh();

    expect($game->seed)->toBe(5)
        ->and($game->board_epoch)->toBe($epoch)
        ->and($game->status)->toBe(GameSessionStatus::Live)
        ->and($game->letters)->toBe(['a', 'b', 'c', 'd'])
        ->and($game->pack_version)->toBe(app(ContentRepository::class)->pack('balloons')->version);

    // The board reads the same rounds after a reload.
    $this->getJson(route('games.state', $game))->assertOk()->assertJsonCount(15, 'rounds');
});

it('builds a home game\'s rounds again for its child', function (): void {
    $child = Child::factory()->for($this->teacher, 'parent')->create();
    $game = Games::start($child, ['mechanic' => 'find', 'pack' => 'animals', 'level' => 'pictures', 'rounds' => 10]);

    $this->patchJson(route('games.rounds.update', $game), ['board_epoch' => claimForRounds($game), 'rounds' => 5])
        ->assertOk()
        ->assertJsonCount(5, 'rounds');

    $rounds = Games::rounds($game);

    expect($rounds)->toHaveCount(5)
        ->and(array_unique(array_map(static fn (GameRound $round): ?string => $round->child_id, $rounds)))->toBe([$child->id])
        ->and(array_unique(array_map(static fn (GameRound $round): ?string => $round->school_class_id, $rounds)))->toBe([null]);
});

it('refuses once anything is answered and changes nothing', function (string $what): void {
    $game = Games::start($this->class, ['rounds' => 10]);
    $epoch = claimForRounds($game);
    $round = Games::round($game);
    $event = match ($what) {
        'hit' => Games::hit($round, Games::target($round)),
        'miss' => Games::hit($round, Games::decoy($round)),
        'skip' => Games::skip($round),
    };

    $this->postJson(route('games.sync', $game), ['board_epoch' => $epoch, 'events' => [$event]])->assertOk();
    $before = storedRounds($game);

    $this->patchJson(route('games.rounds.update', $game), ['board_epoch' => $epoch, 'rounds' => 20])
        ->assertConflict()
        ->assertJsonPath('error', 'rounds_locked')
        ->assertJsonPath('game.status', 'live');

    // A hit is stored as an answer; a skip only closes its round.
    expect(storedRounds($game))->toBe($before)
        ->and(GameAnswer::query()->count())->toBe($what === 'skip' ? 0 : 1);
})->with(['a correct hit' => 'hit', 'a miss' => 'miss', 'a skip' => 'skip']);

it('refuses a board whose epoch is not the game\'s', function (): void {
    $game = Games::start($this->class, ['rounds' => 10]);
    $stale = claimForRounds($game);
    claimForRounds($game); // another window took the game
    $before = storedRounds($game);

    $this->patchJson(route('games.rounds.update', $game), ['board_epoch' => $stale, 'rounds' => 20])
        ->assertConflict()
        ->assertJsonPath('error', 'board_replaced')
        ->assertJsonPath('game.board_epoch', $stale + 1);

    expect(storedRounds($game))->toBe($before);
});

it('refuses a game that is over', function (GameSessionStatus $status): void {
    $game = Games::start($this->class, ['rounds' => 10]);
    $game->forceFill(['status' => $status, 'ended_at' => now()])->save();
    $before = storedRounds($game);

    $this->patchJson(route('games.rounds.update', $game), ['board_epoch' => claimForRounds($game), 'rounds' => 20])
        ->assertConflict()
        ->assertJsonPath('error', 'rounds_locked')
        ->assertJsonPath('game.status', $status->value);

    expect(storedRounds($game))->toBe($before);
})->with([GameSessionStatus::Finished, GameSessionStatus::Abandoned]);

it('refuses a count out of range', function (int $rounds): void {
    $game = Games::start($this->class, ['rounds' => 10]);
    $before = storedRounds($game);

    $this->patchJson(route('games.rounds.update', $game), ['board_epoch' => claimForRounds($game), 'rounds' => $rounds])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('rounds');

    expect(storedRounds($game))->toBe($before);
})->with([4, 31]);

it('refuses with the reason when the game can no longer start as it was set up', function (): void {
    $game = Games::start($this->class, ['letters' => ['a', 'b', 'c', 'd'], 'rounds' => 10]);
    $this->class->update(['learned_letters' => ['a', 'b', 'e', 'ə', 'i']]);
    $before = storedRounds($game);

    $this->patchJson(route('games.rounds.update', $game), ['board_epoch' => claimForRounds($game), 'rounds' => 15])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['letters.2']);

    expect(storedRounds($game))->toBe($before);
});

it('finds no game of another account', function (): void {
    $game = Games::start($this->class, ['rounds' => 10]);
    $before = storedRounds($game);

    $this->actingAs(User::factory()->teacherAndParent()->create())
        ->patchJson(route('games.rounds.update', $game), ['board_epoch' => 0, 'rounds' => 20])
        ->assertNotFound();

    expect(storedRounds($game))->toBe($before);
});

it('lets a guest board change the game it shows and no other', function (): void {
    $shown = Games::start($this->class, ['rounds' => 10], seed: 1);
    $other = Games::start($this->class, ['rounds' => 10], seed: 2);
    $board = GuestBoards::showing($shown);
    $bearer = GuestBoards::bearer($board['token']);
    $before = storedRounds($other);

    $this->patchJson(route('games.rounds.update', $other), ['board_epoch' => 0, 'rounds' => 20], $bearer)->assertNotFound();

    $epoch = (int) $this->postJson(route('games.board.claim', $shown), [], $bearer)->assertOk()->json('game.board_epoch');

    $this->patchJson(route('games.rounds.update', $shown), ['board_epoch' => $epoch, 'rounds' => 20], $bearer)
        ->assertOk()
        ->assertJsonCount(20, 'rounds');

    expect(storedRounds($other))->toBe($before);
});
