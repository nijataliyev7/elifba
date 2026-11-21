<?php

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Language\Support\Az;
use App\Domain\Rooms\Actions\CloseRoom;
use App\Domain\Rooms\Actions\DeleteRoom;
use App\Domain\Rooms\Actions\ForgetRoomPlayer;
use App\Domain\Rooms\Actions\LeaveRoom;
use App\Domain\Rooms\Actions\PruneOldRooms;
use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAnswer;
use App\Domain\Rooms\Models\RoomLetter;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLetters;
use App\Domain\Rooms\Support\RoomRanking;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\RoomPhones;
use Tests\Support\Rooms;

/*
 * The bought letter (docs/architecture.md §12 "«Anaqram»: a clue, a
 * shuffle and a bought letter (ADR 037)", ADR 037 decisions 10–20): a
 * present player who has not answered buys the drawn word's next letter
 * in the answer phase of a round whose question has orders. Only
 * `BuyRoomLetter` writes it, under the room row's lock and then the
 * round's, once per `event_id` and once per place; the place in the body
 * is why a double tap buys one. The first letter leaves the answer half
 * its points, the second and every one after nothing. The tiles reach the
 * buyer's own state alone — never another phone, the screen, the channel
 * or a log — and a purchase never closes a round, counts as an answer or
 * as a read, or raises the revision.
 */

beforeEach(function (): void {
    Rooms::on();
    $this->travelTo('2026-11-24 10:00:00');
    $this->resad = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->aygun = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
    $this->tural = User::factory()->player()->create(['name' => 'Tural Əliyev']);
    $this->room = Rooms::open($this->resad, mechanic: RoomMechanic::Anagram);
    $this->resadRow = $this->room->rowOf($this->resad);
    $this->aygunRow = Rooms::join($this->room, $this->aygun);
    $this->turalRow = Rooms::join($this->room, $this->tural);
    $this->game = Rooms::playing($this->room, mechanic: RoomMechanic::Anagram);
});

/**
 * `$account` buys the letter at `$position` of round `$round`'s word through the endpoint.
 *
 * @return TestResponse<Response>
 */
function lettersBuy(User $account, Room $room, RoomRound $round, int $position, ?string $eventId = null): TestResponse
{
    return test()->actingAs($account)->postJson(route('rooms.letters.store', $room), [
        'event_id' => $eventId ?? Str::lower((string) Str::ulid()),
        'round_id' => $round->id,
        'position' => $position,
    ]);
}

/**
 * The state `$account`'s room page (or the screen) reads, checked against the DTOs.
 *
 * @return array<string, mixed>
 */
function lettersState(User $account, Room $room, bool $screen = false): array
{
    $state = test()->actingAs($account)
        ->getJson(route('rooms.state', $screen ? [$room, 'for' => 'screen'] : $room))
        ->assertOk()
        ->json();

    expect(RoomStateData::fromArray($state)->toArray())->toBe($state);

    return $state;
}

/**
 * What a list of tile ids reads, a letter each, by the round's question.
 *
 * @param  list<string>  $ids
 */
function lettersRead(RoomRound $round, array $ids): string
{
    $says = [];

    foreach ($round->askData()->items as $item) {
        $says[$item->id] = $item->says();
    }

    return implode('', array_map(static fn (string $id): string => $says[$id], $ids));
}

