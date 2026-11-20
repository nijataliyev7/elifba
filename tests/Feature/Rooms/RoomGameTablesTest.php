<?php

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAnswer;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomTimetable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The tables of a room game (docs/architecture.md §1 "The five tables of
 * `Rooms`", ADRs 021–022): moments in whole milliseconds on every engine,
 * a solution no serialization of a round shows, one answer per player per
 * round and per event, and no column for a name or for text a person
 * typed. Run on SQLite and on PostgreSQL 17 (`composer test:pgsql`).
 * From D3 a board game's seats and clock on its row, and its acts
 * (`room_acts`, ADR 034 decision 20): squares by name, never a position.
 */

beforeEach(function (): void {
    $this->travelTo('2026-11-24 18:00:00');
});

it('holds the columns of the contract and nothing about a person', function (): void {
    expect(Schema::getColumnListing('room_games'))->toEqualCanonicalizing(['id', 'room_id', 'position', 'mechanic', 'difficulty', 'content_version', 'seed', 'reveal_ms', 'started_ms', 'ended_ms', 'white_player_id', 'black_player_id', 'clock_ms', 'increment_ms', 'created_at'])
        ->and(Schema::getColumnListing('room_acts'))->toEqualCanonicalizing(['id', 'event_id', 'room_game_id', 'room_player_id', 'kind', 'ply', 'path', 'acted_ms'])
        ->and(Schema::getColumnListing('room_rounds'))->toEqualCanonicalizing(['id', 'room_game_id', 'position', 'payload', 'solution', 'study_ms', 'answer_ms', 'closed_ms'])
        ->and(Schema::getColumnListing('room_answers'))->toEqualCanonicalizing(['id', 'event_id', 'room_round_id', 'room_player_id', 'picked', 'correct', 'elapsed_ms', 'points', 'created_at']);
});

it('keeps moments as whole milliseconds beyond 32 bits on every engine', function (): void {
    $started = 4_102_444_800_123; // 2100-01-01 00:00:00.123
    $game = RoomGame::factory()->create(['started_ms' => $started, 'ended_ms' => $started + 98_765]);
    $round = RoomRound::factory()->for($game, 'game')->closedAt($started + 7_001)->create();

    $game = RoomGame::query()->findOrFail($game->id);
    $round = RoomRound::query()->findOrFail($round->id);

    expect($game->started_ms)->toBe($started)
        ->and($game->ended_ms)->toBe($started + 98_765)
        ->and($game->reveal_ms)->toBe(config('elifba.rooms.reveal_ms'))
        ->and($game->mechanic)->toBe(RoomMechanic::RecallOrder)
        ->and($round->closed_ms)->toBe($started + 7_001)
        ->and($round->study_ms)->toBeInt()
        ->and($round->answer_ms)->toBeInt();

    // As the engine gives them back, without the model's casts.
    $raw = DB::table('room_games')->where('id', $game->id)->first(['started_ms', 'ended_ms']);

    expect((int) $raw?->started_ms)->toBe($started)
        ->and((string) $raw?->started_ms)->toBe((string) $started)
        ->and((string) $raw?->ended_ms)->toBe((string) ($started + 98_765))
        ->and((string) DB::table('room_rounds')->where('id', $round->id)->value('closed_ms'))->toBe((string) ($started + 7_001));
});

it('hides a round\'s solution and payload from every serialization', function (): void {
    $room = Room::factory()->create();
    $game = RoomGame::factory()->for($room)->withRounds(3)->create();
    $round = $game->rounds->first();
    assert($round instanceof RoomRound);
    $solution = $round->solutionData();

    $serialized = [
        json_encode($round->toArray(), JSON_THROW_ON_ERROR),
        $round->toJson(),
        json_encode($round, JSON_THROW_ON_ERROR),
        (string) $round,
        $game->load('rounds')->toJson(),
        $room->load('games.rounds')->toJson(),
        RoomRound::query()->get()->toJson(),
    ];

    expect($solution->accept)->not->toBeEmpty()
        ->and(array_keys($round->toArray()))->toEqualCanonicalizing(['id', 'room_game_id', 'position', 'study_ms', 'answer_ms', 'closed_ms']);

    foreach ($serialized as $json) {
        expect($json)->not->toContain('"solution"')
            ->and($json)->not->toContain('"payload"')
            ->not->toContain('"accept"')
            // the solution's order of ids appears nowhere
            ->not->toContain(implode('","', $solution->ids));
    }

    // The payload holds the question, never in the order to tap.
    expect($round->askData()->ids())->not->toBe($solution->ids)
        ->and($round->askData()->ids())->toEqualCanonicalizing($solution->ids)
        ->and($round->studyData()?->items)->toHaveCount(count($solution->ids));
});

it('stores one answer per player per round and each event once', function (): void {
    $round = RoomRound::factory()->create();
    $answer = RoomAnswer::factory()->for($round, 'round')->create();
    $other = RoomPlayer::factory()->create(['room_id' => $answer->player->room_id]);

    expect($answer->correct)->toBeTrue()
        ->and($answer->points)->toBe(18) // 2.5 s of 10 s
        ->and($answer->picked)->toBe($round->solutionData()->ids)
        ->and(RoomAnswer::factory()->for($round, 'round')->by($other)->wrong()->create()->points)->toBe(0);

    // Each refused insert runs in a savepoint, so PostgreSQL goes on with the test's transaction.
    expect(fn () => DB::transaction(fn () => RoomAnswer::factory()->for($round, 'round')->by($answer->player)->create()))
        ->toThrow(UniqueConstraintViolationException::class);

    $third = RoomPlayer::factory()->create(['room_id' => $answer->player->room_id]);

    expect(fn () => DB::transaction(fn () => RoomAnswer::factory()->for($round, 'round')->by($third)->create(['event_id' => $answer->event_id])))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(RoomAnswer::query()->where('room_round_id', $round->id)->count())->toBe(2);
});

it('plays a game by its timetable at any moment a test travels to', function (): void {
    $room = Room::factory()->create();
    $first = RoomGame::factory()->for($room)->withRounds(5)->ended()->create();

    $this->travel(10)->minutes();
    $game = RoomGame::factory()->for($room)->withRounds(5)->create();
    $timetable = $game->timetable();

    expect($room->games->pluck('id')->all())->toBe([$first->id, $game->id])
        ->and($room->games->pluck('position')->all())->toBe([0, 1])
        ->and($game->rounds->pluck('position')->all())->toBe([0, 1, 2, 3, 4])
        ->and($timetable->opens(0))->toBe(RoomClock::nowMs() + config('elifba.rooms.countdown_ms'))
        ->and(RoomTimetable::at($game, RoomClock::nowMs())->phase)->toBe(RoomPhase::Countdown)
        ->and(RoomTimetable::at($first, RoomClock::nowMs())->isOver())->toBeTrue();

    $this->travelTo(RoomClock::at($timetable->answerFrom(2)));
    expect(RoomTimetable::at($game, RoomClock::nowMs()))->toEqual(RoomTimetable::at($game, $timetable->answerFrom(2)))
        ->and(RoomTimetable::at($game, RoomClock::nowMs())->answers(2))->toBeTrue();

    $this->travelTo(RoomClock::at($timetable->overMs()));
    expect(RoomTimetable::at($game, RoomClock::nowMs())->isOver())->toBeTrue();
});
