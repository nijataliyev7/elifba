<?php

use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Rooms;

/*
 * The host's tools (docs/architecture.md §12 "Rooms and joining", "Games
 * first", ADR 020 decision 8, ADR 026 decision 12): «Kilidlə», «Çıxar»,
 * closing — the host's alone (`RoomPolicy::host`) — and «Mən də
 * oynayıram» before a game (`RoomPolicy::start`), each raising the
 * revision and signalling the room.
 */

beforeEach(function (): void {
    Rooms::on();
    $this->host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->room = Rooms::open($this->host);
    $this->hostRow = RoomPlayer::query()->where('user_id', $this->host->id)->sole();
    $this->player = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
    $this->row = Rooms::join($this->room, $this->player);
    $this->reverb->sent = [];
});

/**
 * @return array<string, list<array<string, mixed>>>
 */
function hostSnapshot(): array
{
    $rows = static fn (string $table): array => DB::table($table)->orderBy('id')->get()
        ->map(static fn (object $row): array => (array) $row)->all();

    return ['rooms' => $rows('rooms'), 'room_players' => $rows('room_players')];
}

/**
 * The revisions the room signalled, in order.
 *
 * @return list<int>
 */
function signalledRevisions(array $sent): array
{
    return array_map(static fn (array $message): int => $message['data']['revision'], $sent);
}

it('locks and unlocks', function (): void {
    $this->actingAs($this->host);

    $this->from(route('rooms.show', $this->room))
        ->patch(route('rooms.update', $this->room), ['locked' => true])
        ->assertRedirect(route('rooms.show', $this->room));
    expect($this->room->refresh()->locked_at)->not->toBeNull()
        ->and($this->room->revision)->toBe(2);
    $this->getJson(route('rooms.state', $this->room))->assertJsonPath('room.locked', true);

    // Locking a locked room changes nothing and says nothing.
    $this->patch(route('rooms.update', $this->room), ['locked' => '1']);
    expect($this->room->refresh()->revision)->toBe(2);

    $this->patch(route('rooms.update', $this->room), ['locked' => false]);
    expect($this->room->refresh()->locked_at)->toBeNull()
        ->and($this->room->revision)->toBe(3)
        ->and(signalledRevisions($this->reverb->sent))->toBe([2, 3]);

    $this->patch(route('rooms.update', $this->room), ['locked' => 'perhaps'])->assertSessionHasErrors('locked');
    $this->patch(route('rooms.update', $this->room), [])->assertSessionHasErrors('locked');
});

it('removes a player for good', function (): void {
    $this->actingAs($this->host)
        ->from(route('rooms.show', $this->room))
        ->delete(route('rooms.members.destroy', [$this->room, $this->row]))
        ->assertRedirect(route('rooms.show', $this->room));

    expect($this->row->refresh()->removed_at)->not->toBeNull()
        ->and($this->room->refresh()->revision)->toBe(2)
        ->and(signalledRevisions($this->reverb->sent))->toBe([2]);

    // Gone from the lobby.
    $this->getJson(route('rooms.state', $this->room))
        ->assertJsonPath('players', [['id' => $this->hostRow->id, 'name' => 'Rəşad Quliyev', 'present' => true, 'host' => true, 'voice' => null, 'ready' => false]]);

    // The removed account finds the room nowhere and cannot come back.
    $before = hostSnapshot();
    $this->actingAs($this->player);
    $this->get(route('rooms.show', $this->room))->assertNotFound();
    $this->getJson(route('rooms.state', $this->room))->assertNotFound();
    $this->post(route('rooms.leave', $this->room))->assertNotFound();
    Rooms::assertRefused($this->get(route('rooms.join', (string) $this->room->code)));
    Rooms::assertRefused($this->post(route('rooms.join.store', (string) $this->room->code)));
    $this->get(route('rooms.index'))->assertInertia(fn (Assert $page) => $page->where('open', []));
    $this->get(route('rooms.past'))->assertInertia(fn (Assert $page) => $page->where('past', []));
    expect(hostSnapshot())->toBe($before);

    // Removing again changes nothing.
    $this->actingAs($this->host)->delete(route('rooms.members.destroy', [$this->room, $this->row]))->assertRedirect();
    expect(hostSnapshot())->toBe($before);
});

