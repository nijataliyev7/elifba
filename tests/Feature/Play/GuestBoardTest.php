<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\SoundChoices;
use App\Domain\Play\Actions\CloseGuestBoard;
use App\Domain\Play\Actions\PruneGuestBoards;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Models\GuestBoard;
use App\Domain\Play\Support\BoardProps;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Games;
use Tests\Support\GuestBoards;

/*
 * The guest board on a shared school computer (docs/architecture.md §4):
 * `/lovhe` opens it, the teacher's phone claims it and sends it games, its
 * bearer reaches only the game it shows, and either side closes it. The
 * secret leaves the server once and is stored hashed.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->create(['name' => 'Gülnarə Məmmədova']);
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '1A']);
    $this->student = Student::factory()->for($this->class)->create(['display_name' => 'Aysel']);
});

/**
 * @param  array<string, mixed>  $body
 */
function asBoard(string $method, string $uri, string $token, array $body = []): TestResponse
{
    return test()->json($method, $uri, $body, GuestBoards::bearer($token));
}

it('opens a board with a code; the secret is answered once and stored only hashed', function (): void {
    $this->travelTo(now()->startOfSecond());

    $response = $this->postJson(route('guest-boards.store'))->assertCreated();
    $token = $response->json('token');
    [$id, $secret] = explode('.', $token, 2);
    $row = DB::table('guest_boards')->where('id', $id)->first();

    expect($response->json('board.id'))->toBe($id)
        ->and($response->json('board.pairing.code'))->toMatch('/^\d{6}$/')
        ->and($response->json('board.pairing.expires_at'))->toBe(now()->addMinutes(10)->utc()->toIso8601ZuluString())
        ->and($response->json('board.pairing.url'))->toBe(rtrim(config('app.url'), '/').'/pult/'.$response->json('board.pairing.code'))
        ->and($response->json('board.expires_at'))->toBe(now()->addHours(12)->utc()->toIso8601ZuluString())
        ->and($response->json('board.teacher'))->toBeNull()
        ->and($response->json('board.game'))->toBeNull()
        ->and(strlen(base64_decode(strtr($secret, '-_', '+/'))))->toBe(32)
        ->and($row?->secret_hash)->toBe(hash('sha256', $secret))
        ->and(json_encode($row))->not->toContain($secret);

    $poll = asBoard('GET', route('guest-boards.show', $id), $token)->assertOk();

    expect($poll->json('props'))->toBeNull()
        ->and($poll->getContent())->not->toContain($secret)
        ->and($poll->getContent())->not->toContain('secret');
});

it('goes through open → claim → show → next game → close, never answering the secret again', function (): void {
    $responses = [];
    $record = function (TestResponse $response) use (&$responses): TestResponse {
        $responses[] = $response->getContent();

        return $response;
    };

    // 1. /lovhe opens the board.
    $opened = $this->postJson(route('guest-boards.store'))->assertCreated();
    $token = $opened->json('token');
    $boardId = $opened->json('board.id');
    $code = $opened->json('board.pairing.code');
    $secret = explode('.', $token, 2)[1];

    // 2. The phone claims it and sends a live game.
    $first = Games::start($this->class);
    $record($this->actingAs($this->teacher)->get(route('pult.code', $code)))->assertOk();
    $record($this->post(route('pult.claim', $code), ['game' => $first->id]))->assertRedirect(route('games.remote', $first));

    // 3. The board's poll finds the game, the teacher's name and the props.
    $poll = $record(asBoard('GET', route('guest-boards.show', $boardId), $token))->assertOk();
    expect($poll->json('board.pairing'))->toBeNull()
        ->and($poll->json('board.teacher'))->toBe(['name' => 'Gülnarə Məmmədova'])
        ->and($poll->json('board.game'))->toBe(['id' => $first->id, 'status' => 'live'])
        ->and($poll->json('props.game.id'))->toBe($first->id)
        ->and($poll->json('props.schoolClass'))->toBe(['id' => $this->class->id, 'name' => '1A'])
        ->and($poll->json('props.students.0.display_name'))->toBe('Aysel');

    // 4. It plays the game like a teacher board, with the same bearer.
    $record(asBoard('POST', route('games.board.claim', $first), $token))->assertOk()->assertExactJson(['game' => ['status' => 'live', 'board_epoch' => 1]]);
    $record(asBoard('GET', route('games.state', $first), $token))->assertOk()->assertJsonPath('game.status', 'live');
    $round = Games::round($first);
    $record(asBoard('POST', route('games.sync', $first), $token, ['board_epoch' => 1, 'events' => [
        Games::hit($round, Games::target($round), $this->student->id, source: 'remote'),
    ]]))->assertOk()->assertJsonPath('results.0.status', 'correct');
    $record(asBoard('PATCH', route('games.status.update', $first), $token, ['status' => 'paused']))->assertOk();
    $record(asBoard('PATCH', route('games.status.update', $first), $token, ['status' => 'finished']))->assertOk()->assertJsonPath('game.status', 'finished');

    // 5. The phone sends the next game, a new one, without a new code.
    $record($this->actingAs($this->teacher)->put(route('guest-boards.game.update', $boardId), [
        'class' => $this->class->id, 'mechanic' => 'find', 'pack' => 'balloons', 'level' => 'letters', 'rounds' => 5,
    ]))->assertRedirect();
    $second = GameSession::query()->where('id', '!=', $first->id)->sole();

    $poll = $record(asBoard('GET', route('guest-boards.show', $boardId), $token))->assertOk();
    expect($poll->json('board.game'))->toBe(['id' => $second->id, 'status' => 'live'])
        ->and($poll->json('board.pairing'))->toBeNull();

    // The bearer now reaches the new game, and the old one no more.
    $record(asBoard('POST', route('games.board.claim', $second), $token))->assertOk();
    $record(asBoard('GET', route('games.state', $first), $token))->assertNotFound();

    // 6. «Lövhəni bağla» on the board: the bearer stops working at once.
    // (The board is another computer: no teacher session goes with it.)
    $this->app['auth']->guard('web')->forgetUser();
    $record(asBoard('DELETE', route('guest-boards.destroy', $boardId), $token))->assertNoContent();
    $record(asBoard('GET', route('guest-boards.show', $boardId), $token))->assertUnauthorized();
    $record(asBoard('GET', route('games.state', $second), $token))->assertUnauthorized();

    // The game goes on: the teacher can send it to another board.
    expect($second->refresh()->status)->toBe(GameSessionStatus::Live)
        ->and(GuestBoard::query()->findOrFail($boardId)->closed_at)->not->toBeNull();

    foreach ($responses as $content) {
        expect((string) $content)->not->toContain($secret);
    }
});