it('sells the drawn word\'s next letter to the buyer alone, in the word\'s order', function (): void {
    $round = Rooms::round($this->game, 0);
    $word = Rooms::right($round);
    $this->travelTo(Rooms::at($this->game, RoomPhase::Answer, 0));

    // Before a purchase: none bought, the question's orders serve, the first letter leaves half.
    expect(RoomLetters::sells($round))->toBeTrue()
        ->and(lettersState($this->aygun, $this->room)['round']['letters'])->toBe(['bought' => [], 'orders' => [], 'price' => 'half']);

    lettersBuy($this->aygun, $this->room, $round, 0)->assertOk()->assertExactJson(['status' => 'accepted']);
    $one = lettersState($this->aygun, $this->room)['round']['letters'];

    expect($one['bought'])->toBe([$word[0]])
        ->and($one['price'])->toBe('rest')
        ->and($one['orders'])->not->toBeEmpty()
        ->and(count($one['orders']))->toBeLessThanOrEqual(6);

    // The buyer's own orders: the tiles left, each once, none reading the rest of a word that starts with the letter bought.
    $rests = [];

    foreach ($round->solutionData()->accept as $spelling) {
        if ($spelling[0] === lettersRead($round, [$word[0]])) {
            $rests[] = implode('', array_slice($spelling, 1));
        }
    }

    foreach ($one['orders'] as $order) {
        expect($order)->toEqualCanonicalizing(array_slice($word, 1))
            ->and($rests)->not->toContain(lettersRead($round, $order));
    }

    // The same at every read, and as `RoomLetters` derives them: never stored.
    expect(lettersState($this->aygun, $this->room)['round']['letters'])->toBe($one)
        ->and($one)->toBe(RoomLetters::of($this->game->refresh(), $round, 1)->toArray());

    lettersBuy($this->aygun, $this->room, $round, 1)->assertExactJson(['status' => 'accepted']);
    $two = lettersState($this->aygun, $this->room)['round']['letters'];

    expect($two['bought'])->toBe(array_slice($word, 0, 2))
        ->and($two['price'])->toBe('none')
        ->and(RoomLetter::query()->orderBy('position')->pluck('position')->all())->toBe([0, 1])
        ->and(RoomLetter::query()->pluck('room_player_id')->unique()->all())->toBe([$this->aygunRow->id]);
});

it('prices the answer by the letters bought: all, half, then nothing', function (int $letters, int $points): void {
    $round = Rooms::round($this->game, 0);
    $this->travelTo(Rooms::at($this->game, RoomPhase::Answer, 0));

    for ($position = 0; $position < $letters; $position++) {
        lettersBuy($this->aygun, $this->room, $round, $position)->assertJsonPath('status', 'accepted');
    }

    // At once: 20 points before the letters.
    $this->actingAs($this->aygun)->postJson(route('rooms.answers.store', $this->room), Rooms::answer($round, Rooms::right($round)))->assertJsonPath('status', 'accepted');

    expect(RoomAnswer::query()->sole()->points)->toBe($points)
        ->and(RoomAnswer::query()->sole()->correct)->toBeTrue();

    // The ranking and the reveal read the stored points; the reveal says how many letters on the buyer's phone alone.
    $this->travelTo(Rooms::at($this->game, RoomPhase::Reveal, 0));
    $state = lettersState($this->aygun, $this->room);

    expect($state['round']['reveal']['mine'])->toMatchArray(['correct' => true, 'points' => $points, 'letters' => $letters])
        ->and($state['round']['reveal']['results'])->toBe([['player_id' => $this->aygunRow->id, 'correct' => true, 'points' => $points]])
        ->and($state['round']['letters'])->toBeNull()
        ->and(collect(RoomRanking::ofGame($this->game->refresh(), RoomClock::nowMs()))->firstWhere('player_id', $this->aygunRow->id)?->points)->toBe($points);
})->with([
    'no letter' => [0, 20],
    'one letter' => [1, 10],
    'two letters' => [2, 0],
    'three letters' => [3, 0],
]);

it('says no price once every letter is bought, and the filled word earns nothing even when right', function (): void {
    $round = Rooms::round($this->game, 0);
    $word = Rooms::right($round);
    $this->travelTo(Rooms::at($this->game, RoomPhase::Answer, 0));

    foreach (array_keys($word) as $position) {
        lettersBuy($this->aygun, $this->room, $round, $position)->assertJsonPath('status', 'accepted');
    }

    expect(lettersState($this->aygun, $this->room)['round']['letters'])->toBe(['bought' => $word, 'orders' => [], 'price' => null]);

    // Nothing is left to buy.
    lettersBuy($this->aygun, $this->room, $round, count($word))->assertUnprocessable()->assertJsonValidationErrors(['position' => __('rooms.errors.letter')]);

    $this->actingAs($this->aygun)->postJson(route('rooms.answers.store', $this->room), Rooms::answer($round, $word))->assertJsonPath('status', 'accepted');

    expect(RoomAnswer::query()->sole())->correct->toBeTrue()->points->toBe(0);
});

