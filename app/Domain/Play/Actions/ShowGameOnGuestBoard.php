<?php

namespace App\Domain\Play\Actions;

use App\Domain\Play\Data\GuestBoardGameData;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Models\GuestBoard;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Sends a game to a guest board the teacher claimed (docs/architecture.md
 * §4): one of their live games, or a new one started here with `StartGame`.
 * From now on the board's bearer reaches this game and no other. It runs
 * again for the next game, without a new code.
 */
final readonly class ShowGameOnGuestBoard
{
    public function __construct(private StartGame $startGame) {}

    /**
     * @throws ModelNotFoundException when the board is closed, expired or not this teacher's
     */
    public function handle(GuestBoard $board, User $teacher, GuestBoardGameData $choice): GameSession
    {
        $shown = DB::transaction(function () use ($board, $teacher, $choice): array {
            $locked = GuestBoard::query()->whereKey($board->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isActive() || ! $locked->isClaimedBy($teacher)) {
                throw (new ModelNotFoundException)->setModel(GuestBoard::class);
            }

            $game = $choice->game ?? $this->startGame->handle(
                $choice->class ?? throw new LogicException('A new game needs a class.'),
                $choice->setup ?? throw new LogicException('A new game needs its setup.'),
            );

            // Validated as the teacher's own already; the board never shows another's.
            if (! $game->isOwnedBy($teacher)) {
                throw (new ModelNotFoundException)->setModel(GameSession::class);
            }

            $locked->game_session_id = $game->id;
            $locked->save();

            return [$locked, $game];
        });

        $board->setRawAttributes($shown[0]->getAttributes(), true);

        return $shown[1];
    }
}
