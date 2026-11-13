<?php

use App\Domain\Family\Models\Child;
use App\Domain\Play\Models\GameSession;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Games;
use Tests\Support\NameClips;

/*
 * The board and the phone of a home game (docs/architecture.md §4 "At
 * home", §5 "A child's stars", ADR 011, R4): the child is always the one
 * at the board, so the board gets the child and no students; the pairing
 * card waits for «Pultu qoş», which still works; and the result screen
 * adds this game's stars to the child's total before it.
 */

beforeEach(function (): void {
    $this->parent = User::factory()->parent()->create();
    $this->child = Child::factory()->for($this->parent, 'parent')->create(['display_name' => 'Aysel', 'avatar' => 'panda']);
    $this->actingAs($this->parent);
});

/**
 * Solves the game's rounds from the board, one hit on each target.
 */
function solveHomeRounds(GameSession $game, int $count): void
{
    $events = [];

    foreach (array_slice(Games::rounds($game), 0, $count) as $round) {
        $events[] = Games::hit($round, Games::target($round));
    }

    test()->postJson(route('games.sync', $game), [
        'board_epoch' => $game->fresh()?->board_epoch,
        'events' => $events,
    ])->assertOk();
}

it('gives the board the child and no students', function (): void {
    NameClips::fake([]);
    $game = Games::start($this->child);

    foreach (['games.board', 'games.remote'] as $route) {
        $this->get(route($route, $game))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('schoolClass', null)
                ->where('child.id', $this->child->id)
                ->where('child.display_name', 'Aysel')
                ->where('child.avatar.key', 'panda')
                ->where('child.avatar.url', fn (string $url): bool => str_contains($url, 'panda'))
                ->where('child.stars', 0)
                // No name clip is made in the test content (ADR 016, PlayerVoiceTest).
                ->where('child.name_audio', null)
                ->where('students', []));
    }
});

it('gives the board the URL of the child\'s name clip, ids only, once the clip is made', function (): void {
    NameClips::fake(['Aysel']);
    $game = Games::start($this->child);

    $this->get(route('games.board', $game))
        ->assertInertia(fn (Assert $page) => $page
            ->where('child.name_audio', "/games/{$game->id}/players/{$this->child->id}/voice")
            ->where('voice.names', true));
});

it('does not open the pairing card on a home board', function (): void {
    $game = Games::start($this->child);

    // The page carries the child, so the board keeps the card closed
    // (`pairingOnLoad`), but it still joins the channel for a phone.
    $this->get(route('games.board', $game))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('games/board')
            ->where('child.id', $this->child->id)
            ->where('phoneRemote', true)
            // No class to add students to (architecture §4 "A student added on the board").
            ->where('pasteLimits', null));

    expect($game->refresh()->join_code)->toBeNull();

    // «Pultu qoş» still brings a code, and the phone finds the game with it.
    $code = $this->postJson(route('games.join-code', $game))->assertOk()->json('pairing.code');

    $this->get(route('pult.code', $code))->assertRedirect(route('games.remote', $game));
});

it('gives the result screen the child\'s total', function (): void {
    $earlier = Games::start($this->child, seed: 1);
    solveHomeRounds($earlier, 2);
    $game = Games::start($this->child, seed: 2);
    solveHomeRounds($game, 1);

    $before = (int) $earlier->rounds()->sum('points');
    $now = (int) $game->rounds()->sum('points');

    expect($before)->toBeGreaterThan(0)->and($now)->toBeGreaterThan(0);

    // The total before this game, and this game's points apart: the board
    // shows `child.stars` plus what this game earned, never counting a
    // round twice when the page is reloaded mid-game.
    $this->get(route('games.board', $game))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('child.stars', $before)
            ->where('game.points', fn ($points): bool => array_sum(collect($points)->all()) === $now));

    // A sibling's stars never reach the child's board.
    $sibling = Child::factory()->for($this->parent, 'parent')->create(['display_name' => 'Tural']);
    solveHomeRounds(Games::start($sibling, seed: 3), 3);

    $this->get(route('games.board', $game))
        ->assertInertia(fn (Assert $page) => $page->where('child.stars', $before));
});