it('stores a purchase once by its event_id, and a double tap buys one letter', function (): void {
    $round = Rooms::round($this->game, 0);
    $this->travelTo(Rooms::at($this->game, RoomPhase::Answer, 0));
    $eventId = Str::lower((string) Str::ulid());

    lettersBuy($this->aygun, $this->room, $round, 0, $eventId)->assertExactJson(['status' => 'accepted']);
    lettersBuy($this->aygun, $this->room, $round, 0, $eventId)->assertExactJson(['status' => 'duplicate']);
    // The same id in its uppercase spelling is the same event.
    lettersBuy($this->aygun, $this->room, $round, 1, Str::upper($eventId))->assertExactJson(['status' => 'duplicate']);

    // A second tap — or a second device — names the place it saw as next: already bought, nothing written.
    lettersBuy($this->aygun, $this->room, $round, 0)->assertExactJson(['status' => 'bought']);

    expect(RoomLetter::query()->count())->toBe(1);

    // A place ahead of the next is refused, as is one past the word.
    lettersBuy($this->aygun, $this->room, $round, 2)->assertUnprocessable()->assertJsonValidationErrors('position');
    lettersBuy($this->aygun, $this->room, $round, count(Rooms::right($round)))->assertUnprocessable()->assertJsonValidationErrors('position');
    lettersBuy($this->aygun, $this->room, $round, -1)->assertUnprocessable()->assertJsonValidationErrors('position');
    $this->actingAs($this->aygun)->postJson(route('rooms.letters.store', $this->room), ['event_id' => 'x', 'round_id' => $round->id, 'position' => 'A'])
        ->assertUnprocessable()->assertJsonValidationErrors(['event_id', 'position']);

    expect(RoomLetter::query()->count())->toBe(1)
        ->and(lettersState($this->aygun, $this->room)['round']['letters']['price'])->toBe('rest');

    // Each player buys their own: Tural's first letter is his, at its own price.
    lettersBuy($this->tural, $this->room, $round, 0)->assertExactJson(['status' => 'accepted']);
    expect(lettersState($this->tural, $this->room)['round']['letters']['price'])->toBe('rest')
        ->and(RoomLetter::query()->count())->toBe(2);
});

it('sells only while the round takes answers: never in the countdown, the reveal, after the bell or the early close, or in another round', function (): void {
    $first = Rooms::round($this->game, 0);
    $second = Rooms::round($this->game, 1);

    // The countdown before round 0 opens.
    $this->travelTo(Rooms::at($this->game, RoomPhase::Countdown));
    lettersBuy($this->aygun, $this->room, $first, 0)->assertExactJson(['status' => 'late']);

    // A round that is not open now.
    $this->travelTo(Rooms::at($this->game, RoomPhase::Answer, 0));
    lettersBuy($this->aygun, $this->room, $second, 0)->assertExactJson(['status' => 'late']);

    // At the bell, the reveal begins.
    $this->travelTo(RoomClock::at($this->game->refresh()->load('rounds')->timetable()->bell(0)));
    lettersBuy($this->aygun, $this->room, $first, 0)->assertExactJson(['status' => 'late']);
    $this->travelTo(Rooms::at($this->game, RoomPhase::Reveal, 0));
    lettersBuy($this->aygun, $this->room, $first, 0)->assertExactJson(['status' => 'late']);

    // A round closed early: everyone the round waits for has answered.
    $this->travelTo(Rooms::at($this->game, RoomPhase::Answer, 1));
    RoomPhones::read($this->room, $this->resad, $this->aygun, $this->tural);

    foreach ([$this->resad, $this->aygun, $this->tural] as $account) {
        $this->actingAs($account)->postJson(route('rooms.answers.store', $this->room), Rooms::answer($second, Rooms::wrong($second)))->assertJsonPath('status', 'accepted');
    }

    expect($second->refresh()->closed_ms)->not->toBeNull();
    lettersBuy($this->aygun, $this->room, $second, 0)->assertExactJson(['status' => 'late']);

    // The game over, and a round of another room's game.
    $this->travelTo(Rooms::at($this->game, RoomPhase::Over));
    lettersBuy($this->aygun, $this->room, Rooms::round($this->game, 4), 0)->assertExactJson(['status' => 'late']);

    expect(RoomLetter::query()->count())->toBe(0);
});

