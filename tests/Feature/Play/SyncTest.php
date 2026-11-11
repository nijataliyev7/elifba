<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Play\Data\SyncResultData;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Enums\HitSource;
use App\Domain\Play\Events\GameSessionTransitioned;
use App\Domain\Play\Events\RoundSolved;
use App\Domain\Play\Models\GameAnswer;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Support\SchoolCalendar;
use App\Domain\Rankings\Support\ClassRanking;
use App\Http\Middleware\ThrottleBoardEvents;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\Games;

/*
 * POST /games/{game}/sync (docs/architecture.md §4): the board's outbox,
 * stored once, judged by RoundJudge, scored by PointsRule, dated by the
 * Baku calendar. Ids in the body are untrusted.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create();
    $this->aysel = Student::factory()->for($this->class)->create(['display_name' => 'Aysel']);
    $this->game = Games::start($this->class);
    $this->actingAs($this->teacher);
});

/**
 * @param  list<array<string, mixed>>  $events
 */
function sync(GameSession $game, array $events, ?int $epoch = null): TestResponse
{
    return test()->postJson(route('games.sync', $game), [
        'board_epoch' => $epoch ?? $game->fresh()?->board_epoch,
        'events' => $events,
    ]);
}

/**
 * @return list<array{event_id: string, status: string, round_solved: bool, points: int|null}>
 */
function results(TestResponse $response): array
{
    $response->assertOk();

    /** @var list<array{event_id: string, status: string, round_solved: bool, points: int|null}> */
    return $response->json('results');
}

