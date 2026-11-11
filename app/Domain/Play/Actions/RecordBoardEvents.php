<?php

namespace App\Domain\Play\Actions;

use App\Domain\Classroom\Models\Student;
use App\Domain\Play\Data\AnswerResultData;
use App\Domain\Play\Data\BoardEventsData;
use App\Domain\Play\Data\GameStatusData;
use App\Domain\Play\Data\HitData;
use App\Domain\Play\Data\SkipData;
use App\Domain\Play\Data\SyncResultData;
use App\Domain\Play\Enums\AnswerStatus;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Events\RoundSolved;
use App\Domain\Play\Exceptions\BoardReplaced;
use App\Domain\Play\Models\GameAnswer;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Support\PointsRule;
use App\Domain\Play\Support\RoundJudge;
use App\Domain\Play\Support\SchoolCalendar;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The board's outbox, stored (`POST /games/{game}/sync`,
 * docs/architecture.md §4, ADR 002). The board is the only writer of
 * results; this is the only code that stores them.
 *
 * Each event runs in its own transaction with the game row and then the
 * round row locked, in that order (claims and status changes lock the game
 * row too), and gets its own result:
 *
 * 1. an old `board_epoch` → `BoardReplaced` (409); events before it stay;
 * 2. an `event_id` already stored → `duplicate`, with the first result's
 *    `round_solved` and `points`;
 * 3. a finished or abandoned game, a round not in this game, a closed
 *    round, an item not in the round or already burst → `stale`, nothing
 *    written;
 * 4. a skip → `skipped` (`duplicate` when the round was already skipped);
 * 5. a hit → judged by `RoundJudge`, stored, progress and misses updated;
 *    the hit that solves the round sets `solved_at`, the student, the
 *    points (`PointsRule`) and the Baku dates (`SchoolCalendar`).
 *
 * A student id that is not an active student of the game's class is stored
 * as null (no one is credited). In a home game (ADR 011) no student id is
 * read at all: answers and rounds keep `student_id` null, and the round is
 * credited to the game's child through the `child_id` `StartGame` gave it.
 * A paused game still takes events: they
 * were made before the pause reached the board. When no open round is
 * left, the game becomes `finished` in the same transaction.
 * `RoundSolved` and `GameSessionTransitioned` are dispatched after commit.
 */
