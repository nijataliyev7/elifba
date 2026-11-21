<?php

use App\Domain\Rooms\Actions\EndRoomGame;
use App\Domain\Rooms\Actions\SetRoomPlayerReady;
use App\Domain\Rooms\Data\RoomReadyData;
use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomClock;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Rooms;

/*
 * Ready for the next game (docs/architecture.md §12 "Ready for the next
 * game", ADR 035): between games a present player who is not the host says
 * «Hazıram» and may take it back. Only `SetRoomPlayerReady` writes it, under
 * the room row's lock, and `LeaveRoom` clears it; whether it counts is
 * derived from the room's next game, so a start writes nothing for it. It
 * reaches every device as the state's `players[].ready` and never gates a
 * start.
 */

beforeEach(function (): void {
    Rooms::on();
    $this->travelTo('2026-11-24 10:00:00');
    $this->resad = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->aygun = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
    $this->tural = User::factory()->player()->create(['name' => 'Tural Əliyev']);
    $this->room = Rooms::open($this->resad);
    $this->resadRow = $this->room->rowOf($this->resad);
    $this->aygunRow = Rooms::join($this->room, $this->aygun);
    $this->turalRow = Rooms::join($this->room, $this->tural);
});

/**
 * `$account` says «Hazıram» (`$on`) or takes it back, through the endpoint.
 *
 * @return TestResponse<Response>
 */
function readyPut(User $account, Room $room, mixed $on = true): TestResponse
{
    return test()->actingAs($account)->putJson(route('rooms.ready.update', $room), ['on' => $on]);
}

/**
 * Each player's `ready` in the state `$account` reads now, by the row's id;
 * the screen's with `$screen`.
 *
 * @return array<string, bool>
 */
function readyMarks(User $account, Room $room, bool $screen = false): array
{
    $state = test()->actingAs($account)
        ->getJson(route('rooms.state', $screen ? [$room, 'for' => 'screen'] : $room))
        ->assertOk()
        ->json();

    expect(RoomStateData::fromArray($state)->toArray())->toBe($state);

    return array_column($state['players'], 'ready', 'id');
}

it('marks and takes back, and every device learns it from the state', function (): void {
    $revision = $this->room->refresh()->revision;
    $signals = count($this->reverb->sent);

    readyPut($this->aygun, $this->room)->assertNoContent();

    $marked = [$this->resadRow->id => false, $this->aygunRow->id => true, $this->turalRow->id => false];
    expect(readyMarks($this->aygun, $this->room))->toBe($marked)
        ->and(readyMarks($this->resad, $this->room))->toBe($marked)
        ->and(readyMarks($this->resad, $this->room, screen: true))->toBe($marked)
        ->and(readyMarks($this->tural, $this->room))->toBe($marked)
        ->and($this->aygunRow->refresh()->ready_for)->toBe(0)
        ->and($this->room->refresh()->revision)->toBe($revision + 1)
        ->and(array_slice($this->reverb->sent, $signals))->toBe([[
            'channels' => ['private-room.'.$this->room->id],
            'event' => 'room.changed',
            'data' => ['v' => 1, 'revision' => $revision + 1],
        ]]);

    readyPut($this->aygun, $this->room, false)->assertNoContent();

    expect(readyMarks($this->resad, $this->room))->toBe([$this->resadRow->id => false, $this->aygunRow->id => false, $this->turalRow->id => false])
        ->and($this->aygunRow->refresh()->ready_for)->toBeNull()
        ->and($this->room->refresh()->revision)->toBe($revision + 2)
        ->and(array_slice($this->reverb->sent, $signals))->toHaveCount(2);
});

it('writes nothing and signals nothing for what already is', function (): void {
    readyPut($this->aygun, $this->room)->assertNoContent();
    $revision = $this->room->refresh()->revision;
    $signals = count($this->reverb->sent);

    readyPut($this->aygun, $this->room)->assertNoContent();
    readyPut($this->tural, $this->room, false)->assertNoContent();

    expect($this->room->refresh()->revision)->toBe($revision)
        ->and($this->reverb->sent)->toHaveCount($signals)
        ->and($this->turalRow->refresh()->ready_for)->toBeNull();
});

