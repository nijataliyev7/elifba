<?php

namespace App\Domain\Play\Actions;

use App\Domain\Play\Data\GamePlayers;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;

/**
 * «Yenidən oyna» on the end screen (`POST /games/{game}/replay`,
 * docs/architecture.md §2 "Game setup"): a new game for the same class or
 * child with the same setup — mechanic, pack, level, letter case, letters,
 * own words and as many rounds as this one had (kept within
 * `elifba.rounds.min`–`.max`) — and a new seed, so the rounds differ. The
 * setup passes `GameSetupData::rules()` for the same `GamePlayers` first,
 * as a new game's does: learned letters narrowed or words hidden since
 * refuse it with the reason. The caller replays only a game that is over.
 */
final readonly class ReplayGame
{
    public function __construct(private StartGame $startGame) {}

    /**
     * @throws ValidationException when the same game can no longer start
     */
    public function handle(GameSession $game): GameSession
    {
        $players = GamePlayers::of($game);
        $count = GameRound::query()->where('game_session_id', $game->id)->count();
        $rounds = max(Config::integer('elifba.rounds.min'), min(Config::integer('elifba.rounds.max'), $count));

        return $this->startGame->handle($players, GameSetupData::validate(GameSetupData::inputOf($game, $rounds), $players));
    }
}
