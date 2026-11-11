<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Family\Models\Child;
use App\Domain\Play\Actions\CloseGuestBoard;
use App\Domain\Play\Actions\TransitionGameSession;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Models\GameSession;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\Games;
use Tests\Support\GuestBoards;

/*
 * Who may join `private-game.{id}` (docs/architecture.md §4), through the
 * real `POST /broadcasting/auth` with the Reverb broadcaster's keys: the
 * owner's session, or the bearer of the guest board that shows the game,
 * while it is live or paused. Nobody else, and nobody once it has ended.
 */

const SOCKET_ID = '1234.5678';

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
    $this->game = Games::start($this->class);
});

/**
 * @param  array<string, string>  $headers
 */
function joinChannel(GameSession|string $game, array $headers = []): TestResponse
{
    $id = $game instanceof GameSession ? $game->id : $game;

    return test()->post('/broadcasting/auth', [
        'socket_id' => SOCKET_ID,
        'channel_name' => 'private-game.'.$id,
    ], ['Accept' => 'application/json', ...$headers]);
}

/**
 * The signature Reverb checks: HMAC-SHA256 of "socket:channel" with the
 * app secret (phpunit.xml).
 */
function channelSignature(GameSession $game): string
{
    return 'elifba-test-key:'.hash_hmac('sha256', SOCKET_ID.':private-game.'.$game->id, 'elifba-test-secret');
}

it('lets the owner join with a valid signature', function (): void {
    $this->actingAs($this->teacher);

    joinChannel($this->game)->assertOk()->assertExactJson(['auth' => channelSignature($this->game)]);
});

it('refuses another teacher and a guest', function (): void {
    joinChannel($this->game)->assertForbidden();

    $this->actingAs(User::factory()->create());
    joinChannel($this->game)->assertForbidden();
});

it('lets the bearer of the board showing this game join', function (): void {
    $board = GuestBoards::showing($this->game);

    joinChannel($this->game, GuestBoards::bearer($board['token']))
        ->assertOk()
        ->assertExactJson(['auth' => channelSignature($this->game)]);
});

it('refuses the bearer of a board showing another game', function (): void {
    $other = Games::start($this->class, seed: 2);
    $board = GuestBoards::showing($other);

    joinChannel($this->game, GuestBoards::bearer($board['token']))->assertForbidden();
    joinChannel($other, GuestBoards::bearer($board['token']))->assertOk();
});

it('refuses the bearer of an unclaimed board', function (): void {
    $board = GuestBoards::open();

    joinChannel($this->game, GuestBoards::bearer($board['token']))->assertForbidden();
});

it('refuses the bearer of an expired board', function (): void {
    $board = GuestBoards::showing($this->game);

    $this->travel(config('elifba.guest_board_hours'))->hours();
    $this->travel(1)->seconds();

    joinChannel($this->game, GuestBoards::bearer($board['token']))->assertForbidden();
});

it('refuses the bearer of a closed board', function (): void {
    $board = GuestBoards::showing($this->game);
    app(CloseGuestBoard::class)->handle($board['board']);

    joinChannel($this->game, GuestBoards::bearer($board['token']))->assertForbidden();
});

it('refuses a bearer with a wrong secret or a made-up board', function (): void {
    $board = GuestBoards::showing($this->game);

    joinChannel($this->game, GuestBoards::bearer($board['board']->id.'.wrong-secret'))->assertForbidden();
    joinChannel($this->game, GuestBoards::bearer('01JZ0000000000000000000000.'.GuestBoards::open()['token']))->assertForbidden();
    joinChannel($this->game, GuestBoards::bearer('no-dot-at-all'))->assertForbidden();
});

it('refuses everyone once the game has ended', function (GameSessionStatus $status): void {
    $board = GuestBoards::showing($this->game);
    app(TransitionGameSession::class)->handle($this->game, $status);

    joinChannel($this->game, GuestBoards::bearer($board['token']))->assertForbidden();

    $this->actingAs($this->teacher);
    joinChannel($this->game)->assertForbidden();
})->with([
    'finished' => [GameSessionStatus::Finished],
    'abandoned' => [GameSessionStatus::Abandoned],
]);

it('keeps a paused game open to the owner and its board', function (): void {
    $board = GuestBoards::showing($this->game);
    app(TransitionGameSession::class)->handle($this->game, GameSessionStatus::Paused);

    joinChannel($this->game, GuestBoards::bearer($board['token']))->assertOk();

    $this->actingAs($this->teacher);
    joinChannel($this->game)->assertOk();
});

it('refuses an unknown or malformed game id', function (string $id): void {
    $this->actingAs($this->teacher);

    joinChannel($id)->assertForbidden();
})->with([
    'unknown ulid' => ['01JZ0000000000000000000000'],
    'not a ulid' => ['1'],
]);

it('refuses the bearer even when the owner is logged in on the same computer, for another game', function (): void {
    // The bearer wins over the session cookie (`guards: board, web`), so a
    // board's tab never borrows the teacher's wider reach.
    $other = Games::start($this->class, seed: 2);
    $board = GuestBoards::showing($other);
    $this->actingAs($this->teacher);

    joinChannel($this->game, GuestBoards::bearer($board['token']))->assertForbidden();
    joinChannel($this->game)->assertOk();
});

it('authorizes the owner of a home game and the board that shows it, and nobody else', function (): void {
    $parent = User::factory()->parent()->create();
    $child = Child::factory()->for($parent, 'parent')->create();
    $home = Games::start($child);
    $board = GuestBoards::showing($home);
    $otherBoard = GuestBoards::showing(Games::start($child, seed: 2));

    // The parent's session and the board showing the game.
    $this->actingAs($parent);
    joinChannel($home)->assertOk()->assertExactJson(['auth' => channelSignature($home)]);
    $this->app['auth']->guard('web')->forgetUser();
    joinChannel($home, GuestBoards::bearer($board['token']))->assertOk()->assertExactJson(['auth' => channelSignature($home)]);

    // Not the board showing the parent's other game, another parent, a
    // teacher, an account without a role, or a guest.
    joinChannel($home, GuestBoards::bearer($otherBoard['token']))->assertForbidden();

    foreach ([User::factory()->parent()->create(), $this->teacher, User::factory()->teacherAndParent()->create(), User::factory()->withoutRole()->create()] as $other) {
        $this->actingAs($other);
        joinChannel($home)->assertForbidden();
    }

    $this->app['auth']->guard('web')->forgetUser();
    joinChannel($home)->assertForbidden();

    // Nobody once it has ended.
    app(TransitionGameSession::class)->handle($home, GameSessionStatus::Finished);
    $this->actingAs($parent);
    joinChannel($home)->assertForbidden();
});