it('refuses the host, a player who left, a removed player, a closed room, a running game and a body without a boolean', function (): void {
    // The host who plays too, and a host who does not play.
    readyPut($this->resad, $this->room)->assertForbidden();
    $hostOnly = Rooms::open(plays: false);
    Rooms::join($hostOnly, $this->tural);
    readyPut($hostOnly->host, $hostOnly)->assertForbidden();

    readyPut($this->aygun, $this->room, 'maybe')->assertJsonValidationErrors('on');
    readyPut($this->aygun, $this->room, null)->assertJsonValidationErrors('on');

    // A game running, its countdown too.
    $game = Rooms::playing($this->room);
    readyPut($this->aygun, $this->room)->assertForbidden();
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    readyPut($this->aygun, $this->room)->assertForbidden();
    $this->travelTo(Rooms::at($game, RoomPhase::Over));

    // A player who left; one the host removed is not found.
    $this->actingAs($this->tural)->post(route('rooms.leave', $this->room))->assertRedirect();
    readyPut($this->tural, $this->room)->assertForbidden();
    $this->actingAs($this->resad)->delete(route('rooms.members.destroy', [$this->room, $this->aygunRow]))->assertRedirect();
    readyPut($this->aygun, $this->room)->assertNotFound();

    $leyla = User::factory()->player()->create();
    Rooms::join($this->room, $leyla);
    $this->actingAs($this->resad)->post(route('rooms.close', $this->room))->assertRedirect();
    readyPut($leyla, $this->room)->assertForbidden();

    expect(RoomPlayer::query()->whereNotNull('ready_for')->count())->toBe(0);
});

it('refuses under the lock a mark that a start overtook', function (): void {
    Rooms::playing($this->room);

    expect(fn () => app(SetRoomPlayerReady::class)->handle($this->room, $this->aygun, new RoomReadyData(true)))
        ->toThrow(AuthorizationException::class)
        ->and($this->aygunRow->refresh()->ready_for)->toBeNull();
});

it('takes every mark down at the next start without writing, and takes a new one after the game', function (): void {
    readyPut($this->aygun, $this->room)->assertNoContent();
    readyPut($this->tural, $this->room)->assertNoContent();

    // Nobody needs to be ready to start, and a start writes nothing for a mark.
    $game = Rooms::playing($this->room);

    expect(readyMarks($this->resad, $this->room))->toBe([$this->resadRow->id => false, $this->aygunRow->id => false, $this->turalRow->id => false])
        ->and($this->aygunRow->refresh()->ready_for)->toBe(0);

    // The final: an old mark still does not count; a new one does, for the next game.
    $this->travelTo(Rooms::at($game, RoomPhase::Over));
    expect(readyMarks($this->tural, $this->room)[$this->aygunRow->id])->toBeFalse();

    readyPut($this->aygun, $this->room)->assertNoContent();

    expect(readyMarks($this->tural, $this->room))->toBe([$this->resadRow->id => false, $this->aygunRow->id => true, $this->turalRow->id => false])
        ->and($this->aygunRow->refresh()->ready_for)->toBe(1);

    // A game the host ends in its countdown, before its round 0 opens: a mark after it counts at once.
    $second = Rooms::playing($this->room);
    expect(RoomClock::nowMs())->toBeLessThan($second->started_ms);
    app(EndRoomGame::class)->handle($this->room);
    readyPut($this->tural, $this->room)->assertNoContent();

    expect(readyMarks($this->resad, $this->room))->toBe([$this->resadRow->id => false, $this->aygunRow->id => false, $this->turalRow->id => true]);
});

it('clears the mark with a leave, so a return starts unmarked', function (): void {
    readyPut($this->aygun, $this->room)->assertNoContent();

    $this->actingAs($this->aygun)->post(route('rooms.leave', $this->room))->assertRedirect();
    expect($this->aygunRow->refresh()->ready_for)->toBeNull();

    Rooms::join($this->room, $this->aygun);

    expect(readyMarks($this->aygun, $this->room)[$this->aygunRow->id])->toBeFalse();
});

it('marks between matches too, seated or watching', function (): void {
    $leyla = User::factory()->player()->create();
    $leylaRow = Rooms::join($this->room, $leyla);
    $game = Rooms::match($this->room, $this->aygunRow, $this->turalRow);
    $this->travelTo(RoomClock::at($game->started_ms + 1_000));

    // A match running: neither a seat nor a watcher.
    readyPut($this->aygun, $this->room)->assertForbidden();
    readyPut($leyla, $this->room)->assertForbidden();

    $this->actingAs($this->tural)
        ->postJson(route('rooms.acts.store', $this->room), [...Rooms::act('resign'), 'game_id' => $game->id])
        ->assertJsonPath('status', 'accepted');

    readyPut($leyla, $this->room)->assertNoContent();
    readyPut($this->aygun, $this->room)->assertNoContent();

    expect(readyMarks($this->resad, $this->room, screen: true))->toBe([
        $this->resadRow->id => false,
        $this->aygunRow->id => true,
        $this->turalRow->id => false,
        $leylaRow->id => true,
    ]);
});

it('is a 404 to everyone while the switch is off', function (): void {
    Config::set('elifba.rooms.enabled', false);

    readyPut($this->aygun, $this->room)->assertNotFound();
    expect($this->aygunRow->refresh()->ready_for)->toBeNull();
});
