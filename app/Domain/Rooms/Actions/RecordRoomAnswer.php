<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\RoomAnswerData;
use App\Domain\Rooms\Data\RoomAnswerReceiptData;
use App\Domain\Rooms\Enums\RoomAnswerStatus;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAnswer;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomJudge;
use App\Domain\Rooms\Support\RoomLetters;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomPointsRule;
use App\Domain\Rooms\Support\RoomRoundClose;
use App\Domain\Rooms\Support\RoomSignal;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of a room result (docs/architecture.md §12 "The race",
 * ADR 021 decisions 1–3 and 7): a player's own answer, posted by their own
 * device. The player is the session's account — its present row in the
 * room — and never anything the body says; a host who does not play has
 * no row and cannot answer.
 *
 * In one transaction, with the room's row and then the round's row locked,
 * always in that order, the checks run in §12's order:
 *
 * 1. the `event_id` is already stored → `duplicate`; this player already
 *    answered the round → `answered`;
 * 2. the round is not the room's latest game's round in its `answer` phase
 *    by the server's clock — before the question, after the bell or the
 *    early close (a closed round stays closed, whatever the clock does
 *    later), a round of another game or room, a game that is over, a
 *    closed room, a player who left or was removed → `late`;
 * 3. `picked` is not the round's own question ids in the count its shape
 *    asks for → 422 on `picked`;
 * 4. otherwise it is judged (`RoomJudge`), timed — `elapsed_ms` is the
 *    server's receipt, read as soon as the room's lock is held, minus
 *    `answer_from` — scored
 *    (`RoomPointsRule`) — from ADR 037 after the letters this player
 *    bought in the round (`afterLetters()`, the rows counted under these
 *    same locks, which `BuyRoomLetter` takes too) — and stored, and the
 *    room is active → `accepted`;
 * 5. the revision rises and the room is signalled, since the count of
 *    answers the screen and the phones show has changed; if every player
 *    the round waits for has now answered — a present player whose page
 *    has read the state since the round before closed, or who answered
 *    (`RoomWaits`) — the round also closes at this moment
 *    (`RoomRoundClose`), in the same revision.
 *
 * `late` and 422 write nothing. The receipt is the same for a right and a
 * wrong answer: `{status, revision}`. Nothing is accepted after the bell,
 * since the reveal is served from that moment. An `event_id` is unique
 * across rooms: the same id sent to two rooms at once, which only a
 * device's own fault makes, stores one and calls the other `duplicate`.
 */
final readonly class RecordRoomAnswer
{
    /**
     * @throws ValidationException on `picked` when it is not made of the round's question
     */
    public function handle(Room $room, User $account, RoomAnswerData $answer): RoomAnswerReceiptData
    {
        try {
            return self::record($room, $account, $answer);
        } catch (UniqueConstraintViolationException) {
            return new RoomAnswerReceiptData(RoomAnswerStatus::Duplicate, (int) Room::query()->whereKey($room->id)->value('revision'));
        }
    }

    private static function record(Room $room, User $account, RoomAnswerData $answer): RoomAnswerReceiptData
    {
        return DB::transaction(static function () use ($room, $account, $answer): RoomAnswerReceiptData {
            $locked = RoomLock::room($room);
            $receivedMs = RoomClock::nowMs();

            if ($locked === null) {
                return new RoomAnswerReceiptData(RoomAnswerStatus::Late, $room->revision);
            }

            $receipt = static fn (RoomAnswerStatus $status): RoomAnswerReceiptData => new RoomAnswerReceiptData($status, $locked->revision);

            // 1. Sent before.
            if (RoomAnswer::query()->where('event_id', $answer->event_id)->exists()) {
                return $receipt(RoomAnswerStatus::Duplicate);
            }

            $row = $locked->players()->where('user_id', $account->id)->whereNull('removed_at')->first();

            if ($row !== null && $row->answers()->where('room_round_id', $answer->round_id)->exists()) {
                return $receipt(RoomAnswerStatus::Answered);
            }

            // 2. Open for answers now, to this player.
            $game = $locked->isOpen() && ($row?->isPresent() ?? false) ? $locked->latestGame() : null;
            $round = RoomLock::round($game?->rounds->firstWhere('id', $answer->round_id));

            if ($row === null || $game === null || $round === null || $round->closed_ms !== null || ! $game->timetable()->moment($receivedMs)->answers($round->position)) {
                return $receipt(RoomAnswerStatus::Late);
            }

            // 3. Made of the round's own question.
            $ask = $round->askData();

            if (count($answer->picked) !== $ask->pick || array_diff($answer->picked, $ask->ids()) !== []) {
                throw ValidationException::withMessages(['picked' => __('rooms.errors.picked')]);
            }

            // 4. Judged, timed, scored, stored.
            $correct = RoomJudge::correct($round->solutionData(), $ask->items, $answer->picked);
            $elapsedMs = $receivedMs - $game->timetable()->answerFrom($round->position);

            $stored = new RoomAnswer;
            $stored->event_id = $answer->event_id;
            $stored->room_round_id = $round->id;
            $stored->room_player_id = $row->id;
            $stored->picked = $answer->picked;
            $stored->correct = $correct;
            $stored->elapsed_ms = $elapsedMs;
            // The letters this player bought in the round, counted under the same locks (ADR 037 decision 17).
            $stored->points = RoomPointsRule::afterLetters(RoomPointsRule::points($correct, $elapsedMs, $round->answer_ms), RoomLetters::count($round, $row));
            $stored->save();

            $locked->last_activity_at = now();

            // 5. One more answer, and perhaps nobody left to wait for: one change.
            RoomRoundClose::ifEveryoneAnswered($locked, $game, $round, $receivedMs);
            RoomSignal::raise($locked);

            return $receipt(RoomAnswerStatus::Accepted);
        });
    }
}