/**
 * Everything a sync could change.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function playSnapshot(): array
{
    $rows = static fn (string $table): array => DB::table($table)->orderBy('id')->get()
        ->map(static fn (object $row): array => (array) $row)->all();

    return ['game_sessions' => $rows('game_sessions'), 'game_rounds' => $rows('game_rounds'), 'game_answers' => $rows('game_answers')];
}

describe('hits', function (): void {
    it('stores a hit that solves the round and credits the student', function (): void {
        Event::fake([RoundSolved::class]);
        $round = Games::round($this->game);
        $hit = Games::hit($round, Games::target($round), $this->aysel->id, source: 'remote');

        $response = sync($this->game, [$hit]);

        expect(results($response))->toBe([
            ['event_id' => $hit['event_id'], 'status' => 'correct', 'round_solved' => true, 'points' => 3],
        ])->and($response->json('game'))->toBe(['status' => 'live', 'board_epoch' => 0]);

        $round->refresh();
        $answer = GameAnswer::query()->sole();
        $item = collect($round->payload['items'])->firstWhere('id', Games::target($round));

        expect($round->solved_at)->not->toBeNull()
            ->and($round->student_id)->toBe($this->aysel->id)
            ->and($round->points)->toBe(3)
            ->and($round->progress)->toBe([Games::target($round)])
            ->and($round->misses)->toBe(0)
            ->and($answer->event_id)->toBe($hit['event_id'])
            ->and($answer->game_round_id)->toBe($round->id)
            ->and($answer->student_id)->toBe($this->aysel->id)
            ->and($answer->item_id)->toBe(Games::target($round))
            ->and($answer->content_key)->toBe($item['content_key'])
            ->and($answer->correct)->toBeTrue()
            ->and($answer->source)->toBe(HitSource::Remote)
            ->and($answer->board_epoch)->toBe(0)
            // Stored to the second: enough to see how long a hit waited in the outbox.
            ->and($answer->client_at?->toIso8601ZuluString())->toBe('2026-11-09T08:15:30Z');

        Event::assertDispatched(RoundSolved::class, fn (RoundSolved $event): bool => $event->roundId === $round->id
            && $event->gameId === $this->game->id
            && $event->schoolClassId === $this->class->id
            && $event->studentId === $this->aysel->id
            && $event->points === 3);
    });

    it('gives 3, 2 or 1 points by the misses before the solving hit', function (int $misses, int $points): void {
        $round = Games::round($this->game);
        $events = [];

        foreach (range(1, $misses) as $index) {
            if ($misses > 0) {
                $events[] = Games::hit($round, Games::decoy($round, $index % 3), $this->aysel->id);
            }
        }

        $events = array_slice($events, 0, $misses);
        $events[] = Games::hit($round, Games::target($round), $this->aysel->id);

        $results = results(sync($this->game, $events));

        expect(array_column($results, 'status'))->toBe([...array_fill(0, $misses, 'miss'), 'correct'])
            ->and(end($results))->toMatchArray(['round_solved' => true, 'points' => $points])
            ->and($round->fresh()?->misses)->toBe($misses)
            ->and($round->fresh()?->points)->toBe($points)
            ->and(GameAnswer::query()->where('correct', false)->count())->toBe($misses);
    })->with([
        'no miss' => [0, 3],
        'one miss' => [1, 2],
        'two misses' => [2, 1],
        'four misses, the same decoy twice' => [4, 1],
    ]);

    it('solves a pop round when its four items are hit in any order, with no miss and 3 points that count in the ranking', function (): void {
        Event::fake([RoundSolved::class]);
        $game = Games::start($this->class, ['mechanic' => 'pop', 'level' => 'words-short']);
        $round = Games::round($game);
        [$a, $b, $c, $d] = array_column($round->payload['items'], 'id');
        $order = [$c, $a, $d, $b];
        $events = array_map(fn (string $id): array => Games::hit($round, $id, $this->aysel->id, source: 'remote'), $order);

        $results = results(sync($game, $events));

        expect($round->payload['solution'])->toBe(['order' => 'any', 'item_ids' => [$a, $b, $c, $d]])
            ->and(array_map(static fn (array $result): array => [$result['status'], $result['round_solved'], $result['points']], $results))->toBe([
                ['correct', false, null],
                ['correct', false, null],
                ['correct', false, null],
                ['correct', true, 3],
            ]);

        $round->refresh();

        expect($round->progress)->toBe($order)
            ->and($round->misses)->toBe(0)
            ->and($round->points)->toBe(3)
            ->and($round->student_id)->toBe($this->aysel->id)
            ->and($round->solved_at)->not->toBeNull()
            ->and(GameAnswer::query()->where('correct', true)->count())->toBe(4)
            ->and(GameAnswer::query()->where('correct', false)->count())->toBe(0)
            ->and(Games::round($game, 1)->solved_at)->toBeNull();

        Event::assertDispatchedTimes(RoundSolved::class, 1);
        Event::assertDispatched(RoundSolved::class, fn (RoundSolved $event): bool => $event->roundId === $round->id
            && $event->studentId === $this->aysel->id
            && $event->points === 3);

        $ranked = ClassRanking::day($this->class, SchoolCalendar::today());

        expect([$ranked[0]->student->id, $ranked[0]->points, $ranked[0]->place])->toBe([$this->aysel->id, 3, 1]);
    });

    it('gives a count round its points: a wrong number is a miss, N solves it with one point less', function (): void {
        Event::fake([RoundSolved::class]);
        $game = Games::start($this->class, ['mechanic' => 'count', 'pack' => 'fruit', 'level' => 'numbers-5']);
        $round = Games::round($game);
        $answer = Games::target($round);
        $wrong = Games::decoy($round);
        $n = $round->payload['prompt']['count'];

        $results = results(sync($game, [
            Games::hit($round, $wrong, $this->aysel->id),
            Games::hit($round, $answer, $this->aysel->id),
        ]));

        expect(collect($round->payload['items'])->firstWhere('id', $answer)['text'] ?? null)->toBe((string) $n)
            ->and(array_map(static fn (array $result): array => [$result['status'], $result['round_solved'], $result['points']], $results))->toBe([
                ['miss', false, null],
                ['correct', true, 2],
            ])
            ->and($round->fresh()?->misses)->toBe(1)
            ->and($round->fresh()?->points)->toBe(2)
            ->and(GameAnswer::query()->where('correct', false)->sole()->content_key)->toStartWith('number:');

        Event::assertDispatched(RoundSolved::class, fn (RoundSolved $event): bool => $event->roundId === $round->id
            && $event->studentId === $this->aysel->id
            && $event->points === 2);
    });

    it('stores the points of a whole-class round but credits no one', function (): void {
        $round = Games::round($this->game);

        expect(results(sync($this->game, [Games::hit($round, Games::target($round))]))[0])
            ->toMatchArray(['status' => 'correct', 'round_solved' => true, 'points' => 3]);

        expect($round->fresh()?->points)->toBe(3)
            ->and($round->fresh()?->student_id)->toBeNull()
            ->and(GameAnswer::query()->sole()->student_id)->toBeNull();
    });

    it('stores a student who is not an active student of the class as nobody', function (Closure $student): void {
        $round = Games::round($this->game);
        $studentId = $student($this);

        expect(results(sync($this->game, [
            Games::hit($round, Games::decoy($round), $studentId),
            Games::hit($round, Games::target($round), $studentId),
        ]))[1])->toMatchArray(['status' => 'correct', 'points' => 2]);

        expect($round->fresh()?->student_id)->toBeNull()
            ->and(GameAnswer::query()->whereNotNull('student_id')->count())->toBe(0);
    })->with([
        'another teacher\'s student' => [fn ($test): string => Student::factory()->create()->id],
        'a student of the teacher\'s other class' => [fn ($test): string => Student::factory()->for(SchoolClass::factory()->for($test->teacher, 'teacher'))->create()->id],
        'an archived student of the class' => [fn ($test): string => Student::factory()->for($test->class)->archived()->create()->id],
        'an unknown ulid' => [fn ($test): string => (string) Str::ulid()],
        'not an id' => [fn ($test): string => "x' OR '1'='1"],
    ]);

    it('answers each event in order, across rounds', function (): void {
        [$first, $second] = Games::rounds($this->game);
        $events = [
            Games::hit($first, Games::decoy($first), $this->aysel->id),
            Games::hit($first, Games::target($first), $this->aysel->id),
            Games::hit($second, Games::target($second), $this->aysel->id),
        ];

        expect(array_map(static fn (array $result): array => [$result['event_id'], $result['status'], $result['points']], results(sync($this->game, $events))))->toBe([
            [$events[0]['event_id'], 'miss', null],
            [$events[1]['event_id'], 'correct', 2],
            [$events[2]['event_id'], 'correct', 3],
        ]);
    });

    it('keeps the game active', function (): void {
        $this->travelTo(now()->addHours(2)->startOfSecond());
        $round = Games::round($this->game);

        sync($this->game, [Games::hit($round, Games::decoy($round))]);

        expect($this->game->fresh()?->last_activity_at->equalTo(now()))->toBeTrue();
    });

    it('answers in the shape of the contract', function (): void {
        $round = Games::round($this->game);
        $json = sync($this->game, [Games::hit($round, Games::target($round)), Games::skip(Games::round($this->game, 1))])->json();

        expect(SyncResultData::fromArray($json)->toArray())->toBe($json);
    });
});

describe('duplicates', function (): void {
    it('stores an event once and repeats its first result', function (): void {
        $round = Games::round($this->game);
        $miss = Games::hit($round, Games::decoy($round), $this->aysel->id);
        $solve = Games::hit($round, Games::target($round), $this->aysel->id);

        results(sync($this->game, [$miss, $solve]));
        $before = playSnapshot();

        expect(results(sync($this->game, [$solve, $miss])))->toBe([
            ['event_id' => $solve['event_id'], 'status' => 'duplicate', 'round_solved' => true, 'points' => 2],
            ['event_id' => $miss['event_id'], 'status' => 'duplicate', 'round_solved' => false, 'points' => null],
        ])->and(playSnapshot())->toBe($before);
    });

    it('stores a repeated event of the same batch once', function (): void {
        $round = Games::round($this->game);
        $miss = Games::hit($round, Games::decoy($round));

        expect(array_column(results(sync($this->game, [$miss, $miss])), 'status'))->toBe(['miss', 'duplicate'])
            ->and($round->fresh()?->misses)->toBe(1)
            ->and(GameAnswer::query()->count())->toBe(1);
    });

    it('answers duplicate when the same event was stored by a request that committed first', function (): void {
        $round = Games::round($this->game);
        $hit = Games::hit($round, Games::target($round), $this->aysel->id);
        $raced = false;
        $committed = false;

        // This request's insert loses the race on the unique event_id…
        GameAnswer::creating(function () use (&$raced): void {
            if (! $raced) {
                $raced = true;

                throw new UniqueConstraintViolationException('testing', 'insert into "game_answers"', [], new PDOException('UNIQUE constraint failed: game_answers.event_id'));
            }
        });

        // …to the other request, whose answer is there once this one rolls back.
        Event::listen(TransactionRolledBack::class, function () use (&$committed, $round, $hit): void {
            if ($committed) {
                return;
            }

            $committed = true;
            GameAnswer::factory()->for($round, 'round')->create([
                'event_id' => $hit['event_id'],
                'item_id' => $hit['item_id'],
                'correct' => true,
                'student_id' => $this->aysel->id,
            ]);
            $round->forceFill(['solved_at' => now(), 'student_id' => $this->aysel->id, 'points' => 3, 'progress' => [$hit['item_id']]])->save();
        });

        expect(results(sync($this->game, [$hit])))->toBe([
            ['event_id' => $hit['event_id'], 'status' => 'duplicate', 'round_solved' => true, 'points' => 3],
        ])->and(GameAnswer::query()->count())->toBe(1)
            ->and($raced)->toBeTrue()
            ->and($committed)->toBeTrue();
    });

    it('does not report another game\'s result for an event id taken there', function (): void {
        $other = Games::start($this->class, seed: 2);
        $otherRound = Games::round($other);
        $hit = Games::hit($otherRound, Games::target($otherRound));
        results(sync($other, [$hit]));

        $round = Games::round($this->game);
        $before = playSnapshot();

        expect(results(sync($this->game, [[...$hit, 'round_id' => $round->id]])))->toBe([
            ['event_id' => $hit['event_id'], 'status' => 'duplicate', 'round_solved' => false, 'points' => null],
        ])->and(playSnapshot())->toBe($before);
    });
});

describe('stale events', function (): void {
    it('writes nothing for a round that is not in this game', function (Closure $roundId): void {
        $id = $roundId($this);
        $before = playSnapshot();

        expect(results(sync($this->game, [Games::hit($id, 'a'), Games::skip($id)])))->each->toMatchArray(['status' => 'stale', 'round_solved' => false, 'points' => null])
            ->and(playSnapshot())->toBe($before);
    })->with([
        'a round of the teacher\'s other game' => [fn ($test): string => Games::round(Games::start($test->class, seed: 3))->id],
        'a round of another teacher\'s game' => [fn ($test): string => Games::round(Games::start(SchoolClass::factory()->create(), seed: 4))->id],
        'an unknown ulid' => [fn ($test): string => (string) Str::ulid()],
        'not an id' => [fn ($test): string => "x' OR '1'='1"],
    ]);

    it('writes nothing for an item that is not in the round, or a hit after the round is solved', function (): void {
        $round = Games::round($this->game);
        results(sync($this->game, [Games::hit($round, Games::target($round))]));
        $before = playSnapshot();

        expect(array_column(results(sync($this->game, [
            Games::hit($round, Games::decoy($round)),
            Games::hit($round, Games::target($round)),
            Games::hit(Games::round($this->game, 1), 'zz'),
        ])), 'status'))->toBe(['stale', 'stale', 'stale'])
            ->and(playSnapshot())->toBe($before);
    });
});

describe('skips', function (): void {
    it('skips a round with no points, once', function (): void {
        $round = Games::round($this->game);
        $skip = Games::skip($round);

        expect(results(sync($this->game, [$skip]))[0])->toBe(['event_id' => $skip['event_id'], 'status' => 'skipped', 'round_solved' => false, 'points' => null]);

        $round->refresh();
        expect($round->skipped_at)->not->toBeNull()
            ->and($round->solved_at)->toBeNull()
            ->and($round->points)->toBe(0)
            ->and($round->played_on)->toBeNull();

        $before = playSnapshot();

        expect(array_column(results(sync($this->game, [$skip, Games::skip($round), Games::hit($round, Games::target($round))])), 'status'))
            ->toBe(['duplicate', 'duplicate', 'stale'])
            ->and(playSnapshot())->toBe($before);
    });

    it('does not skip a solved round', function (): void {
        $round = Games::round($this->game);

        expect(array_column(results(sync($this->game, [Games::hit($round, Games::target($round)), Games::skip($round)])), 'status'))->toBe(['correct', 'stale'])
            ->and($round->fresh()?->skipped_at)->toBeNull()
            ->and($round->fresh()?->points)->toBe(3);
    });
});

describe('the end of a game', function (): void {
    it('finishes the game when no open round is left, after the commit', function (): void {
        Event::fake([GameSessionTransitioned::class, RoundSolved::class]);
        $rounds = Games::rounds($this->game);
        $events = array_map(static fn (GameRound $round): array => Games::hit($round, Games::target($round)), array_slice($rounds, 0, 4));
        $events[] = Games::skip($rounds[4]);
        $late = Games::hit($rounds[4], Games::target($rounds[4]));
        $events[] = $late;

        $response = sync($this->game, $events);

        expect(array_column(results($response), 'status'))->toBe(['correct', 'correct', 'correct', 'correct', 'skipped', 'stale'])
            ->and($response->json('game'))->toBe(['status' => 'finished', 'board_epoch' => 0])
            ->and($this->game->fresh()?->status)->toBe(GameSessionStatus::Finished)
            ->and($this->game->fresh()?->ended_at)->not->toBeNull();

        Event::assertDispatchedTimes(RoundSolved::class, 4);
        Event::assertDispatched(GameSessionTransitioned::class, fn (GameSessionTransitioned $event): bool => $event->gameId === $this->game->id
            && $event->from === GameSessionStatus::Live
            && $event->to === GameSessionStatus::Finished);
    });

    it('never writes to a finished or abandoned game', function (GameSessionStatus $status): void {
        $this->game->forceFill(['status' => $status, 'ended_at' => now()])->save();
        $round = Games::round($this->game);
        $before = playSnapshot();

        $response = sync($this->game, [Games::hit($round, Games::target($round)), Games::skip(Games::round($this->game, 1))]);

        expect(array_column(results($response), 'status'))->toBe(['stale', 'stale'])
            ->and($response->json('game.status'))->toBe($status->value)
            ->and(playSnapshot())->toBe($before);
    })->with([GameSessionStatus::Finished, GameSessionStatus::Abandoned]);

    it('still takes the events of a paused game, made before the pause reached the board', function (): void {
        $this->game->forceFill(['status' => GameSessionStatus::Paused])->save();
        $round = Games::round($this->game);

        expect(results(sync($this->game, [Games::hit($round, Games::target($round))]))[0]['status'])->toBe('correct')
            ->and($this->game->fresh()?->status)->toBe(GameSessionStatus::Paused);
    });
});

describe('fencing', function (): void {
    it('refuses a board with an old epoch and writes nothing', function (): void {
        $this->postJson(route('games.board.claim', $this->game))->assertOk()->assertExactJson(['game' => ['status' => 'live', 'board_epoch' => 1]]);
        $this->postJson(route('games.board.claim', $this->game))->assertOk()->assertExactJson(['game' => ['status' => 'live', 'board_epoch' => 2]]);
        $round = Games::round($this->game);
        $before = playSnapshot();

        sync($this->game, [Games::hit($round, Games::target($round))], epoch: 1)
            ->assertStatus(409)
            ->assertExactJson(['error' => 'board_replaced', 'game' => ['status' => 'live', 'board_epoch' => 2]]);

        expect(playSnapshot())->toBe($before);

        expect(results(sync($this->game, [Games::hit($round, Games::target($round))], epoch: 2))[0]['status'])->toBe('correct');
    });

    it('refuses even the old board\'s duplicates', function (): void {
        $round = Games::round($this->game);
        $hit = Games::hit($round, Games::target($round));
        results(sync($this->game, [$hit], epoch: 0));
        $this->postJson(route('games.board.claim', $this->game))->assertOk();

        sync($this->game, [$hit], epoch: 0)->assertStatus(409);
    });
});

describe('malformed bodies', function (): void {
    it('refuses the whole request only when its shape is wrong', function (Closure $body): void {
        $before = playSnapshot();

        $this->postJson(route('games.sync', $this->game), $body(Games::round($this->game)))->assertUnprocessable();

        expect(playSnapshot())->toBe($before);
    })->with([
        'no epoch' => [fn (GameRound $round): array => ['events' => [Games::hit($round, 'a')]]],
        'no events' => [fn (GameRound $round): array => ['board_epoch' => 0, 'events' => []]],
        'more than 50 events' => [fn (GameRound $round): array => ['board_epoch' => 0, 'events' => array_map(fn (): array => Games::hit($round, 'a'), range(1, 51))]],
        'an event id that is not a ulid' => [fn (GameRound $round): array => ['board_epoch' => 0, 'events' => [Games::hit($round, 'a', eventId: 'event-1')]]],
        'an unknown event type' => [fn (GameRound $round): array => ['board_epoch' => 0, 'events' => [[...Games::hit($round, 'a'), 'type' => 'tap']]]],
        'an unknown source' => [fn (GameRound $round): array => ['board_epoch' => 0, 'events' => [Games::hit($round, 'a', source: 'pen')]]],
        'a hit without an item' => [fn (GameRound $round): array => ['board_epoch' => 0, 'events' => [array_diff_key(Games::hit($round, 'a'), ['item_id' => true])]]],
        'a hit without its student key' => [fn (GameRound $round): array => ['board_epoch' => 0, 'events' => [array_diff_key(Games::hit($round, 'a'), ['student_id' => true])]]],
        'a client time that is not a time' => [fn (GameRound $round): array => ['board_epoch' => 0, 'events' => [[...Games::hit($round, 'a'), 'client_at' => 'soon']]]],
    ]);

    it('takes a skip without the keys only a hit has', function (): void {
        expect(results(sync($this->game, [Games::skip(Games::round($this->game))]))[0]['status'])->toBe('skipped');
    });
});

describe('Baku dates', function (): void {
    it('dates a solved round by the Baku calendar', function (string $utc, string $playedOn, string $weekStart): void {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
        $round = Games::round($this->game);

        results(sync($this->game, [Games::hit($round, Games::target($round), $this->aysel->id)]));

        $stored = DB::table('game_rounds')->where('id', $round->id)->first(['played_on', 'week_start']);

        // Stored as the bare date on every engine, so rankings can compare it.
        expect((array) $stored)->toBe(['played_on' => $playedOn, 'week_start' => $weekStart])
            ->and($round->fresh()?->played_on)->toBe($playedOn)
            ->and(GameRound::query()->where('played_on', $playedOn)->where('week_start', $weekStart)->count())->toBe(1);
    })->with([
        'before Baku midnight' => ['2026-11-10 19:59:59', '2026-11-10', '2026-11-09'],
        'at Baku midnight' => ['2026-11-10 20:00:00', '2026-11-11', '2026-11-09'],
        'Sunday night in Baku' => ['2026-11-15 19:59:59', '2026-11-15', '2026-11-09'],
        'Monday in Baku, Sunday in UTC' => ['2026-11-15 20:00:01', '2026-11-16', '2026-11-16'],
    ]);
});

describe('throttling', function (): void {
    it('takes 240 events a minute per game, counting events', function (): void {
        $round = Games::round($this->game, 1);
        $batch = fn (int $count): array => array_map(fn (): array => Games::hit($round, 'zz'), range(1, $count));

        foreach (range(1, 4) as $request) {
            sync($this->game, $batch(50))->assertOk();
        }

        sync($this->game, $batch(40))->assertOk();
        sync($this->game, $batch(1))->assertTooManyRequests()->assertExactJson(['error' => 'too_many_events'])->assertHeader('Retry-After');

        // Another game has its own budget.
        sync(Games::start($this->class, seed: 9), $batch(1))->assertOk();

        $this->travel(61)->seconds();
        sync($this->game, $batch(50))->assertOk();
    });

    it('is the limit the architecture names', function (): void {
        expect(ThrottleBoardEvents::EVENTS_PER_MINUTE)->toBe(240);
    });
});
