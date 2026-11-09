<?php

namespace App\Domain\Play\Actions;

use App\Domain\Play\Data\GuestBoardGameData;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Models\GuestBoard;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * The teacher's phone claims an open guest board by its code
 * (docs/architecture.md §4): the board is bound to the teacher, its code is
 * cleared — so nobody else can claim it — and `ShowGameOnGuestBoard` sends
 * it the chosen game, all in one transaction. A board claimed, closed or
 * expired a moment ago is not found (404, like an unknown code).
 */
final readonly class ClaimGuestBoard
{
    public function __construct(private ShowGameOnGuestBoard $show) {}

    /**
     * @throws ModelNotFoundException when the board is no longer claimable
     */
    public function handle(GuestBoard $board, User $teacher, GuestBoardGameData $choice): GameSession
    {
        return DB::transaction(function () use ($board, $teacher, $choice): GameSession {
            $locked = GuestBoard::query()->whereKey($board->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isClaimable()) {
                throw (new ModelNotFoundException)->setModel(GuestBoard::class);
            }

            $locked->user_id = $teacher->id;
            $locked->claimed_at = now();
            $locked->code = null;
            $locked->code_expires_at = null;
            $locked->save();

            $game = $this->show->handle($locked, $teacher, $choice);
            $board->setRawAttributes($locked->getAttributes(), true);

            return $game;
        });
    }
}
