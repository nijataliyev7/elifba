<?php

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Actions\CloseRoom;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Rooms;

/*
 * Opening a room (docs/architecture.md §12 "Rooms and joining", "Games
 * first", ADRs 020 and 026): `POST /rooms` `{mechanic}` → `OpenRoom`, the
 * room behind the chosen game with a code from `RoomCode` and the host as
 * its first player, one open room per host. What the choice holds and how
 * it changes is ChooseGameTest's.
 */

beforeEach(function (): void {
    Rooms::on();
    $this->host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->actingAs($this->host);
});

it('opens a room with a code and the host as its first player', function (): void {
    $this->travelTo('2026-11-24 19:00:00');

    $response = $this->post(route('rooms.store'), ['mechanic' => 'recall_order']);

    $room = Room::query()->sole();
    $response->assertRedirect(route('rooms.show', $room));

    expect($room->user_id)->toBe($this->host->id)
        ->and($room->code)->toMatch('/^\d{6}$/')
        ->and($room->locked_at)->toBeNull()
        ->and($room->closed_at)->toBeNull()
        ->and($room->revision)->toBe(0)
        ->and($room->last_activity_at->toDateTimeString())->toBe('2026-11-24 19:00:00')
        ->and($room->isOpen())->toBeTrue()
        // The instance's live voice is off here: so is the room's (RoomVoiceTest has it on, ADR 031 decision 37).
        ->and($room->voice_at)->toBeNull();

    $player = RoomPlayer::query()->sole();
    expect($player->room_id)->toBe($room->id)
        ->and($player->user_id)->toBe($this->host->id)
        ->and($player->isPresent())->toBeTrue()
        ->and($player->voice_admitted_at)->toBeNull();

    // The lobby shows the code and the host by name, marked as the viewer.
    $this->get(route('rooms.show', $room))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('rooms/show')
            ->where('state.room.code', $room->code)
            ->where('state.me', ['player_id' => $player->id, 'host' => true])
            ->where('state.players', [['id' => $player->id, 'name' => 'Rəşad Quliyev', 'present' => true, 'host' => true, 'voice' => null]])
            ->where('can', ['host' => true, 'leave' => true, 'delete' => false, 'forget' => false]));

    // No room table holds a name.
    foreach (['rooms', 'room_players'] as $table) {
        expect(json_encode(DB::table($table)->get(), JSON_UNESCAPED_UNICODE))->not->toContain('Rəşad');
    }
});

it('opens a room only behind a game a room can play', function (): void {
    foreach ([[], ['mechanic' => ''], ['mechanic' => 'find'], ['mechanic' => 'words'], ['plays' => false]] as $wrong) {
        $this->from(route('rooms.index'))
            ->post(route('rooms.store'), $wrong)
            ->assertRedirect(route('rooms.index'))
            ->assertSessionHasErrors('mechanic');
    }

    expect(Room::query()->count())->toBe(0);
});

it('never opens a second room for a host who has an open one', function (): void {
    $first = Rooms::open($this->host);

    $this->post(route('rooms.store'), ['mechanic' => 'anagram'])
        ->assertRedirect(route('rooms.show', $first))
        ->assertSessionHasNoErrors();

    expect(Room::query()->count())->toBe(1);

    // Another host opens theirs; the first host's next room waits for the close.
    Rooms::open();
    $this->post(route('rooms.close', $first))->assertRedirect(route('rooms.show', $first));
    $this->post(route('rooms.store'), ['mechanic' => 'anagram'])->assertSessionHasNoErrors();

    expect(Room::query()->where('user_id', $this->host->id)->count())->toBe(2);
});

it('lets a host whose room went idle open another', function (): void {
    $this->travelTo('2026-11-24 10:00:00');
    Rooms::open($this->host);

    $this->travelTo('2026-11-24 13:00:01');
    $this->post(route('rooms.store'), ['mechanic' => 'recall_order'])->assertSessionHasNoErrors();

    expect(Room::query()->where('user_id', $this->host->id)->count())->toBe(2)
        ->and(Room::query()->open()->count())->toBe(1);
});

it('never gives two open rooms one code', function (): void {
    $codes = ['482913', '482913', '482913', '107364'];
    app()->instance(RoomCode::class, new RoomCode(static function () use (&$codes): string {
        return array_shift($codes) ?? throw new RuntimeException('No more codes.');
    }));

    $first = Rooms::open();
    $second = Rooms::open();

    expect($first->code)->toBe('482913')
        ->and($second->code)->toBe('107364')
        ->and(Room::query()->count())->toBe(2);

    // A closed room's code is free again: the column no longer holds it.
    app(CloseRoom::class)->handle($first);
    $codes = ['482913'];

    expect($first->refresh()->code)->toBeNull()
        ->and(Rooms::open()->code)->toBe('482913');
});

it('limits opening to six a minute per account', function (): void {
    foreach (range(1, 6) as $_) {
        $this->post(route('rooms.store'), ['mechanic' => 'recall_order'])->assertStatus(302);
    }

    $this->post(route('rooms.store'), ['mechanic' => 'recall_order'])->assertTooManyRequests();
});

it('lists the account\'s own rooms on «Oyunlar» and «Keçmiş oyunlar»', function (): void {
    $this->travelTo('2026-11-24 19:00:00');
    $mine = Rooms::open($this->host, mechanic: RoomMechanic::Anagram);
    $joined = Rooms::open();
    Rooms::join($joined, $this->host);
    Rooms::playing($joined);
    Rooms::open();

    $this->get(route('rooms.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('rooms/index')
            ->where('open', [
                ['id' => $joined->id, 'code' => $joined->code, 'host' => false, 'players' => 2, 'mechanic' => 'recall_order', 'running' => true],
                ['id' => $mine->id, 'code' => $mine->code, 'host' => true, 'players' => 1, 'mechanic' => 'anagram', 'running' => false],
            ])
            ->missing('past'));
    $this->get(route('rooms.past'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('rooms/past')->where('past', []));

    app(CloseRoom::class)->handle($mine);
    $this->post(route('rooms.leave', $joined));

    $this->get(route('rooms.index'))->assertInertia(fn (Assert $page) => $page->where('open', []));
    $this->get(route('rooms.past'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('past', [
                // The room left mid-game lists its game; no answer: no points and no place (B4).
                ['id' => $joined->id, 'date' => '24 noyabr 2026', 'host' => false, 'players' => 2, 'games' => [['mechanic' => 'recall_order', 'difficulty' => 1]], 'mine' => ['points' => 0, 'place' => null]],
                ['id' => $mine->id, 'date' => '24 noyabr 2026', 'host' => true, 'players' => 1, 'games' => [], 'mine' => ['points' => 0, 'place' => null]],
            ]));
});
