<?php

use App\Domain\Content\Data\RoomTauntData;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TauntVoices;
use App\Domain\Rooms\Actions\DeleteRoom;
use App\Domain\Rooms\Actions\ForgetRoomPlayer;
use App\Domain\Rooms\Actions\PruneOldRooms;
use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAnswer;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomTaunt;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomRanking;
use App\Domain\Rooms\Support\RoomWaits;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RoomPhones;
use Tests\Support\Rooms;

/*
 * Taunts (docs/architecture.md §12 "Taunts (T2)", ADR 029): a ready-made
 * jibe a present player throws at another during a game, for one point of
 * the thrower's own (`RoomPointsRule::TAUNT_COST`) — the target loses
 * nothing. Only `ThrowRoomTaunt` writes one, under the room row's lock,
 * once per `event_id`; the balance and the cost are derived, and the cost
 * counts in the ranking from the first close after the throw. A taunt
 * reaches only its target's state and the screen's, for `show_ms`; it never
 * closes a round, counts as an answer or as a read, and nothing of who
 * taunted whom is shown after the game.
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
 * Gives each row its points in round `$index`: 0 is a wrong answer.
 *
 * @param  list<array{0: RoomPlayer, 1: int}>  $points
 */
function tauntsPoints(RoomGame $game, int $index, array $points): void
{
    foreach ($points as [$row, $value]) {
        RoomAnswer::factory()->for(Rooms::round($game, $index), 'round')->by($row)->create([
            'correct' => $value > 0,
            'points' => $value,
        ]);
    }
}

/**
 * `$account` throws a taunt at `$target` through the endpoint.
 *
 * @return TestResponse<Response>
 */
function tauntsThrow(User $account, Room $room, RoomPlayer|string $target, ?string $eventId = null, string $taunt = 'turtle'): TestResponse
{
    return test()->actingAs($account)->postJson(route('rooms.taunts.store', $room), [
        'event_id' => $eventId ?? Str::lower((string) Str::ulid()),
        'target_id' => $target instanceof RoomPlayer ? $target->id : $target,
        'taunt' => $taunt,
    ]);
}

/**
 * The state `$account` reads now, through the endpoint; the screen's with `$screen`.
 *
 * @return array<string, mixed>
 */
function tauntsState(User $account, Room $room, bool $screen = false): array
{
    $state = test()->actingAs($account)
        ->getJson(route('rooms.state', $screen ? [$room, 'for' => 'screen'] : $room))
        ->assertOk()
        ->json();

    expect(RoomStateData::fromArray($state)->toArray())->toBe($state);

    return $state;
}

/**
 * Each row's points in a ranking, by the row's id.
 *
 * @param  array<string, mixed>  $state
 * @return array<string, array{0: int, 1: int|null}>
 */
function tauntsRanking(array $state): array
{
    $rows = [];

    foreach ($state['ranking'] as $row) {
        $rows[$row['player_id']] = [$row['points'], $row['place']];
    }

    return $rows;
}

it('takes one point from the thrower and none from the target', function (): void {
    $game = Rooms::playing($this->room);
    tauntsPoints($game, 0, [[$this->aygunRow, 20], [$this->resadRow, 15]]);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    $revision = $this->room->refresh()->revision;
    $signals = count($this->reverb->sent);

    expect(tauntsState($this->aygun, $this->room)['taunts'])->toBe(['open' => true, 'balance' => 20, 'ready_ms' => null, 'received' => [], 'shown' => null]);

    tauntsThrow($this->aygun, $this->room, $this->resadRow)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['status' => 'accepted', 'revision' => $revision + 1]);

    // The thrower's balance at once; the ranking only at the next close; the target untouched.
    $aygun = tauntsState($this->aygun, $this->room);
    $resad = tauntsState($this->resad, $this->room);

    expect($aygun['taunts']['balance'])->toBe(19)
        ->and($aygun['taunts']['ready_ms'])->toBe(RoomClock::nowMs() + 10_000)
        ->and(tauntsRanking($aygun)[$this->aygunRow->id])->toBe([20, 1])
        ->and($resad['taunts']['balance'])->toBe(15)
        ->and(tauntsRanking($resad)[$this->resadRow->id])->toBe([15, 2])
        ->and(array_slice($this->reverb->sent, $signals))->toBe([[
            'channels' => ['private-room.'.$this->room->id],
            'event' => 'room.changed',
            'data' => ['v' => 1, 'revision' => $revision + 1],
        ]]);

    $this->travelTo(Rooms::at($game, RoomPhase::Reveal, 1));
    $state = tauntsState($this->resad, $this->room);

    expect(tauntsRanking($state))->toBe([
        $this->aygunRow->id => [19, 1],
        $this->resadRow->id => [15, 2],
        $this->turalRow->id => [0, null],
    ])->and($state['round']['reveal']['spent'])->toBe([['player_id' => $this->aygunRow->id, 'count' => 1]]);

    // The room's standing after the game: the net, the target's points as they were.
    $this->travelTo(Rooms::at($game, RoomPhase::Over));

    expect(tauntsRanking(tauntsState($this->tural, $this->room)))->toBe([
        $this->aygunRow->id => [19, 1],
        $this->resadRow->id => [15, 2],
        $this->turalRow->id => [0, null],
    ])->and(RoomAnswer::query()->sum('points'))->toEqual(35);
});

