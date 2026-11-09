<?php

namespace App\Domain\Play\Actions;

use App\Domain\Play\Data\GameStatusData;
use App\Domain\Play\Models\GameSession;
use Illuminate\Support\Facades\DB;

/**
 * A board load claims the game (docs/architecture.md §4): `board_epoch`
 * goes up by one and the new value is the board's fencing token, so a
 * board opened earlier in another window can no longer sync (409
 * `board_replaced`). A finished or abandoned game is not written to: the
 * board gets its status and epoch and shows the result.
 */
final readonly class ClaimBoard
{
    public function handle(GameSession $game): GameStatusData
    {
        $locked = DB::transaction(function () use ($game): GameSession {
            $locked = GameSession::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isOpen()) {
                $locked->board_epoch++;
                $locked->last_activity_at = now();
                $locked->save();
            }

            return $locked;
        });

        $game->setRawAttributes($locked->getAttributes(), true);

        return GameStatusData::of($game);
    }
}
