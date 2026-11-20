<?php

namespace App\Domain\Rooms\Policies;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomVoice;
use App\Models\User;

/**
 * Who reaches what in a room, second layer (docs/architecture.md §12 "Rooms
 * and joining", ADR 020 decision 5): `{room}` already binds only a room the
 * account hosts or has a row in that was not removed, and every route with
 * it also passes one of these (`->can()`). Three abilities are §12's —
 * `view`, `play`, `host` — and the others join one of them to the room's
 * state, one for each condition of §12's route table. None asks for a role.
 * An answer takes `play`: a host who does not play has no row to answer
 * with.
 */
final class RoomPolicy
{
    /** The room page: the host, or a player, present or past. */
    public function view(User $user, Room $room): bool
    {
        return $room->isHostedBy($user) || $room->rowOf($user) !== null;
    }

    /** A present player of an open room: the live room, leaving. */
    public function play(User $user, Room $room): bool
    {
        return $room->isOpen() && ($room->rowOf($user)?->isPresent() ?? false);
    }

    /** The host, playing or not: the host's tools. */
    public function host(User $user, Room $room): bool
    {
        return $room->isHostedBy($user);
    }

    /** The state: `play` or `host`. */
    public function watch(User $user, Room $room): bool
    {
        return $this->host($user, $room) || $this->play($user, $room);
    }

    /**
     * `watch` of an open room: the cheer's name clips, which the screen says
     * after the final's fanfare and, in a room without a screen, every
     * phone (ADR 030 as amended 2026-11-19).
     */
    public function hear(User $user, Room $room): bool
    {
        return $room->isOpen() && $this->watch($user, $room);
    }

    /** `host` of an open room: the big screen (B4). */
    public function screen(User $user, Room $room): bool
    {
        return $this->host($user, $room) && $room->isOpen();
    }

    /** `host` of an open room with no game running: starting a game (B3). */
    public function start(User $user, Room $room): bool
    {
        return $this->host($user, $room) && $room->isOpen() && $room->board === null && $room->runningGame(RoomClock::nowMs()) === null;
    }

    /** `host` of an open board room with no match running: starting a match (ADR 034). */
    public function startMatch(User $user, Room $room): bool
    {
        return $this->host($user, $room) && $room->isOpen() && $room->board !== null && $room->runningMatch() === null;
    }

    /** `host` of an open room while a match runs in it: ending the match (ADR 034). */
    public function endMatch(User $user, Room $room): bool
    {
        return $this->host($user, $room) && $room->isOpen() && $room->runningMatch() !== null;
    }

    /** `host` of an open room, while a game is running in it: ending the game (B3). */
    public function end(User $user, Room $room): bool
    {
        return $this->host($user, $room) && $room->isOpen() && $room->runningGame(RoomClock::nowMs()) !== null;
    }

    /** `host`, and the row is a player's of this room, never the host's own. */
    public function remove(User $user, Room $room, RoomPlayer $member): bool
    {
        return $this->host($user, $room)
            && $member->room_id === $room->id
            && $member->user_id !== $room->user_id;
    }

    /**
     * `host` of an open room (§12 "Live voice", ADR 031 decision 9): the
     * room's voice; with a member, a row of this room that is not the
     * host's own — admitting, muting, letting speak. Only the voice routes
     * ask it, and `rooms.voice` has answered 404 before while the
     * instance's voice is off.
     */
    public function voice(User $user, Room $room, ?RoomPlayer $member = null): bool
    {
        return $this->host($user, $room)
            && $room->isOpen()
            && ($member === null || ($member->room_id === $room->id && $member->user_id !== $room->user_id));
    }

    /**
     * A ticket to the room's voice (ADR 031 decision 18): the host — playing
     * or not — or a present player the host let in, of an open room whose
     * voice is on. Never a newcomer who waits, a player who left or was
     * removed, a closed or idle room, a room whose voice is off. Behind
     * `rooms.voice`, as `voice` is.
     */
    public function listen(User $user, Room $room): bool
    {
        if (! $room->isOpen() || ! $room->voiceIsOn()) {
            return false;
        }

        $row = $room->rowOf($user);

        return $row !== null && RoomVoice::mayListen($room, $row);
    }

    /** `host`, once the room is closed. */
    public function delete(User $user, Room $room): bool
    {
        return $this->host($user, $room) && ! $room->isOpen();
    }

    /** `view` with a row of one's own, once the room is closed: taking one's name out. */
    public function forget(User $user, Room $room): bool
    {
        return ! $room->isOpen() && $room->rowOf($user) !== null;
    }
}
