<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\Drivers\RoomGameDriver;
use App\Domain\Rooms\Support\RoomClock;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * A player's own room page read the state (docs/architecture.md §12 "The
 * race", ADR 021 decision 7): the only writer of `room_players.seen_ms`,
 * which tells a round whether to wait for that player (`RoomWaits`). Called
 * by the room page's props and `GET /rooms/{room}/state` — never by the big
 * screen, which reads the same state with the host's account and is not
 * the host's phone.
 *
 * It writes only when the read changes what a round waits for: in an open
 * room with a game running, for a present player whose last read is older
 * than the moment the round now ahead counts from
 * (`RoomWaits::readCountsFrom()`) — at most once a round, never in the
 * lobby, once the game is over or in a board game, where no round waits
 * (`RoomGameDriver::readCountsFrom()`). One conditional update of the player's
 * own row, without the room's lock: it neither raises the revision nor
 * signals, and it never closes a round, since a read only adds a player
 * to those a round waits for.
 */
final readonly class RecordRoomRead
{
    public function handle(Room $room, User $account): void
    {
        if (! $room->isOpen()) {
            return;
        }

        $nowMs = RoomClock::nowMs();
        $row = $room->players()->present()->where('user_id', $account->id)->first();
        $game = $row === null ? null : $room->latestGame();
        $since = $game === null ? null : RoomGameDriver::of($game->mechanic)->readCountsFrom($game, $nowMs);

        if ($row === null || $since === null || ($row->seen_ms !== null && $row->seen_ms >= $since)) {
            return;
        }

        RoomPlayer::query()
            ->whereKey($row->id)
            ->where(static fn (Builder $query) => $query->whereNull('seen_ms')->orWhere('seen_ms', '<', $nowMs))
            ->update(['seen_ms' => $nowMs]);
    }
}
