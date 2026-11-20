<?php

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Enums\RoomSequenceRule;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TauntVoices;
use App\Domain\Content\Support\VoiceClips;
use App\Domain\Language\Support\Ordinal;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAnswer;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomJoinLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RoomPhones;
use Tests\Support\Rooms;

/*
 * The room pages' data (docs/architecture.md §12 "Rooms and joining",
 * "Scoring and what is kept", "The big screen", "Adults' privacy"):
 * «Oyunlar» lists an account's own open rooms and «Keçmiş oyunlar» its past ones, a past one with
 * its games and the account's own place; a closed room is its results, game
 * by game, named from the accounts as they are now; a name taken out is
 * null and its points stay; a deleted room is gone for everyone; the
 * screen is the host's. Everything ranked is derived on each read. No page
 * gets another account's id or address, or a solution before its reveal.
 */

beforeEach(function (): void {
    Rooms::on();
    $this->travelTo('2026-11-24 19:00:00');
    $this->resad = User::factory()->player()->create(['name' => 'Rəşad Quliyev', 'email' => 'resad@example.com']);
    $this->aygun = User::factory()->player()->create(['name' => 'Aygün Məmmədova', 'email' => 'aygun@example.com']);
    $this->tural = User::factory()->player()->create(['name' => 'Tural Əliyev', 'email' => 'tural@example.com']);
});

/**
 * Plays a whole game in the room through the answer endpoint, as the
 * players' phones would: in each round each account of `$right` taps the
 * solution and each of `$wrong` a wrong answer, then the game runs to its
 * end. Returns the game, over.
 *
 * @param  list<User>  $right
 * @param  list<User>  $wrong
 */
function pagesPlay(Room $room, RoomMechanic $mechanic, int $difficulty, array $right, array $wrong = []): RoomGame
{
    $game = Rooms::playing($room, 5, $difficulty, $mechanic);

    foreach (range(0, 4) as $index) {
        $round = Rooms::round($game, $index);
        test()->travelTo(Rooms::at($game, RoomPhase::Answer, $index));
        RoomPhones::read($room, ...$right, ...$wrong);

        foreach ($right as $account) {
            test()->actingAs($account)->postJson(route('rooms.answers.store', $room), Rooms::answer($round, Rooms::right($round)))->assertOk();
        }

        foreach ($wrong as $account) {
            test()->actingAs($account)->postJson(route('rooms.answers.store', $room), Rooms::answer($round, Rooms::wrong($round)))->assertOk();
        }
    }

    test()->travelTo(Rooms::at($game, RoomPhase::Over));

    return $game->refresh();
}

/** The points a row has in a game, from the stored answers. */
function pagesPoints(RoomGame $game, RoomPlayer $row): int
{
    return (int) RoomAnswer::query()
        ->whereIn('room_round_id', RoomRound::query()->where('room_game_id', $game->id)->select('id'))
        ->where('room_player_id', $row->id)
        ->sum('points');
}

/** The row of `$account` in `$room`. */
function pagesRow(Room $room, User $account): RoomPlayer
{
    return RoomPlayer::query()->where('room_id', $room->id)->where('user_id', $account->id)->sole();
}

/**
 * The page as JSON, without the translations every page carries.
 *
 * @return array<string, mixed>
 */
function pagesProps(TestResponse $response): array
{
    $props = $response->assertOk()->viewData('page')['props'];
    unset($props['translations']);

    return $props;
}

