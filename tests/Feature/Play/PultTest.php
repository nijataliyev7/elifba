<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Play\Actions\IssueJoinCode;
use App\Domain\Play\Actions\TransitionGameSession;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Models\GuestBoard;
use App\Domain\Play\Support\PairingCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Games;
use Tests\Support\GuestBoards;

/*
 * `/pult` on the teacher's phone (docs/architecture.md §4): the phone is
 * logged in as the teacher, and a code only says which board. An unknown or
 * expired code, another teacher's game and a game that has ended all give
 * the same 404; the three routes allow 10 requests a minute per teacher.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '1A']);
    $this->game = Games::start($this->class);
});

/**
 * The game's join code, issued the way the pairing card asks for it.
 */
function joinCode(GameSession $game): string
{
    return (string) app(IssueJoinCode::class)->handle($game)->join_code;
}

describe('GET /pult/{code}', function (): void {
    it('opens the remote of the teacher\'s own live game', function (): void {
        $code = joinCode($this->game);

        $this->actingAs($this->teacher)->get(route('pult.code', $code))
            ->assertRedirect(route('games.remote', $this->game));
    });

    it('sends a phone that is not logged in to the login page, then back', function (): void {
        $code = joinCode($this->game);

        $this->get(route('pult.code', $code))->assertRedirect(route('login'));

        expect(session('url.intended'))->toBe(route('pult.code', $code));
    });

    it('answers the same 404 for every code that opens nothing', function (Closure $code): void {
        $teacher = $this->teacher;
        $reference = $this->actingAs($teacher)->get(route('pult.code', '000001'))->assertNotFound()->getContent();

        $this->actingAs($teacher)->get('/pult/'.$code($this))
            ->assertNotFound()
            ->assertContent((string) $reference);
    })->with([
        'unknown' => [fn (): string => '999999'],
        'expired' => [function ($test): string {
            $code = joinCode($test->game);
            $test->travel(config('elifba.pairing_code_ttl_minutes'))->minutes();
            $test->travel(1)->seconds();

            return $code;
        }],
        'another teacher\'s live game' => [fn ($test): string => joinCode(Games::start(SchoolClass::factory()->create(), seed: 3))],
        'finished game (code cleared)' => [function ($test): string {
            $code = joinCode($test->game);
            app(TransitionGameSession::class)->handle($test->game, GameSessionStatus::Finished);

            return $code;
        }],
        'finished game (code left behind)' => [function ($test): string {
            $code = joinCode($test->game);
            DB::table('game_sessions')->where('id', $test->game->id)->update(['status' => 'finished']);

            return $code;
        }],
        'abandoned game' => [function ($test): string {
            $code = joinCode($test->game);
            DB::table('game_sessions')->where('id', $test->game->id)->update(['status' => 'abandoned']);

            return $code;
        }],
        'claimed guest board' => [function ($test): string {
            $board = GuestBoards::open();
            $code = (string) $board['board']->code;
            $test->actingAs($test->teacher)->post(route('pult.claim', $code), ['game' => $test->game->id]);

            return $code;
        }],
        'closed guest board' => [function ($test): string {
            $board = GuestBoards::open();
            $board['board']->forceFill(['closed_at' => now()])->save();

            return (string) $board['board']->code;
        }],
        'five digits' => [fn (): string => '12345'],
        'letters' => [fn (): string => 'abcdef'],
    ]);

    it('allows 10 requests a minute per teacher', function (): void {
        $this->actingAs($this->teacher);

        foreach (range(1, 10) as $attempt) {
            $this->get(route('pult.code', sprintf('%06d', $attempt)))->assertNotFound();
        }

        $this->get(route('pult.code', '000011'))->assertTooManyRequests();
        $this->get(route('pult'))->assertTooManyRequests();

        // Another teacher has a limit of their own.
        $this->actingAs(User::factory()->create())->get(route('pult.code', '000012'))->assertNotFound();

        $this->travel(61)->seconds();
        $this->actingAs($this->teacher)->get(route('pult.code', '000013'))->assertNotFound();
    });
});

