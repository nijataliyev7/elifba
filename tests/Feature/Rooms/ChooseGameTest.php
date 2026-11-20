<?php

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Actions\ChooseRoomGame;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RoomPhones;
use Tests\Support\Rooms;

/*
 * Games first (docs/architecture.md §12 "Games first", ADR 026 decisions
 * 7–12): a game chosen on «Oyunlar» opens the room behind it, or gives the
 * host's open room that game; the choice is the room's (`rooms.setup_*`),
 * changed by the host alone from the phone or the big screen
 * (`ChooseRoomGame`, `RoomPolicy::start`), signalled like any change, and
 * read by every device from the state's `setup`; starting takes no body and
 * starts the choice, so «Yenidən oyna» is the same start again; a new
 * room's difficulty and rounds are derived from the host's own newest game
 * (`RoomDefaults`), never stored on the account.
 */

beforeEach(function (): void {
    Rooms::on();
    $this->host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->player = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
    $this->stranger = User::factory()->player()->create(['name' => 'Tural Əliyev']);
});

/**
 * Everything a choice could change.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function chooseSnapshot(): array
{
    $rows = static fn (string $table): array => DB::table($table)->orderBy('id')->get()
        ->map(static fn (object $row): array => (array) $row)->all();

    return ['rooms' => $rows('rooms'), 'room_players' => $rows('room_players'), 'room_games' => $rows('room_games')];
}

/**
 * The state's `setup` as `$account` reads it.
 *
 * @return array<string, mixed>|null
 */
function chosenFor(User $account, Room $room): ?array
{
    /** @var array<string, mixed>|null $setup */
    $setup = test()->actingAs($account)->getJson(route('rooms.state', $room))->assertOk()->json('setup');

    return $setup;
}

it('opens a room behind the chosen game', function (): void {
    $this->actingAs($this->host)
        ->post(route('rooms.store'), ['mechanic' => 'anagram'])
        ->assertRedirect(route('rooms.show', $room = Room::query()->sole()));

    // The game chosen, at the defaults: the host has played no game yet.
    expect($room->setup_mechanic)->toBe(RoomMechanic::Anagram)
        ->and([$room->setup_difficulty, $room->setup_rounds])->toBe([2, 10])
        ->and($room->revision)->toBe(0)
        ->and(RoomPlayer::query()->sole()->user_id)->toBe($this->host->id)
        ->and($this->reverb->sent)->toBe([]);

    $this->get(route('rooms.show', $room))->assertInertia(fn (Assert $page) => $page
        ->component('rooms/show')
        ->where('state.setup', ['kind' => 'race', 'mechanic' => 'anagram', 'difficulty' => 2, 'rounds' => 10, 'played' => false])
        ->where('state.game', null)
        ->has('catalog.mechanics', 7));
});

it('takes the difficulty and the rounds of the host\'s newest game, else the defaults', function (): void {
    // An earlier room the host closed, with two games: the newer one counts.
    $earlier = Rooms::open($this->host);
    $first = Rooms::playing($earlier, rounds: 5, difficulty: 1);
    $this->travelTo(Rooms::at($first, RoomPhase::Over));
    $second = Rooms::playing($earlier, rounds: 15, difficulty: 3, mechanic: RoomMechanic::RecallCells);
    $this->travelTo(Rooms::at($second, RoomPhase::Over));
    $this->actingAs($this->host)->post(route('rooms.close', $earlier));

    // A game the host only played in, in someone else's room, does not.
    $theirs = Rooms::open($this->player);
    Rooms::join($theirs, $this->host);
    $this->travelTo(now()->addMinute());
    $played = Rooms::playing($theirs, rounds: 20, difficulty: 4);
    $this->travelTo(Rooms::at($played, RoomPhase::Over));

    $this->actingAs($this->host)->post(route('rooms.store'), ['mechanic' => 'odd_one_out']);
    $room = Room::query()->where('user_id', $this->host->id)->open()->sole();

    expect([$room->setup_mechanic, $room->setup_difficulty, $room->setup_rounds])->toBe([RoomMechanic::OddOneOut, 3, 15]);

    // Rounds no longer inside the bounds: the defaults, the difficulty with them.
    $this->post(route('rooms.close', $room));
    Config::set('elifba.rooms.rounds.max', 12);
    $this->post(route('rooms.store'), ['mechanic' => 'spelling']);
    $room = Room::query()->where('user_id', $this->host->id)->open()->sole();

    expect([$room->setup_mechanic, $room->setup_difficulty, $room->setup_rounds])->toBe([RoomMechanic::Spelling, 2, 10]);

    // Nothing of it is kept on the account.
    expect(json_encode(DB::table('users')->where('id', $this->host->id)->first()))->not->toContain('spelling');
});