it('counts a taunt from the first close after it, and every one once the game is over', function (): void {
    Config::set('elifba.rooms.taunts.cooldown_ms', 1_000);
    $game = Rooms::playing($this->room);
    tauntsPoints($game, 0, [[$this->aygunRow, 20]]);
    $timetable = $game->refresh()->load('rounds')->timetable();

    // In round 1's question: counted at round 1's close.
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    tauntsThrow($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'accepted');
    // In round 1's reveal, its own first moment included: counted at round 2's close.
    $this->travelTo(RoomClock::at($timetable->closes(1)));
    tauntsThrow($this->aygun, $this->room, $this->turalRow)->assertJsonPath('status', 'accepted');

    $this->travelTo(RoomClock::at($timetable->closes(1) - 1));
    expect(RoomRanking::ofGame($game, RoomClock::nowMs())[0]->points)->toBe(20);
    $this->travelTo(RoomClock::at($timetable->closes(1)));
    expect(RoomRanking::ofGame($game, RoomClock::nowMs())[0]->points)->toBe(19)
        ->and(tauntsState($this->aygun, $this->room)['round']['reveal']['spent'])->toBe([['player_id' => $this->aygunRow->id, 'count' => 1]]);
    $this->travelTo(RoomClock::at($timetable->closes(2) - 1));
    expect(RoomRanking::ofGame($game, RoomClock::nowMs())[0]->points)->toBe(19)
        ->and(tauntsState($this->aygun, $this->room)['taunts']['balance'])->toBe(18);
    $this->travelTo(RoomClock::at($timetable->closes(2)));
    expect(RoomRanking::ofGame($game, RoomClock::nowMs())[0]->points)->toBe(18)
        ->and(tauntsState($this->aygun, $this->room)['round']['reveal']['spent'])->toBe([['player_id' => $this->aygunRow->id, 'count' => 1]]);

    // A taunt in the question the host's end cuts off never meets its close: it counts once the game is over.
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 3));
    tauntsThrow($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'accepted');
    expect(RoomRanking::ofGame($game, RoomClock::nowMs())[0]->points)->toBe(18);
    $this->actingAs($this->resad)->deleteJson(route('rooms.game.destroy', $this->room))->assertNoContent();
    $over = tauntsState($this->aygun, $this->room);

    expect($over['game']['phase'])->toBe('over')
        ->and($over['taunts'])->toBeNull()
        ->and(tauntsRanking($over)[$this->aygunRow->id])->toBe([17, 1])
        ->and(RoomRanking::ofGame($game->refresh(), RoomClock::nowMs())[0]->points)->toBe(17);

    // The next game spends its own points only: the room's standing is not a balance.
    $next = Rooms::playing($this->room);
    $this->travelTo(Rooms::at($next, RoomPhase::Answer, 0));
    expect(tauntsState($this->aygun, $this->room)['taunts']['balance'])->toBe(0);
    tauntsThrow($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'no_points');
});

it('refuses a throw with no points, and two at once never spend one point twice', function (): void {
    Config::set('elifba.rooms.taunts.cooldown_ms', 0);
    $game = Rooms::playing($this->room);

    // Before the first close every balance is 0.
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 0));
    tauntsThrow($this->aygun, $this->room, $this->resadRow)->assertExactJson(['status' => 'no_points', 'revision' => $this->room->refresh()->revision]);

    // One point left: one throw takes it, the next finds none.
    tauntsPoints($game, 0, [[$this->aygunRow, 1]]);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    tauntsThrow($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'accepted');
    tauntsThrow($this->aygun, $this->room, $this->turalRow)->assertJsonPath('status', 'no_points');

    expect(RoomTaunt::query()->count())->toBe(1)
        ->and(tauntsState($this->aygun, $this->room)['taunts']['balance'])->toBe(0);

    // At 0 after the close: no place, and never below 0.
    $this->travelTo(Rooms::at($game, RoomPhase::Reveal, 1));
    expect(tauntsRanking(tauntsState($this->aygun, $this->room))[$this->aygunRow->id])->toBe([0, null]);
    $this->travelTo(Rooms::at($game, RoomPhase::Over));
    expect(tauntsRanking(tauntsState($this->aygun, $this->room))[$this->aygunRow->id])->toBe([0, null]);
});

