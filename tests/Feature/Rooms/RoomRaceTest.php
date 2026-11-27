<?php

use App\Domain\Content\Data\RangeData;
use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Az;
use App\Domain\Rooms\Actions\StartRoomGame;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAnswer;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomPlayable;
use App\Domain\Rooms\Support\RoomPointsRule;
use App\Domain\Rooms\Support\RoomSeed;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RoomPhones;
use Tests\Support\Rooms;

/*
 * The race (docs/architecture.md §12 "The race", ADR 021): each player's
 * device posts its own answer and the server alone judges, times and
 * scores it; the timetable is derived and every phase serves only what it
 * may. Every state read here is read twice — `GET /rooms/{room}/state` and
 * the first props of `rooms/show` — and the two must be the same bytes,
 * since a page starts from its props; each must also go through the DTOs
 * unchanged, which refuse a key in the wrong phase.
 */

beforeEach(function (): void {
    Rooms::on();
    $this->travelTo('2026-11-24 10:00:00');
    $this->host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->room = Rooms::open($this->host);
    $this->hostRow = RoomPlayer::query()->where('room_id', $this->room->id)->where('user_id', $this->host->id)->sole();
    $this->aygun = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
    $this->aygunRow = Rooms::join($this->room, $this->aygun);
});

/**
 * The state as `$account` reads it, from the endpoint and from the page's
 * props, which must agree; `wire` is everything the account received.
 *
 * @return array{state: array<string, mixed>, wire: string}
 */
function raceRead(User $account, Room $room): array
{
    $json = test()->actingAs($account)->getJson(route('rooms.state', $room))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
    $props = test()->get(route('rooms.show', $room))->assertOk()->viewData('page')['props'];
    $state = $json->json();
    unset($props['translations']);

    expect($props['state'])->toBe($state)
        ->and(RoomStateData::fromArray($state)->toArray())->toBe($state);

    return ['state' => $state, 'wire' => $json->getContent().json_encode($props, JSON_UNESCAPED_UNICODE)];
}

/**
 * Posts `$picked` for `$round` as `$account`.
 *
 * @param  list<string>  $picked
 * @param  array<string, mixed>  $extra
 */
function raceAnswer(User $account, Room $room, RoomRound $round, array $picked, ?string $eventId = null, array $extra = []): TestResponse
{
    return test()->actingAs($account)->postJson(route('rooms.answers.store', $room), [...Rooms::answer($round, $picked, $eventId), ...$extra]);
}

/**
 * The ids a round shows to study and asks with.
 *
 * @return array{study: list<string>, ask: list<string>}
 */
function raceIds(RoomRound $round): array
{
    return [
        'study' => array_map(static fn ($item): string => $item->id, $round->studyData()->items ?? []),
        'ask' => $round->askData()->ids(),
    ];
}

/**
 * Nothing in `$wire` tells right from wrong or the order to tap: no
 * solution, result, own answer, seed or word, and the solution's ids never as a
 * list in their order. A choice's right id is an option of the question,
 * so it may be there only as an item's id, like every other option's.
 */
function raceWithholds(string $wire, RoomRound $round): void
{
    $right = Rooms::right($round);

    // Keys, not values: a taunt's key is a word too (`mine`, ADR 029 decision 27).
    expect($wire)->not->toMatch('/"(solution|accept|correct|mine|results|seed|picked|elapsed_ms|spent)":/')
        ->and($wire)->not->toContain('"word":')
        ->and($wire)->not->toContain(json_encode($right));

    if ($round->solutionData()->shape === RoomAnswerShape::Choice) {
        expect(substr_count($wire, $right[0]))->toBe(substr_count($wire, '"id":"'.$right[0].'"'));
    } else {
        expect($wire)->not->toContain(implode('', $right));
    }
}

function raceSnapshot(): array
{
    $rows = static fn (string $table): array => DB::table($table)->orderBy('id')->get()
        ->map(static fn (object $row): array => (array) $row)->all();

    return [
        'rooms' => $rows('rooms'),
        'room_players' => $rows('room_players'),
        'room_games' => $rows('room_games'),
        'room_rounds' => $rows('room_rounds'),
        'room_answers' => $rows('room_answers'),
    ];
}

it('serves nothing of a round before it opens', function (): void {
    $game = Rooms::playing($this->room);
    $rounds = $game->rounds;
    RoomPhones::read($this->room, $this->host, $this->aygun);

    // The countdown: round 0's number and moments, nothing of any round's content.
    foreach ([$this->host, $this->aygun] as $account) {
        ['state' => $state, 'wire' => $wire] = raceRead($account, $this->room);

        expect($state['game'])->toBe(['kind' => 'race', 'id' => $game->id, 'mechanic' => 'recall_order', 'difficulty' => 1, 'rounds' => 5, 'phase' => 'live'])
            ->and($state['round'])->toMatchArray([
                'id' => $rounds[0]->id, 'index' => 0, 'total' => 5, 'phase' => 'countdown',
                'opens_ms' => $game->started_ms, 'closes_ms' => null, 'next_ms' => null,
                'study' => null, 'ask' => null, 'reveal' => null, 'answered' => false, 'answered_count' => 0, 'present_count' => 2,
            ]);

        foreach ($rounds as $round) {
            foreach ([...raceIds($round)['study'], ...raceIds($round)['ask']] as $id) {
                expect($wire)->not->toContain($id);
            }
        }
    }

    // While round 0 is revealed, round 1 is still nothing; while round 1 is studied, round 2 is.
    foreach ([[RoomPhase::Reveal, 0, 1], [RoomPhase::Study, 1, 2], [RoomPhase::Reveal, 4, null]] as [$phase, $index, $next]) {
        $this->travelTo(Rooms::at($game, $phase, $index));
        ['state' => $state, 'wire' => $wire] = raceRead($this->aygun, $this->room);

        expect($state['round']['index'])->toBe($index)->and($state['round']['phase'])->toBe($phase->value);

        foreach ($rounds->slice($next ?? 5) as $later) {
            foreach ([...raceIds($later)['study'], ...raceIds($later)['ask']] as $id) {
                expect($wire)->not->toContain($id);
            }
        }
    }
});

it('serves the study material only while it is studied', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $study = $round->studyData()?->toArray();

    $this->travelTo(Rooms::at($game, RoomPhase::Study));
    expect(raceRead($this->aygun, $this->room)['state']['round'])->toMatchArray(['phase' => 'study', 'study' => $study, 'ask' => null]);

    $this->travelTo(RoomClock::at($game->timetable()->answerFrom(0) - 1));
    expect(raceRead($this->host, $this->room)['state']['round'])->toMatchArray(['phase' => 'study', 'study' => $study]);

    // From the question on, the material is gone, however often the page reloads.
    foreach ([RoomPhase::Answer, RoomPhase::Answer, RoomPhase::Reveal] as $phase) {
        $this->travelTo(Rooms::at($game, $phase));

        foreach ([$this->aygun, $this->host] as $account) {
            ['state' => $state, 'wire' => $wire] = raceRead($account, $this->room);

            expect($state['round']['study'])->toBeNull();

            foreach (raceIds($round)['study'] as $id) {
                expect($wire)->not->toContain($id);
            }
        }
    }
});

it('serves the question only from the answer phase', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $ask = $round->askData()->toArray();

    foreach ([RoomPhase::Countdown, RoomPhase::Study] as $phase) {
        $this->travelTo(Rooms::at($game, $phase));
        ['state' => $state, 'wire' => $wire] = raceRead($this->aygun, $this->room);

        expect($state['round']['ask'])->toBeNull();

        foreach (raceIds($round)['ask'] as $id) {
            expect($wire)->not->toContain($id);
        }
    }

    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    expect(raceRead($this->aygun, $this->room)['state']['round'])->toMatchArray(['phase' => 'answer', 'ask' => $ask, 'study' => null, 'reveal' => null]);

    // The question stays with its solution in the reveal.
    $this->travelTo(Rooms::at($game, RoomPhase::Reveal));
    expect(raceRead($this->aygun, $this->room)['state']['round'])->toMatchArray(['phase' => 'reveal', 'ask' => $ask]);
});

