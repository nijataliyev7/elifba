<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\RoomTauntData;
use App\Domain\Rooms\Data\RoomTauntReceiptData;
use App\Domain\Rooms\Enums\RoomTauntStatus;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomTaunt;
use App\Domain\Rooms\Support\Drivers\RoomGameDriver;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomPointsRule;
use App\Domain\Rooms\Support\RoomSignal;
use App\Domain\Rooms\Support\RoomTauntBalance;
use App\Domain\Rooms\Support\RoomTauntWindow;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of a taunt (docs/architecture.md §12 "Taunts (T2)", ADR
 * 029 decision 13): a ready-made jibe a present player throws at another
 * during a game, paid for with `RoomPointsRule::TAUNT_COST` of the
 * thrower's own points. The thrower is the session's account — its present
 * row in the room — and never anything the body says; a host who does not
 * play has no present row and throws nothing.
 *
 * In one transaction under the room row's lock (`RoomLock`), so that two
 * taps, two tabs or two devices never spend one point twice, the checks
 * run in §12's order:
 *
 * 1. the `event_id` is already stored → `duplicate`;
 * 2. no game is running, its last round has closed, the room is closed or
 *    the thrower is not present (`RoomTauntWindow`) → `late`;
 * 3. the host has switched taunts off → `off`;
 * 4. the target is not a present player of this room, or is the thrower's
 *    own row → 422 on `target_id`;
 * 5. the thrower's latest taunt of this game is younger than
 *    `elifba.rooms.taunts.cooldown_ms` by the server's clock → `cooling`;
 * 6. the thrower's balance (`RoomTauntBalance`) is below the cost →
 *    `no_points`;
 * 7. otherwise it is stored with the server's moment, the room is active,
 *    the revision rises and the room is signalled after commit →
 *    `accepted`.
 *
 * Who throws at whom is the game's driver's (`RoomGameDriver::mayThrow()`,
 * `mayAim()`): in a race any present player at any other; in a board game
 * a seat at the other seat alone (ADR 034 decision 22) — a watcher's throw
 * is `late`, a target that is not the other seat 422.
 *
 * Only `accepted` writes. A taunt never closes a round, never counts as an
 * answer and never as a read: no `RoomRoundClose`, no `seen_ms`, nothing
 * `RoomWaits` reads. Its cost counts in the ranking from the next close
 * (`RoomTauntCost`). An `event_id` is unique across rooms, as an answer's.
 */
final readonly class ThrowRoomTaunt
{
    /**
     * @throws ValidationException on `target_id` when it is not another present player of the room
     */
    public function handle(Room $room, User $account, RoomTauntData $taunt): RoomTauntReceiptData
    {
        try {
            return self::throw($room, $account, $taunt);
        } catch (UniqueConstraintViolationException) {
            return new RoomTauntReceiptData(RoomTauntStatus::Duplicate, (int) Room::query()->whereKey($room->id)->value('revision'));
        }
    }

    private static function throw(Room $room, User $account, RoomTauntData $taunt): RoomTauntReceiptData
    {
        return DB::transaction(static function () use ($room, $account, $taunt): RoomTauntReceiptData {
            $locked = RoomLock::room($room);
            $nowMs = RoomClock::nowMs();

            if ($locked === null) {
                return new RoomTauntReceiptData(RoomTauntStatus::Late, $room->revision);
            }

            $receipt = static fn (RoomTauntStatus $status): RoomTauntReceiptData => new RoomTauntReceiptData($status, $locked->revision);

            // 1. Sent before.
            if (RoomTaunt::query()->where('event_id', $taunt->event_id)->exists()) {
                return $receipt(RoomTauntStatus::Duplicate);
            }

            // 2. A game running, before its last close, and the thrower in it.
            $row = $locked->rowOf($account);
            $game = $locked->latestGame();

            if ($row === null || ! $row->isPresent() || $game === null || ! RoomTauntWindow::open($locked, $game, $row, $nowMs)) {
                return $receipt(RoomTauntStatus::Late);
            }

            // 3. The host's switch.
            if (! $locked->taunts) {
                return $receipt(RoomTauntStatus::Off);
            }

            // 4. Another present player of this room, at whom the game lets the thrower aim.
            if ($taunt->target_id === $row->id || ! RoomGameDriver::of($game->mechanic)->mayAim($game, $row, $taunt->target_id) || ! $locked->players()->present()->whereKey($taunt->target_id)->exists()) {
                throw ValidationException::withMessages(['target_id' => __('rooms.taunts.late')]);
            }

            // 5. The cooldown, by the server's clock.
            $latest = RoomTaunt::query()
                ->where('room_game_id', $game->id)
                ->where('room_player_id', $row->id)
                ->max('sent_ms');

            if ($latest !== null && $nowMs < (int) $latest + Config::integer('elifba.rooms.taunts.cooldown_ms')) {
                return $receipt(RoomTauntStatus::Cooling);
            }

            // 6. A point to pay with.
            if (RoomTauntBalance::of($game, $row, $nowMs) < RoomPointsRule::TAUNT_COST) {
                return $receipt(RoomTauntStatus::NoPoints);
            }

            // 7. Stored, and every device reads the state again.
            $stored = new RoomTaunt;
            $stored->event_id = $taunt->event_id;
            $stored->room_game_id = $game->id;
            $stored->room_player_id = $row->id;
            $stored->target_player_id = $taunt->target_id;
            $stored->taunt = $taunt->taunt;
            $stored->sent_ms = $nowMs;
            $stored->save();

            $locked->last_activity_at = now();
            RoomSignal::raise($locked);

            return $receipt(RoomTauntStatus::Accepted);
        });
    }
}
