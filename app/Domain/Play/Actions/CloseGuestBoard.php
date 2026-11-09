<?php

namespace App\Domain\Play\Actions;

use App\Domain\Play\Models\GuestBoard;

/**
 * «Lövhəni bağla», from the board or the teacher's phone
 * (docs/architecture.md §4): the board's bearer stops working at once and
 * its code is gone. The game it showed goes on; the teacher can send it to
 * another board. `PruneGuestBoards` deletes the row later.
 */
final readonly class CloseGuestBoard
{
    public function handle(GuestBoard $board): GuestBoard
    {
        $board->closed_at ??= now();
        $board->code = null;
        $board->code_expires_at = null;
        $board->save();

        return $board;
    }
}