it('refuses a player who answered, one who left, a host who does not play, an outsider and a closed room', function (): void {
    $round = Rooms::round($this->game, 0);
    $this->travelTo(Rooms::at($this->game, RoomPhase::Answer, 0));

    // A buy after the player's own answer is late: the answer was priced as it came.
    $this->actingAs($this->aygun)->postJson(route('rooms.answers.store', $this->room), Rooms::answer($round, Rooms::right($round)))->assertJsonPath('status', 'accepted');
    lettersBuy($this->aygun, $this->room, $round, 0)->assertExactJson(['status' => 'late']);
    expect(RoomAnswer::query()->sole()->points)->toBe(20);

    // A player who left is no present player of the room.
    app(LeaveRoom::class)->handle($this->room, $this->turalRow);
    lettersBuy($this->tural, $this->room, $round, 0)->assertForbidden();

    // An account that is not in the room is told nothing of it.
    lettersBuy(User::factory()->player()->create(), $this->room, $round, 0)->assertNotFound();

    // A host who does not play has no row.
    $host = User::factory()->player()->create();
    $watched = Rooms::open($host, plays: false, mechanic: RoomMechanic::Anagram);
    Rooms::join($watched, User::factory()->player()->create());
    $there = Rooms::playing($watched, mechanic: RoomMechanic::Anagram);
    $this->travelTo(Rooms::at($there, RoomPhase::Answer, 0));
    lettersBuy($host, $watched, Rooms::round($there, 0), 0)->assertForbidden();

    // A closed room.
    $this->travelTo(Rooms::at($this->game, RoomPhase::Answer, 1));
    app(CloseRoom::class)->handle($this->room);
    lettersBuy($this->resad, $this->room, Rooms::round($this->game, 1), 0)->assertForbidden();

    expect(RoomLetter::query()->count())->toBe(0);
});

it('sells no letter in a round whose question has no orders', function (): void {
    // Another mechanic's round…
    $other = Rooms::open(User::factory()->player()->create());
    $player = User::factory()->player()->create();
    Rooms::join($other, $player);
    $recall = Rooms::playing($other);
    $this->travelTo(Rooms::at($recall, RoomPhase::Answer, 0));
    lettersBuy($player, $other, Rooms::round($recall, 0), 0)->assertUnprocessable()->assertJsonValidationErrors(['position' => __('rooms.errors.letter')]);
    expect(lettersState($player, $other)['round']['letters'])->toBeNull();

    // …and an anagram round stored before ADR 037, whose question has none.
    $round = Rooms::round($this->game, 0);
    $round->payload = ['ask' => array_diff_key($round->payload['ask'], ['clue' => true, 'orders' => true])] + $round->payload;
    $round->save();
    $this->travelTo(Rooms::at($this->game, RoomPhase::Answer, 0));

    lettersBuy($this->aygun, $this->room, $round, 0)->assertUnprocessable()->assertJsonValidationErrors('position');
    expect(lettersState($this->aygun, $this->room)['round']['letters'])->toBeNull()
        ->and(RoomLetter::query()->count())->toBe(0);
});

