<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Play\Actions\TransitionGameSession;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Tests\Support\Games;

/*
 * The teacher board's pairing card (docs/architecture.md §4):
 * `POST /games/{game}/join-code` gives the game's code — kept while valid,
 * replaced once expired — with the QR's URL on APP_URL. A game that has
 * ended has none.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
    $this->game = Games::start($this->class);
    $this->actingAs($this->teacher);
    $this->travelTo(now()->startOfSecond());
});

it('gives a 6-digit code valid for elifba.pairing_code_ttl_minutes, with the QR\'s URL on APP_URL', function (): void {
    Config::set('app.url', 'https://elifba.example');

    $response = $this->postJson(route('games.join-code', $this->game))->assertOk();
    $code = $response->json('pairing.code');

    expect($code)->toMatch('/^\d{6}$/')
        ->and($response->json('pairing'))->toBe([
            'code' => $code,
            'expires_at' => now()->addMinutes(10)->utc()->toIso8601ZuluString(),
            'url' => "https://elifba.example/pult/{$code}",
            'entry_url' => 'https://elifba.example/pult',
        ])
        ->and($this->game->refresh()->join_code)->toBe($code);
});

it('keeps a valid code, so a QR on the wall keeps working, and replaces an expired one', function (): void {
    $first = $this->postJson(route('games.join-code', $this->game))->json('pairing.code');

    $this->travel(9)->minutes();
    expect($this->postJson(route('games.join-code', $this->game))->json('pairing.code'))->toBe($first);

    $this->travel(1)->minutes();
    $this->travel(1)->seconds();
    $second = $this->postJson(route('games.join-code', $this->game))->json('pairing.code');

    expect($second)->not->toBe($first)
        ->and($this->game->refresh()->join_code_expires_at?->toIso8601ZuluString())->toBe(now()->addMinutes(10)->utc()->toIso8601ZuluString());
});

it('does not touch the game\'s activity', function (): void {
    $before = $this->game->refresh()->last_activity_at;
    $this->travel(5)->minutes();

    $this->postJson(route('games.join-code', $this->game))->assertOk();

    expect($this->game->refresh()->last_activity_at->equalTo($before))->toBeTrue();
});

it('gives a game that has ended no code (409 game_over)', function (GameSessionStatus $status): void {
    app(TransitionGameSession::class)->handle($this->game, $status);

    $this->postJson(route('games.join-code', $this->game))
        ->assertConflict()
        ->assertExactJson(['error' => 'game_over', 'game' => ['status' => $status->value, 'board_epoch' => 0]]);

    expect($this->game->refresh()->join_code)->toBeNull();
})->with([
    'finished' => [GameSessionStatus::Finished],
    'abandoned' => [GameSessionStatus::Abandoned],
]);

it('keeps a paused game\'s code', function (): void {
    app(TransitionGameSession::class)->handle($this->game, GameSessionStatus::Paused);

    $this->postJson(route('games.join-code', $this->game))->assertOk();
});

it('loses the code when the game ends', function (): void {
    $this->postJson(route('games.join-code', $this->game))->assertOk();

    $this->patchJson(route('games.status.update', $this->game), ['status' => 'finished'])->assertOk();

    expect($this->game->refresh()->join_code)->toBeNull()
        ->and($this->game->join_code_expires_at)->toBeNull();
});