it('stores a taunt once by its event_id', function (): void {
    Config::set('elifba.rooms.taunts.cooldown_ms', 0);
    $game = Rooms::playing($this->room);
    tauntsPoints($game, 0, [[$this->aygunRow, 20]]);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    $eventId = Str::lower((string) Str::ulid());

    tauntsThrow($this->aygun, $this->room, $this->resadRow, $eventId)->assertJsonPath('status', 'accepted');
    $revision = $this->room->refresh()->revision;
    tauntsThrow($this->aygun, $this->room, $this->resadRow, Str::upper($eventId))->assertExactJson(['status' => 'duplicate', 'revision' => $revision]);
    tauntsThrow($this->aygun, $this->room, $this->turalRow, $eventId, 'dice')->assertJsonPath('status', 'duplicate');

    expect(RoomTaunt::query()->count())->toBe(1)
        ->and(tauntsState($this->aygun, $this->room)['taunts']['balance'])->toBe(19)
        ->and($this->room->refresh()->revision)->toBe($revision);
});

it('refuses in the lobby, the first round\'s countdown, the last reveal, the final, a closed room and while switched off', function (): void {
    // The lobby: no game.
    tauntsThrow($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'late');
    expect(tauntsState($this->aygun, $this->room)['taunts'])->toBeNull();

    // The countdown: the window is open, but nobody has a point yet.
    $game = Rooms::playing($this->room, rounds: 5);
    $this->travelTo(Rooms::at($game, RoomPhase::Countdown));
    expect(tauntsState($this->aygun, $this->room)['taunts'])->toMatchArray(['open' => true, 'balance' => 0]);
    tauntsThrow($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'no_points');

    // The last reveal and the final: closed for taunts, whatever the balance.
    foreach (range(0, 4) as $round) {
        tauntsPoints($game, $round, [[$this->aygunRow, 20]]);
    }

    $this->travelTo(RoomClock::at($game->refresh()->load('rounds')->timetable()->closes(4) - 1));
    expect(tauntsState($this->aygun, $this->room)['taunts']['open'])->toBeTrue();
    $this->travelTo(Rooms::at($game, RoomPhase::Reveal, 4));
    expect(tauntsState($this->aygun, $this->room)['taunts'])->toMatchArray(['open' => false, 'balance' => 100]);
    tauntsThrow($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'late');
    $this->travelTo(Rooms::at($game, RoomPhase::Over));
    tauntsThrow($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'late');

    // Switched off: refused and not open; on again, it throws.
    $next = Rooms::playing($this->room);
    tauntsPoints($next, 0, [[$this->aygunRow, 20]]);
    $this->travelTo(Rooms::at($next, RoomPhase::Answer, 1));
    $revision = $this->room->refresh()->revision;
    $this->actingAs($this->resad)->putJson(route('rooms.taunts.update', $this->room), ['on' => false])->assertNoContent();
    expect($this->room->refresh()->revision)->toBe($revision + 1)
        ->and(tauntsState($this->aygun, $this->room)['room']['taunts'])->toBeFalse()
        ->and(tauntsState($this->aygun, $this->room)['taunts']['open'])->toBeFalse();
    tauntsThrow($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'off');
    $this->actingAs($this->resad)->putJson(route('rooms.taunts.update', $this->room), ['on' => false])->assertNoContent();
    expect($this->room->refresh()->revision)->toBe($revision + 1);
    $this->actingAs($this->aygun)->putJson(route('rooms.taunts.update', $this->room), ['on' => true])->assertForbidden();
    $this->actingAs($this->resad)->putJson(route('rooms.taunts.update', $this->room), ['on' => 'maybe'])->assertJsonValidationErrors('on');
    $this->actingAs($this->resad)->putJson(route('rooms.taunts.update', $this->room), ['on' => true])->assertNoContent();
    tauntsThrow($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'accepted');

    // A closed room: no player plays in it, and the host's switch is shut too.
    $this->actingAs($this->resad)->post(route('rooms.close', $this->room));
    tauntsThrow($this->aygun, $this->room, $this->resadRow)->assertForbidden();
    $this->actingAs($this->resad)->putJson(route('rooms.taunts.update', $this->room), ['on' => false])->assertForbidden();

    expect(RoomTaunt::query()->count())->toBe(1);
});