it('never serves the solution or a result before the round closes', function (): void {
    $tural = User::factory()->player()->create(['name' => 'Tural Əliyev']);
    $turalRow = Rooms::join($this->room, $tural);
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    RoomPhones::read($this->room, $this->aygun, $tural);
    $before = raceRead($this->host, $this->room)['state']['ranking'];

    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertOk();
    $this->travelTo(RoomClock::at(RoomClock::nowMs() + 1500));
    raceAnswer($this->host, $this->room, $round, Rooms::wrong($round))->assertOk();

    // Tural has not answered: the round is open, and nobody learns anything of it.
    foreach ([[$this->aygun, true], [$this->host, true], [$tural, false]] as [$account, $answered]) {
        ['state' => $state, 'wire' => $wire] = raceRead($account, $this->room);

        expect($state['round'])->toMatchArray(['phase' => 'answer', 'reveal' => null, 'closes_ms' => null, 'next_ms' => null, 'answered' => $answered, 'answered_count' => 2, 'present_count' => 3])
            ->and($state['ranking'])->toBe($before)
            ->and(array_column($state['ranking'], 'points'))->toBe([0, 0, 0]);
        raceWithholds($wire, $round);
    }

    // From the bell, all of it.
    $this->travelTo(Rooms::at($game, RoomPhase::Reveal));
    ['state' => $state] = raceRead($this->aygun, $this->room);
    $points = RoomPointsRule::points(true, 0, $round->answer_ms);

    expect($state['round']['phase'])->toBe('reveal')
        ->and($state['round']['closes_ms'])->toBe($game->timetable()->bell(0))
        ->and($state['round']['reveal'])->toBe([
            'solution' => Rooms::right($round),
            'word' => null,
            'mine' => ['picked' => Rooms::right($round), 'correct' => true, 'points' => $points, 'elapsed_ms' => 0, 'letters' => 0],
            'results' => [
                ['player_id' => $this->hostRow->id, 'correct' => false, 'points' => 0],
                ['player_id' => $this->aygunRow->id, 'correct' => true, 'points' => $points],
            ],
            'spent' => [],
        ])
        ->and($state['ranking'][0])->toBe(['player_id' => $this->aygunRow->id, 'points' => $points, 'place' => 1]);

    // Tural, who did not answer, has no result of his own.
    expect(raceRead($tural, $this->room)['state']['round']['reveal']['mine'])->toBeNull()
        ->and($turalRow->answers()->count())->toBe(0);
});

it('counts each player\'s answer once, whatever is sent twice', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    $event = Rooms::answer($round, Rooms::right($round))['event_id'];

    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round), $event)->assertExactJson(['status' => 'accepted', 'revision' => 4]);
    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round), $event)->assertExactJson(['status' => 'duplicate', 'revision' => 4]);
    // The same event with other items, in upper case, or later: the first stays.
    $this->travelTo(RoomClock::at(RoomClock::nowMs() + 2000));
    raceAnswer($this->aygun, $this->room, $round, Rooms::wrong($round), strtoupper($event))->assertJsonPath('status', 'duplicate');

    expect(RoomAnswer::query()->count())->toBe(1)
        ->and(RoomAnswer::query()->sole())->toMatchArray(['event_id' => $event, 'picked' => Rooms::right($round), 'correct' => true, 'elapsed_ms' => 0]);
});

it('refuses a second answer of the same player', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));

    raceAnswer($this->aygun, $this->room, $round, Rooms::wrong($round))->assertJsonPath('status', 'accepted');
    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertExactJson(['status' => 'answered', 'revision' => 4]);

    expect(RoomAnswer::query()->sole())->toMatchArray(['room_player_id' => $this->aygunRow->id, 'correct' => false, 'points' => 0]);
});

it('refuses an answer after the bell', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $timetable = $game->timetable();
    $before = raceSnapshot();

    // Before the question: late, whatever is picked.
    foreach ([Rooms::at($game, RoomPhase::Countdown), Rooms::at($game, RoomPhase::Study), RoomClock::at($timetable->answerFrom(0) - 1)] as $moment) {
        $this->travelTo($moment);
        raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertExactJson(['status' => 'late', 'revision' => 3]);
        raceAnswer($this->aygun, $this->room, $round, ['zzzzzzzz'])->assertExactJson(['status' => 'late', 'revision' => 3]);
    }

    // At the bell and after it: late; the reveal is served from that moment.
    foreach ([$timetable->bell(0), $timetable->bell(0) + 1, $timetable->next(0) + 1] as $ms) {
        $this->travelTo(RoomClock::at($ms));
        raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertJsonPath('status', 'late');
    }

    expect(raceSnapshot())->toBe($before);

    // A moment before the bell is still in time.
    $this->travelTo(RoomClock::at($timetable->bell(1) - 1));
    RoomPhones::read($this->room, $this->host, $this->aygun);
    $last = Rooms::round($game, 1);
    raceAnswer($this->aygun, $this->room, $last, Rooms::right($last))->assertJsonPath('status', 'accepted');
    expect(RoomAnswer::query()->sole()->elapsed_ms)->toBe($last->answer_ms - 1);

    // After an early close too: a late joiner finds the round closed.
    raceAnswer($this->host, $this->room, $last, Rooms::right($last))->assertJsonPath('status', 'accepted');
    $late = User::factory()->player()->create();
    Rooms::join($this->room, $late);
    raceAnswer($late, $this->room, $last, Rooms::right($last))->assertJsonPath('status', 'late');

    // …and stays closed if the server's clock steps back.
    $this->travelTo(RoomClock::at((int) $last->refresh()->closed_ms - 500));
    raceAnswer($late, $this->room, $last, Rooms::right($last))->assertJsonPath('status', 'late');

    // A round of another game, or of another room, is never open here.
    $other = Rooms::playing(Rooms::open($this->aygun), rounds: 5);
    $this->travelTo(Rooms::at($other, RoomPhase::Answer));
    raceAnswer($this->aygun, $this->room, Rooms::round($other, 0), Rooms::right(Rooms::round($other, 0)))->assertJsonPath('status', 'late');
    expect(RoomAnswer::query()->count())->toBe(2);
});

it('refuses items that are not the round\'s', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $next = Rooms::round($game, 1);
    $right = Rooms::right($round);
    $foreign = [
        'ids of another round' => Rooms::right($next),
        'the material\'s ids' => raceIds($round)['study'],
        'one too few' => array_slice($right, 1),
        'one too many' => [...$right, raceIds($next)['ask'][0]],
        'an unknown id' => [...array_slice($right, 1), 'zzzzzzzz'],
    ];
    $before = raceSnapshot();

    // Before the question, a foreign id is only late: nothing is learnt of the round.
    $this->travelTo(Rooms::at($game, RoomPhase::Study));
    foreach ($foreign as $picked) {
        raceAnswer($this->aygun, $this->room, $round, $picked)->assertJsonPath('status', 'late');
    }

    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    foreach ($foreign as $what => $picked) {
        raceAnswer($this->aygun, $this->room, $round, $picked)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['picked' => __('rooms.errors.picked')]);
    }

    // Malformed bodies are refused before anything is read.
    foreach ([['picked' => []], ['picked' => ['ABCDEFGH']], ['picked' => 'abcdefgh'], ['picked' => ['b' => $right[0]]], ['picked' => array_fill(0, 5000, 'zzzzzzzz')], ['round_id' => 'x'], ['event_id' => '7']] as $broken) {
        $this->actingAs($this->aygun)
            ->postJson(route('rooms.answers.store', $this->room), [...Rooms::answer($round, $right), ...$broken])
            ->assertUnprocessable();
    }

    expect(raceSnapshot())->toBe($before);

    // An id picked twice is the round's own and the right count: judged, and wrong.
    raceAnswer($this->aygun, $this->room, $round, [$right[0], ...array_slice($right, 0, -1)])->assertJsonPath('status', 'accepted');
    expect(RoomAnswer::query()->sole()->correct)->toBeFalse();
});

it('measures the time on the server\'s clock', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $from = $game->timetable()->answerFrom(0);
    $this->travelTo(RoomClock::at($from));
    RoomPhones::read($this->room, $this->host, $this->aygun);

    // Whatever the device says of its own time is not read.
    $this->travelTo(RoomClock::at($from + 2345));
    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round), extra: ['elapsed_ms' => 1, 'sent_ms' => $from, 'client_ms' => $from])
        ->assertExactJson(['status' => 'accepted', 'revision' => 4]);

    $this->travelTo(RoomClock::at($from + 9999));
    raceAnswer($this->host, $this->room, $round, Rooms::right($round))->assertJsonPath('status', 'accepted');

    $answers = RoomAnswer::query()->orderBy('id')->get();
    expect($answers->pluck('elapsed_ms')->all())->toBe([2345, 9999])
        ->and($answers->pluck('points')->all())->toBe([
            RoomPointsRule::points(true, 2345, $round->answer_ms),
            RoomPointsRule::points(true, 9999, $round->answer_ms),
        ])
        ->and(raceRead($this->aygun, $this->room)['state']['round']['reveal']['mine']['elapsed_ms'])->toBe(2345);
});