it('sends the board exactly the board props, whatever the teacher chose for the phone remote', function (bool $phoneRemote): void {
    $this->teacher->forceFill(['phone_remote' => $phoneRemote])->save();
    $game = Games::start($this->class);
    $board = GuestBoards::showing($game);

    $poll = asBoard('GET', route('guest-boards.show', $board['board']->id), $board['token'])->assertOk();

    expect(array_keys($poll->json('props')))->toBe(['game', 'schoolClass', 'child', 'pack', 'students', 'voice'])
        ->and($poll->json('props'))->toEqual(json_decode((string) json_encode(BoardProps::of($game->refresh())), true));
})->with(['remote on' => [true], 'remote off' => [false]]);

it('plays the sounds the game\'s owner chose, as the teacher board does', function (): void {
    $this->teacher->forceFill(['board_sounds' => ['miss' => 'pluck-down']])->save();
    $game = Games::start($this->class);
    $board = GuestBoards::showing($game);

    $poll = asBoard('GET', route('guest-boards.show', $board['board']->id), $board['token'])->assertOk();

    expect($poll->json('props.pack.style.sounds.miss'))->toBe(ContentAssets::url('sounds/pluck-down.mp3', SoundChoices::version()));
});

it('sends the board the pictures and figures of the game\'s pack, as the teacher board gets them', function (): void {
    $game = Games::start($this->class, ['pack' => 'animals']);
    $board = GuestBoards::showing($game);
    $pack = app(ContentRepository::class)->pack('animals');
    $cow = array_search('fluent/1F404.svg', $pack->style->figures, true);

    $poll = asBoard('GET', route('guest-boards.show', $board['board']->id), $board['token'])->assertOk();

    expect($poll->json('props.pack.style.item'))->toBe('animal')
        ->and($poll->json('props.pack.style.figures'))->toHaveCount(count($pack->style->figures))
        ->and($poll->json("props.pack.style.figures.{$cow}"))->toBe(['url' => ContentAssets::url('fluent/1F404.svg', $pack->version), 'words' => ['word:inək']])
        ->and($poll->json('props.pack.style.figures.0.words'))->toBe([])
        ->and($poll->json('props.pack.pictures')['word:dəvəquşu'])->toBe([ContentAssets::url('animals/devequsu.svg', $pack->version)])
        ->and($poll->json('props.pack.pictures')['letter:p'])->toBe(array_map(
            static fn (string $path): string => ContentAssets::url($path, $pack->version),
            ['fluent/1F408.svg', 'fluent/1F405.svg', 'fluent/1F427.svg'],
        ))
        ->and($poll->json('props.pack.pictures')['syllable:pi'])->toBe([ContentAssets::url('fluent/1F408.svg', $pack->version)])
        ->and($poll->json('props.pack.pictures'))->not->toHaveKey('letter:ə');
});