it('refuses oneself, a player who left, a removed player and a host who does not play as a target, and a host who does not play as a thrower', function (): void {
    $leila = User::factory()->player()->create(['name' => 'Leyla Həsənova']);
    $leilaRow = Rooms::join($this->room, $leila);
    $game = Rooms::playing($this->room);
    tauntsPoints($game, 0, [[$this->aygunRow, 20]]);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    $this->actingAs($this->tural)->post(route('rooms.leave', $this->room));
    $this->actingAs($this->resad)->delete(route('rooms.members.destroy', [$this->room, $leilaRow]));
    $elsewhere = RoomPlayer::factory()->create();

    foreach ([$this->aygunRow, $this->turalRow, $leilaRow, $elsewhere, (string) Str::ulid()] as $target) {
        tauntsThrow($this->aygun, $this->room, $target)->assertUnprocessable()->assertJsonValidationErrors('target_id');
    }

    tauntsThrow($this->aygun, $this->room, 'nobody')->assertJsonValidationErrors('target_id');
    tauntsThrow($this->aygun, $this->room, $this->resadRow, taunt: 'Bir az tez ol!')->assertJsonValidationErrors('taunt');
    tauntsThrow($this->aygun, $this->room, $this->resadRow, taunt: 'crown')->assertJsonValidationErrors('taunt');
    expect(RoomTaunt::query()->count())->toBe(0);

    // A host who does not play: neither a target nor a thrower.
    $room = Rooms::open($this->tural, plays: false);
    $aygunThere = Rooms::join($room, $this->aygun);
    Rooms::join($room, $this->resad);
    $there = Rooms::playing($room);
    tauntsPoints($there, 0, [[$aygunThere, 20]]);
    $this->travelTo(Rooms::at($there, RoomPhase::Answer, 1));

    tauntsThrow($this->aygun, $room, $room->players()->where('user_id', $this->tural->id)->sole())->assertJsonValidationErrors('target_id');
    tauntsThrow($this->tural, $room, $aygunThere)->assertForbidden();
    expect(tauntsState($this->tural, $room)['taunts'])->toBe(['open' => false, 'balance' => null, 'ready_ms' => null, 'received' => [], 'shown' => null])
        ->and(RoomTaunt::query()->count())->toBe(0);
});

it('takes a taunt at a present player who is not the thrower\'s strip rival, and shows it to that player and the screen only', function (): void {
    // A race of three: Rəşad leads, Aygün second, Tural third. On a phone the strip's rival is the leader, or the runner-up for the leader (ADR 027).
    $game = Rooms::playing($this->room);
    tauntsPoints($game, 0, [[$this->resadRow, 20], [$this->aygunRow, 10], [$this->turalRow, 5]]);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));

    expect(array_column(tauntsState($this->tural, $this->room)['ranking'], 'player_id'))->toBe([$this->resadRow->id, $this->aygunRow->id, $this->turalRow->id]);

    // Tural picks Aygün, not the leader on his strip; Rəşad picks Tural, not the runner-up on his (ADR 029 as amended 2026-11-21).
    $sentMs = RoomClock::nowMs();
    tauntsThrow($this->tural, $this->room, $this->aygunRow, taunt: 'dice')->assertJsonPath('status', 'accepted');
    tauntsThrow($this->resad, $this->room, $this->turalRow, taunt: 'mine')->assertJsonPath('status', 'accepted');
    $atAygun = RoomTaunt::query()->where('taunt', 'dice')->sole();
    $atTural = RoomTaunt::query()->where('taunt', 'mine')->sole();

    expect(tauntsState($this->aygun, $this->room)['taunts']['received'])->toBe([['id' => $atAygun->id, 'from' => $this->turalRow->id, 'taunt' => 'dice', 'sent_ms' => $sentMs]])
        ->and(tauntsState($this->tural, $this->room)['taunts']['received'])->toBe([['id' => $atTural->id, 'from' => $this->resadRow->id, 'taunt' => 'mine', 'sent_ms' => $sentMs]])
        ->and(tauntsState($this->resad, $this->room)['taunts']['received'])->toBe([])
        ->and(tauntsState($this->resad, $this->room, screen: true)['taunts']['shown'])->toEqualCanonicalizing([
            ['id' => $atAygun->id, 'from' => $this->turalRow->id, 'to' => $this->aygunRow->id, 'taunt' => 'dice', 'sent_ms' => $sentMs],
            ['id' => $atTural->id, 'from' => $this->resadRow->id, 'to' => $this->turalRow->id, 'taunt' => 'mine', 'sent_ms' => $sentMs],
        ]);
});