describe('GET /pult', function (): void {
    it('opens the only live game at once, and lists them with ?list=1', function (): void {
        $this->actingAs($this->teacher)->get(route('pult'))->assertRedirect(route('games.remote', $this->game));

        $this->get(route('pult', ['list' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pult/index')
                ->has('games', 1)
                ->where('games.0.id', $this->game->id)
                ->where('games.0.schoolClass.name', '1A'));
    });

    it('keeps the list for a teacher who turned the phone remote off, whose board waits for no phone', function (): void {
        $this->teacher->forceFill(['phone_remote' => false])->save();

        $this->actingAs($this->teacher)->get(route('pult'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pult/index')
                ->has('games', 1)
                ->where('games.0.id', $this->game->id));
    });

    it('still opens a guest board\'s code for a teacher who turned the phone remote off', function (): void {
        $this->teacher->forceFill(['phone_remote' => false])->save();
        $board = GuestBoards::open();

        $this->actingAs($this->teacher)->get(route('pult.code', (string) $board['board']->code))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('pult/guest-board'));

        $this->post(route('pult.claim', (string) $board['board']->code), ['game' => $this->game->id])
            ->assertRedirect(route('games.remote', $this->game));
    });

    it('lists the teacher\'s live and paused games, newest first, and nothing else', function (): void {
        $this->travel(1)->minutes();
        $paused = Games::start($this->class, seed: 2);
        app(TransitionGameSession::class)->handle($paused, GameSessionStatus::Paused);
        $finished = Games::start($this->class, seed: 3);
        app(TransitionGameSession::class)->handle($finished, GameSessionStatus::Finished);
        Games::start(SchoolClass::factory()->create(), seed: 4);

        $this->actingAs($this->teacher)->get(route('pult'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('pult/index')
                ->where('games.0.id', $paused->id)
                ->where('games.0.status', 'paused')
                ->where('games.1.id', $this->game->id)
                ->has('games', 2));
    });

    it('takes a typed code, with spaces, and refuses a malformed one', function (): void {
        $this->actingAs($this->teacher)->get(route('pult', ['code' => '482 913']))
            ->assertRedirect(route('pult.code', '482913'));

        $this->from(route('pult', ['list' => 1]))->get(route('pult', ['code' => '48291']))
            ->assertRedirect(route('pult', ['list' => 1]))
            ->assertSessionHasErrors('code');
    });
});

describe('a guest board\'s code', function (): void {
    it('asks what to show, with the teacher\'s live games and classes', function (): void {
        $board = GuestBoards::open();
        SchoolClass::factory()->for($this->teacher, 'teacher')->archived()->create(['name' => '4B']);
        SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => 'Ə sinfi']);

        $this->actingAs($this->teacher)->get(route('pult.code', $board['board']->code))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('pult/guest-board')
                ->where('code', $board['board']->code)
                ->where('games.0.id', $this->game->id)
                // Sorted by AzCollator, as every list of names.
                ->where('classes', [
                    ['id' => SchoolClass::query()->where('name', 'Ə sinfi')->value('id'), 'name' => 'Ə sinfi'],
                    ['id' => $this->class->id, 'name' => '1A'],
                ]));
    });

    it('offers the setup form for each active class, with that class\'s options and letters', function (): void {
        $board = GuestBoards::open();
        $few = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '1B', 'learned_letters' => ['a', 'b', 'c']]);

        $page = $this->actingAs($this->teacher)->get(route('pult.code', $board['board']->code))->assertOk()->inertiaPage();
        $setup = $page['props']['setup'];

        expect(array_keys($setup['classes']))->toEqualCanonicalizing([$this->class->id, $few->id])
            ->and($setup['packs'])->not->toBeEmpty()
            ->and($setup['levels'])->not->toBeEmpty()
            ->and($setup['rounds'])->toBe(['min' => config('elifba.rounds.min'), 'max' => config('elifba.rounds.max'), 'default' => config('elifba.rounds.default')])
            ->and(array_column($setup['classes'][$few->id]['letters'], 'lower'))->toBe(['a', 'b', 'c'])
            ->and(count($setup['classes'][$this->class->id]['letters']))->toBe(32)
            // The same options the setup page shows for that class.
            ->and($setup['classes'][$few->id]['options'])->toBe(
                $this->get(route('classes.games.create', $few))->inertiaPage()['props']['options'],
            );
    });

    it('claims the board for one of the teacher\'s live games', function (): void {
        $board = GuestBoards::open();
        $code = (string) $board['board']->code;

        $this->actingAs($this->teacher)->post(route('pult.claim', $code), ['game' => $this->game->id])
            ->assertRedirect(route('games.remote', $this->game));

        $claimed = $board['board']->refresh();
        expect($claimed->user_id)->toBe($this->teacher->id)
            ->and($claimed->claimed_at)->not->toBeNull()
            ->and($claimed->code)->toBeNull()
            ->and($claimed->code_expires_at)->toBeNull()
            ->and($claimed->game_session_id)->toBe($this->game->id);

        // The code is gone: nobody else can claim the board with it.
        $this->actingAs(User::factory()->create())->post(route('pult.claim', $code), ['game' => $this->game->id])->assertNotFound();
    });

    it('claims the board for a new game set up on the phone', function (): void {
        $board = GuestBoards::open();

        $this->actingAs($this->teacher)->post(route('pult.claim', $board['board']->code), [
            'class' => $this->class->id,
            'mechanic' => 'find',
            'pack' => 'balloons',
            'level' => 'letters',
            'rounds' => 6,
        ])->assertRedirect();

        $game = GameSession::query()->where('id', '!=', $this->game->id)->sole();
        expect($game->user_id)->toBe($this->teacher->id)
            ->and($game->status)->toBe(GameSessionStatus::Live)
            ->and($game->rounds()->count())->toBe(6)
            ->and($board['board']->refresh()->game_session_id)->toBe($game->id);
    });

    it('refuses another teacher\'s game or class, an archived class and a bad setup, and claims nothing', function (Closure $body): void {
        $board = GuestBoards::open();

        $this->actingAs($this->teacher)->from(route('pult.code', $board['board']->code))
            ->post(route('pult.claim', $board['board']->code), $body($this))
            ->assertRedirect(route('pult.code', $board['board']->code))
            ->assertSessionHasErrors();

        expect($board['board']->refresh()->claimed_at)->toBeNull()
            ->and(GameSession::query()->count())->toBe(1 + ($this->extraGames ?? 0));
    })->with([
        'another teacher\'s game' => [function ($test): array {
            $test->extraGames = 1;

            return ['game' => Games::start(SchoolClass::factory()->create(), seed: 5)->id];
        }],
        'another teacher\'s class' => [fn (): array => ['class' => SchoolClass::factory()->create()->id, 'mechanic' => 'find', 'pack' => 'balloons', 'level' => 'letters']],
        'an archived class' => [fn ($test): array => ['class' => SchoolClass::factory()->for($test->teacher, 'teacher')->archived()->create()->id, 'mechanic' => 'find', 'pack' => 'balloons', 'level' => 'letters']],
        'a finished game' => [function ($test): array {
            app(TransitionGameSession::class)->handle($test->game, GameSessionStatus::Finished);

            return ['game' => $test->game->id];
        }],
        'both a game and a class' => [fn ($test): array => ['game' => $test->game->id, 'class' => $test->class->id]],
        'neither' => [fn (): array => []],
        'a setup the rules refuse' => [fn ($test): array => ['class' => $test->class->id, 'mechanic' => 'find', 'pack' => 'balloons', 'level' => 'no-such-level']],
    ]);

    it('never puts a student\'s name in the page', function (): void {
        Student::factory()->for($this->class)->create(['display_name' => 'Aysel']);
        $board = GuestBoards::open();

        $props = $this->actingAs($this->teacher)->get(route('pult.code', $board['board']->code))->inertiaPage()['props'];
        unset($props['translations']);

        expect(json_encode($props, JSON_UNESCAPED_UNICODE))->not->toContain('Aysel');
    });
});

