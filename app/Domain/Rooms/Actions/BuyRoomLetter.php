<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\RoomLetterData;
use App\Domain\Rooms\Data\RoomLetterReceiptData;
use App\Domain\Rooms\Enums\RoomLetterStatus;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomLetter;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLetters;
use App\Domain\Rooms\Support\RoomLock;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of a bought letter (docs/architecture.md §12 "«Anaqram»:
 * a clue, a shuffle and a bought letter (ADR 037)", ADR 037 decisions
 * 10–12): a player buys the next letter of the drawn word in the round
 * open for answers, at the price of that round's answer
 * (`RoomPointsRule::afterLetters()`, which `RecordRoomAnswer` applies).
 * The buyer is the session's account — its present row in the room — and
 * never anything the body says; a host who does not play has no row and
 * buys nothing.
 *
 * In one transaction, with the room's row and then the round's row locked,
 * always in that order — as an answer takes them, so a purchase and the
 * same player's answer never cross — the checks run in §12's order:
 *
 * 1. the `event_id` is already stored → `duplicate`;
 * 2. the round is not the room's latest game's round in its `answer`
 *    phase by the server's clock — before the question, after the bell or
 *    the early close, in the reveal, a round of another game or room, a
 *    game that is over — the room is closed, or the player left, was
 *    removed or has answered the round → `late`;
 * 3. the round sells no letters (its question has no orders), or
 *    `position` is past the word's last letter or ahead of the letters
 *    bought → 422 on `position`;
 * 4. `position` names a letter this player already bought in the round —
 *    a second tap, a second device → `bought`: the position in the body is
 *    why a double tap never buys two;
 * 5. otherwise it is stored with the server's moment and the room is
 *    active → `accepted`.
 *
 * Only `accepted` writes. A purchase raises no revision and signals
 * nothing: nobody else's view changes, and the buyer's page reads its own
 * state. It never closes a round and never counts as an answer or as a
 * read — nothing `RoomWaits` or the timetable reads — and never moves the
 * ranking, the strip or a taunt balance before an answer is stored. An
 * `event_id` is unique across rooms, as an answer's.
 */
final readonly class BuyRoomLetter
{
    /**
     * @throws ValidationException on `position` when the round sells no letters or it names no next letter
     */
    public function handle(Room $room, User $account, RoomLetterData $letter): RoomLetterReceiptData
    {
        try {
            return self::buy($room, $account, $letter);
        } catch (UniqueConstraintViolationException) {
            // Another request stored this event or this place first, between the checks and the write.
            return new RoomLetterReceiptData(RoomLetter::query()->where('event_id', $letter->event_id)->exists() ? RoomLetterStatus::Duplicate : RoomLetterStatus::Bought);
        }
    }

    private static function buy(Room $room, User $account, RoomLetterData $letter): RoomLetterReceiptData
    {
        return DB::transaction(static function () use ($room, $account, $letter): RoomLetterReceiptData {
            $locked = RoomLock::room($room);
            $receivedMs = RoomClock::nowMs();

            if ($locked === null) {
                return new RoomLetterReceiptData(RoomLetterStatus::Late);
            }

            // 1. Sent before.
            if (RoomLetter::query()->where('event_id', $letter->event_id)->exists()) {
                return new RoomLetterReceiptData(RoomLetterStatus::Duplicate);
            }

            // 2. Open for answers now, to this player, who has not answered it.
            $row = $locked->rowOf($account);
            $game = $locked->isOpen() && ($row?->isPresent() ?? false) ? $locked->latestGame() : null;
            $round = RoomLock::round($game?->rounds->firstWhere('id', $letter->round_id));

            if ($row === null || $game === null || $round === null || $round->closed_ms !== null
                || ! $game->timetable()->moment($receivedMs)->answers($round->position)
                || $row->answers()->where('room_round_id', $round->id)->exists()) {
                return new RoomLetterReceiptData(RoomLetterStatus::Late);
            }

            // 3. A letter this round sells, and the next one or one bought.
            $bought = RoomLetters::count($round, $row);

            if (! RoomLetters::sells($round) || $letter->position >= RoomLetters::length($round) || $letter->position > $bought) {
                throw ValidationException::withMessages(['position' => __('rooms.errors.letter')]);
            }

            // 4. Bought already: a second tap or a second device.
            if ($letter->position < $bought) {
                return new RoomLetterReceiptData(RoomLetterStatus::Bought);
            }

            // 5. Stored; nobody else's view changes, so no revision and no signal.
            $stored = new RoomLetter;
            $stored->event_id = $letter->event_id;
            $stored->room_round_id = $round->id;
            $stored->room_player_id = $row->id;
            $stored->position = $letter->position;
            $stored->bought_ms = $receivedMs;
            $stored->save();

            $locked->last_activity_at = now();
            $locked->save();

            return new RoomLetterReceiptData(RoomLetterStatus::Accepted);
        });
    }
}
