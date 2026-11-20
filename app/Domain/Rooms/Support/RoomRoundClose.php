<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;

/**
 * The early close (docs/architecture.md §12 "The race", ADR 021 decision 7,
 * step 5): the one change a fact makes to the timetable. When every player
 * the round that is open for answers waits for has answered it
 * (`RoomWaits`: a present player who has answered it, or whose own room
 * page has read the state since the round before it closed), its
 * `closed_ms` is that moment, and the reveal is served from it. Checked
 * after an answer (`RecordRoomAnswer`), a leave (`LeaveRoom`), a removal
 * (`RemoveRoomPlayer`) and a return (`JoinRoom`: a player who had answered
 * comes back to a round nobody else is in), so a round never waits for
 * someone who is gone. A page's read (`RecordRoomRead`) is never a check:
 * it can only add a player to those the round waits for. An account
 * deleted mid-round is not seen here (nothing outside `Rooms` may call
 * it): its round runs to the bell.
 *
 * Who is waited for is counted at the moment of the check: a host who does
 * not play has no row and is not counted; a late joiner is, from their
 * page's first read; a phone that is gone, in the round it vanished in
 * only. A round that waits for nobody — nobody present has answered it
 * or read since it began counting — does not close early: it runs to its
 * bell, as the rounds after it would; there is nobody to reveal it to
 * sooner, and nobody's answer to wait for.
 *
 * Each caller holds the room's row locked and locks the round's row after
 * it, always in that order (`RoomLock`).
 */
final class RoomRoundClose
{
    /**
     * After the present players changed: closes the round that is open for
     * answers in the room's running game, if everyone it waits for has
     * answered it (`RaceDriver::whenPresenceChanges()`, which an open room's
     * running race reaches).
     *
     * @return bool whether a round closed
     */
    public static function whenPresenceChanges(Room $locked, RoomGame $game, int $nowMs): bool
    {
        $moment = $game->timetable()->moment($nowMs);

        if ($moment->phase !== RoomPhase::Answer || $moment->round === null) {
            return false;
        }

        $round = RoomLock::round($game->rounds->firstWhere('position', $moment->round));

        return $round !== null && self::ifEveryoneAnswered($locked, $game, $round, $nowMs);
    }

    /**
     * Closes `$round` of `$game` at `$nowMs` if every player it waits for
     * has answered it; the caller holds both rows locked and knows the
     * round is open for answers.
     *
     * @return bool whether it closed
     */
    public static function ifEveryoneAnswered(Room $locked, RoomGame $game, RoomRound $round, int $nowMs): bool
    {
        $since = RoomWaits::since($game, $round->position);
        $present = $locked->players()->present()->get();
        $answered = $round->answers()->whereIn('room_player_id', $present->modelKeys())->pluck('room_player_id')->all();
        $waited = $present->filter(static fn (RoomPlayer $row): bool => RoomWaits::waitsFor($row, $since, in_array($row->id, $answered, true)));

        // Everyone who answered is waited for: the round is done when nobody else is.
        if ($answered === [] || $waited->count() > count($answered)) {
            return false;
        }

        $round->closed_ms = $nowMs;
        $round->save();

        return true;
    }
}