it('keeps the cooldown by the server\'s clock', function (): void {
    $game = Rooms::playing($this->room, difficulty: 2);
    tauntsPoints($game, 0, [[$this->aygunRow, 20]]);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    $sentMs = RoomClock::nowMs();
    tauntsThrow($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'accepted');

    $this->travelTo(RoomClock::at($sentMs + 9_999));
    expect(tauntsState($this->aygun, $this->room)['taunts']['ready_ms'])->toBe($sentMs + 10_000);
    tauntsThrow($this->aygun, $this->room, $this->turalRow)->assertJsonPath('status', 'cooling');
    // Another thrower has a cooldown of their own.
    tauntsPoints($game, 0, [[$this->turalRow, 12]]);
    tauntsThrow($this->tural, $this->room, $this->aygunRow)->assertJsonPath('status', 'accepted');

    $this->travelTo(RoomClock::at($sentMs + 10_000));
    expect(tauntsState($this->aygun, $this->room)['taunts']['ready_ms'])->toBeNull();
    tauntsThrow($this->aygun, $this->room, $this->turalRow)->assertJsonPath('status', 'accepted');

    // A device's clock is never asked: nothing of the body moves it.
    tauntsThrow($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'cooling');
    expect(RoomTaunt::query()->where('room_player_id', $this->aygunRow->id)->pluck('sent_ms')->all())->toBe([$sentMs, $sentMs + 10_000]);
});

it('never closes a round, counts as an answer or as a read', function (): void {
    $game = Rooms::playing($this->room);
    tauntsPoints($game, 0, [[$this->aygunRow, 20], [$this->resadRow, 10], [$this->turalRow, 12]]);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    // Rəşad's and Aygün's phones follow the game; Tural's is gone.
    RoomPhones::read($this->room, $this->resad, $this->aygun);
    $round = Rooms::round($game, 1);
    $this->actingAs($this->resad)->postJson(route('rooms.answers.store', $this->room), Rooms::answer($round, Rooms::right($round)))->assertJsonPath('status', 'accepted');
    $seen = RoomPlayer::query()->orderBy('id')->pluck('seen_ms', 'id')->all();
    $resadBefore = tauntsState($this->resad, $this->room);

    // Aygün, who has not answered, and Tural, whose phone is gone, both throw.
    tauntsThrow($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'accepted');
    tauntsThrow($this->tural, $this->room, $this->resadRow)->assertJsonPath('status', 'accepted');

    expect($round->refresh()->closed_ms)->toBeNull()
        ->and(RoomAnswer::query()->where('room_round_id', $round->id)->count())->toBe(1)
        ->and(RoomPlayer::query()->orderBy('id')->pluck('seen_ms', 'id')->all())->toBe($seen)
        ->and(RoomWaits::waitsFor($this->turalRow->refresh(), RoomWaits::since($game->refresh()->load('rounds'), 1), false))->toBeFalse();

    // The target's state counts the same answers and tells no verdict; the round waits for Aygün still.
    $resad = tauntsState($this->resad, $this->room);
    expect($resad['round']['phase'])->toBe('answer')
        ->and($resad['round']['answered_count'])->toBe($resadBefore['round']['answered_count'])
        ->and($resad['round']['present_count'])->toBe($resadBefore['round']['present_count'])
        ->and($resad['round']['reveal'])->toBeNull()
        ->and($resad['ranking'])->toBe($resadBefore['ranking'])
        ->and(json_encode($resad))->not->toMatch('/"(solution|correct|mine|results|picked|elapsed_ms|spent)":/');

    // The thrower's balance says nothing of the open round: a right answer leaves it as it was until the close.
    RoomPhones::read($this->room, $this->tural);
    $balance = tauntsState($this->aygun, $this->room)['taunts']['balance'];
    $this->actingAs($this->aygun)->postJson(route('rooms.answers.store', $this->room), Rooms::answer($round, Rooms::right($round)))->assertJsonPath('status', 'accepted');
    expect($round->refresh()->closed_ms)->toBeNull()
        ->and(tauntsState($this->aygun, $this->room)['taunts']['balance'])->toBe($balance);
    $this->travelTo(Rooms::at($game, RoomPhase::Reveal, 1));
    expect(tauntsState($this->aygun, $this->room)['taunts']['balance'])->toBeGreaterThan($balance);
});