it('lists an account\'s open and past rooms and nobody else\'s', function (): void {
    // A past room: Rəşad and Aygün played «Xanaları yadda saxla», Aygün right every time, Rəşad never.
    $past = Rooms::open($this->resad);
    Rooms::join($past, $this->aygun);
    $game = pagesPlay($past, RoomMechanic::RecallCells, 2, right: [$this->aygun], wrong: [$this->resad]);
    $this->actingAs($this->resad)->post(route('rooms.close', $past))->assertRedirect();

    // An open room of Rəşad's with Aygün in it, and one of Tural's that is nobody else's business.
    $this->travelTo('2026-11-25 10:00:00');
    $open = Rooms::open($this->resad);
    Rooms::join($open, $this->aygun);
    $turals = Rooms::open($this->tural);
    $nigar = User::factory()->player()->create(['name' => 'Nigar Həsənova']);
    Rooms::join($turals, $nigar);

    $aygunPoints = pagesPoints($game, pagesRow($past, $this->aygun));

    expect($aygunPoints)->toBeGreaterThanOrEqual(50);

    $this->actingAs($this->resad)->get(route('rooms.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('rooms/index')
            ->where('open', [['id' => $open->id, 'code' => $open->code, 'host' => true, 'players' => 2, 'mechanic' => 'recall_order', 'board' => null, 'running' => false]])
            ->missing('past'));
    $this->get(route('rooms.past'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('rooms/past')
            ->where('past', [[
                'id' => $past->id,
                'date' => '24 noyabr 2026',
                'host' => true,
                'players' => 2,
                'games' => [['mechanic' => 'recall_cells', 'difficulty' => 2]],
                // No points: no place.
                'mine' => ['points' => 0, 'place' => null],
            ]]));

    $this->actingAs($this->aygun)->get(route('rooms.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('open', [['id' => $open->id, 'code' => $open->code, 'host' => false, 'players' => 2, 'mechanic' => 'recall_order', 'board' => null, 'running' => false]]));
    $this->get(route('rooms.past'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('past', [[
                'id' => $past->id,
                'date' => '24 noyabr 2026',
                'host' => false,
                'players' => 2,
                'games' => [['mechanic' => 'recall_cells', 'difficulty' => 2]],
                'mine' => ['points' => $aygunPoints, 'place' => Ordinal::label(1, 'az')],
            ]]));

    // In English the place is labelled in English, by the server.
    $this->aygun->forceFill(['locale' => 'en'])->save();
    $this->actingAs($this->aygun)->get(route('rooms.past'))
        ->assertInertia(fn (Assert $page) => $page->where('past.0.mine.place', '1st')->where('past.0.date', '24 November 2026'));

    // Tural sees his own room only; Nigar the same one; nobody else's.
    $this->actingAs($this->tural)->get(route('rooms.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('open', [['id' => $turals->id, 'code' => $turals->code, 'host' => true, 'players' => 2, 'mechanic' => 'recall_order', 'board' => null, 'running' => false]]));
    $this->get(route('rooms.past'))->assertInertia(fn (Assert $page) => $page->where('past', []));
    $this->actingAs($nigar)->get(route('rooms.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('open', [['id' => $turals->id, 'code' => $turals->code, 'host' => false, 'players' => 2, 'mechanic' => 'recall_order', 'board' => null, 'running' => false]]));
    $this->get(route('rooms.past'))->assertInertia(fn (Assert $page) => $page->where('past', []));

    // A player who left an open room finds it under the past ones, with what the room has played so far.
    $this->actingAs($this->aygun)->post(route('rooms.leave', $open))->assertRedirect();
    $this->get(route('rooms.index'))->assertInertia(fn (Assert $page) => $page->where('open', []));
    $this->get(route('rooms.past'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('past.0', ['id' => $open->id, 'date' => '25 November 2026', 'host' => false, 'players' => 2, 'games' => [], 'mine' => ['points' => 0, 'place' => null]]));

    // A player the host removed sees the room no more.
    $this->actingAs($this->tural)->delete(route('rooms.members.destroy', [$turals, pagesRow($turals, $nigar)]))->assertRedirect();
    $this->actingAs($nigar)->get(route('rooms.index'))->assertInertia(fn (Assert $page) => $page->where('open', []));
    $this->get(route('rooms.past'))->assertInertia(fn (Assert $page) => $page->where('past', []));
});

it('gives a closed room its games and rankings by name', function (): void {
    $room = Rooms::open($this->resad);
    Rooms::join($room, $this->aygun);
    Rooms::join($room, $this->tural);
    [$resad, $aygun, $tural] = [pagesRow($room, $this->resad), pagesRow($room, $this->aygun), pagesRow($room, $this->tural)];

    // Game 1: Aygün and Tural right at once, Rəşad wrong. Game 2: Rəşad right, the others wrong.
    $first = pagesPlay($room, RoomMechanic::RecallOrder, 1, right: [$this->aygun, $this->tural], wrong: [$this->resad]);
    $second = pagesPlay($room, RoomMechanic::RecallCells, 3, right: [$this->resad], wrong: [$this->aygun, $this->tural]);

    expect(pagesPoints($first, $aygun))->toBe(100)
        ->and(pagesPoints($first, $tural))->toBe(100)
        ->and(pagesPoints($second, $resad))->toBe(100);

    // While the room is open, its page has no results of games.
    $this->actingAs($this->aygun)->get(route('rooms.show', $room))
        ->assertInertia(fn (Assert $page) => $page->where('games', null));
    $this->actingAs($this->resad)->post(route('rooms.close', $room))->assertRedirect();

    // The open room's page has no games; the closed one's has each with its ranking.
    foreach ([$this->resad, $this->aygun, $this->tural] as $account) {
        $props = pagesProps($this->actingAs($account)->get(route('rooms.show', $room)));
        $names = array_column($props['state']['players'], 'name', 'id');
        $ranked = static fn (array $game): array => array_map(
            static fn (array $row): array => [$names[$row['player_id']], $row['points'], $row['place']],
            $game['ranking'],
        );

        expect($props['room'])->toBe(['id' => $room->id, 'status' => 'closed'])
            ->and($props['join'])->toBeNull()
            ->and($props['catalog'])->toBeNull()
            ->and(array_map(static fn (array $game): array => [$game['id'], $game['mechanic'], $game['difficulty'], $game['rounds']], $props['games']))->toBe([
                [$first->id, 'recall_order', 1, 5],
                [$second->id, 'recall_cells', 3, 5],
            ])
            // A shared place, names in Azerbaijani order inside it; no points, no place.
            ->and($ranked($props['games'][0]))->toBe([
                ['Aygün Məmmədova', 100, 1],
                ['Tural Əliyev', 100, 1],
                ['Rəşad Quliyev', 0, null],
            ])
            ->and($ranked($props['games'][1]))->toBe([
                ['Rəşad Quliyev', 100, 1],
                ['Aygün Məmmədova', 0, null],
                ['Tural Əliyev', 0, null],
            ])
            // The room's standing over both games: three players on 100, one place.
            ->and($props['state']['ranking'])->toBe([
                ['player_id' => $aygun->id, 'points' => 100, 'place' => 1],
                ['player_id' => $resad->id, 'points' => 100, 'place' => 1],
                ['player_id' => $tural->id, 'points' => 100, 'place' => 1],
            ])
            ->and($props['place_labels'][0])->toBe(Ordinal::label(1, 'az'));
    }

    // Names are the accounts' as they are now.
    $this->aygun->forceFill(['name' => 'Aygün Həsənova'])->save();
    $this->actingAs($this->resad)->get(route('rooms.show', $room))
        ->assertInertia(fn (Assert $page) => $page->where('state.players.1.name', 'Aygün Həsənova'));
});

it('sends no account id, address or solution to any room page', function (): void {
    $room = Rooms::open($this->resad);
    Rooms::join($room, $this->aygun);
    $game = Rooms::playing($room, 5, 2, RoomMechanic::RecallCells);
    $accounts = [$this->resad, $this->aygun];

    /**
     * Every page a room account may open now, as the JSON it receives.
     *
     * @return array<string, string>
     */
    $pages = function (User $account) use ($room): array {
        $this->actingAs($account);
        $open = $room->refresh()->isOpen();
        $pages = [
            'rooms/index' => $this->get(route('rooms.index')),
            'rooms/show' => $this->get(route('rooms.show', $room)),
            'rooms/join' => $this->get(route('rooms.entrance')),
            ...($open && $room->isHostedBy($account) ? ['rooms/screen' => $this->get(route('rooms.screen', $room))] : []),
        ];

        $wire = array_map(static function (TestResponse $response): string {
            $props = $response->assertOk()->viewData('page')['props'];
            // The viewer's own account is in the shared `auth.user`, as on every page.
            unset($props['translations'], $props['auth']);

            return (string) json_encode($props, JSON_UNESCAPED_UNICODE);
        }, $pages);

        if ($open) {
            $wire['state'] = (string) $this->getJson(route('rooms.state', $room))->assertOk()->getContent();
        }

        return $wire;
    };

    $check = function (string $moment, ?RoomRound $round) use ($pages, $accounts): void {
        foreach ($accounts as $viewer) {
            foreach ($pages($viewer) as $name => $wire) {
                foreach ([$this->resad, $this->aygun, $this->tural] as $someone) {
                    expect($wire)->not->toContain($someone->email, "{$moment} {$name}")
                        ->and($wire)->not->toMatch('/"(user_id|account_id|email|ip|ip_address|seed)"/', "{$moment} {$name}")
                        ->and($wire)->not->toContain('"id":'.$someone->id.',', "{$moment} {$name}")
                        ->and($wire)->not->toContain('"id":'.$someone->id.'}', "{$moment} {$name}");
                }

                // Keys, not values: a taunt's key is a word too (`mine`, ADR 029 decision 27).
                if ($round !== null) {
                    expect($wire)->not->toMatch('/"(solution|accept|correct|mine|results|picked|elapsed_ms|spent)":/', "{$moment} {$name}")
                        ->and($wire)->not->toContain(json_encode(Rooms::right($round)), "{$moment} {$name}");
                }
            }
        }
    };

    // The lobby, the countdown, a study and a question: nothing of anyone, no solution.
    $check('countdown', Rooms::round($game, 0));

    foreach ([RoomPhase::Study, RoomPhase::Answer] as $phase) {
        $this->travelTo(Rooms::at($game, $phase, 1));
        $check($phase->value, Rooms::round($game, 1));
    }

    // Answered, revealed, over, closed: still no account id or address.
    $round = Rooms::round($game, 1);
    $this->actingAs($this->aygun)->postJson(route('rooms.answers.store', $room), Rooms::answer($round, Rooms::right($round)))->assertOk();
    $this->travelTo(Rooms::at($game, RoomPhase::Reveal, 1));
    $check('reveal', null);
    $this->travelTo(Rooms::at($game, RoomPhase::Over));
    $check('over', null);
    $this->actingAs($this->resad)->post(route('rooms.close', $room));
    $check('closed', null);

    // The open room's join link is on the server's own address, never the request's.
    $other = Rooms::open($this->tural);
    config(['app.url' => 'http://192.168.1.20:8000']);
    $this->actingAs($this->tural)->get(route('rooms.show', $other))
        ->assertInertia(fn (Assert $page) => $page->where('join', [
            'url' => 'http://192.168.1.20:8000/oyun/'.$other->code.'?t='.RoomJoinLink::tag($other),
            'entry_url' => 'http://192.168.1.20:8000/oyun',
        ]));
});

it('takes a player\'s name out of a closed room and keeps the points', function (): void {
    $room = Rooms::open($this->resad);
    Rooms::join($room, $this->aygun);
    $aygun = pagesRow($room, $this->aygun);
    $game = pagesPlay($room, RoomMechanic::RecallCells, 1, right: [$this->aygun], wrong: [$this->resad]);
    $points = pagesPoints($game, $aygun);

    // Not while the room is open.
    $this->actingAs($this->aygun)->delete(route('rooms.name.destroy', $room))->assertForbidden();
    $this->actingAs($this->resad)->post(route('rooms.close', $room));

    $this->actingAs($this->aygun)->delete(route('rooms.name.destroy', $room))->assertRedirect(route('rooms.index'));

    expect($aygun->refresh()->user_id)->toBeNull();

    // Gone from her list and her reach.
    $this->get(route('rooms.past'))->assertInertia(fn (Assert $page) => $page->where('past', []));
    $this->get(route('rooms.show', $room))->assertNotFound();

    // The host still sees her points and her place, without a name.
    $props = pagesProps($this->actingAs($this->resad)->get(route('rooms.show', $room)));

    expect($props['state']['players'][1])->toBe(['id' => $aygun->id, 'name' => null, 'present' => false, 'host' => false, 'voice' => null])
        ->and($props['games'][0]['ranking'][0])->toBe(['player_id' => $aygun->id, 'points' => $points, 'place' => 1])
        ->and($props['state']['ranking'][0])->toBe(['player_id' => $aygun->id, 'points' => $points, 'place' => 1])
        ->and(json_encode($props, JSON_UNESCAPED_UNICODE))->not->toContain('Aygün');

    // And on «Keçmiş oyunlar» the host, who scored nothing, has no place.
    $this->get(route('rooms.past'))
        ->assertInertia(fn (Assert $page) => $page->where('past.0.mine', ['points' => 0, 'place' => null]));
});

it('deletes a closed room for everyone', function (): void {
    $room = Rooms::open($this->resad);
    Rooms::join($room, $this->aygun);
    pagesPlay($room, RoomMechanic::RecallCells, 1, right: [$this->aygun, $this->resad]);

    // Not while it is open, and never by a player.
    $this->actingAs($this->resad)->delete(route('rooms.destroy', $room))->assertForbidden();
    $this->actingAs($this->resad)->post(route('rooms.close', $room));
    $this->actingAs($this->aygun)->delete(route('rooms.destroy', $room))->assertForbidden();

    $this->actingAs($this->resad)->delete(route('rooms.destroy', $room))->assertRedirect(route('rooms.index'));

    // Every row under it is gone.
    foreach (['rooms' => 'id', 'room_players' => 'room_id', 'room_games' => 'room_id'] as $table => $column) {
        expect(DB::table($table)->where($column, $room->id)->count())->toBe(0, $table);
    }

    expect(DB::table('room_rounds')->count())->toBe(0)
        ->and(DB::table('room_answers')->count())->toBe(0);

    foreach ([$this->resad, $this->aygun] as $account) {
        $this->actingAs($account)->get(route('rooms.index'))->assertInertia(fn (Assert $page) => $page->where('open', []));
        $this->get(route('rooms.past'))->assertInertia(fn (Assert $page) => $page->where('past', []));
        $this->get(route('rooms.show', $room))->assertNotFound();
    }
});

it('gives the screen only to the host', function (): void {
    $room = Rooms::open($this->resad);
    Rooms::join($room, $this->aygun);

    // The host: the state the room page has, the place labels and the join link from the server.
    $props = pagesProps($this->actingAs($this->resad)->get(route('rooms.screen', $room)));

    expect(array_keys($props))->toContain('room', 'state', 'place_labels', 'join')
        ->and($props['room'])->toBe(['id' => $room->id, 'status' => 'open'])
        ->and($props['state'])->toBe($this->getJson(route('rooms.state', [$room, 'for' => 'screen']), ['Referer' => route('rooms.screen', $room)])->json())
        ->and($props['join']['url'])->toBe(rtrim((string) config('app.url'), '/').'/oyun/'.$room->code.'?t='.RoomJoinLink::tag($room));
    $this->get(route('rooms.screen', $room))->assertInertia(fn (Assert $page) => $page->component('rooms/screen'));

    // In a game it follows the same state, and writes nothing but its own read (`rooms.screen_seen_ms`).
    $game = Rooms::playing($room);
    $this->travelTo(Rooms::at($game, RoomPhase::Study, 0));
    $rooms = static fn (): array => DB::table('rooms')->get()->map(static fn (object $row): array => array_diff_key((array) $row, ['screen_seen_ms' => true]))->all();
    $before = [$rooms(), DB::table('room_players')->get()->all(), DB::table('room_answers')->count(), RoomRound::query()->whereNotNull('closed_ms')->count()];
    $props = pagesProps($this->actingAs($this->resad)->get(route('rooms.screen', $room)));

    expect($props['state']['round']['phase'])->toBe('study')
        ->and($props['state']['room']['screen'])->toBeTrue()
        ->and($props['state'])->toBe($this->getJson(route('rooms.state', [$room, 'for' => 'screen']), ['Referer' => route('rooms.screen', $room)])->json())
        ->and([$rooms(), DB::table('room_players')->get()->all(), DB::table('room_answers')->count(), RoomRound::query()->whereNotNull('closed_ms')->count()])->toEqual($before)
        ->and($room->fresh()?->screen_seen_ms)->toBe(RoomClock::nowMs());

    // A player of the room, a host who does not play too: only the host.
    $this->actingAs($this->aygun)->get(route('rooms.screen', $room))->assertForbidden();
    $this->actingAs($this->tural)->get(route('rooms.screen', $room))->assertNotFound();
    $watcher = User::factory()->player()->create();
    $watched = Rooms::open($watcher, plays: false);
    $this->actingAs($watcher)->get(route('rooms.screen', $watched))->assertOk();

    // A closed room has no screen; an idle one neither.
    $this->actingAs($this->resad)->post(route('rooms.close', $room));
    $this->get(route('rooms.screen', $room))->assertForbidden();
    $this->travelTo(now()->addHours(config('elifba.rooms.idle_hours') + 1));
    $this->actingAs($watcher)->get(route('rooms.screen', $watched))->assertForbidden();

    // A visitor is sent to the login.
    auth()->logout();
    $this->get(route('rooms.screen', $watched))->assertRedirect(route('login'));
});

it('sends the screen the room\'s sounds and a phone its own and the room\'s, and a closed room none', function (): void {
    $room = Rooms::open($this->resad);
    Rooms::join($room, $this->aygun);
    $folder = asset('content/rooms/sounds').'/';
    $screen = ['countdown', 'go', 'study', 'question', 'clock', 'close', 'ranking', 'place', 'roll', 'fanfare', 'join'];
    $phone = ['tap', 'undo', 'sent', 'right', 'wrong'];

    $sounds = pagesProps($this->actingAs($this->resad)->get(route('rooms.screen', $room)))['sounds'];

    expect(array_keys($sounds))->toBe($screen)
        ->and($sounds)->each->toStartWith($folder);

    // The host and a player on their phones: the room's moments, which a phone plays only while the
    // room has no screen (ADR 028 as amended 2026-11-19), and their own quiet cues.
    foreach ([$this->resad, $this->aygun] as $account) {
        $sounds = pagesProps($this->actingAs($account)->get(route('rooms.show', $room)))['sounds'];

        expect(array_keys($sounds))->toBe([...$screen, ...$phone])
            ->and($sounds)->each->toStartWith($folder);
    }

    // A player who left follows nothing, and a closed room plays nothing.
    $this->actingAs($this->aygun)->post(route('rooms.leave', $room))->assertRedirect();
    $this->get(route('rooms.show', $room))->assertInertia(fn (Assert $page) => $page->where('sounds', null));
    $this->actingAs($this->resad)->post(route('rooms.close', $room))->assertRedirect();
    $this->get(route('rooms.show', $room))->assertInertia(fn (Assert $page) => $page->where('sounds', null));
});

it('sends the screen and a phone of an open room the end phrases said after the winner\'s name, and a closed room none', function (): void {
    $room = Rooms::open($this->resad);
    Rooms::join($room, $this->aygun);
    $clips = app(VoiceClips::class);

    $finish = pagesProps($this->actingAs($this->resad)->get(route('rooms.screen', $room)))['finish'];

    // The board's own clips, «Əla oynadın!» and «Əla oynadınız!» (ADR 030): public files that name nobody.
    expect($finish)->toBe(['one' => $clips->url($clips->finish()), 'many' => $clips->url($clips->finishClass())])
        ->and($finish['one'])->toStartWith(asset('content/voice').'/')
        ->and($finish['many'])->toStartWith(asset('content/voice').'/');

    // A phone says them in a room without a screen (ADR 030 as amended 2026-11-19).
    foreach ([$this->resad, $this->aygun] as $account) {
        expect(pagesProps($this->actingAs($account)->get(route('rooms.show', $room)))['finish'])->toBe($finish);
    }

    // A closed room says nothing.
    $this->actingAs($this->resad)->post(route('rooms.close', $room))->assertRedirect();
    $this->get(route('rooms.show', $room))->assertInertia(fn (Assert $page) => $page->where('finish', null));
});

it('sends the rivalry icons to the room page of an open room and to the screen, and none to a closed room', function (): void {
    $room = Rooms::open($this->resad);
    Rooms::join($room, $this->aygun);
    $version = (new ContentRepository(base_path('content')))->roomRivalry()->version;
    $icons = [
        'clash' => asset('content/fluent/26A1.svg').'?v='.$version,
        'leader' => asset('content/fluent/1F451.svg').'?v='.$version,
        'overtake' => asset('content/fluent/1F525.svg').'?v='.$version,
    ];

    // The screen, and every phone that follows the open room: the same three Fluent files.
    expect(pagesProps($this->actingAs($this->resad)->get(route('rooms.screen', $room)))['rivalry'])->toBe($icons);

    foreach ([$this->resad, $this->aygun] as $account) {
        expect(pagesProps($this->actingAs($account)->get(route('rooms.show', $room)))['rivalry'])->toBe($icons);
    }

    // A player who left follows nothing, and a closed room has no strip.
    $this->actingAs($this->aygun)->post(route('rooms.leave', $room))->assertRedirect();
    $this->get(route('rooms.show', $room))->assertInertia(fn (Assert $page) => $page->where('rivalry', null));
    $this->actingAs($this->resad)->post(route('rooms.close', $room))->assertRedirect();
    $this->get(route('rooms.show', $room))->assertInertia(fn (Assert $page) => $page->where('rivalry', null));
});

it('sends the room page and the screen the taunts\' images and clips, and a closed room none', function (): void {
    $room = Rooms::open($this->resad);
    Rooms::join($room, $this->aygun);
    $keys = ['turtle', 'tick_tock', 'frozen', 'sleepy', 'lucky', 'dice', 'mine', 'catch_me', 'too_easy', 'so_close', 'silly', 'what_now'];
    $folder = asset('content/fluent').'/';
    $clips = asset('content/rooms/taunts').'/';
    $turtle = app(TauntVoices::class)->line(app(ContentRepository::class)->roomTaunts()['turtle']);

    // The screen and every phone that follows the room: each key with its emoji and its clip, never a phrase.
    foreach ([[$this->resad, route('rooms.screen', $room)], [$this->resad, route('rooms.show', $room)], [$this->aygun, route('rooms.show', $room)]] as [$account, $url]) {
        $taunts = pagesProps($this->actingAs($account)->get($url))['taunts'];

        expect(array_column($taunts, 'key'))->toBe($keys)
            ->and(array_map(array_keys(...), $taunts))->each->toBe(['key', 'url', 'voice'])
            ->and(array_column($taunts, 'url'))->each->toStartWith($folder)
            ->and(array_column($taunts, 'url')[0])->toBe($folder.'1F422.svg?v=2')
            ->and(array_column($taunts, 'voice'))->each->toStartWith($clips)->toEndWith('.mp3?v=2')
            ->and(array_column($taunts, 'voice')[0])->toBe($clips.$turtle?->hash.'.mp3?v=2')
            ->and(array_unique(array_column($taunts, 'voice')))->toHaveCount(12);
    }

    // A player who left follows nothing, and a closed room throws nothing.
    $this->actingAs($this->aygun)->post(route('rooms.leave', $room))->assertRedirect();
    $this->get(route('rooms.show', $room))->assertInertia(fn (Assert $page) => $page->where('taunts', null));
    $this->actingAs($this->resad)->post(route('rooms.close', $room))->assertRedirect();
    $this->get(route('rooms.show', $room))->assertInertia(fn (Assert $page) => $page->where('taunts', null));
});

it('names the family of each game the setup offers and what each level holds', function (): void {
    $room = Rooms::open($this->resad);
    Rooms::join($room, $this->aygun);
    $content = app(ContentRepository::class);

    $setup = pagesProps($this->actingAs($this->resad)->get(route('rooms.show', $room)))['catalog'];

    expect(array_column($setup['mechanics'], 'mechanic'))->toBe(['recall_order', 'recall_cells', 'next_in_sequence', 'odd_one_out', 'anagram', 'spelling'])
        ->and(array_column($setup['mechanics'], 'family'))->toBe(['memory', 'memory', 'logic', 'logic', 'words', 'words']);

    foreach ($setup['mechanics'] as $offer) {
        $mechanic = RoomMechanic::from($offer['mechanic']);

        expect($offer['family'])->toBe($mechanic->family()->value)
            ->and(array_column($offer['levels'], 'difficulty'))->toBe($offer['difficulties']);

        // Each level as levels.json holds it: the times, how many items a round asks to recall, a grid;
        // a row's rules, terms and range; an odd one's source and options; a word's letters and a spelling's swaps.
        foreach ($offer['levels'] as $described) {
            $level = $content->roomLevel($mechanic, $described['difficulty']);
            $count = $level->length ?? $level->lit;

            expect($described)->toBe([
                'difficulty' => $level->difficulty,
                'study_ms' => $level->studyMs,
                'answer_ms' => $level->answerMs,
                'count' => $count === null ? null : ['min' => $count->min, 'max' => $count->max],
                'grid' => $level->grid?->toArray(),
                'rules' => $level->rules === [] ? null : array_map(static fn (RoomSequenceRule $rule): string => $rule->value, $level->rules),
                'terms' => $level->terms,
                'range' => $level->range === null ? null : ['min' => $level->range->min, 'max' => $level->range->max],
                'source' => $level->source?->value,
                'options' => $level->options,
                'letters' => $level->wordLength === null ? null : ['min' => $level->wordLength->min, 'max' => $level->wordLength->max],
                'swaps' => $level->swaps,
            ]);
        }
    }

    // A row's length for a row, the lit cells and the grid for cells.
    $cells = collect($setup['mechanics'])->firstWhere('mechanic', 'recall_cells');
    $rows = collect($setup['mechanics'])->firstWhere('mechanic', 'recall_order');
    expect($cells['levels'][1]['grid'])->toBe($content->roomLevel(RoomMechanic::RecallCells, 2)->grid?->toArray())
        ->and($cells['levels'][1]['count'])->toBe(['min' => $content->roomLevel(RoomMechanic::RecallCells, 2)->lit?->min, 'max' => $content->roomLevel(RoomMechanic::RecallCells, 2)->lit?->max])
        ->and($rows['levels'][0]['grid'])->toBeNull()
        ->and($rows['levels'][0]['count'])->toBe(['min' => $content->roomLevel(RoomMechanic::RecallOrder, 1)->length?->min, 'max' => $content->roomLevel(RoomMechanic::RecallOrder, 1)->length?->max]);

    // B5: a logic game is answered at once; a row says its rules, terms and numbers, an odd one its source and options.
    $sequence = collect($setup['mechanics'])->firstWhere('mechanic', 'next_in_sequence');
    $odd = collect($setup['mechanics'])->firstWhere('mechanic', 'odd_one_out');
    expect(array_unique(array_column([...$sequence['levels'], ...$odd['levels']], 'study_ms')))->toBe([0])
        ->and($sequence['levels'][0])->toMatchArray(['count' => null, 'grid' => null, 'rules' => ['arithmetic'], 'terms' => 4, 'range' => ['min' => 1, 'max' => 50], 'source' => null, 'options' => null])
        ->and($sequence['levels'][3]['rules'])->toHaveCount(6)
        ->and($odd['levels'][0])->toMatchArray(['count' => null, 'rules' => null, 'terms' => null, 'range' => null, 'source' => 'words', 'options' => 4])
        ->and(array_column($odd['levels'], 'source'))->toBe(['words', 'numbers', 'words', 'numbers']);
    // A word game's letters, and a spelling's swaps; nothing to study and no count of items.
    $anagram = collect($setup['mechanics'])->firstWhere('mechanic', 'anagram');
    $spelling = collect($setup['mechanics'])->firstWhere('mechanic', 'spelling');
    expect($anagram['family'])->toBe('words')
        ->and($anagram['levels'][0])->toMatchArray(['study_ms' => 0, 'count' => null, 'grid' => null, 'swaps' => null])
        ->and($anagram['levels'][0]['letters'])->toBe(['min' => $content->roomLevel(RoomMechanic::Anagram, 1)->wordLength?->min, 'max' => $content->roomLevel(RoomMechanic::Anagram, 1)->wordLength?->max])
        ->and(array_column($spelling['levels'], 'swaps'))->toBe([2, 2, 1, 1])
        ->and($spelling['levels'][3]['letters'])->toBe(['min' => $content->roomLevel(RoomMechanic::Spelling, 4)->wordLength?->min, 'max' => $content->roomLevel(RoomMechanic::Spelling, 4)->wordLength?->max])
        ->and($rows['levels'][0]['letters'])->toBeNull();

    // Nobody else gets it: a player, and the host once the room is closed.
    $this->actingAs($this->aygun)->get(route('rooms.show', $room))->assertInertia(fn (Assert $page) => $page->where('catalog', null));
    $this->actingAs($this->resad)->post(route('rooms.close', $room));
    $this->get(route('rooms.show', $room))->assertInertia(fn (Assert $page) => $page->where('catalog', null));
});

it('sends the home the catalog and the open rooms, and the past rooms to their own page', function (): void {
    $past = Rooms::open($this->resad);
    $game = pagesPlay($past, RoomMechanic::RecallCells, 2, right: [$this->resad]);
    $this->actingAs($this->resad)->post(route('rooms.close', $past));
    $open = Rooms::open($this->resad, mechanic: RoomMechanic::Spelling);
    $root = rtrim((string) config('app.url'), '/');

    // «Oyunlar»: the games as the server offers them, the open room with the game of its choice, the code field's address.
    $home = pagesProps($this->get(route('rooms.index')));
    expect(array_keys($home))->toContain('catalog', 'open', 'entrance')
        ->and($home)->not->toHaveKey('past')
        ->and($home['catalog'])->toBe(RoomGameSetupData::options())
        ->and(array_column($home['catalog']['mechanics'], 'mechanic'))->toBe(array_map(static fn (RoomMechanic $mechanic): string => $mechanic->value, RoomGameSetupData::mechanics()))
        ->and($home['open'])->toBe([['id' => $open->id, 'code' => $open->code, 'host' => true, 'players' => 1, 'mechanic' => 'spelling', 'board' => null, 'running' => false]])
        ->and($home['entrance'])->toBe($root.'/oyun');

    // While a game runs there, the home says so.
    Rooms::playing($open);
    expect(pagesProps($this->get(route('rooms.index')))['open'][0]['running'])->toBeTrue();

    // «Keçmiş oyunlar»: the past rooms alone.
    $this->get(route('rooms.past'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('rooms/past')
        ->where('past', [[
            'id' => $past->id,
            'date' => '24 noyabr 2026',
            'host' => true,
            'players' => 1,
            'games' => [['mechanic' => 'recall_cells', 'difficulty' => 2]],
            'mine' => ['points' => pagesPoints($game, pagesRow($past, $this->resad)), 'place' => Ordinal::label(1, 'az')],
        ]])
        // How long its results are kept, for its one line (ADR 026 decision 14).
        ->where('retention_days', config('elifba.retention_days'))
        ->missing('open')
        ->missing('catalog'));
});

it('sends the screen what it needs to choose and start', function (): void {
    $room = Rooms::open($this->resad);
    Rooms::join($room, $this->aygun);

    // The catalog the host's phone gets, and the choice in the state.
    $screen = pagesProps($this->actingAs($this->resad)->get(route('rooms.screen', $room)));
    $phone = pagesProps($this->get(route('rooms.show', $room)));

    expect($screen['catalog'])->toBe($phone['catalog'])
        ->and($screen['catalog'])->toBe(RoomGameSetupData::options())
        ->and($screen['state']['setup'])->toBe(['mechanic' => 'recall_order', 'difficulty' => 2, 'rounds' => 10, 'played' => false])
        ->and($screen['state']['setup'])->toBe($phone['state']['setup']);

    // With them it changes the choice and starts, through the host's own routes.
    $this->putJson(route('rooms.setup.update', $room), ['mechanic' => 'recall_cells', 'difficulty' => 1, 'rounds' => 5])->assertNoContent();
    $this->postJson(route('rooms.games.store', $room))->assertNoContent();
    $game = $room->latestGame();

    expect([$game?->mechanic, $game?->difficulty])->toBe([RoomMechanic::RecallCells, 1])
        ->and(pagesProps($this->get(route('rooms.screen', $room)))['state']['game']['mechanic'])->toBe('recall_cells');
});

it('gives the start screen, the screen and the round the link with the room\'s tag', function (): void {
    // ADR 033 decision 5: the QR's and the shared link's address carries the room's tag, derived and stored nowhere.
    $room = Rooms::open($this->resad);
    Rooms::join($room, $this->aygun);
    $root = rtrim((string) config('app.url'), '/');
    $tag = RoomJoinLink::tag($room);
    $link = ['url' => "{$root}/oyun/{$room->code}?t={$tag}", 'entry_url' => "{$root}/oyun"];

    expect($tag)->toMatch('/^[0-9a-f]{6}$/')
        ->and(RoomJoinLink::tag($room->refresh()))->toBe($tag)
        ->and(RoomJoinLink::tag(Rooms::open($this->tural)))->not->toBe($tag)
        ->and(RoomJoinLink::tagMatches($room, null))->toBeTrue()
        ->and(RoomJoinLink::tagMatches($room, $tag))->toBeTrue()
        ->and(RoomJoinLink::tagMatches($room, strtoupper($tag)))->toBeFalse();

    // The host's start screen and a player's, with «Dostunu çağır» behind it.
    expect(pagesProps($this->actingAs($this->resad)->get(route('rooms.show', $room)))['join'])->toBe($link)
        ->and(pagesProps($this->actingAs($this->aygun)->get(route('rooms.show', $room)))['join'])->toBe($link)
        // The big screen.
        ->and(pagesProps($this->actingAs($this->resad)->get(route('rooms.screen', $room)))['join'])->toBe($link);

    // In a round, behind the code chip.
    $game = Rooms::playing($room);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 0));
    expect(pagesProps($this->actingAs($this->aygun)->get(route('rooms.show', $room)))['join'])->toBe($link);

    // The tag is in no table: the room's row holds no column with it.
    expect(json_encode(DB::table('rooms')->where('id', $room->id)->first()))->not->toContain($tag);
});

it('writes the link without a port its scheme implies, as a chat shows it', function (string $appUrl, string $root): void {
    // ADR 033 "As built (I2)": docker-compose builds `APP_URL` as `https://elifba.io:443`.
    config(['app.url' => $appUrl]);
    $room = Rooms::open($this->resad);

    expect(RoomJoinLink::of($room))->toBe([
        'url' => "{$root}/oyun/{$room->code}?t=".RoomJoinLink::tag($room),
        'entry_url' => "{$root}/oyun",
    ]);
})->with([
    'https on 443' => ['https://elifba.io:443', 'https://elifba.io'],
    'http on 80' => ['http://elifba.example:80', 'http://elifba.example'],
    'a trailing slash' => ['https://elifba.io:443/', 'https://elifba.io'],
    'another port stays' => ['http://192.168.1.20:8000', 'http://192.168.1.20:8000'],
    'https on 80 stays' => ['https://elifba.example:80', 'https://elifba.example:80'],
    'no port' => ['https://elifba.io', 'https://elifba.io'],
]);

it('shows nothing of a room at the door', function (): void {
    // ADR 033 decision 11: the door reads no room — not the code, the game, the host or who has joined.
    $room = Rooms::open($this->resad);
    Rooms::join($room, $this->aygun);
    auth()->logout();

    $this->get(route('rooms.join', ['code' => (string) $room->code, 't' => RoomJoinLink::tag($room)]), ['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'])
        ->assertRedirect(route('rooms.invited'));
    $props = pagesProps($this->get(route('rooms.invited')));
    $json = (string) json_encode($props);

    expect(array_keys($props))->toEqualCanonicalizing(['errors', 'name', 'auth', 'sidebarOpen', 'locale', 'locales', 'passwordRules', 'googleSignIn'])
        ->not->toContain('room', 'join', 'code', 'players', 'host', 'state');

    foreach ([(string) $room->code, $room->id, 'Rəşad', 'Aygün', RoomJoinLink::tag($room)] as $secret) {
        expect($json)->not->toContain($secret);
    }
});
