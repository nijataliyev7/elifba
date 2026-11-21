<?php

use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomScreen;
use App\Domain\Rooms\Support\RoomState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Rooms;

/*
 * A room's state without a game (docs/architecture.md §12 "The state",
 * "Adults' privacy"): the room, the viewer and the players by the names
 * their accounts have now — nothing else about anyone. The lobby fixture,
 * tests/Fixtures/rooms/state.lobby.json, is what Vitest parses with the zod
 * schema (resources/js/rooms/contract.test.ts); here the server builds it
 * from rows, byte for byte, and the DTOs round-trip it.
 */

const ROOMS_LOBBY_FIXTURE = __DIR__.'/../../Fixtures/rooms/state.lobby.json';

/**
 * @return array<string, mixed>
 */
function lobbyFixture(): array
{
    $decoded = json_decode((string) file_get_contents(ROOMS_LOBBY_FIXTURE), true, 64, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : throw new RuntimeException('The lobby fixture is not an object.');
}

beforeEach(function (): void {
    Rooms::on();
});

it('builds the lobby fixture from rows, and the DTOs round-trip it', function (): void {
    $fixture = lobbyFixture();
    $this->travelTo(CarbonImmutable::createFromTimestampMs($fixture['server_ms']));

    $host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $viewer = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
    $left = User::factory()->player()->create(['name' => 'Tural Əliyev']);

    $room = Room::factory()->for($host, 'host')->create([
        'id' => $fixture['room']['id'],
        'code' => $fixture['room']['code'],
        'revision' => $fixture['revision'],
    ]);
    [$hostRow, $viewerRow, $leftRow, $namelessRow] = array_column($fixture['players'], 'id');
    $at = static fn (int $minutes): CarbonImmutable => CarbonImmutable::now()->subMinutes(10 - $minutes);

    // «Hazıram» for the first game (ADR 035): the viewer's counts; the host's and an absent player's never do.
    RoomPlayer::factory()->for($room)->for($host)->create(['id' => $hostRow, 'joined_at' => $at(0), 'ready_for' => 0]);
    RoomPlayer::factory()->for($room)->for($viewer)->create(['id' => $viewerRow, 'joined_at' => $at(1), 'ready_for' => 0]);
    RoomPlayer::factory()->for($room)->for($left)->left()->create(['id' => $leftRow, 'joined_at' => $at(2), 'ready_for' => 0]);
    RoomPlayer::factory()->for($room)->create(['id' => $namelessRow, 'user_id' => null, 'joined_at' => $at(3)]);
    // Removed: not in the lobby at all.
    RoomPlayer::factory()->for($room)->removed()->create(['joined_at' => $at(4)]);

    $this->actingAs($viewer)->getJson(route('rooms.state', $room))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson($fixture);

    expect(RoomStateData::fromArray($fixture)->toArray())->toBe($fixture);
});

it('gives the lobby its players by name and never an account id or an address', function (): void {
    $this->freezeTime();
    $host = User::factory()->player()->create(['name' => 'Rəşad Quliyev', 'email' => 'resad@example.com']);
    $room = Rooms::open($host);
    $player = User::factory()->player()->create(['name' => 'Aygün Məmmədova', 'email' => 'aygun@example.com']);
    $row = Rooms::join($room, $player);

    foreach ([$host, $player] as $account) {
        $json = $this->actingAs($account)->getJson(route('rooms.state', $room))->assertOk();
        $page = $this->get(route('rooms.show', $room))->assertOk();
        $props = $page->viewData('page')['props'];
        $text = $json->getContent().json_encode([$props['room'], $props['state'], $props['can']], JSON_UNESCAPED_UNICODE);

        expect($json->json('players.*.name'))->toBe(['Rəşad Quliyev', 'Aygün Məmmədova'])
            ->and($props['state'])->toBe($json->json());

        foreach ([$host, $player] as $someone) {
            expect($text)->not->toContain($someone->email)
                ->and($text)->not->toMatch('/"(user_id|account_id|email|ip)"/')
                ->and($text)->not->toContain('"id":'.$someone->id.',');
        }
    }

    // The name is the account's as it is now.
    $player->forceFill(['name' => 'Aygün Həsənova'])->save();
    // Joined before the first game: ready for it by default (ADR 035 decision 21).
    $this->actingAs($host)->getJson(route('rooms.state', $room))
        ->assertJsonPath('players.1', ['id' => $row->id, 'name' => 'Aygün Həsənova', 'present' => true, 'host' => false, 'voice' => null, 'ready' => true]);
});

it('names no one who took their name out', function (): void {
    $host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $room = Rooms::open($host);
    $player = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
    $row = Rooms::join($room, $player);
    $this->actingAs($host)->post(route('rooms.close', $room));

    $this->actingAs($player)
        ->delete(route('rooms.name.destroy', $room))
        ->assertRedirect(route('rooms.index'));

    expect($row->refresh()->user_id)->toBeNull();

    // The room is gone from the account's list and its page is not found.
    $this->get(route('rooms.past'))->assertInertia(fn (Assert $page) => $page->where('past', []));
    $this->get(route('rooms.show', $room))->assertNotFound();

    // The others see the row without a name.
    $this->actingAs($host)->get(route('rooms.show', $room))
        ->assertInertia(fn (Assert $page) => $page
            ->where('state.players.1', ['id' => $row->id, 'name' => null, 'present' => false, 'host' => false, 'voice' => null, 'ready' => false])
            ->where('state.me.player_id', RoomPlayer::query()->where('user_id', $host->id)->value('id'))
            // A closed room has no choice of a next game (G2).
            ->where('state.setup', null));
    expect((string) $this->get(route('rooms.show', $room))->getContent())->not->toContain('Aygün');
});

it('refuses the state to an account outside the room', function (): void {
    $room = Rooms::open();
    Rooms::join($room);
    $removed = User::factory()->player()->create();
    $removedRow = Rooms::join($room, $removed);
    $this->actingAs($room->host)->delete(route('rooms.members.destroy', [$room, $removedRow]));

    foreach ([User::factory()->player()->create(), $removed, User::factory()->teacherAndPlayer()->create()] as $outsider) {
        $this->actingAs($outsider)->getJson(route('rooms.state', $room))->assertNotFound();
        $this->get(route('rooms.show', $room))->assertNotFound();
    }

    // Without the player role too: the room is not found before the role is asked for.
    $this->actingAs(User::factory()->teacherAndParent()->create())->getJson(route('rooms.state', $room))->assertNotFound();

    auth()->logout();
    $this->getJson(route('rooms.state', $room))->assertUnauthorized();
});

it('limits the state to 120 a minute per account', function (): void {
    $room = Rooms::open();
    $this->actingAs($room->host);

    foreach (range(1, 120) as $_) {
        $this->getJson(route('rooms.state', $room))->assertOk();
    }

    $this->getJson(route('rooms.state', $room))->assertTooManyRequests();
});

/*
 * A room without a screen (§12 "A room without a screen", ADR 028 as
 * amended 2026-11-19): the state's `room.screen` says whether the room's
 * big screen read the state within three beats; without one, every phone
 * plays the room's cues too.
 */

it('says the room has its screen while the screen reads, and none three beats after its last read', function (): void {
    $this->freezeTime();
    $host = User::factory()->player()->create();
    $player = User::factory()->player()->create();
    $room = Rooms::open($host);
    Rooms::join($room, $player);
    $screen = static fn (Room $room): bool => test()->actingAs($player)->getJson(route('rooms.state', $room))->assertOk()->json('room.screen');

    expect($screen($room))->toBeFalse();

    $this->actingAs($host)->getJson(route('rooms.state', [$room, 'for' => 'screen']))
        ->assertOk()
        ->assertJsonPath('room.screen', true);

    expect($screen($room))->toBeTrue();

    $this->travel(RoomScreen::goneMs())->milliseconds();
    expect($screen($room))->toBeTrue();

    $this->travel(1)->milliseconds();
    expect($screen($room))->toBeFalse();

    // Opening the screen's page is a read too.
    $this->actingAs($host)->get(route('rooms.screen', $room))->assertOk();
    expect($screen($room))->toBeTrue();

    // A closed room has no screen, whatever it read last.
    $this->actingAs($host)->post(route('rooms.close', $room))->assertRedirect();
    expect(RoomState::for($room->refresh(), $host)->room->screen)->toBeFalse();
});

it('records the screen only from its own requests: never a phone\'s, a player\'s or another room\'s', function (): void {
    $host = User::factory()->player()->create();
    $player = User::factory()->player()->create();
    $room = Rooms::open($host);
    $other = Rooms::open(User::factory()->player()->create());
    Rooms::join($room, $player);

    // The host's phone, and a request from the screen's page without `for=screen`.
    $this->actingAs($host)->getJson(route('rooms.state', $room))->assertOk()->assertJsonPath('room.screen', false);
    $this->actingAs($host)->get(route('rooms.show', $room))->assertOk();
    $this->getJson(route('rooms.state', $room), ['Referer' => route('rooms.screen', $room)])->assertOk()->assertJsonPath('room.screen', false);

    // A player may not ask as the screen, and the host of one room is not another's screen.
    $this->actingAs($player)->getJson(route('rooms.state', [$room, 'for' => 'screen']))->assertForbidden();
    $this->actingAs($player)->get(route('rooms.screen', $room))->assertForbidden();
    $this->actingAs($host)->getJson(route('rooms.state', [$other, 'for' => 'screen']))->assertNotFound();

    expect($room->refresh()->screen_seen_ms)->toBeNull()
        ->and($other->refresh()->screen_seen_ms)->toBeNull();
});

it('records the screen without raising the revision, signalling or touching the room\'s activity, at most once a second', function (): void {
    $host = User::factory()->player()->create();
    $room = Rooms::open($host);
    Rooms::join($room);
    $room->refresh();
    $before = [$room->revision, $room->last_activity_at->getTimestampMs(), $room->updated_at->getTimestampMs()];
    $sent = count($this->reverb->sent);

    $this->travel(5)->seconds();
    $this->actingAs($host)->getJson(route('rooms.state', [$room, 'for' => 'screen']))->assertOk();
    $first = RoomClock::nowMs();

    $this->travel(999)->milliseconds();
    $this->getJson(route('rooms.state', [$room, 'for' => 'screen']))->assertOk();
    $room->refresh();

    expect($room->screen_seen_ms)->toBe($first)
        ->and([$room->revision, $room->last_activity_at->getTimestampMs(), $room->updated_at->getTimestampMs()])->toBe($before)
        ->and(count($this->reverb->sent))->toBe($sent);

    $this->travel(1)->milliseconds();
    $this->getJson(route('rooms.state', [$room, 'for' => 'screen']))->assertOk();

    expect($room->refresh()->screen_seen_ms)->toBe($first + 1000);
});

it('builds the voice fixtures from rows: a player of each standing with the voice on, and the host\'s view with it off', function (): void {
    Rooms::voiceOn();
    $fixture = static fn (string $name): array => json_decode((string) file_get_contents(__DIR__."/../../Fixtures/rooms/{$name}.json"), true, 64, JSON_THROW_ON_ERROR);
    $voice = $fixture('state.voice');
    $off = $fixture('state.voice.off');
    $this->travelTo(CarbonImmutable::createFromTimestampMs($voice['server_ms']));

    $host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $viewer = User::factory()->player()->create(['name' => 'Aygün Məmmədova', 'voice_agreed_at' => now()]);
    $tural = User::factory()->player()->create(['name' => 'Tural Əliyev']);
    $leyla = User::factory()->player()->create(['name' => 'Leyla Həsənova']);
    $room = Room::factory()->for($host, 'host')->create([
        'id' => $voice['room']['id'],
        'code' => $voice['room']['code'],
        'revision' => $voice['revision'],
    ]);
    $room->forceFill(['voice_at' => now(), 'voice_epoch' => 2])->save();
    [$hostRow, $viewerRow, $turalRow, $namelessRow, $leylaRow] = array_column($voice['players'], 'id');
    $at = static fn (int $minutes): CarbonImmutable => CarbonImmutable::now()->subMinutes(10 - $minutes);

    RoomPlayer::factory()->for($room)->for($host)->create(['id' => $hostRow, 'joined_at' => $at(0), 'voice_admitted_at' => now()]);
    RoomPlayer::factory()->for($room)->for($viewer)->create(['id' => $viewerRow, 'joined_at' => $at(1), 'voice_admitted_at' => now()]);
    RoomPlayer::factory()->for($room)->for($tural)->create(['id' => $turalRow, 'joined_at' => $at(2), 'voice_admitted_at' => now(), 'voice_muted_at' => now()]);
    // Let in once, but without an account now: no standing.
    RoomPlayer::factory()->for($room)->create(['id' => $namelessRow, 'user_id' => null, 'joined_at' => $at(3), 'voice_admitted_at' => now()]);
    // Came in while a game ran, the voice on: waits.
    RoomPlayer::factory()->for($room)->for($leyla)->create(['id' => $leylaRow, 'joined_at' => $at(4)]);
    RoomPlayer::factory()->for($room)->removed()->create(['joined_at' => $at(5), 'voice_admitted_at' => now()]);

    $this->actingAs($viewer)->getJson(route('rooms.state', $room))
        ->assertOk()
        ->assertExactJson($voice);
    expect(RoomStateData::fromArray($voice)->toArray())->toBe($voice);

    // The host's view of the lobby with the voice off: `on` false, no standing anywhere.
    RoomPlayer::query()->where('room_id', $room->id)->delete();
    $room->forceFill(['voice_at' => null, 'revision' => $off['revision']])->save();
    $left = User::factory()->player()->create(['name' => 'Tural Əliyev']);
    [$hostRow, $aygunRow, $leftRow, $namelessRow] = array_column($off['players'], 'id');
    RoomPlayer::factory()->for($room)->for($host)->create(['id' => $hostRow, 'joined_at' => $at(0), 'voice_admitted_at' => now()]);
    RoomPlayer::factory()->for($room)->for($viewer)->create(['id' => $aygunRow, 'joined_at' => $at(1), 'voice_admitted_at' => now()]);
    RoomPlayer::factory()->for($room)->for($left)->left()->create(['id' => $leftRow, 'joined_at' => $at(2)]);
    RoomPlayer::factory()->for($room)->create(['id' => $namelessRow, 'user_id' => null, 'joined_at' => $at(3)]);

    $this->actingAs($host)->getJson(route('rooms.state', $room))
        ->assertOk()
        ->assertExactJson($off);

    // A closed room says nothing of voice, whatever its rows hold.
    $room->forceFill(['voice_at' => now(), 'closed_at' => now(), 'code' => null])->save();
    expect($this->getJson(route('rooms.state', $room))->assertOk()->json('voice'))->toBeNull();
    $this->get(route('rooms.show', $room))->assertInertia(fn (Assert $page) => $page->where('state.voice', null));
});