it('gives the choice to the open room instead of opening a second', function (): void {
    $room = Rooms::open($this->host);
    Rooms::join($room, $this->player);
    $this->actingAs($this->host)->putJson(route('rooms.setup.update', $room), ['mechanic' => 'recall_cells', 'difficulty' => 3, 'rounds' => 15])->assertNoContent();
    $revision = $room->refresh()->revision;
    $this->reverb->sent = [];

    $this->post(route('rooms.store'), ['mechanic' => 'anagram'])
        ->assertRedirect(route('rooms.show', $room))
        ->assertSessionHasNoErrors();

    // The same room: this game, its difficulty and rounds kept; one change, signalled.
    expect(Room::query()->count())->toBe(1)
        ->and([$room->refresh()->setup_mechanic, $room->setup_difficulty, $room->setup_rounds])->toBe([RoomMechanic::Anagram, 3, 15])
        ->and($room->revision)->toBe($revision + 1)
        ->and($this->reverb->sent)->toHaveCount(1)
        ->and(chosenFor($this->player, $room))->toBe(['kind' => 'race', 'mechanic' => 'anagram', 'difficulty' => 3, 'rounds' => 15, 'played' => false]);

    // An account that only plays in someone else's room opens its own as before.
    $this->actingAs($this->player)->post(route('rooms.store'), ['mechanic' => 'spelling']);
    expect(Room::query()->where('user_id', $this->player->id)->open()->sole()->setup_mechanic)->toBe(RoomMechanic::Spelling);
});

it('changes nothing while a game is running', function (): void {
    $room = Rooms::open($this->host);
    Rooms::playing($room);
    $before = chooseSnapshot();
    $this->reverb->sent = [];

    $this->actingAs($this->host)
        ->post(route('rooms.store'), ['mechanic' => 'anagram'])
        ->assertRedirect(route('rooms.show', $room))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => __('rooms.errors.game_running')]);

    expect(chooseSnapshot())->toBe($before)
        ->and($this->reverb->sent)->toBe([]);
});

it('refuses a game that cannot be played', function (): void {
    $room = Rooms::open($this->host);
    $before = chooseSnapshot();
    $whole = ['mechanic' => 'recall_cells', 'difficulty' => 3, 'rounds' => 15];
    $this->actingAs($this->host);

    // A level the room cannot play, the children's mechanics, rounds out of bounds, a part of the choice.
    foreach ([['rounds' => 4], ['rounds' => 21], ['difficulty' => 0], ['difficulty' => 5], ['mechanic' => 'words'], ['mechanic' => 'find'], ['mechanic' => 'count']] as $wrong) {
        $this->putJson(route('rooms.setup.update', $room), [...$whole, ...$wrong])->assertUnprocessable();
    }

    foreach (['mechanic', 'difficulty', 'rounds'] as $missing) {
        $this->putJson(route('rooms.setup.update', $room), array_diff_key($whole, [$missing => true]))->assertJsonValidationErrors($missing);
    }

    foreach (['', 'find', 'pop'] as $mechanic) {
        $this->post(route('rooms.store'), ['mechanic' => $mechanic])->assertSessionHasErrors('mechanic');
    }

    expect(chooseSnapshot())->toBe($before);
});

it('lets only the host change the choice or start, from the phone or the screen', function (): void {
    $room = Rooms::open($this->host);
    Rooms::join($room, $this->player);
    $before = chooseSnapshot();
    $choice = ['mechanic' => 'next_in_sequence', 'difficulty' => 1, 'rounds' => 5];

    // A player of the room: forbidden; another account: no such room.
    foreach ([[$this->player, 403], [$this->stranger, 404]] as [$account, $status]) {
        $this->actingAs($account)->putJson(route('rooms.setup.update', $room), $choice)->assertStatus($status);
        $this->actingAs($account)->postJson(route('rooms.games.store', $room))->assertStatus($status);
        $this->actingAs($account)->putJson(route('rooms.plays.update', $room), ['plays' => false])->assertStatus($status);
    }

    expect(chooseSnapshot())->toBe($before);

    // The big screen is the host's own session: it sends the host's requests, and nothing about the page decides.
    $screen = ['Referer' => route('rooms.screen', $room)];
    $this->actingAs($this->host)->get(route('rooms.screen', $room))->assertInertia(fn (Assert $page) => $page
        ->where('catalog', RoomGameSetupData::options())
        ->where('state.setup', ['kind' => 'race', 'mechanic' => 'recall_order', 'difficulty' => 2, 'rounds' => 10, 'played' => false]));
    $this->putJson(route('rooms.setup.update', $room), $choice, $screen)->assertNoContent();
    $this->postJson(route('rooms.games.store', $room), [], $screen)->assertNoContent();

    $game = $room->latestGame();
    expect([$game?->mechanic, $game?->difficulty, $game?->rounds->count()])->toBe([RoomMechanic::NextInSequence, 1, 5]);
});