it('lets the teacher who claimed it close it from the phone', function (): void {
    $game = Games::start($this->class);
    $board = GuestBoards::showing($game);

    $this->actingAs($this->teacher)->from(route('games.remote', $game))
        ->delete(route('guest-boards.destroy', $board['board']))
        ->assertRedirect(route('games.remote', $game));

    expect($board['board']->refresh()->closed_at)->not->toBeNull();
    asBoard('GET', route('guest-boards.show', $board['board']), $board['token'])->assertUnauthorized();
});

it('shows the remote which guest board shows the game', function (): void {
    $game = Games::start($this->class);
    $board = GuestBoards::showing($game);

    $this->actingAs($this->teacher)->get(route('games.remote', $game))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('games/remote')
            ->where('game.id', $game->id)
            ->where('guestBoard', ['id' => $board['board']->id]));

    app(CloseGuestBoard::class)->handle($board['board']);

    $this->get(route('games.remote', $game))->assertInertia(fn ($page) => $page->where('guestBoard', null));
});

it('keeps one teacher off another teacher\'s board', function (): void {
    $game = Games::start($this->class);
    $board = GuestBoards::showing($game);
    $other = User::factory()->create();
    $otherGame = Games::start(SchoolClass::factory()->for($other, 'teacher')->create(), seed: 2);

    $this->actingAs($other);
    $this->put(route('guest-boards.game.update', $board['board']), ['game' => $otherGame->id])->assertNotFound();
    $this->delete(route('guest-boards.destroy', $board['board']))->assertNotFound();

    expect($board['board']->refresh()->game_session_id)->toBe($game->id)
        ->and($board['board']->closed_at)->toBeNull();
});

