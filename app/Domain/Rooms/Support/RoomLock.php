<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Content\Support\TeacherLock;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomRound;

/**
 * The locks of a room's writes (docs/architecture.md §12 "Rooms and
 * joining", "The race"): the room's row, which every change to a room takes
 * first — a join, a leave, a removal, the lock, the close, a game started
 * or ended, an answer — so a count and a check see every committed row; a
 * round's row, which only a holder of its room's row takes, after it —
 * an answer, and an early close after a leave or a removal — so the order
 * is always room, then round, and two writers never wait on each other
 * crosswise; and the host's `users` row (the lock a teacher's words take,
 * `TeacherLock`), which opening a room takes so that one host never has
 * two open rooms. `FOR UPDATE` on PostgreSQL; SQLite has no row locks and
 * its transactions are deferred (config/database.php), so there two writers
 * at the same instant may make the second fail with "database is locked"
 * rather than wait — never a wrong row; production runs PostgreSQL.
 */
final class RoomLock
{
    /**
     * The room's row, read again under the lock; null once it is gone.
     */
    public static function room(Room $room): ?Room
    {
        return Room::query()->whereKey($room->id)->lockForUpdate()->first();
    }

    /**
     * The room's row, read again under a shared lock (`FOR SHARE` on
     * PostgreSQL), by a reader inside a transaction: it waits for a writer
     * that holds the row — an act stamped by the server's clock but not
     * yet committed — and its own moment is read after that commit, so it
     * never reports a flag fall that the commit takes back. Only such a
     * read takes it (`RoomState::for()`): a shared lock held by every read
     * would let readers jump a waiting move and charge the wait to its
     * clock. Readers do not wait on each other. Null once the row is gone.
     */
    public static function shared(Room $room): ?Room
    {
        return Room::query()->whereKey($room->id)->sharedLock()->first();
    }

    /**
     * A round's row, read again under the lock, by a caller that holds its
     * room's row; null when there is none.
     */
    public static function round(?RoomRound $round): ?RoomRound
    {
        return $round === null ? null : RoomRound::query()->whereKey($round->id)->lockForUpdate()->first();
    }

    public static function host(int $hostId): void
    {
        TeacherLock::acquire($hostId);
    }
}