it('refuses a change while a game runs and in a closed room', function (): void {
    $room = Rooms::open($this->host);
    $game = Rooms::playing($room);
    $choice = ['mechanic' => 'anagram', 'difficulty' => 1, 'rounds' => 5];
    $before = chooseSnapshot();
    $this->actingAs($this->host);

    // Running, to its last reveal: by the policy, and by the action under the lock.
    foreach ([RoomPhase::Countdown, RoomPhase::Answer, RoomPhase::Reveal] as $phase) {
        $this->travelTo(Rooms::at($game, $phase, 4));
        $this->putJson(route('rooms.setup.update', $room), $choice)->assertForbidden();
        $this->putJson(route('rooms.plays.update', $room), ['plays' => false])->assertForbidden();
    }

    expect(fn () => app(ChooseRoomGame::class)->handle($room, new RoomGameSetupData(RoomMechanic::Anagram, 1, 5)))->toThrow(AuthorizationException::class)
        ->and(chooseSnapshot())->toBe($before);

    // Over: the choice changes. Closed: never again.
    $this->travelTo(Rooms::at($game, RoomPhase::Over));
    $this->putJson(route('rooms.setup.update', $room), $choice)->assertNoContent();
    $this->post(route('rooms.close', $room));
    $closed = chooseSnapshot();
    $this->putJson(route('rooms.setup.update', $room), [...$choice, 'rounds' => 6])->assertForbidden();

    expect(fn () => app(ChooseRoomGame::class)->handle($room, new RoomGameSetupData(RoomMechanic::Anagram, 1, 6)))->toThrow(AuthorizationException::class)
        ->and(chooseSnapshot())->toBe($closed)
        ->and(chosenFor($this->host, $room))->toBeNull();
});

it('signals every device when the choice changes', function (): void {
    $room = Rooms::open($this->host);
    Rooms::join($room, $this->player);
    $this->reverb->sent = [];
    $this->freezeTime();

    $this->actingAs($this->host)->putJson(route('rooms.setup.update', $room), ['mechanic' => 'spelling', 'difficulty' => 4, 'rounds' => 20])->assertNoContent();

    // `room.changed` with the revision and nothing else; the room is active.
    expect($this->reverb->sent)->toBe([[
        'channels' => ['private-room.'.$room->id],
        'event' => 'room.changed',
        'data' => ['v' => 1, 'revision' => $room->refresh()->revision],
    ]])
        ->and($room->last_activity_at->toDateTimeString())->toBe(now()->toDateTimeString());

    // The same choice again changes nothing and says nothing.
    $this->putJson(route('rooms.setup.update', $room), ['mechanic' => 'spelling', 'difficulty' => '4', 'rounds' => '20'])->assertNoContent();
    expect($this->reverb->sent)->toHaveCount(1);
});

it('shows the choice to the host and a present player, and nothing of it before joining', function (): void {
    $room = Rooms::open($this->host);
    $row = Rooms::join($room, $this->player);
    $this->actingAs($this->host)->putJson(route('rooms.setup.update', $room), ['mechanic' => 'recall_cells', 'difficulty' => 4, 'rounds' => 8]);
    $chosen = ['kind' => 'race', 'mechanic' => 'recall_cells', 'difficulty' => 4, 'rounds' => 8, 'played' => false];

    // The host's phone, the screen and a present player read the same choice, in the page's props and the state.
    expect(chosenFor($this->host, $room))->toBe($chosen)
        ->and(chosenFor($this->player, $room))->toBe($chosen);
    $this->actingAs($this->host)->get(route('rooms.show', $room))->assertInertia(fn (Assert $page) => $page->where('state.setup', $chosen));
    $this->get(route('rooms.screen', $room))->assertInertia(fn (Assert $page) => $page->where('state.setup', $chosen));
    $this->actingAs($this->player)->get(route('rooms.show', $room))->assertInertia(fn (Assert $page) => $page
        ->where('state.setup', $chosen)
        ->where('catalog', null));

    // The join page: the code and the account's own name, nothing of the room.
    $join = $this->actingAs($this->stranger)->get(route('rooms.join', $room->code))->assertOk()->viewData('page')['props'];
    unset($join['translations']);
    expect(array_keys($join))->not->toContain('state', 'setup', 'catalog', 'room')
        ->and(json_encode($join))->not->toContain('recall_cells');

    // A player who left gets no state.
    $this->actingAs($this->player)->post(route('rooms.leave', $room));
    $this->getJson(route('rooms.state', $room))->assertForbidden();
    $this->get(route('rooms.show', $room))->assertInertia(fn (Assert $page) => $page->where('state', null));

    expect($row->refresh()->isPresent())->toBeFalse();
});

