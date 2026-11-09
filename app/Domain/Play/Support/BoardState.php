<?php

namespace App\Domain\Play\Support;

use App\Domain\Play\Data\GameData;
use App\Domain\Play\Data\GameStateData;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;

/**
 * Reads a game as `GET /games/{game}/state` answers it
 * (docs/architecture.md §2, §4): every round snapshot in order and where
 * each stands. The current round is the first that is neither solved nor
 * skipped; it is derived here and on the board, never stored.
 */
final class BoardState
{
    public static function of(GameSession $game): GameStateData
    {
        $rounds = GameRound::query()->where('game_session_id', $game->id)->orderBy('position')->get();
        $total = $rounds->count();

        return new GameStateData(
            game: new GameData($game->id, $game->status, $game->board_epoch, $game->pack, $game->mechanic),
            rounds: array_values($rounds->map(static fn (GameRound $round) => $round->snapshot($total))->all()),
            progress: array_values($rounds->map(static fn (GameRound $round) => $round->progressData())->all()),
        );
    }
}