it('never says right or wrong in the receipt', function (): void {
    $tural = User::factory()->player()->create();
    Rooms::join($this->room, $tural);
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    RoomPhones::read($this->room, $this->host, $this->aygun, $tural);

    $right = raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $wrong = raceAnswer($this->host, $this->room, $round, Rooms::wrong($round))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    // Each accepted answer is one change of the room; the receipts differ by that alone.
    expect($right->json())->toBe(['status' => 'accepted', 'revision' => 5])
        ->and($wrong->json())->toBe(['status' => 'accepted', 'revision' => 6])
        ->and(RoomAnswer::query()->orderBy('id')->pluck('correct')->all())->toBe([true, false]);
});

it('closes the round when every present player has answered', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    RoomPhones::read($this->room, $this->host, $this->aygun);
    $this->travelTo(RoomClock::at($game->timetable()->answerFrom(0) + 1000));

    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertExactJson(['status' => 'accepted', 'revision' => 4]);
    expect($round->refresh()->closed_ms)->toBeNull();

    $this->travelTo(RoomClock::at(RoomClock::nowMs() + 500));
    $closedAt = RoomClock::nowMs();
    raceAnswer($this->host, $this->room, $round, Rooms::wrong($round))->assertExactJson(['status' => 'accepted', 'revision' => 5]);

    expect($round->refresh()->closed_ms)->toBe($closedAt);
    ['state' => $state] = raceRead($this->aygun, $this->room);
    expect($state['revision'])->toBe(5)
        ->and($state['round'])->toMatchArray(['index' => 0, 'phase' => 'reveal', 'closes_ms' => $closedAt, 'next_ms' => $closedAt + $game->reveal_ms, 'answered_count' => 2])
        ->and($game->refresh()->timetable()->opens(1))->toBe($closedAt + $game->reveal_ms);

    // A host who does not play is not waited for.
    $this->travelTo('2026-11-24 12:00:00');
    $watcher = User::factory()->player()->create();
    $room = Rooms::open($watcher, plays: false);
    [$a, $b] = [User::factory()->player()->create(), User::factory()->player()->create()];
    Rooms::join($room, $a);
    Rooms::join($room, $b);
    $other = Rooms::playing($room);
    $first = Rooms::round($other, 0);
    $this->travelTo(Rooms::at($other, RoomPhase::Answer));
    RoomPhones::read($room, $watcher, $a, $b);
    raceAnswer($a, $room, $first, Rooms::right($first));
    expect($first->refresh()->closed_ms)->toBeNull();
    raceAnswer($b, $room, $first, Rooms::right($first));
    expect($first->refresh()->closed_ms)->toBe(RoomClock::nowMs());
});

it('does not wait for a player who left or was removed', function (): void {
    $tural = User::factory()->player()->create();
    $nigar = User::factory()->player()->create();
    Rooms::join($this->room, $tural);
    $nigarRow = Rooms::join($this->room, $nigar);
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    RoomPhones::read($this->room, $this->host, $this->aygun, $tural, $nigar);

    raceAnswer($this->host, $this->room, $round, Rooms::right($round));
    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round));

    // Tural leaves: Nigar has still to answer.
    $this->actingAs($tural)->post(route('rooms.leave', $this->room))->assertRedirect();
    expect($round->refresh()->closed_ms)->toBeNull();

    // The host removes Nigar: nobody left to wait for.
    $this->travelTo(RoomClock::at(RoomClock::nowMs() + 700));
    $this->actingAs($this->host)->delete(route('rooms.members.destroy', [$this->room, $nigarRow]))->assertRedirect();
    expect($round->refresh()->closed_ms)->toBe(RoomClock::nowMs());

    // Round 1: the host answers and Aygün leaves.
    $next = Rooms::round($game, 1);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    RoomPhones::read($this->room, $this->host, $this->aygun);
    raceAnswer($this->host, $this->room, $next, Rooms::right($next));
    expect($next->refresh()->closed_ms)->toBeNull();
    $this->actingAs($this->aygun)->post(route('rooms.leave', $this->room));
    expect($next->refresh()->closed_ms)->toBe(RoomClock::nowMs());

    // Round 2: the host, the only one left, leaves without answering — a round with no present player runs to its bell.
    $third = Rooms::round($game, 2);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 2));
    $this->actingAs($this->host)->post(route('rooms.leave', $this->room));
    expect($third->refresh()->closed_ms)->toBeNull();
});

it('closes the round when a player who answered comes back to it alone', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    RoomPhones::read($this->room, $this->host, $this->aygun);
    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round));
    $this->actingAs($this->aygun)->post(route('rooms.leave', $this->room));
    $this->actingAs($this->host)->post(route('rooms.leave', $this->room));
    expect($round->refresh()->closed_ms)->toBeNull();

    $this->travelTo(RoomClock::at(RoomClock::nowMs() + 900));
    $this->actingAs($this->aygun)->post(route('rooms.join.store', (string) $this->room->code))->assertRedirect(route('rooms.show', $this->room));
    expect($round->refresh()->closed_ms)->toBe(RoomClock::nowMs());
});

it('lets a late joiner answer the open round', function (): void {
    $game = Rooms::playing($this->room);
    [$first, $second] = [Rooms::round($game, 0), Rooms::round($game, 1)];
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    RoomPhones::read($this->room, $this->host, $this->aygun);
    raceAnswer($this->host, $this->room, $first, Rooms::right($first));
    raceAnswer($this->aygun, $this->room, $first, Rooms::right($first));

    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    RoomPhones::read($this->room, $this->host, $this->aygun);
    raceAnswer($this->host, $this->room, $second, Rooms::right($second));

    $tural = User::factory()->player()->create(['name' => 'Tural Əliyev']);
    $this->actingAs($tural)->post(route('rooms.join.store', (string) $this->room->code))->assertRedirect(route('rooms.show', $this->room));
    $turalRow = RoomPlayer::query()->where('user_id', $tural->id)->sole();

    ['state' => $state, 'wire' => $wire] = raceRead($tural, $this->room);
    expect($state['round'])->toMatchArray(['index' => 1, 'phase' => 'answer', 'ask' => $second->askData()->toArray(), 'answered' => false, 'answered_count' => 1, 'present_count' => 3])
        ->and(collect($state['ranking'])->firstWhere('player_id', $turalRow->id))->toBe(['player_id' => $turalRow->id, 'points' => 0, 'place' => null]);
    raceWithholds($wire, $second);

    // Counted from the moment of joining: Aygün's answer no longer closes the round.
    raceAnswer($this->aygun, $this->room, $second, Rooms::right($second));
    expect($second->refresh()->closed_ms)->toBeNull();

    $this->travelTo(RoomClock::at($game->timetable()->answerFrom(1) + 4000));
    raceAnswer($tural, $this->room, $second, Rooms::right($second))->assertJsonPath('status', 'accepted');

    expect($turalRow->answers()->sole()->elapsed_ms)->toBe(4000)
        ->and($second->refresh()->closed_ms)->toBe(RoomClock::nowMs())
        // Nothing for the round before he came.
        ->and($turalRow->answers()->where('room_round_id', $first->id)->exists())->toBeFalse();
});