it('offers the teacher who claimed it the next game, on the same page as the code', function (): void {
    $game = Games::start($this->class);
    $board = GuestBoards::showing($game);

    $this->actingAs($this->teacher)->get(route('guest-boards.game.edit', $board['board']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('pult/guest-board')
            ->where('code', null)
            ->where('board', ['id' => $board['board']->id])
            ->where('games.0.id', $game->id)
            // Named as the setup names it: «Hərflər · Tap!».
            ->where('games.0.level', ['title' => 'Hərflər', 'unit' => 'letter'])
            ->where('classes', [['id' => $this->class->id, 'name' => '1A']])
            ->has('setup.classes.'.$this->class->id.'.options')
            ->has('setup.classes.'.$this->class->id.'.letters')
            ->where('setup.classes.'.$this->class->id.'.allLetters', true));
});

it('offers no next game for a board that is not the teacher\'s, or not active', function (Closure $board): void {
    $this->actingAs($this->teacher)->get(route('guest-boards.game.edit', $board($this)))->assertNotFound();
})->with([
    'another teacher\'s board' => [fn ($test): GuestBoard => GuestBoards::showing(Games::start(SchoolClass::factory()->create(), seed: 4))['board']],
    'an unclaimed board' => [fn (): GuestBoard => GuestBoards::open()['board']],
    'a closed board' => [function ($test): GuestBoard {
        $board = GuestBoards::showing(Games::start($test->class))['board'];
        app(CloseGuestBoard::class)->handle($board);

        return $board;
    }],
]);

it('lets the bearer win over a teacher logged in on the same computer, and never borrows that teacher\'s reach', function (): void {
    // A shared school computer where some teacher is still logged in
    // (`auth:board,web`, architecture §4 "Guard order"): the board's tab
    // sends the session cookie and its bearer, and acts as the board.
    $shown = Games::start($this->class);
    $board = GuestBoards::showing($shown);
    $loggedIn = User::factory()->create();
    $theirs = Games::start(SchoolClass::factory()->for($loggedIn, 'teacher')->create(), seed: 2);
    $this->actingAs($loggedIn);

    // The game the board shows, though the logged-in teacher does not own it.
    asBoard('GET', route('guest-boards.show', $board['board']), $board['token'])->assertOk()->assertJsonPath('board.game.id', $shown->id);
    asBoard('POST', route('games.board.claim', $shown), $board['token'])->assertOk();
    asBoard('GET', route('games.state', $shown), $board['token'])->assertOk();

    // The logged-in teacher's own game: the bearer decides, so 404.
    asBoard('GET', route('games.state', $theirs), $board['token'])->assertNotFound();
    asBoard('PATCH', route('games.status.update', $theirs), $board['token'], ['status' => 'finished'])->assertNotFound();
    expect($theirs->refresh()->status)->toBe(GameSessionStatus::Live);

    // A closed board's bearer authenticates nothing, so the request is the
    // session's, like any other request from that browser: the board's
    // game is a 404 for this teacher, their own game is theirs. The board's
    // poll (`auth:board` only) answers 401, and the tab opens a new board.
    app(CloseGuestBoard::class)->handle($board['board']);
    asBoard('GET', route('guest-boards.show', $board['board']), $board['token'])->assertUnauthorized();
    asBoard('GET', route('games.state', $shown), $board['token'])->assertNotFound();
    asBoard('GET', route('games.state', $theirs), $board['token'])->assertOk();
});

it('keeps one board\'s bearer off another board', function (): void {
    $game = Games::start($this->class);
    $mine = GuestBoards::showing($game);
    $theirs = GuestBoards::showing(Games::start($this->class, seed: 2));

    asBoard('GET', route('guest-boards.show', $theirs['board']), $mine['token'])->assertNotFound();
    asBoard('DELETE', route('guest-boards.destroy', $theirs['board']), $mine['token'])->assertNotFound();
    asBoard('PUT', route('guest-boards.game.update', $mine['board']), $mine['token'], ['game' => $game->id])->assertUnauthorized();

    expect($theirs['board']->refresh()->closed_at)->toBeNull();
});

it('refuses a wrong, malformed or missing bearer', function (Closure $token): void {
    $board = GuestBoards::open();

    $this->getJson(route('guest-boards.show', $board['board']), ['Authorization' => $token($board)])->assertUnauthorized();
})->with([
    'wrong secret' => [fn (array $board): string => 'Bearer '.$board['board']->id.'.'.GuestBoard::newSecret()],
    'the hash as secret' => [fn (array $board): string => 'Bearer '.$board['board']->id.'.'.$board['board']->secret_hash],
    'no secret' => [fn (array $board): string => 'Bearer '.$board['board']->id.'.'],
    'no dot' => [fn (array $board): string => 'Bearer '.$board['board']->id],
    'not bearer' => [fn (array $board): string => 'Basic '.base64_encode($board['token'])],
    'empty' => [fn (array $board): string => ''],
]);

it('lets an unclaimed board die with its code, and a claimed one after elifba.guest_board_hours', function (): void {
    $unclaimed = GuestBoards::open();
    $claimed = GuestBoards::showing(Games::start($this->class));

    $this->travel(config('elifba.pairing_code_ttl_minutes'))->minutes();
    $this->travel(1)->seconds();
    asBoard('GET', route('guest-boards.show', $unclaimed['board']), $unclaimed['token'])->assertUnauthorized();
    asBoard('GET', route('guest-boards.show', $claimed['board']), $claimed['token'])->assertOk();

    $this->travel(config('elifba.guest_board_hours'))->hours();
    asBoard('GET', route('guest-boards.show', $claimed['board']), $claimed['token'])->assertUnauthorized();
});

it('opens 6 boards a minute per IP, and the poll has a limit of its own', function (): void {
    $first = $this->postJson(route('guest-boards.store'))->assertCreated();

    foreach (range(2, 6) as $attempt) {
        $this->postJson(route('guest-boards.store'))->assertCreated();
    }

    $this->postJson(route('guest-boards.store'))->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->postJson(route('guest-boards.store'))->assertCreated();

    // A 2 s poll for a minute and more is not the opening limit.
    foreach (range(1, 40) as $poll) {
        asBoard('GET', route('guest-boards.show', $first->json('board.id')), $first->json('token'))->assertOk();
    }

    expect(GuestBoard::query()->count())->toBe(7);
});

it('prunes closed, expired and never-claimed boards hourly, and keeps the games', function (): void {
    $game = Games::start($this->class);
    $active = GuestBoards::showing($game);
    $waiting = GuestBoards::open();
    $closed = GuestBoards::showing($game);
    app(CloseGuestBoard::class)->handle($closed['board']);
    $stale = GuestBoards::open();
    DB::table('guest_boards')->where('id', $stale['board']->id)->update(['code_expires_at' => now()->subSecond()]);
    $expired = GuestBoards::showing($game);
    DB::table('guest_boards')->where('id', $expired['board']->id)->update(['expires_at' => now()->subSecond()]);

    expect(app(PruneGuestBoards::class)->handle())->toBe(3)
        ->and(GuestBoard::query()->pluck('id')->sort()->values()->all())->toBe(collect([$active['board']->id, $waiting['board']->id])->sort()->values()->all())
        ->and($game->refresh()->exists)->toBeTrue();

    $this->artisan('play:prune-guest-boards')->assertSuccessful();

    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'play:prune-guest-boards'));
    expect($event?->expression)->toBe('0 * * * *');
});

it('serves /lovhe without a login', function (): void {
    $this->get(route('lovhe'))->assertOk()->assertInertia(fn ($page) => $page->component('lovhe'));
});
