<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\RoomActData;
use App\Domain\Rooms\Data\RoomActReceiptData;
use App\Domain\Rooms\Enums\RoomActStatus;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAct;
use App\Domain\Rooms\Support\Drivers\RoomGameDriver;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of a seat's act in a board game (docs/architecture.md §12
 * "Board games", "An act", ADR 034 decision 9): a move, a draw offer, its
 * answer or a resignation, posted by the seat's own device. The seat is the
 * session's account — its present row in the room — and never anything the
 * body says. The pull request's `PlayRoomMove` ordered its checks so.
 *
 * In one transaction under the room row's lock (`RoomLock`), so that two
 * taps, two tabs or two devices are taken one after the other, the checks
 * run in §12's order:
 *
 * 1. the `event_id` is already stored → `duplicate`;
 * 2. the room is closed, its game is not live or not the game the act
 *    names (`game_id`: an act sent again after its game ended), or the
 *    account is no present player → `late`;
 * 3. the game's driver judges the act against the game as it stands now,
 *    replayed from its acts (`RoomGameDriver::act()`): a race takes none —
 *    `late`; in a board game a player without a seat → 403; a move before
 *    the clock starts, out of turn, for another ply or at or after the
 *    flag → `late`, a path that is no legal move → 422; an offer the seat
 *    may not make now, an answer to no standing offer of the other seat at
 *    that ply → `late`; a resignation is taken while the match is live;
 * 4. otherwise the act is stored with the server's moment
 *    (`RoomClock::nowMs()`, read as soon as the lock is held), the room is
 *    active, its revision rises and `room.changed` goes out after the
 *    commit → `accepted`.
 *
 * Only `accepted` writes; nothing else — no position, turn, time, count or
 * result — is stored, since all of it is replayed from the acts. An
 * `event_id` is unique across rooms, as an answer's.
 */
final readonly class RecordRoomAct
{
    /**
     * @throws AuthorizationException when the player holds no seat in the match
     * @throws ValidationException on `path` when a move is no legal move
     */
    public function handle(Room $room, User $account, RoomActData $act): RoomActReceiptData
    {
        try {
            return self::record($room, $account, $act);
        } catch (UniqueConstraintViolationException) {
            return new RoomActReceiptData(RoomActStatus::Duplicate, (int) Room::query()->whereKey($room->id)->value('revision'));
        }
    }

    private static function record(Room $room, User $account, RoomActData $act): RoomActReceiptData
    {
        return DB::transaction(static function () use ($room, $account, $act): RoomActReceiptData {
            $locked = RoomLock::room($room);
            $nowMs = RoomClock::nowMs();

            if ($locked === null) {
                return new RoomActReceiptData(RoomActStatus::Late, $room->revision);
            }

            $receipt = static fn (RoomActStatus $status): RoomActReceiptData => new RoomActReceiptData($status, $locked->revision);

            // 1. Sent before.
            if (RoomAct::query()->where('event_id', $act->event_id)->exists()) {
                return $receipt(RoomActStatus::Duplicate);
            }

            // 2. The live game the act names, and the account present in it.
            $row = $locked->rowOf($account);
            $game = $locked->isOpen() && ($row?->isPresent() ?? false) ? $locked->runningGame($nowMs) : null;

            if ($row === null || $game === null || $game->id !== $act->game_id) {
                return $receipt(RoomActStatus::Late);
            }

            // 3. Judged against the game as it stands.
            [$status, $ply] = RoomGameDriver::of($game->mechanic)->act($game, $row, $act, $nowMs);

            if ($status !== RoomActStatus::Accepted || $ply === null) {
                return $receipt(RoomActStatus::Late);
            }

            // 4. Stored, and every device reads the state again.
            $stored = new RoomAct;
            $stored->event_id = $act->event_id;
            $stored->room_game_id = $game->id;
            $stored->room_player_id = $row->id;
            $stored->kind = $act->kind;
            $stored->ply = $ply;
            $stored->path = $act->path;
            $stored->acted_ms = $nowMs;
            $stored->save();

            $locked->last_activity_at = now();
            RoomSignal::raise($locked);

            return $receipt(RoomActStatus::Accepted);
        });
    }
}