// A phone that is simply gone — a closed tab without «Çıx» — stays present,
// but stops reading: the round it vanished in waits for it to the bell,
// and no round after it does (§12 "Late, lost and gone", ADR 021 decision
// 10). A player thinking on a live phone is always waited for.
it('waits for a phone that is gone only in the round it vanished in', function (): void {
    $tural = User::factory()->player()->create(['name' => 'Tural Əliyev']);
    $turalRow = Rooms::join($this->room, $tural);
    $game = Rooms::playing($this->room);
    [$first, $second, $third] = [Rooms::round($game, 0), Rooms::round($game, 1), Rooms::round($game, 2)];

    // Round 0: all three phones read the question; then Tural's is gone.
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    foreach ([$this->host, $this->aygun, $tural] as $account) {
        raceRead($account, $this->room);
    }
    raceAnswer($this->host, $this->room, $first, Rooms::right($first));
    $this->travelTo(RoomClock::at(RoomClock::nowMs() + 2000));
    raceAnswer($this->aygun, $this->room, $first, Rooms::right($first));

    // The round he vanished in waits for him to its bell; reading it closes nothing.
    expect($first->refresh()->closed_ms)->toBeNull()
        ->and(raceRead($this->aygun, $this->room)['state']['round'])->toMatchArray(['phase' => 'answer', 'answered_count' => 2, 'present_count' => 3]);
    $this->travelTo(RoomClock::at($game->timetable()->bell(0) - 1));
    expect(raceRead($this->host, $this->room)['state']['round']['phase'])->toBe('answer')
        ->and($first->refresh()->closed_ms)->toBeNull();

    // The bell: the live phones read the reveal.
    $this->travelTo(Rooms::at($game, RoomPhase::Reveal));
    raceRead($this->host, $this->room);
    expect(raceRead($this->aygun, $this->room)['state']['round'])->toMatchArray(['index' => 0, 'phase' => 'reveal', 'closes_ms' => $game->timetable()->bell(0), 'answered_count' => 2, 'present_count' => 3]);

    // Round 1: Tural is still in the room, but his phone has not read since round 0 closed.
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    raceRead($this->host, $this->room);
    ['state' => $state] = raceRead($this->aygun, $this->room);
    expect(collect($state['players'])->firstWhere('id', $turalRow->id)['present'])->toBeTrue()
        ->and($state['round'])->toMatchArray(['index' => 1, 'phase' => 'answer', 'answered_count' => 0, 'present_count' => 2]);

    // Aygün, on a live phone, thinks: the host's answer does not close the round, however late it is.
    raceAnswer($this->host, $this->room, $second, Rooms::wrong($second))->assertJsonPath('status', 'accepted');
    $this->travelTo(RoomClock::at($game->timetable()->bell(1) - 1000));
    expect($second->refresh()->closed_ms)->toBeNull()
        ->and(raceRead($this->aygun, $this->room)['state']['round'])->toMatchArray(['phase' => 'answer', 'answered_count' => 1, 'present_count' => 2]);

    // Her answer, the last live player's, closes it at once.
    raceAnswer($this->aygun, $this->room, $second, Rooms::right($second))->assertJsonPath('status', 'accepted');
    expect($second->refresh()->closed_ms)->toBe(RoomClock::nowMs())
        ->and(raceRead($this->host, $this->room)['state']['round'])->toMatchArray(['index' => 1, 'phase' => 'reveal', 'answered_count' => 2, 'present_count' => 2]);
    raceRead($this->aygun, $this->room);

    // Round 2 does not wait for him either.
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 2));
    raceRead($this->host, $this->room);
    raceRead($this->aygun, $this->room);
    raceAnswer($this->host, $this->room, $third, Rooms::right($third));
    raceAnswer($this->aygun, $this->room, $third, Rooms::right($third));
    expect($third->refresh()->closed_ms)->toBe(RoomClock::nowMs());

    // Round 3: his phone comes back, and from its read the round waits for him again.
    $fourth = Rooms::round($game, 3);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 3));
    foreach ([$this->host, $this->aygun, $tural] as $account) {
        raceRead($account, $this->room);
    }
    raceAnswer($this->host, $this->room, $fourth, Rooms::right($fourth));
    raceAnswer($this->aygun, $this->room, $fourth, Rooms::right($fourth));
    expect($fourth->refresh()->closed_ms)->toBeNull();

    raceAnswer($tural, $this->room, $fourth, Rooms::wrong($fourth))->assertJsonPath('status', 'accepted');
    expect($fourth->refresh()->closed_ms)->toBe(RoomClock::nowMs())
        ->and($turalRow->answers()->pluck('room_round_id')->all())->toBe([$fourth->id]);
});

it('records a phone\'s read only when it changes what a round waits for', function (): void {
    $seen = fn (): ?int => $this->aygunRow->refresh()->seen_ms;

    // In the lobby a read records nothing: no round is waiting.
    raceRead($this->aygun, $this->room);
    expect($seen())->toBeNull();

    // The countdown: the first read counts for round 0; the next ones in it change nothing.
    $game = Rooms::playing($this->room);
    $first = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Countdown));
    $read = RoomClock::nowMs();
    raceRead($this->aygun, $this->room);
    foreach ([RoomPhase::Study, RoomPhase::Answer] as $phase) {
        $this->travelTo(Rooms::at($game, $phase));
        raceRead($this->aygun, $this->room);
    }
    expect($seen())->toBe($read);

    // The round closes; the reveal's first read counts for round 1.
    raceRead($this->host, $this->room);
    raceAnswer($this->host, $this->room, $first, Rooms::right($first));
    raceAnswer($this->aygun, $this->room, $first, Rooms::right($first));
    $this->travelTo(RoomClock::at((int) $first->refresh()->closed_ms + 100));
    raceRead($this->aygun, $this->room);
    expect($seen())->toBe(RoomClock::nowMs());

    // The last reveal and the end record nothing: no round is ahead.
    $this->travelTo(Rooms::at($game, RoomPhase::Reveal, 4));
    $before = $seen();
    raceRead($this->aygun, $this->room);
    $this->travelTo(Rooms::at($game, RoomPhase::Over));
    raceRead($this->aygun, $this->room);
    expect($seen())->toBe($before);
});

it('never takes the big screen for the host\'s phone', function (): void {
    $game = Rooms::playing($this->room);
    [$first, $second] = [Rooms::round($game, 0), Rooms::round($game, 1)];
    $screen = route('rooms.screen', $this->room);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));

    // The host's phone is gone; the screen follows the room in the host's session, with its page and every read.
    $this->actingAs($this->host)->get($screen)->assertOk();
    $state = $this->actingAs($this->host)->getJson(route('rooms.state', $this->room), ['Referer' => $screen])->assertOk()->json();
    expect($this->hostRow->refresh()->seen_ms)->toBeNull()
        ->and($state['round'])->toMatchArray(['phase' => 'answer', 'answered_count' => 0, 'present_count' => 0]);

    // So the round waits for Aygün alone, and the screen shows a full count as it closes.
    raceRead($this->aygun, $this->room);
    raceAnswer($this->aygun, $this->room, $first, Rooms::right($first))->assertJsonPath('status', 'accepted');
    expect($first->refresh()->closed_ms)->toBe(RoomClock::nowMs())
        ->and($this->actingAs($this->host)->getJson(route('rooms.state', $this->room), ['Referer' => $screen])->json('round'))
        ->toMatchArray(['phase' => 'reveal', 'answered_count' => 1, 'present_count' => 1]);

    // The host's own room page reads — with its own address, or none — and round 1 waits for the host.
    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    $this->actingAs($this->host)->getJson(route('rooms.state', $this->room), ['Referer' => route('rooms.show', $this->room)])->assertOk();
    expect($this->hostRow->refresh()->seen_ms)->toBe(RoomClock::nowMs());
    raceRead($this->aygun, $this->room);
    raceAnswer($this->aygun, $this->room, $second, Rooms::right($second));
    expect($second->refresh()->closed_ms)->toBeNull();

    raceAnswer($this->host, $this->room, $second, Rooms::wrong($second));
    expect($second->refresh()->closed_ms)->toBe(RoomClock::nowMs());
});

it('waits for a late joiner from the room page the join leads to', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    RoomPhones::read($this->room, $this->host, $this->aygun);
    raceAnswer($this->host, $this->room, $round, Rooms::right($round));

    // Tural joins with the code and lands on the room page; he reads nothing else.
    $tural = User::factory()->player()->create(['name' => 'Tural Əliyev']);
    $this->actingAs($tural)->followingRedirects()->post(route('rooms.join.store', (string) $this->room->code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('rooms/show')->where('state.round.present_count', 3));

    // Aygün's answer no longer closes the round; his does.
    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round));
    expect($round->refresh()->closed_ms)->toBeNull();

    $this->travelTo(RoomClock::at(RoomClock::nowMs() + 3000));
    raceAnswer($tural, $this->room, $round, Rooms::right($round))->assertJsonPath('status', 'accepted');
    expect($round->refresh()->closed_ms)->toBe(RoomClock::nowMs());
});

it('reveals a round for 3 s in a new game, and a started game keeps its own reveal', function (): void {
    $default = config('elifba.rooms.reveal_ms');
    expect($default)->toBe(3_000);

    // A game started while the reveal was 5 s keeps it to its end.
    config(['elifba.rooms.reveal_ms' => 5_000]);
    $old = Rooms::playing($this->room);
    config(['elifba.rooms.reveal_ms' => $default]);
    $this->travelTo(Rooms::at($old, RoomPhase::Reveal, 0));
    ['state' => $state] = raceRead($this->aygun, $this->room);

    expect($old->refresh()->reveal_ms)->toBe(5_000)
        ->and($state['round']['next_ms'] - $state['round']['closes_ms'])->toBe(5_000);

    // The next game reveals each round for 3 s.
    $this->travelTo(Rooms::at($old, RoomPhase::Over));
    $this->actingAs($this->host)->putJson(route('rooms.setup.update', $this->room), ['mechanic' => 'recall_order', 'difficulty' => 1, 'rounds' => 5])->assertNoContent();
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    $new = $this->room->refresh()->latestGame();
    $this->travelTo(Rooms::at($new, RoomPhase::Reveal, 0));
    ['state' => $state] = raceRead($this->aygun, $this->room);

    expect($new?->reveal_ms)->toBe(3_000)
        ->and($state['round']['next_ms'] - $state['round']['closes_ms'])->toBe(3_000)
        ->and($new?->timetable()->opens(1))->toBe($new?->timetable()->bell(0) + 3_000);
});