it('cannot remove the host\'s own row', function (): void {
    $before = hostSnapshot();

    $this->actingAs($this->host)
        ->delete(route('rooms.members.destroy', [$this->room, $this->hostRow]))
        ->assertForbidden();

    expect(hostSnapshot())->toBe($before)
        ->and($this->reverb->sent)->toBe([]);
});

it('finds no row of another room under this one', function (): void {
    $other = Rooms::open();
    $otherRow = Rooms::join($other, User::factory()->player()->create());
    $before = hostSnapshot();

    $this->actingAs($this->host)
        ->delete(route('rooms.members.destroy', [$this->room, $otherRow]))
        ->assertNotFound();

    expect(hostSnapshot())->toBe($before);
});

it('closes the room and clears its code', function (): void {
    $code = (string) $this->room->code;

    $this->actingAs($this->host)
        ->post(route('rooms.close', $this->room))
        ->assertRedirect(route('rooms.show', $this->room));

    $this->room->refresh();
    expect($this->room->closed_at)->not->toBeNull()
        ->and($this->room->code)->toBeNull()
        ->and($this->room->isOpen())->toBeFalse()
        ->and($this->room->revision)->toBe(2)
        ->and(signalledRevisions($this->reverb->sent))->toBe([2]);

    // A closed room is its results: everyone who was in it sees who.
    foreach ([$this->host, $this->player] as $account) {
        $this->actingAs($account)->get(route('rooms.show', $this->room))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('room.status', 'closed')
                ->where('state.room', ['id' => $this->room->id, 'status' => 'closed', 'locked' => false, 'code' => null, 'taunts' => true, 'screen' => false])
                ->where('state.players.0.name', 'Rəşad Quliyev')
                ->where('state.players.1.name', 'Aygün Məmmədova')
                ->where('can.leave', false)
                ->where('can.forget', true));
    }

    // Its code opens nothing, and nothing in it changes any more.
    Rooms::assertRefused($this->actingAs($this->player)->get(route('rooms.join', $code)));
    $this->getJson(route('rooms.state', $this->room))->assertForbidden();
    $this->post(route('rooms.leave', $this->room))->assertForbidden();

    $before = hostSnapshot();
    $this->actingAs($this->host);
    $this->post(route('rooms.close', $this->room))->assertRedirect();
    $this->patch(route('rooms.update', $this->room), ['locked' => true])->assertRedirect();
    $this->delete(route('rooms.members.destroy', [$this->room, $this->row]))->assertRedirect();
    expect(hostSnapshot())->toBe($before);
});