describe('one code space over two tables', function (): void {
    it('resolves a code held by both a join code and a guest board, deterministically', function (): void {
        // No database constraint spans the two tables; force the collision.
        $code = joinCode($this->game);
        $board = GuestBoards::open();
        DB::table('guest_boards')->where('id', $board['board']->id)->update(['code' => $code]);
        $other = User::factory()->create();

        // The owner of the game always reaches their game first …
        $this->actingAs($this->teacher)->get(route('pult.code', $code))->assertRedirect(route('games.remote', $this->game));
        // … another teacher reaches the open board, never the game.
        $this->actingAs($other)->get(route('pult.code', $code))->assertInertia(fn (Assert $page) => $page->component('pult/guest-board'));

        expect(app(PairingCode::class)->resolve($code, $this->teacher))->toBeInstanceOf(GameSession::class)
            ->and(app(PairingCode::class)->resolve($code, $other))->toBeInstanceOf(GuestBoard::class);
    });

    it('skips a code in use by a live game or an open board', function (): void {
        $joinCode = joinCode($this->game);
        $boardCode = (string) GuestBoards::open()['board']->code;
        $codes = [$joinCode, $boardCode, '424242'];
        app()->instance(PairingCode::class, new PairingCode(static function () use (&$codes): string {
            return array_shift($codes) ?? throw new RuntimeException('Out of codes.');
        }));

        expect(GuestBoards::open()['board']->code)->toBe('424242');
    });

    it('retries when the store hits a unique index (an expired code still in its column)', function (): void {
        $expired = joinCode($this->game);
        $this->travel(config('elifba.pairing_code_ttl_minutes') + 1)->minutes();
        $other = Games::start($this->class, seed: 2);
        $codes = [$expired, '515151'];
        app()->instance(PairingCode::class, new PairingCode(static function () use (&$codes): string {
            return array_shift($codes) ?? throw new RuntimeException('Out of codes.');
        }));

        expect(app(PairingCode::class)->inUse($expired))->toBeFalse()
            ->and(app(IssueJoinCode::class)->handle($other)->join_code)->toBe('515151')
            ->and($this->game->refresh()->join_code)->toBe($expired);
    });

    it('normalizes what a teacher types', function (mixed $input, ?string $code): void {
        expect(PairingCode::normalize($input))->toBe($code);
    })->with([
        ['482913', '482913'],
        ['482 913', '482913'],
        ['482-913', '482913'],
        [' 482913 ', '482913'],
        [482913, '482913'],
        ['48291', null],
        ['4829133', null],
        ['48a913', null],
        [null, null],
        [['482913'], null],
    ]);
});