it('runs to its end with nobody watching', function (): void {
    $game = Rooms::playing($this->room, rounds: 5);
    $over = $game->timetable()->overMs();
    $before = raceSnapshot();

    // Just before the end, the last reveal; at the end, over — and asking writes nothing.
    $this->travelTo(RoomClock::at($over - 1));
    expect(raceRead($this->aygun, $this->room)['state']['round'])->toMatchArray(['index' => 4, 'phase' => 'reveal']);

    $this->travelTo(RoomClock::at($over));
    ['state' => $state] = raceRead($this->aygun, $this->room);
    expect($state['game'])->toMatchArray(['id' => $game->id, 'phase' => 'over'])
        ->and($state['round'])->toBeNull()
        ->and(array_column($state['ranking'], 'place'))->toBe([null, null])
        ->and(raceSnapshot())->toBe($before);

    // The room is a lobby again: the host may start the next game.
    $this->actingAs($this->host)->putJson(route('rooms.setup.update', $this->room), ['mechanic' => 'recall_order', 'difficulty' => 2, 'rounds' => 5])->assertNoContent();
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    expect(RoomGame::query()->where('room_id', $this->room->id)->orderBy('position')->pluck('position')->all())->toBe([0, 1]);
});

it('ends when the host ends it', function (): void {
    $game = Rooms::playing($this->room);
    [$first, $second] = [Rooms::round($game, 0), Rooms::round($game, 1)];
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    RoomPhones::read($this->room, $this->host, $this->aygun);
    raceAnswer($this->host, $this->room, $first, Rooms::wrong($first));
    raceAnswer($this->aygun, $this->room, $first, Rooms::right($first));

    $this->travelTo(Rooms::at($game, RoomPhase::Answer, 1));
    RoomPhones::read($this->room, $this->host, $this->aygun);
    raceAnswer($this->aygun, $this->room, $second, Rooms::right($second))->assertJsonPath('status', 'accepted');

    // Only the host, and only while it runs.
    $this->actingAs($this->aygun)->deleteJson(route('rooms.game.destroy', $this->room))->assertForbidden();
    $revision = $this->room->refresh()->revision;
    $this->actingAs($this->host)->deleteJson(route('rooms.game.destroy', $this->room))->assertNoContent();

    expect($game->refresh()->ended_ms)->toBe(RoomClock::nowMs())
        ->and($this->room->refresh()->revision)->toBe($revision + 1);

    // Over at once; the round it cut off gives nothing and is never revealed.
    ['state' => $state, 'wire' => $wire] = raceRead($this->aygun, $this->room);
    expect($state['game']['phase'])->toBe('over')
        ->and($state['round'])->toBeNull()
        ->and($state['ranking'][0])->toBe(['player_id' => $this->aygunRow->id, 'points' => $first->answers()->where('room_player_id', $this->aygunRow->id)->sole()->points, 'place' => 1]);
    raceWithholds($wire, $second);

    raceAnswer($this->host, $this->room, $second, Rooms::right($second))->assertJsonPath('status', 'late');
    $this->actingAs($this->host)->deleteJson(route('rooms.game.destroy', $this->room))->assertForbidden();
});

it('starts no second game while one is running', function (): void {
    $setup = ['mechanic' => 'recall_order', 'difficulty' => 1, 'rounds' => 5];

    // Only the host, and only a level the room can play (the choice is ChooseGameTest's).
    $this->actingAs($this->aygun)->postJson(route('rooms.games.store', $this->room))->assertForbidden();
    $this->actingAs($this->host)->putJson(route('rooms.setup.update', $this->room), $setup)->assertNoContent();
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    $game = $this->room->latestGame();
    expect($game)->toMatchArray(['position' => 0, 'difficulty' => 1, 'reveal_ms' => 3000, 'started_ms' => RoomClock::nowMs() + 3000])
        ->and($game?->rounds)->toHaveCount(5);

    // Refused while it runs, by the policy and by the action under the lock.
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room))->assertForbidden();
    $this->travelTo(Rooms::at($game, RoomPhase::Reveal, 4));
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room))->assertForbidden();
    expect(fn () => app(StartRoomGame::class)->handle($this->room))->toThrow(AuthorizationException::class);
    expect(RoomGame::query()->count())->toBe(1);

    // Over: the next one starts.
    $this->travelTo(Rooms::at($game, RoomPhase::Over));
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    expect(RoomGame::query()->count())->toBe(2);

    // Never in a closed room.
    $this->travelTo(Rooms::at($this->room->latestGame(), RoomPhase::Over));
    $this->post(route('rooms.close', $this->room));
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room))->assertForbidden();
    expect(fn () => app(StartRoomGame::class)->handle($this->room))->toThrow(AuthorizationException::class);
});

it('offers the setup of a game to the host of an open room alone, as the server accepts it', function (): void {
    // Each mechanic the room can play with its difficulties, and the rounds' bounds; never the content's own list.
    // (What each level holds is RoomPagesTest's.)
    $offered = ['mechanics' => [
        ['mechanic' => 'recall_order', 'family' => 'memory', 'difficulties' => [1, 2, 3, 4]],
        ['mechanic' => 'recall_cells', 'family' => 'memory', 'difficulties' => [1, 2, 3, 4]],
        ['mechanic' => 'next_in_sequence', 'family' => 'logic', 'difficulties' => [1, 2, 3, 4]],
        ['mechanic' => 'odd_one_out', 'family' => 'logic', 'difficulties' => [1, 2, 3, 4]],
        ['mechanic' => 'anagram', 'family' => 'words', 'difficulties' => [1, 2, 3, 4]],
        ['mechanic' => 'spelling', 'family' => 'words', 'difficulties' => [1, 2, 3, 4]],
        ['mechanic' => 'draughts', 'family' => 'board', 'difficulties' => [1, 2, 3]],
        // Y6 lists «101» (ADR 038 decision 73): a turn's time at each level.
        ['mechanic' => 'domino_101', 'family' => 'domino', 'difficulties' => [1, 2, 3]],
    ], 'rounds' => ['min' => 5, 'max' => 20]];
    $setup = $this->actingAs($this->host)->get(route('rooms.show', $this->room))->viewData('page')['props']['catalog'];
    expect([
        'mechanics' => array_map(static fn (array $offer): array => array_diff_key($offer, ['levels' => true]), $setup['mechanics']),
        'rounds' => $setup['rounds'],
    ])->toBe($offered);
    $this->actingAs($this->aygun)->get(route('rooms.show', $this->room))
        ->assertInertia(fn (Assert $page) => $page->where('catalog', null));

    foreach ($offered['mechanics'] as $offer) {
        foreach ($offer['difficulties'] as $difficulty) {
            // A race's rounds, a board game's two seats (D3): each kind reads its own; a domino game's table is the room's (ADR 038 decision 75).
            $seats = ['white' => Str::lower((string) Str::ulid()), 'black' => Str::lower((string) Str::ulid())];
            $choice = $offer['family'] === 'domino' ? [] : ['rounds' => 5, ...$seats];
            expect(RoomGameSetupData::validate(['mechanic' => $offer['mechanic'], 'difficulty' => $difficulty, ...$choice])->difficulty)->toBe($difficulty);
        }
    }

    $this->actingAs($this->host)->post(route('rooms.close', $this->room));
    $this->get(route('rooms.show', $this->room))
        ->assertInertia(fn (Assert $page) => $page->where('catalog', null));
});

it('ends the game when the room closes', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertJsonPath('status', 'accepted');

    $this->actingAs($this->host)->post(route('rooms.close', $this->room))->assertRedirect(route('rooms.show', $this->room));

    expect($game->refresh()->ended_ms)->toBe(RoomClock::nowMs());

    // The closed room's page: the game is over, its open round never counted nor revealed.
    $props = $this->actingAs($this->aygun)->get(route('rooms.show', $this->room))->assertOk()->viewData('page')['props'];
    unset($props['translations']);
    expect($props['state']['game']['phase'])->toBe('over')
        ->and($props['state']['round'])->toBeNull()
        ->and(array_column($props['state']['ranking'], 'points'))->toBe([0, 0]);
    raceWithholds((string) json_encode($props), $round);

    // Nobody answers in a closed room.
    raceAnswer($this->host, $this->room, $round, Rooms::right($round))->assertForbidden();
    expect(RoomAnswer::query()->count())->toBe(1);
});