it('shows a taunt only to its target and the screen, and only for show_ms', function (): void {
    $game = Rooms::playing($this->room);
    tauntsPoints($game, 0, [[$this->aygunRow, 20]]);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    $sentMs = RoomClock::nowMs();
    tauntsThrow($this->aygun, $this->room, $this->resadRow, taunt: 'sleepy')->assertJsonPath('status', 'accepted');
    $taunt = RoomTaunt::query()->sole();

    $this->travelTo(RoomClock::at($sentMs + 3_999));
    expect(tauntsState($this->resad, $this->room)['taunts']['received'])->toBe([['id' => $taunt->id, 'from' => $this->aygunRow->id, 'taunt' => 'sleepy', 'sent_ms' => $sentMs]])
        ->and(tauntsState($this->resad, $this->room)['taunts']['shown'])->toBeNull()
        ->and(tauntsState($this->aygun, $this->room)['taunts']['received'])->toBe([])
        ->and(tauntsState($this->tural, $this->room)['taunts']['received'])->toBe([])
        ->and(tauntsState($this->resad, $this->room, screen: true)['taunts']['shown'])->toBe([['id' => $taunt->id, 'from' => $this->aygunRow->id, 'to' => $this->resadRow->id, 'taunt' => 'sleepy', 'sent_ms' => $sentMs]]);

    // Only the host of an open room asks as the screen, and the screen's request records no read.
    $this->actingAs($this->aygun)->getJson(route('rooms.state', [$this->room, 'for' => 'screen']))->assertForbidden();
    $seen = $this->resadRow->refresh()->seen_ms;
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 2));
    tauntsState($this->resad, $this->room, screen: true);
    expect($this->resadRow->refresh()->seen_ms)->toBe($seen);

    // At sent_ms + show_ms it is gone from every state.
    $this->travelTo(RoomClock::at($sentMs + 4_000));
    expect(tauntsState($this->resad, $this->room)['taunts']['received'])->toBe([])
        ->and(tauntsState($this->resad, $this->room, screen: true)['taunts']['shown'])->toBe([]);

    // Three at most, newest first; while switched off, none.
    Config::set('elifba.rooms.taunts.cooldown_ms', 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 2));
    foreach (['turtle', 'dice', 'mine', 'lucky'] as $key) {
        tauntsThrow($this->aygun, $this->room, $this->resadRow, taunt: $key)->assertJsonPath('status', 'accepted');
        $this->travelTo(RoomClock::at(RoomClock::nowMs() + 10));
    }

    expect(array_column(tauntsState($this->resad, $this->room)['taunts']['received'], 'taunt'))->toBe(['lucky', 'mine', 'dice'])
        ->and(array_column(tauntsState($this->resad, $this->room, screen: true)['taunts']['shown'], 'taunt'))->toBe(['lucky', 'mine', 'dice']);
    $this->actingAs($this->resad)->putJson(route('rooms.taunts.update', $this->room), ['on' => false])->assertNoContent();
    expect(tauntsState($this->resad, $this->room)['taunts']['received'])->toBe([])
        ->and(tauntsState($this->resad, $this->room, screen: true)['taunts']['shown'])->toBe([]);
});

it('shows who taunted whom nowhere after the game', function (): void {
    $game = Rooms::playing($this->room);
    tauntsPoints($game, 0, [[$this->aygunRow, 20]]);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    tauntsThrow($this->aygun, $this->room, $this->resadRow, taunt: 'catch_me')->assertJsonPath('status', 'accepted');
    $taunt = RoomTaunt::query()->sole();
    $this->travelTo(Rooms::at($game, RoomPhase::Over));

    $over = tauntsState($this->resad, $this->room);
    expect($over['taunts'])->toBeNull()
        ->and(tauntsRanking($over)[$this->aygunRow->id])->toBe([19, 1]);

    $this->actingAs($this->resad)->post(route('rooms.close', $this->room));
    // A page's props without the interface's translations, which hold every phrase of the list.
    $props = function (User $account, string $url): string {
        $props = $this->actingAs($account)->get($url)->assertOk()->viewData('page')['props'];
        unset($props['translations']);

        return (string) json_encode($props);
    };
    $pages = [
        json_encode($over),
        $props($this->resad, route('rooms.show', $this->room)),
        $props($this->aygun, route('rooms.show', $this->room)),
        $props($this->aygun, route('rooms.past')),
    ];

    foreach ($pages as $wire) {
        expect($wire)->not->toContain($taunt->id)
            ->and($wire)->not->toContain('catch_me')
            ->and($wire)->not->toMatch('/"(received|shown|spent|balance|ready_ms)"/');
    }
});

it('keeps the cost when a player takes their name out or is removed', function (): void {
    $game = Rooms::playing($this->room);
    tauntsPoints($game, 0, [[$this->aygunRow, 20], [$this->turalRow, 19], [$this->resadRow, 10]]);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    tauntsThrow($this->aygun, $this->room, $this->turalRow)->assertJsonPath('status', 'accepted');
    tauntsThrow($this->tural, $this->room, $this->aygunRow)->assertJsonPath('status', 'accepted');
    tauntsThrow($this->resad, $this->room, $this->turalRow)->assertJsonPath('status', 'accepted');

    // The host removes Tural mid-game: his taunt and the one aimed at him stay, and nobody else's points move.
    $this->actingAs($this->resad)->delete(route('rooms.members.destroy', [$this->room, $this->turalRow]));
    $this->travelTo(Rooms::at($game, RoomPhase::Over));
    $this->actingAs($this->resad)->post(route('rooms.close', $this->room));

    expect(RoomTaunt::query()->count())->toBe(3)
        ->and(RoomTaunt::query()->where('room_player_id', $this->aygunRow->id)->value('target_player_id'))->toBe($this->turalRow->id)
        ->and(array_map(fn ($row): array => [$row->player_id, $row->points, $row->place], RoomRanking::ofRoom($this->room->refresh(), RoomClock::nowMs())))->toBe([
            [$this->aygunRow->id, 19, 1],
            [$this->resadRow->id, 9, 2],
        ]);

    // Aygün takes her name out: «Oyunçu» keeps the cost.
    app(ForgetRoomPlayer::class)->handle($this->room, $this->aygun);

    expect(RoomTaunt::query()->count())->toBe(3)
        ->and(array_map(fn ($row): array => [$row->player_id, $row->points, $row->place], RoomRanking::ofRoom($this->room->refresh(), RoomClock::nowMs())))->toBe([
            [$this->aygunRow->id, 19, 1],
            [$this->resadRow->id, 9, 2],
        ]);
});