it('starts what the room\'s choice says', function (): void {
    $room = Rooms::open($this->host);
    Rooms::join($room, $this->player);
    $this->actingAs($this->host)->putJson(route('rooms.setup.update', $room), ['mechanic' => 'odd_one_out', 'difficulty' => 3, 'rounds' => 7]);

    // A body that still arrives is ignored: what starts is what every device was shown.
    $this->postJson(route('rooms.games.store', $room), ['mechanic' => 'recall_order', 'difficulty' => 1, 'rounds' => 5])->assertNoContent();

    $game = RoomGame::query()->sole();
    expect([$game->mechanic, $game->difficulty, $game->rounds()->count()])->toBe([RoomMechanic::OddOneOut, 3, 7]);

    // While it runs the choice is still there, not yet played; the room keeps it after the game.
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    RoomPhones::read($room, $this->player);
    expect(chosenFor($this->player, $room))->toBe(['kind' => 'race', 'mechanic' => 'odd_one_out', 'difficulty' => 3, 'rounds' => 7, 'played' => false]);
    $this->travelTo(Rooms::at($game, RoomPhase::Over));
    expect([$room->refresh()->setup_mechanic, $room->setup_difficulty, $room->setup_rounds])->toBe([RoomMechanic::OddOneOut, 3, 7]);
});

it('plays again with the same choice', function (): void {
    $room = Rooms::open($this->host);
    $first = Rooms::playing($room, rounds: 6, difficulty: 2, mechanic: RoomMechanic::Spelling);
    $this->travelTo(Rooms::at($first, RoomPhase::Over));

    // «Yenidən oyna» is the start again, with no body.
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $room))->assertNoContent();

    $again = $room->latestGame();
    expect($again?->position)->toBe(1)
        ->and([$again?->mechanic, $again?->difficulty, $again?->rounds->count()])->toBe([RoomMechanic::Spelling, 2, 6])
        ->and($again?->seed)->not->toBe($first->seed);
});

it('says played only while the choice is the game just played', function (): void {
    $room = Rooms::open($this->host);
    Rooms::join($room, $this->player);
    $choice = ['mechanic' => 'recall_order', 'difficulty' => 1, 'rounds' => 5];

    // Before any game, and while one runs: not played.
    expect(chosenFor($this->player, $room)['played'])->toBeFalse();
    $game = Rooms::playing($room, rounds: 5, difficulty: 1);
    $this->travelTo(Rooms::at($game, RoomPhase::Reveal, 4));
    RoomPhones::read($room, $this->player);
    expect(chosenFor($this->player, $room)['played'])->toBeFalse();

    // Over: played — the page shows the final.
    $this->travelTo(Rooms::at($game, RoomPhase::Over));
    expect(chosenFor($this->player, $room))->toBe(['kind' => 'race', ...$choice, 'played' => true]);

    // Another game, other rounds, another difficulty: the start screen again; back to the same: the final again.
    foreach ([['mechanic' => 'anagram'], ['rounds' => 6], ['difficulty' => 2]] as $change) {
        $this->actingAs($this->host)->putJson(route('rooms.setup.update', $room), [...$choice, ...$change])->assertNoContent();
        expect(chosenFor($this->player, $room)['played'])->toBeFalse();
    }

    $this->actingAs($this->host)->putJson(route('rooms.setup.update', $room), $choice)->assertNoContent();
    expect(chosenFor($this->player, $room)['played'])->toBeTrue();

    // A room opened before G2 has no stored choice: its latest game is the choice, and it was played.
    $old = Room::factory()->for($this->host, 'host')->create();
    $played = RoomGame::factory()->for($old)->withRounds(5)->create(['started_ms' => 0]);

    expect($old->setup_mechanic)->toBeNull();
    expect(chosenFor($this->host, $old))->toBe(['kind' => 'race', 'mechanic' => $played->mechanic->value, 'difficulty' => $played->difficulty, 'rounds' => 5, 'played' => true]);
});