it('takes the player from the session, never from the body', function (): void {
    $watcher = User::factory()->player()->create();
    $room = Rooms::open($watcher, plays: false);
    $playerRow = Rooms::join($room, $this->aygun);
    $game = Rooms::playing($room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));

    // A host who does not play has no row to answer with.
    raceAnswer($watcher, $room, $round, Rooms::right($round))->assertForbidden();

    // A body that names a player is answered as the session's account.
    raceAnswer($this->aygun, $room, $round, Rooms::wrong($round), extra: ['player_id' => $playerRow->id, 'room_player_id' => $this->hostRow->id, 'user_id' => $watcher->id])
        ->assertJsonPath('status', 'accepted');

    expect(RoomAnswer::query()->sole()->room_player_id)->toBe($playerRow->id);
});

it('limits answers to 30 a minute per account', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Study));

    foreach (range(1, 30) as $_) {
        raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertOk();
    }

    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertTooManyRequests();
});

// B4: «Xanaları yadda saxla» (§12 "Room games"). The study names the lit
// cells; nothing else before the reveal does — not the countdown, not the
// question, which is the whole dark grid with ids of its own, the same
// whichever cells were lit.
it('plays a game of cells to its ranking and names no lit cell before the reveal', function (): void {
    $this->actingAs($this->host)->putJson(route('rooms.setup.update', $this->room), ['mechanic' => 'recall_cells', 'difficulty' => 2, 'rounds' => 5])->assertNoContent();
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    $game = $this->room->refresh()->latestGame()?->load('rounds');
    $level = app(ContentRepository::class)->roomLevel(RoomMechanic::RecallCells, 2);
    $grid = $level->grid?->toArray();
    $everyCell = [];

    for ($row = 0; $row < ($grid['rows'] ?? 0); $row++) {
        for ($col = 0; $col < ($grid['cols'] ?? 0); $col++) {
            $everyCell[] = ['cell', null, null, [$col, $row]];
        }
    }

    expect($game)->not->toBeNull()
        ->and($game?->mechanic)->toBe(RoomMechanic::RecallCells)
        ->and($game?->rounds)->toHaveCount(5);

    // The countdown: nothing of any round.
    ['state' => $state, 'wire' => $wire] = raceRead($this->aygun, $this->room);

    expect($state['game'])->toMatchArray(['mechanic' => 'recall_cells', 'difficulty' => 2, 'phase' => 'live'])
        ->and($state['round']['phase'])->toBe('countdown');

    foreach ($game->rounds ?? [] as $round) {
        foreach ([...raceIds($round)['study'], ...raceIds($round)['ask']] as $id) {
            expect($wire)->not->toContain($id);
        }
    }

    $points = 0;

    foreach (range(0, 4) as $index) {
        $round = Rooms::round($game, $index);
        $lit = array_map(static fn ($item): ?array => $item->cell, $round->studyData()->items ?? []);

        // The study: the lit cells on the grid, and nothing of the question.
        $this->travelTo(Rooms::at($game, RoomPhase::Study, $index));
        ['state' => $state, 'wire' => $wire] = raceRead($this->aygun, $this->room);

        expect($state['round']['study'])->toMatchArray(['layout' => 'grid', 'grid' => $grid])
            ->and(array_column($state['round']['study']['items'], 'cell'))->toBe($lit)
            ->and(count($lit))->toBeGreaterThanOrEqual($level->lit->min ?? 0)->toBeLessThanOrEqual($level->lit->max ?? 0)
            ->and($state['round']['ask'])->toBeNull();
        raceWithholds($wire, $round);

        foreach (raceIds($round)['ask'] as $id) {
            expect($wire)->not->toContain($id);
        }

        // The question: the dark grid in reading order, asking for as many as were lit; no id of the study.
        $this->travelTo(Rooms::at($game, RoomPhase::Answer, $index));

        foreach ([$this->host, $this->aygun] as $account) {
            ['state' => $state, 'wire' => $wire] = raceRead($account, $this->room);
            $ask = $state['round']['ask'];

            expect($state['round']['study'])->toBeNull()
                ->and(array_map(static fn (array $item): array => [$item['kind'], $item['text'], $item['asset'], $item['cell']], $ask['items']))->toBe($everyCell)
                ->and([$ask['shape'], $ask['pick'], $ask['layout'], $ask['grid']])->toBe(['set', count($lit), 'grid', $grid]);
            raceWithholds($wire, $round);

            foreach (raceIds($round)['study'] as $id) {
                expect($wire)->not->toContain($id);
            }
        }

        // Aygün taps the lit cells in another order and is right; Rəşad swaps one for a dark cell.
        raceAnswer($this->aygun, $this->room, $round, array_reverse(Rooms::right($round)))->assertExactJson(['status' => 'accepted', 'revision' => $this->room->refresh()->revision]);
        raceAnswer($this->host, $this->room, $round, Rooms::wrong($round))->assertOk();
        $aygun = RoomAnswer::query()->where('room_round_id', $round->id)->where('room_player_id', $this->aygunRow->id)->sole();
        $points += $aygun->points;

        // Both answered: the round is revealed, the lit cells named by the question's ids.
        ['state' => $state] = raceRead($this->aygun, $this->room);

        expect($state['round']['phase'])->toBe('reveal')
            ->and($state['round']['reveal']['solution'])->toBe(Rooms::right($round))
            ->and($state['round']['reveal']['mine'])->toMatchArray(['correct' => true, 'points' => $aygun->points])
            ->and($state['round']['reveal']['results'])->toBe([
                ['player_id' => $this->hostRow->id, 'correct' => false, 'points' => 0],
                ['player_id' => $this->aygunRow->id, 'correct' => true, 'points' => $aygun->points],
            ]);
    }

    // Over: Aygün first with every round's points, Rəşad after the places without one.
    $this->travelTo(Rooms::at($game, RoomPhase::Over));
    ['state' => $state] = raceRead($this->host, $this->room);

    expect($state['game']['phase'])->toBe('over')
        ->and($points)->toBeGreaterThanOrEqual(50)
        ->and($state['ranking'])->toBe([
            ['player_id' => $this->aygunRow->id, 'points' => $points, 'place' => 1],
            ['player_id' => $this->hostRow->id, 'points' => 0, 'place' => null],
        ]);
});

// B4: shapes in «Ardıcıllığı yadda saxla» (§12 "Room games"): pictures with
// no text, judged by what they show.
it('plays a row of shapes and judges it by their pictures', function (): void {
    $level = new RoomLevelData(RoomMechanic::RecallOrder, 2, 4_500, 15_000, [RoomItemKind::Shape], new RangeData(5, 6));
    [$built] = RoomPlayable::builder(RoomMechanic::RecallOrder)->build($level, 1, RoomSeed::random(7));
    $game = RoomGame::factory()->for($this->room)->create(['difficulty' => 2]);
    $round = RoomRound::factory()->for($game, 'game')->built($built)->create(['position' => 0]);
    $game->load('rounds');

    $this->travelTo(Rooms::at($game, RoomPhase::Study));
    ['state' => $state] = raceRead($this->aygun, $this->room);
    $study = $state['round']['study']['items'];

    expect(array_unique(array_column($study, 'kind')))->toBe(['shape'])
        ->and(array_filter(array_column($study, 'text')))->toBe([])
        ->and($study[0]['asset'])->toMatch('#/content/rooms/[a-z]+\.svg\?v=\d+$#');

    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    RoomPhones::read($this->room, $this->host);
    ['state' => $state, 'wire' => $wire] = raceRead($this->aygun, $this->room);
    $asked = array_column($state['round']['ask']['items'], 'asset');

    // The question is the same pictures, never in the order shown.
    expect($asked)->not->toBe(array_column($study, 'asset'))
        ->and(array_values(array_diff($asked, array_column($study, 'asset'))))->toBe([]);
    raceWithholds($wire, $round);

    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertOk();
    raceAnswer($this->host, $this->room, $round, Rooms::wrong($round))->assertOk();
    ['state' => $state] = raceRead($this->aygun, $this->room);

    expect($state['round']['reveal']['results'])->toBe([
        ['player_id' => $this->hostRow->id, 'correct' => false, 'points' => 0],
        ['player_id' => $this->aygunRow->id, 'correct' => true, 'points' => $state['round']['reveal']['mine']['points']],
    ]);
});

