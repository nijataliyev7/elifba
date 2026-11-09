<?php

namespace App\Domain\Play\Actions;

use App\Domain\Play\Data\GameStatusData;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Events\GameSessionTransitioned;
use App\Domain\Play\Exceptions\InvalidTransition;
use App\Domain\Play\Models\GameSession;
use Illuminate\Support\Facades\DB;

/**
 * The only way a game's status changes (docs/architecture.md §1), along
 * `GameSessionStatus::allowedTransitions()`. It locks the game row, so it
 * never races a sync or a claim. A game that ends gets `ended_at` and loses
 * its join code. `GameSessionTransitioned` is dispatched after the commit.
 *
 * Asking for the status the game already has changes nothing and
 * dispatches nothing, so a repeated pause or end is harmless.
 */
final readonly class TransitionGameSession
{
    /**
     * @throws InvalidTransition when the change is not allowed
     */
    public function handle(GameSession $game, GameSessionStatus $to): GameSession
    {
        $locked = DB::transaction(function () use ($game, $to): GameSession {
            $locked = GameSession::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($from === $to) {
                return $locked;
            }

            if (! $from->canTransitionTo($to)) {
                throw new InvalidTransition(GameStatusData::of($locked), $to);
            }

            $locked->status = $to;
            $locked->last_activity_at = now();

            if ($to->isFinal()) {
                $locked->ended_at = now();
                $locked->join_code = null;
                $locked->join_code_expires_at = null;
            }

            $locked->save();

            GameSessionTransitioned::dispatch($locked->id, $from, $to);

            return $locked;
        });

        return $game->setRawAttributes($locked->getAttributes(), true);
    }
}