it('forgets the target after a day, and goes with the room', function (): void {
    $game = Rooms::playing($this->room);
    tauntsPoints($game, 0, [[$this->aygunRow, 20]]);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    $sentMs = RoomClock::nowMs();
    tauntsThrow($this->aygun, $this->room, $this->resadRow)->assertJsonPath('status', 'accepted');

    $this->travelTo(RoomClock::at($sentMs + 24 * 3_600_000));
    app(PruneOldRooms::class)->handle();
    expect(RoomTaunt::query()->sole()->target_player_id)->toBe($this->resadRow->id);

    $this->travelTo(RoomClock::at($sentMs + 24 * 3_600_000 + 1));
    app(PruneOldRooms::class)->handle();
    $kept = RoomTaunt::query()->sole();

    expect($kept->target_player_id)->toBeNull()
        ->and($kept->room_player_id)->toBe($this->aygunRow->id)
        ->and($kept->sent_ms)->toBe($sentMs)
        ->and(RoomRanking::ofRoom($this->room->refresh(), RoomClock::nowMs())[0]->points)->toBe(19);

    // Deleting the closed room takes its taunts; so does the retention period.
    app(DeleteRoom::class)->handle($this->room);
    expect(RoomTaunt::query()->count())->toBe(0);

    $other = Rooms::open($this->tural);
    $otherRow = Rooms::join($other, $this->aygun);
    $there = Rooms::playing($other);
    RoomTaunt::factory()->for($there, 'game')->by($otherRow)->at($other->rowOf($this->tural))->create();
    $this->travelTo(now()->addDays(Config::integer('elifba.retention_days') + 1));
    app(PruneOldRooms::class)->handle();

    expect(RoomTaunt::query()->count())->toBe(0)
        ->and(DB::table('room_taunts')->count())->toBe(0);
});

it('holds exactly the approved list', function (): void {
    $taunts = app(ContentRepository::class)->roomTaunts();

    // ADR 029 decision 49: the twelve, in the fan's order, each with its emoji and its tag.
    expect(array_map(static fn (RoomTauntData $taunt): array => [$taunt->key, $taunt->image, $taunt->voice], array_values($taunts)))->toBe([
        ['turtle', 'fluent/1F422.svg', 'mischievously'],
        ['tick_tock', 'fluent/23F0.svg', 'mischievously'],
        ['frozen', 'fluent/1F976.svg', 'mischievously'],
        ['sleepy', 'fluent/1F634.svg', 'mischievously'],
        ['lucky', 'fluent/1F340.svg', 'sarcastic'],
        ['dice', 'fluent/1F3B2.svg', 'mischievously'],
        ['mine', 'fluent/1F60E.svg', 'mischievously'],
        ['catch_me', 'fluent/1F680.svg', 'mischievously'],
        ['too_easy', 'fluent/1F971.svg', 'dismissive'],
        ['so_close', 'fluent/1F605.svg', 'mischievously'],
        ['silly', 'fluent/1F92A.svg', 'mischievously'],
        ['what_now', 'fluent/1F602.svg', 'mischievously'],
    ])->and(app(ContentRepository::class)->roomTauntVoice()->context)->toBe('Oyun gedir, raund başladı, hamı cavab axtarır.');
});