// B5: «Sıranı davam etdir» and «Artıq olanı tap» (§12 "Room games"). Nothing
// is studied: a round opens with its question — the row to continue in
// `ask.shown`, the options in `ask.items` — and nothing before the reveal
// tells the right option: not its place, not its id, not a key beside it.
it('plays a logic game to its ranking', function (string $mechanic, int $difficulty): void {
    $this->actingAs($this->host)->putJson(route('rooms.setup.update', $this->room), ['mechanic' => $mechanic, 'difficulty' => $difficulty, 'rounds' => 5])->assertNoContent();
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    $game = $this->room->refresh()->latestGame()?->load('rounds');
    $level = app(ContentRepository::class)->roomLevel(RoomMechanic::from($mechanic), $difficulty);

    expect($game?->rounds)->toHaveCount(5);

    // The countdown: nothing of any round.
    ['state' => $state, 'wire' => $wire] = raceRead($this->aygun, $this->room);

    expect($state['round']['phase'])->toBe('countdown');

    foreach ($game->rounds ?? [] as $round) {
        foreach ([...$round->askData()->ids(), ...array_map(static fn ($item): string => $item->id, $round->askData()->shown)] as $id) {
            expect($wire)->not->toContain($id);
        }
    }

    $points = 0;

    foreach (range(0, 4) as $index) {
        $round = Rooms::round($game, $index);
        $ask = $round->askData();
        $shownIds = array_map(static fn ($item): string => $item->id, $ask->shown);

        expect($round->study_ms)->toBe(0)
            ->and($round->studyData())->toBeNull();

        // A moment before it opens: nothing of this round.
        $this->travelTo(Rooms::at($game, RoomPhase::Study, $index)->subMillisecond());
        ['wire' => $wire] = raceRead($this->aygun, $this->room);

        foreach ([...$ask->ids(), ...$shownIds] as $id) {
            expect($wire)->not->toContain($id);
        }

        // It opens with its question: no study phase, the row apart from the options.
        $this->travelTo(Rooms::at($game, RoomPhase::Study, $index));

        foreach ([$this->host, $this->aygun] as $account) {
            ['state' => $state, 'wire' => $wire] = raceRead($account, $this->room);
            $asked = $state['round']['ask'];

            expect($state['round']['phase'])->toBe('answer')
                ->and($state['round']['study'])->toBeNull()
                ->and($state['round']['answer_from_ms'])->toBe($state['round']['opens_ms'])
                ->and([$asked['shape'], $asked['pick'], $asked['layout'], $asked['grid']])->toBe(['choice', 1, 'row', null])
                ->and(array_column($asked['items'], 'id'))->toBe($ask->ids())
                ->and(array_column($asked['shown'], 'id'))->toBe($shownIds);
            raceWithholds($wire, $round);
        }

        if ($mechanic === 'next_in_sequence') {
            expect($asked['shown'])->toHaveCount($level->terms ?? 0)
                ->and($asked['items'])->toHaveCount(4)
                ->and(array_unique(array_column([...$asked['shown'], ...$asked['items']], 'kind')))->toBe(['number']);

            // A term of the row is shown, never an answer.
            raceAnswer($this->aygun, $this->room, $round, [$shownIds[0]])->assertUnprocessable();
        } else {
            expect($asked['shown'])->toBe([])
                ->and($asked['items'])->toHaveCount($level->options ?? 0)
                ->and(array_unique(array_column($asked['items'], 'kind')))->toBe([$level->source?->kind()->value]);
        }

        // Aygün chooses the right option, Rəşad another.
        raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertExactJson(['status' => 'accepted', 'revision' => $this->room->refresh()->revision]);
        raceAnswer($this->host, $this->room, $round, Rooms::wrong($round))->assertOk();
        $aygun = RoomAnswer::query()->where('room_round_id', $round->id)->where('room_player_id', $this->aygunRow->id)->sole();
        $points += $aygun->points;

        // Both answered: the round is revealed, the right option named by its id.
        ['state' => $state] = raceRead($this->aygun, $this->room);

        expect($state['round']['phase'])->toBe('reveal')
            ->and($state['round']['reveal']['solution'])->toBe(Rooms::right($round))
            ->and($state['round']['ask']['shown'])->toBe(array_map(static fn ($item): array => $item->toArray(), $ask->shown))
            ->and($state['round']['reveal']['mine'])->toMatchArray(['picked' => Rooms::right($round), 'correct' => true, 'points' => $aygun->points])
            ->and($state['round']['reveal']['results'])->toBe([
                ['player_id' => $this->hostRow->id, 'correct' => false, 'points' => 0],
                ['player_id' => $this->aygunRow->id, 'correct' => true, 'points' => $aygun->points],
            ]);
    }

    // Over: Aygün first with every round's points, Rəşad after the places without one.
    $this->travelTo(Rooms::at($game, RoomPhase::Over));
    ['state' => $state] = raceRead($this->host, $this->room);

    expect($state['game'])->toMatchArray(['mechanic' => $mechanic, 'difficulty' => $difficulty, 'phase' => 'over'])
        ->and($points)->toBeGreaterThanOrEqual(50)
        ->and($state['ranking'])->toBe([
            ['player_id' => $this->aygunRow->id, 'points' => $points, 'place' => 1],
            ['player_id' => $this->hostRow->id, 'points' => 0, 'place' => null],
        ]);
})->with([
    'a row of numbers' => ['next_in_sequence', 3],
    'the hardest rows' => ['next_in_sequence', 4],
    'words' => ['odd_one_out', 1],
    'numbers' => ['odd_one_out', 2],
]);

// B5 added `shown` to the question: a round stored before it reads as one
// that shows nothing beside its items.
it('reads a round stored before the question had shown items', function (): void {
    $game = Rooms::playing($this->room);
    $round = Rooms::round($game, 0);
    $payload = DB::table('room_rounds')->where('id', $round->id)->value('payload');
    $stored = json_decode(is_string($payload) ? $payload : '{}', true);
    unset($stored['ask']['shown']);
    DB::table('room_rounds')->where('id', $round->id)->update(['payload' => json_encode($stored)]);

    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    ['state' => $state] = raceRead($this->aygun, $this->room);

    expect(array_key_exists('shown', $stored['ask']))->toBeFalse()
        ->and($state['round']['ask']['shown'])->toBe([])
        ->and(array_column($state['round']['ask']['items'], 'id'))->toBe($round->askData()->ids());

    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertJsonPath('status', 'accepted');
});

