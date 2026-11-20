<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\RoomMatchTauntData;
use App\Domain\Rooms\Enums\RoomTauntStatus;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomMatchTaunt;
use App\Domain\Rooms\Support\Checkers;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * A player of the running board match throws a taunt at the other
 * (docs/architecture.md §12 "Board games", ADR 034 decision 11), the only
 * writer of one. Under the room row's lock: sent before → `duplicate`; no
 * match runs, or the account plays no side of it → `late`; the host's
 * switch is off → `off`; within the cooldown of the thrower's last taunt in
 * this match → `cooling`; else it is stored, free, and the room is
 * signalled. It never counts as a move.
 */
final readonly class ThrowMatchTaunt
{
    public function handle(Room $room, User $account, RoomMatchTauntData $taunt): RoomTauntStatus
    {
        try {
            return self::throw($room, $account, $taunt);
        } catch (UniqueConstraintViolationException) {
            return RoomTauntStatus::Duplicate;
        }
    }

    private static function throw(Room $room, User $account, RoomMatchTauntData $taunt): RoomTauntStatus
    {
        return DB::transaction(static function () use ($room, $account, $taunt): RoomTauntStatus {
            $locked = RoomLock::room($room);

            if ($locked === null) {
                return RoomTauntStatus::Late;
            }

            if (RoomMatchTaunt::query()->where('event_id', $taunt->event_id)->exists()) {
                return RoomTauntStatus::Duplicate;
            }

            $row = $locked->rowOf($account);
            $match = $locked->runningMatch();
            $side = $match?->sideOf($row?->id);

            if ($match === null || $row === null || ! $row->isPresent() || $side === null || ! $locked->isOpen()) {
                return RoomTauntStatus::Late;
            }

            if (! $locked->taunts) {
                return RoomTauntStatus::Off;
            }

            $nowMs = RoomClock::nowMs();
            $latest = RoomMatchTaunt::query()
                ->where('room_match_id', $match->id)
                ->where('room_player_id', $row->id)
                ->max('sent_ms');

            if ($latest !== null && $nowMs < (int) $latest + Config::integer('elifba.rooms.taunts.cooldown_ms')) {
                return RoomTauntStatus::Cooling;
            }

            $stored = new RoomMatchTaunt;
            $stored->event_id = $taunt->event_id;
            $stored->room_match_id = $match->id;
            $stored->room_player_id = $row->id;
            $stored->target_player_id = $match->playerOf(Checkers::other($side));
            $stored->taunt = $taunt->taunt;
            $stored->sent_ms = $nowMs;
            $stored->save();

            $locked->last_activity_at = now();
            RoomSignal::raise($locked);

            return RoomTauntStatus::Accepted;
        });
    }
}