it('deletes a closed room for everyone, and never an open one', function (): void {
    $this->actingAs($this->host)->delete(route('rooms.destroy', $this->room))->assertForbidden();
    expect($this->room->refresh()->exists)->toBeTrue();

    $this->post(route('rooms.close', $this->room));
    $this->delete(route('rooms.destroy', $this->room))->assertRedirect(route('rooms.index'));

    expect(Room::query()->whereKey($this->room->id)->exists())->toBeFalse()
        ->and(RoomPlayer::query()->where('room_id', $this->room->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey([$this->host->id, $this->player->id])->count())->toBe(2);

    $this->actingAs($this->player)->get(route('rooms.show', $this->room))->assertNotFound();
    $this->get(route('rooms.index'))->assertInertia(fn (Assert $page) => $page->where('open', []));
    $this->get(route('rooms.past'))->assertInertia(fn (Assert $page) => $page->where('past', []));
});

it('lets only the host do any of it', function (): void {
    $before = hostSnapshot();
    $this->actingAs($this->player);

    $this->patch(route('rooms.update', $this->room), ['locked' => true])->assertForbidden();
    $this->delete(route('rooms.members.destroy', [$this->room, $this->hostRow]))->assertForbidden();
    $this->delete(route('rooms.members.destroy', [$this->room, $this->row]))->assertForbidden();
    $this->post(route('rooms.close', $this->room))->assertForbidden();
    $this->delete(route('rooms.destroy', $this->room))->assertForbidden();

    $this->get(route('rooms.show', $this->room))->assertInertia(fn (Assert $page) => $page
        ->where('can', ['host' => false, 'leave' => true, 'delete' => false, 'forget' => false]));

    expect(hostSnapshot())->toBe($before)
        ->and($this->reverb->sent)->toBe([]);
});

it('lets the host step out of playing and back in', function (): void {
    $this->actingAs($this->host);

    // Out: the host's row is left, as a leave leaves it; the host's tools stay, a player's go.
    $this->putJson(route('rooms.plays.update', $this->room), ['plays' => false])->assertNoContent();
    expect($this->hostRow->refresh()->left_at)->not->toBeNull()
        ->and($this->room->refresh()->revision)->toBe(2);
    $this->getJson(route('rooms.state', $this->room))
        ->assertJsonPath('players.0', ['id' => $this->hostRow->id, 'name' => 'Rəşad Quliyev', 'present' => false, 'host' => true, 'voice' => null, 'ready' => false]);
    $this->get(route('rooms.show', $this->room))->assertInertia(fn (Assert $page) => $page->where('can.host', true)->where('can.leave', false));

    // Asking for what already is changes nothing.
    $this->putJson(route('rooms.plays.update', $this->room), ['plays' => '0'])->assertNoContent();
    expect($this->room->refresh()->revision)->toBe(2);

    // A game without the host: never waited for, never answering.
    $game = Rooms::playing($this->room);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    $this->postJson(route('rooms.answers.store', $this->room), Rooms::answer(Rooms::round($game, 0), ['zzzzzzzz']))->assertForbidden();
    $this->travelTo(Rooms::at($game, RoomPhase::Over));

    // Back in: the same row, present again.
    $revision = $this->room->refresh()->revision;
    $this->putJson(route('rooms.plays.update', $this->room), ['plays' => true])->assertNoContent();
    expect($this->hostRow->refresh()->isPresent())->toBeTrue()
        ->and(RoomPlayer::query()->where('user_id', $this->host->id)->count())->toBe(1)
        ->and($this->room->refresh()->revision)->toBe($revision + 1)
        ->and(signalledRevisions($this->reverb->sent))->toContain(2, $revision + 1);

    // A room the host opened without a row of their own gets one.
    $bare = Room::factory()->for($this->host, 'host')->create();
    $this->putJson(route('rooms.plays.update', $bare), ['plays' => true])->assertNoContent();
    expect($bare->rowOf($this->host)?->isPresent())->toBeTrue();

    // Only the host, before a game; a value is required.
    $this->putJson(route('rooms.plays.update', $this->room), [])->assertJsonValidationErrors('plays');
    $this->actingAs($this->player)->putJson(route('rooms.plays.update', $this->room), ['plays' => false])->assertForbidden();
});

it('does not let the lock keep the host out', function (): void {
    $this->actingAs($this->host);
    $this->putJson(route('rooms.plays.update', $this->room), ['plays' => false])->assertNoContent();
    $this->patch(route('rooms.update', $this->room), ['locked' => true]);

    // The lock stops new players, not the host.
    $this->putJson(route('rooms.plays.update', $this->room), ['plays' => true])->assertNoContent();
    expect($this->hostRow->refresh()->isPresent())->toBeTrue();

    // A full room does.
    $this->putJson(route('rooms.plays.update', $this->room), ['plays' => false])->assertNoContent();
    Config::set('elifba.rooms.max_players', 1);
    $before = hostSnapshot();
    $this->putJson(route('rooms.plays.update', $this->room), ['plays' => true])
        ->assertJsonValidationErrors(['plays' => __('rooms.errors.full')]);
    expect(hostSnapshot())->toBe($before);
});