it('shows the purchase to the buyer alone: not another phone, not the screen, not the channel, not a log', function (): void {
    $round = Rooms::round($this->game, 0);
    $this->travelTo(Rooms::at($this->game, RoomPhase::Answer, 0));
    // Every page reads once, so the round waits for them and the screen counts as shown.
    lettersState($this->resad, $this->room);
    lettersState($this->aygun, $this->room);
    lettersState($this->resad, $this->room, screen: true);
    $revision = $this->room->refresh()->revision;
    $others = [lettersState($this->aygun, $this->room), lettersState($this->resad, $this->room, screen: true)];
    $signals = count($this->reverb->sent);
    Log::spy();

    // Rəşad, the host who plays, buys two letters on his phone.
    lettersBuy($this->resad, $this->room, $round, 0)->assertExactJson(['status' => 'accepted']);
    lettersBuy($this->resad, $this->room, $round, 1)->assertExactJson(['status' => 'accepted']);

    expect(lettersState($this->resad, $this->room)['round']['letters']['bought'])->toBe(array_slice(Rooms::right($round), 0, 2));

    // Aygün's phone and the screen — read with Rəşad's account — read exactly what they read before.
    $after = [lettersState($this->aygun, $this->room), lettersState($this->resad, $this->room, screen: true)];

    foreach ($after as $index => $state) {
        expect(array_diff_key($state, ['server_ms' => true]))->toBe(array_diff_key($others[$index], ['server_ms' => true]));
    }

    expect($after[1]['round']['letters'])->toBeNull()
        ->and($after[0]['round']['letters'])->toBe(['bought' => [], 'orders' => [], 'price' => 'half']);

    // No revision, no signal: the channel carries only `room.changed {v, revision}`, and nothing for a purchase.
    expect($this->room->refresh()->revision)->toBe($revision)
        ->and(array_slice($this->reverb->sent, $signals))->toBe([]);

    $this->actingAs($this->resad)->postJson(route('rooms.answers.store', $this->room), Rooms::answer($round, Rooms::right($round)))->assertJsonPath('status', 'accepted');

    expect(array_slice($this->reverb->sent, $signals))->toBe([[
        'channels' => ['private-room.'.$this->room->id],
        'event' => 'room.changed',
        'data' => ['v' => 1, 'revision' => $revision + 1],
    ]]);

    // In the reveal, the buyer's phone says how many; the screen has no viewer's answer at all, and the others none of his.
    $this->travelTo(Rooms::at($this->game, RoomPhase::Reveal, 0));
    $resad = lettersState($this->resad, $this->room);
    $screen = lettersState($this->resad, $this->room, screen: true);
    $aygun = lettersState($this->aygun, $this->room);

    expect($resad['round']['reveal']['mine']['letters'])->toBe(2)
        ->and($screen['round']['reveal']['mine'])->toBeNull()
        ->and($aygun['round']['reveal']['mine'])->toBeNull()
        ->and(json_encode([$screen, $aygun]))->not->toContain('"letters":2')
        ->and($screen['round']['reveal']['results'])->toBe($resad['round']['reveal']['results']);

    Log::shouldNotHaveReceived('debug');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

it('never closes a round, counts as an answer or as a read', function (): void {
    $round = Rooms::round($this->game, 0);
    $this->travelTo(Rooms::at($this->game, RoomPhase::Answer, 0));
    // Rəşad's and Tural's phones follow the game; Aygün's has not read since the countdown.
    RoomPhones::read($this->room, $this->resad, $this->tural);
    $this->actingAs($this->resad)->postJson(route('rooms.answers.store', $this->room), Rooms::answer($round, Rooms::right($round)))->assertJsonPath('status', 'accepted');
    $seen = RoomPlayer::query()->orderBy('id')->pluck('seen_ms', 'id')->all();
    $timetable = $this->game->refresh()->load('rounds')->timetable();
    $before = lettersState($this->resad, $this->room)['round'];

    // Tural, whom the round waits for, buys every letter but one; Aygün, whose phone is gone, buys one.
    foreach (range(0, count(Rooms::right($round)) - 2) as $position) {
        lettersBuy($this->tural, $this->room, $round, $position)->assertJsonPath('status', 'accepted');
    }

    lettersBuy($this->aygun, $this->room, $round, 0)->assertJsonPath('status', 'accepted');

    $after = lettersState($this->resad, $this->room)['round'];

    expect($round->refresh()->closed_ms)->toBeNull()
        ->and(RoomAnswer::query()->count())->toBe(1)
        ->and(RoomPlayer::query()->orderBy('id')->pluck('seen_ms', 'id')->all())->toBe($seen)
        ->and($this->game->refresh()->load('rounds')->timetable()->bell(0))->toBe($timetable->bell(0))
        ->and([$after['answered_count'], $after['present_count']])->toBe([$before['answered_count'], $before['present_count']])
        ->and($after['phase'])->toBe('answer');

    // The round still waits for Tural's answer, and closes early once it comes.
    $this->actingAs($this->tural)->postJson(route('rooms.answers.store', $this->room), Rooms::answer($round, Rooms::right($round)))->assertJsonPath('status', 'accepted');
    expect($round->refresh()->closed_ms)->not->toBeNull()
        ->and(RoomAnswer::query()->where('room_player_id', $this->turalRow->id)->sole()->points)->toBe(0);
});

it('keeps the letters with the round and the buyer\'s row, and lets them go with the room', function (): void {
    $round = Rooms::round($this->game, 0);
    $this->travelTo(Rooms::at($this->game, RoomPhase::Answer, 0));
    lettersBuy($this->aygun, $this->room, $round, 0)->assertJsonPath('status', 'accepted');
    lettersBuy($this->tural, $this->room, $round, 0)->assertJsonPath('status', 'accepted');
    $this->actingAs($this->aygun)->postJson(route('rooms.answers.store', $this->room), Rooms::answer($round, Rooms::right($round)))->assertJsonPath('status', 'accepted');

    // A player who takes their name out keeps the row and its points, so the letters stay with it — and no longer with the account.
    $this->travelTo(Rooms::at($this->game, RoomPhase::Over));
    app(CloseRoom::class)->handle($this->room);
    app(ForgetRoomPlayer::class)->handle($this->room, $this->aygun);

    expect($this->aygunRow->refresh()->user_id)->toBeNull()
        ->and(RoomLetter::query()->where('room_player_id', $this->aygunRow->id)->count())->toBe(1)
        ->and(RoomAnswer::query()->where('room_player_id', $this->aygunRow->id)->sole()->points)->toBe(10);

    // A row or a round deleted takes its letters (the foreign keys cascade).
    DB::table('room_players')->where('id', $this->turalRow->id)->delete();
    expect(RoomLetter::query()->pluck('room_player_id')->all())->toBe([$this->aygunRow->id]);

    DB::table('room_rounds')->where('id', $round->id)->delete();
    expect(RoomLetter::query()->count())->toBe(0);

    // Deleting a closed room, and the retention period, take every letter of its games.
    $other = Rooms::open($this->tural, mechanic: RoomMechanic::Anagram);
    Rooms::join($other, $this->aygun);
    $there = Rooms::playing($other, mechanic: RoomMechanic::Anagram);
    $this->travelTo(Rooms::at($there, RoomPhase::Answer, 0));
    lettersBuy($this->aygun, $other, Rooms::round($there, 0), 0)->assertJsonPath('status', 'accepted');
    $this->travelTo(Rooms::at($there, RoomPhase::Over));
    app(CloseRoom::class)->handle($other);
    app(DeleteRoom::class)->handle($other);
    expect(RoomLetter::query()->count())->toBe(0);

    $kept = Rooms::open($this->tural, mechanic: RoomMechanic::Anagram);
    Rooms::join($kept, $this->aygun);
    $old = Rooms::playing($kept, mechanic: RoomMechanic::Anagram);
    $this->travelTo(Rooms::at($old, RoomPhase::Answer, 0));
    lettersBuy($this->aygun, $kept, Rooms::round($old, 0), 0)->assertJsonPath('status', 'accepted');
    $this->travelTo(now()->addDays(Config::integer('elifba.retention_days') + 1));
    app(PruneOldRooms::class)->handle();

    expect(DB::table('room_letters')->count())->toBe(0);
});

it('keeps nothing of a letter but its place: never a letter, a text or a name', function (): void {
    $round = Rooms::round($this->game, 0);
    $this->travelTo(Rooms::at($this->game, RoomPhase::Answer, 0));
    lettersBuy($this->aygun, $this->room, $round, 0)->assertJsonPath('status', 'accepted');

    $row = (array) DB::table('room_letters')->sole();
    $letter = lettersRead($round, [Rooms::right($round)[0]]);

    expect(array_keys($row))->toBe(['id', 'event_id', 'room_round_id', 'room_player_id', 'position', 'bought_ms'])
        ->and($row['position'])->toBe(0)
        ->and((int) $row['bought_ms'])->toBe(RoomClock::nowMs())
        ->and(json_encode($row))->not->toContain('Aygün')
        ->and(in_array($letter, $row, true) || in_array(Az::lower($letter), $row, true))->toBeFalse();
});