it('names a phrase for every taunt in both languages and no other', function (): void {
    $keys = array_keys(app(ContentRepository::class)->roomTaunts());

    foreach (['az', 'en'] as $locale) {
        $lines = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, 64, JSON_THROW_ON_ERROR);
        $phrases = array_filter($lines, static fn (string $key): bool => str_starts_with($key, 'rooms.taunts.say.'), ARRAY_FILTER_USE_KEY);

        expect(array_map(static fn (string $key): string => substr($key, strlen('rooms.taunts.say.')), array_keys($phrases)))->toEqualCanonicalizing($keys, $locale);

        foreach ($phrases as $key => $phrase) {
            expect($phrase)->toBeString()->not->toBe('', "{$locale} {$key}");
        }
    }

    // The approved wording, pinned (ADR 029 decision 49).
    $az = array_map(static fn (string $key): string => __("rooms.taunts.say.{$key}", [], 'az'), $keys);
    $en = array_map(static fn (string $key): string => __("rooms.taunts.say.{$key}", [], 'en'), $keys);

    expect(array_combine($keys, $az))->toBe([
        'turtle' => 'Tısbağa da səndən tezdir!',
        'tick_tock' => 'Tik-tak, vaxt gedir ha!',
        'frozen' => 'Dondun qaldın, nə oldu?',
        'sleepy' => 'Oyan, oyan! Oyun gedir!',
        'lucky' => 'Vay, bəxtin necə gətirdi!',
        'dice' => 'Zər atırsan, nədir?',
        'mine' => 'Bu raund mənimdir, ha-ha!',
        'catch_me' => 'Yetiş görüm, yetişə bilsən!',
        'too_easy' => 'Pəh! Nə asan idi!',
        'so_close' => 'Vay-vay, az qaldı! Heyif!',
        'silly' => 'Ay lölüy!',
        'what_now' => 'Noldu, Paşinyan?!',
    ])->and(array_combine($keys, $en))->toBe([
        'turtle' => 'Even a turtle beats you!',
        'tick_tock' => "Tick-tock, time's running!",
        'frozen' => 'Frozen solid, huh?',
        'sleepy' => "Wakey wakey, game's on!",
        'lucky' => 'Wow, lucky much?',
        'dice' => 'Rolling dice, are we?',
        'mine' => "This round's mine, ha-ha!",
        'catch_me' => 'Catch me if you can!',
        'too_easy' => 'Pfft! Too easy!',
        'so_close' => 'Ooh, so close! Too bad!',
        'silly' => 'Oh, you silly!',
        'what_now' => 'What happened, Pashinyan?!',
    ])->and(__('rooms.taunts.at', ['name' => 'Aygün'], 'az'))->toBe('Atmaca at: Aygün')
        ->and(__('rooms.taunts.at', ['name' => 'Aygün'], 'en'))->toBe('Throw a taunt at Aygün');
});

it('gives every approved taunt its clip in the board\'s voice', function (): void {
    $content = app(ContentRepository::class);
    $voices = app(TauntVoices::class);
    $listed = json_decode((string) file_get_contents(base_path('content/voice-clips.json')), true, 4, JSON_THROW_ON_ERROR)['taunts'] ?? [];
    $texts = [];

    foreach ($content->roomTaunts() as $key => $taunt) {
        $line = $voices->line($taunt);
        // Nigar says the Azerbaijani phrase after the taunt's own tag, in every locale.
        $text = "[{$taunt->voice}] ".__("rooms.taunts.say.{$key}", [], 'az');

        expect($line?->text)->toBe($text, $key)
            ->and($listed[$line?->hash] ?? null)->toBe($text, $key)
            ->and(base_path('content/assets/'.TauntVoices::path((string) $line?->hash)))->toBeFile()
            ->and($voices->url($taunt))->toBe(asset('content/rooms/taunts/'.$line?->hash.'.mp3').'?v='.$taunt->version);
        $texts[] = $text;
    }

    // Exactly one clip per taunt; and the credits name the folder as our own, made with ElevenLabs.
    $credit = array_values(array_filter($content->credits(), static fn ($credit): bool => $credit->files === ['content/assets/rooms/taunts/*']));

    expect(array_values($listed))->toEqualCanonicalizing($texts)
        ->and(glob(base_path('content/assets/rooms/taunts/*.mp3')) ?: [])->toHaveCount(12)
        ->and($credit)->toHaveCount(1)
        ->and($credit[0]->license->value)->toBe('MIT')
        ->and($credit[0]->changes)->toContain('eleven_v4')->toContain('Nigar')->not->toContain('{month}');
});

it('shows a taunt on a page for as long as the server lists it, and its reaction for less', function (): void {
    $source = (string) file_get_contents(resource_path('js/components/rooms/taunts.ts'));
    preg_match('/export const TAUNT_SHOW_MS = ([\d_]+);/', $source, $show);
    preg_match('/export const TAUNT_REACTION_MS = ([\d_]+);/', $source, $reaction);
    $showMs = Config::integer('elifba.rooms.taunts.show_ms');

    expect((int) str_replace('_', '', $show[1] ?? ''))->toBe($showMs)
        // ADR 029 decision 41: drawn 3 s; the state's second more covers a late read.
        ->and((int) str_replace('_', '', $reaction[1] ?? ''))->toBe(3_000)->toBeLessThan($showMs);
});