final readonly class RecordBoardEvents
{
    public function __construct(private TransitionGameSession $transition) {}

    /**
     * @throws BoardReplaced when the board's epoch is not the game's
     */
    public function handle(GameSession $game, BoardEventsData $data): SyncResultData
    {
        $total = GameRound::query()->where('game_session_id', $game->id)->count();
        $results = [];

        foreach ($data->events as $event) {
            $results[] = $this->record($game->id, $event, $data->board_epoch, $total);
        }

        $game->refresh();

        return new SyncResultData($results, GameStatusData::of($game));
    }

    private function record(string $gameId, HitData|SkipData $event, int $epoch, int $total): AnswerResultData
    {
        try {
            return DB::transaction(fn (): AnswerResultData => $this->apply($gameId, $event, $epoch, $total));
        } catch (UniqueConstraintViolationException $exception) {
            // The same event_id was stored by a request that committed first.
            return $this->earlier($gameId, $event) ?? throw $exception;
        }
    }

    private function apply(string $gameId, HitData|SkipData $event, int $epoch, int $total): AnswerResultData
    {
        $game = GameSession::query()->whereKey($gameId)->lockForUpdate()->firstOrFail();

        if ($game->board_epoch !== $epoch) {
            throw new BoardReplaced(GameStatusData::of($game));
        }

        $earlier = $this->earlier($gameId, $event);

        if ($earlier !== null) {
            return $earlier;
        }

        $round = $game->status->isOpen()
            ? GameRound::query()->whereKey($event->round_id)->where('game_session_id', $gameId)->lockForUpdate()->first()
            : null;

        if ($round === null) {
            return new AnswerResultData($event->event_id, AnswerStatus::Stale);
        }

        $result = $event instanceof SkipData
            ? $this->skip($round, $event)
            : $this->hit($game, $round, $event, $epoch, $total);

        if (in_array($result->status, [AnswerStatus::Correct, AnswerStatus::Miss, AnswerStatus::Skipped], true)) {
            $game->last_activity_at = now();
            $game->save();
        }

        $closed = $result->round_solved || $result->status === AnswerStatus::Skipped;

        if ($closed && ! GameRound::query()->where('game_session_id', $gameId)->open()->exists()) {
            $this->transition->handle($game, GameSessionStatus::Finished);
        }

        return $result;
    }

    private function skip(GameRound $round, SkipData $event): AnswerResultData
    {
        if ($round->skipped_at !== null) {
            return new AnswerResultData($event->event_id, AnswerStatus::Duplicate);
        }

        if ($round->solved_at !== null) {
            return new AnswerResultData($event->event_id, AnswerStatus::Stale);
        }

        $round->skipped_at = now();
        $round->save();

        return new AnswerResultData($event->event_id, AnswerStatus::Skipped);
    }

    private function hit(GameSession $game, GameRound $round, HitData $event, int $epoch, int $total): AnswerResultData
    {
        if (! $round->isOpen()) {
            return new AnswerResultData($event->event_id, AnswerStatus::Stale);
        }

        $snapshot = $round->snapshot($total);
        $verdict = RoundJudge::judge($snapshot, $round->progress, $event->item_id);
        $item = $snapshot->item($event->item_id);

        if ($verdict->status === AnswerStatus::Stale || $item === null) {
            return new AnswerResultData($event->event_id, AnswerStatus::Stale);
        }

        $studentId = $this->student($game, $event->student_id);

        $answer = new GameAnswer([
            'event_id' => $event->event_id,
            'item_id' => $item->id,
            'content_key' => $item->content_key,
            'correct' => $verdict->status === AnswerStatus::Correct,
            'source' => $event->source,
            'board_epoch' => $epoch,
            'client_at' => $event->client_at,
        ]);
        $answer->game_round_id = $round->id;
        $answer->student_id = $studentId;
        $answer->save();

        $round->progress = $verdict->progress;

        if ($verdict->status === AnswerStatus::Miss) {
            $round->misses++;
        }

        if ($verdict->solved) {
            $solvedAt = now();
            $dates = SchoolCalendar::stamp($solvedAt);

            $round->solved_at = $solvedAt;
            $round->student_id = $studentId;
            $round->points = PointsRule::for($round->misses);
            $round->forceFill($dates);
        }

        $round->save();

        if ($verdict->solved) {
            RoundSolved::dispatch($round->id, $game->id, $round->school_class_id, $round->child_id, $studentId, $round->points);
        }

        return new AnswerResultData(
            event_id: $event->event_id,
            status: $verdict->status,
            round_solved: $verdict->solved,
            points: $verdict->solved ? $round->points : null,
        );
    }

    /**
     * The result of an event that was stored before, as `duplicate`: a hit
     * with this event_id is in `game_answers`. It repeats whether that hit
     * solved its round (it is the last correct answer of a solved round)
     * and the points. An event_id taken in another game reports nothing of
     * that game. Skips are not stored by event_id; a repeated skip finds
     * its round skipped instead.
     */
    private function earlier(string $gameId, HitData|SkipData $event): ?AnswerResultData
    {
        if ($event instanceof SkipData) {
            return null;
        }

        $answer = GameAnswer::query()->where('event_id', $event->event_id)->first();

        if ($answer === null) {
            return null;
        }

        $round = GameRound::query()->whereKey($answer->game_round_id)->where('game_session_id', $gameId)->first();
        $solving = $round !== null
            && $round->solved_at !== null
            && $answer->correct
            && ! GameAnswer::query()
                ->where('game_round_id', $round->id)
                ->where('correct', true)
                ->where('id', '>', $answer->id)
                ->exists();

        return new AnswerResultData(
            event_id: $event->event_id,
            status: AnswerStatus::Duplicate,
            round_solved: $solving,
            points: $solving ? $round->points : null,
        );
    }

    /**
     * The student at the board when they are an active student of the
     * game's class; null otherwise (an unknown, foreign or archived id
     * credits no one). A home game has no students: its child is the one at
     * the board, whatever id the board sends.
     */
    private function student(GameSession $game, ?string $studentId): ?string
    {
        if ($studentId === null || $game->school_class_id === null) {
            return null;
        }

        $known = Student::query()
            ->whereKey($studentId)
            ->where('school_class_id', $game->school_class_id)
            ->active()
            ->exists();

        return $known ? $studentId : null;
    }
}