// B6: «Anaqram» and «Düzgün yazılış» (§12 "Room games"). Nothing is
// studied; the question opens with the round. Before the reveal no state
// holds the word or marks an option: an anagram's tiles never spell a word
// they make, and a spelling's four options carry the same keys.
it('plays a word game to its ranking', function (string $mechanic, int $difficulty): void {
    $this->actingAs($this->host)->putJson(route('rooms.setup.update', $this->room), ['mechanic' => $mechanic, 'difficulty' => $difficulty, 'rounds' => 5])->assertNoContent();
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    $game = $this->room->refresh()->latestGame()?->load('rounds');
    $level = app(ContentRepository::class)->roomLevel(RoomMechanic::from($mechanic), $difficulty);

    expect($game?->mechanic)->toBe(RoomMechanic::from($mechanic))
        ->and($game?->rounds)->toHaveCount(5);

    // The countdown: nothing of any round.
    ['state' => $state, 'wire' => $wire] = raceRead($this->aygun, $this->room);

    expect($state['game'])->toMatchArray(['mechanic' => $mechanic, 'difficulty' => $difficulty, 'phase' => 'live'])
        ->and($state['round']['phase'])->toBe('countdown');

    foreach ($game->rounds ?? [] as $round) {
        foreach (raceIds($round)['ask'] as $id) {
            expect($wire)->not->toContain($id);
        }
    }

    $points = 0;

    foreach (range(0, 4) as $index) {
        $round = Rooms::round($game, $index);
        $solution = $round->solutionData();
        $word = (string) $solution->word;

        // The round opens on its question: no study phase, no material.
        $this->travelTo(Rooms::at($game, RoomPhase::Study, $index));

        foreach ([$this->host, $this->aygun] as $account) {
            ['state' => $state, 'wire' => $wire] = raceRead($account, $this->room);
            $ask = $state['round']['ask'];

            expect($state['round']['phase'])->toBe('answer')
                ->and($state['round']['opens_ms'])->toBe($state['round']['answer_from_ms'])
                ->and($state['round']['study'])->toBeNull();
            raceWithholds($wire, $round);

            if ($mechanic === 'anagram') {
                // The letters in capitals, never in the order of a word they spell.
                expect([$ask['shape'], $ask['pick'], $ask['layout']])->toBe(['sequence', mb_strlen($word), 'row'])
                    ->and(array_unique(array_column($ask['items'], 'kind')))->toBe(['letter'])
                    ->and(mb_str_split(implode('', array_column($ask['items'], 'text'))))->toEqualCanonicalizing(mb_str_split($word))
                    ->and(mb_strlen($word))->toBeGreaterThanOrEqual($level->wordLength->min ?? 0)->toBeLessThanOrEqual($level->wordLength->max ?? 0);

                foreach ($solution->accept as $reading) {
                    $spelled = implode('', $reading);

                    expect(implode('', array_column($ask['items'], 'text')))->not->toBe($spelled)
                        ->and($wire)->not->toContain($spelled)
                        ->and($wire)->not->toContain(Az::lower($spelled));
                }
            } else {
                // Four spellings of one word; nothing marks the right one.
                expect([$ask['shape'], $ask['pick'], $ask['layout']])->toBe(['choice', 1, 'row'])
                    ->and($ask['items'])->toHaveCount(4)
                    ->and(array_unique(array_column($ask['items'], 'kind')))->toBe(['word'])
                    ->and(array_column($ask['items'], 'text'))->toContain($word);

                foreach ($ask['items'] as $item) {
                    expect(array_keys($item))->toBe(['id', 'kind', 'text', 'asset', 'cell']);
                }
            }
        }

        // Aygün is right — with another word the letters spell, when there is one — and Rəşad wrong.
        $right = Rooms::right($round);

        if ($mechanic === 'anagram' && count($solution->accept) > 1) {
            $left = $round->askData()->items;
            $right = [];

            foreach ($solution->accept[1] as $letter) {
                $at = array_key_first(array_filter($left, static fn ($item): bool => $item->text === $letter));
                $right[] = $left[$at]->id;
                unset($left[$at]);
            }
        }

        raceAnswer($this->aygun, $this->room, $round, $right)->assertExactJson(['status' => 'accepted', 'revision' => $this->room->refresh()->revision]);
        raceAnswer($this->host, $this->room, $round, Rooms::wrong($round))->assertOk();
        $aygun = RoomAnswer::query()->where('room_round_id', $round->id)->where('room_player_id', $this->aygunRow->id)->sole();
        $points += $aygun->points;

        // Both answered: the reveal names the solution and the word.
        ['state' => $state] = raceRead($this->aygun, $this->room);

        expect($state['round']['phase'])->toBe('reveal')
            ->and($state['round']['reveal']['solution'])->toBe($solution->ids)
            ->and($state['round']['reveal']['word'])->toBe($word)
            ->and($state['round']['reveal']['mine'])->toMatchArray(['picked' => $right, 'correct' => true, 'points' => $aygun->points])
            ->and($state['round']['reveal']['results'])->toBe([
                ['player_id' => $this->hostRow->id, 'correct' => false, 'points' => 0],
                ['player_id' => $this->aygunRow->id, 'correct' => true, 'points' => $aygun->points],
            ]);
    }

    // Over: Aygün first with every round's points, Rəşad after the places without one.
    $this->travelTo(Rooms::at($game, RoomPhase::Over));
    ['state' => $state] = raceRead($this->host, $this->room);

    expect($state['game']['phase'])->toBe('over')
        ->and($points)->toBeGreaterThanOrEqual(50)
        ->and($state['ranking'])->toBe([
            ['player_id' => $this->aygunRow->id, 'points' => $points, 'place' => 1],
            ['player_id' => $this->hostRow->id, 'points' => 0, 'place' => null],
        ]);
})->with([
    'anagram' => ['anagram', 1],
    'spelling' => ['spelling', 3],
]);

// A player never types (ADR 023 decision 4): an answer is item ids of the
// round, and nothing else a body carries is stored.
it('stores no text a player wrote', function (string $mechanic): void {
    $this->actingAs($this->host)->putJson(route('rooms.setup.update', $this->room), ['mechanic' => $mechanic, 'difficulty' => 2, 'rounds' => 5])->assertNoContent();
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    $game = $this->room->refresh()->latestGame()?->load('rounds');
    $round = Rooms::round($game, 0);
    $this->travelTo(Rooms::at($game, RoomPhase::Answer));
    $before = raceSnapshot();
    $typed = 'Salam, mən Aygünəm';

    // A word typed as the answer is no item of the round: refused, nothing written.
    foreach ([[(string) $round->solutionData()->word], [Az::lower((string) $round->solutionData()->word)], ['aeroport'], [$typed]] as $picked) {
        raceAnswer($this->aygun, $this->room, $round, $picked)->assertUnprocessable();
    }

    expect(raceSnapshot())->toBe($before);

    // Text beside the item ids is never read.
    raceAnswer($this->aygun, $this->room, $round, Rooms::right($round), extra: ['text' => $typed, 'word' => $typed, 'answer' => $typed, 'note' => $typed])
        ->assertJsonPath('status', 'accepted');

    $answer = RoomAnswer::query()->sole();
    $stored = json_encode(raceSnapshot(), JSON_UNESCAPED_UNICODE);

    expect($answer->picked)->toBe(Rooms::right($round))
        ->and($stored)->not->toContain('Salam')
        ->and($stored)->not->toContain('Aygünəm');
})->with(['anagram', 'spelling']);

// Live voice (C3, ADR 031 decision 24): the voice is a second connection, and
// nothing of a round asks the voice server anything — so one that is down
// moves no round, no clock and no point.
it('plays a whole game with the voice on without one request to the voice server from an answer, a read or the state', function (): void {
    $voice = Rooms::voiceOn($this->reverb);
    $this->actingAs($this->host)->putJson(route('rooms.voice.update', $this->room), ['on' => true])->assertNoContent();
    $this->actingAs($this->aygun)->postJson(route('rooms.voice.tickets.store', $this->room), ['agree' => true])->assertOk();
    $calls = $voice->sent;
    $outcomes = [];

    // One game with the voice server answering, the same game with it down.
    foreach (['answering', 'down'] as $server) {
        if ($server === 'down') {
            $voice->down();
        }

        $this->actingAs($this->host)->putJson(route('rooms.setup.update', $this->room), ['mechanic' => 'recall_order', 'difficulty' => 1, 'rounds' => 5])->assertNoContent();
        $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
        $game = $this->room->refresh()->latestGame();
        expect($game)->not->toBeNull();
        /** @var RoomGame $game */
        $outcome = [];

        foreach (range(0, 4) as $index) {
            $round = Rooms::round($game, $index);
            $this->travelTo(Rooms::at($game, RoomPhase::Answer, $index));
            raceRead($this->host, $this->room);
            raceRead($this->aygun, $this->room);
            $this->travelTo(RoomClock::at(RoomClock::nowMs() + 1200));

            raceAnswer($this->aygun, $this->room, $round, Rooms::right($round))->assertOk()->assertJsonPath('status', 'accepted');
            raceAnswer($this->host, $this->room, $round, Rooms::wrong($round))->assertOk()->assertJsonPath('status', 'accepted');

            // Both answered: the round closes now, and its reveal is the one a game without voice shows.
            $this->travelTo(Rooms::at($game, RoomPhase::Reveal, $index));
            ['state' => $state] = raceRead($this->aygun, $this->room);
            expect($state['round'])->toMatchArray(['index' => $index, 'phase' => 'reveal', 'answered_count' => 2, 'present_count' => 2])
                ->and($state['voice'])->toBe(['on' => true, 'agreed' => true]);
            $outcome[] = array_map(static fn (array $result): array => [$result['correct'], $result['points']], $state['round']['reveal']['results']);
        }

        $this->travelTo(Rooms::at($game, RoomPhase::Over));
        ['state' => $state] = raceRead($this->aygun, $this->room);
        expect($state['game']['phase'])->toBe('over');
        $outcomes[$server] = $outcome;
        $this->travel(1)->minutes();
    }

    // A game started and ended by the host asks nothing either.
    $this->actingAs($this->host)->postJson(route('rooms.games.store', $this->room))->assertNoContent();
    $this->actingAs($this->host)->deleteJson(route('rooms.game.destroy', $this->room))->assertNoContent();

    expect($voice->sent)->toBe($calls)
        ->and($outcomes['down'])->toBe($outcomes['answering']);
});
