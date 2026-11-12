<?php

namespace App\Domain\Play\Actions;

use App\Domain\Play\Data\GamePlayers;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Data\GameStateData;
use App\Domain\Play\Data\GameStatusData;
use App\Domain\Play\Data\RoundCountData;
use App\Domain\Play\Exceptions\BoardReplaced;
use App\Domain\Play\Exceptions\RoundsLocked;
use App\Domain\Play\Models\GameAnswer;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Support\BoardState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Another number of rounds, chosen on the board before the game begins
 * (`PATCH /games/{game}/rounds`, docs/architecture.md §4 "Round count").
 * Under the game row's lock — the one `RecordBoardEvents`, claims and
 * status changes take — it refuses a board whose epoch is not the game's
 * (`BoardReplaced`), a game that has ended or begun (`RoundsLocked`: a
 * round solved, skipped, hit or missed, points, or any event stored), then
 * builds every round again exactly as `StartGame` did — the game's setup
 * (`GameSetupData::inputOf()`, checked by `GameSetupData::rules()` for the
 * same `GamePlayers`, so a game the owner's learned letters or hidden words
 * no longer allow is refused with the reason), the pack as the owner plays
 * it now (`TeacherPacks`, its version stored), the learned letters and the
 * same seed — and replaces the rounds in the same transaction. The rounds
 * get new ids; the epoch stays, so the board that asked keeps playing.
 */
final readonly class ChangeRoundCount
{
    public function __construct(private StartGame $startGame) {}

    /**
     * @throws BoardReplaced when the board's epoch is not the game's
     * @throws RoundsLocked when the game has begun or ended
     * @throws ValidationException when the game's setup can no longer start
     */
    public function handle(GameSession $game, RoundCountData $data): GameStateData
    {
        $players = GamePlayers::of($game);

        DB::transaction(function () use ($game, $data, $players): void {
            $locked = GameSession::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();

            if ($locked->board_epoch !== $data->board_epoch) {
                throw new BoardReplaced(GameStatusData::of($locked));
            }

            if (! $locked->status->isOpen() || $this->begun($locked)) {
                throw new RoundsLocked(GameStatusData::of($locked));
            }

            $setup = GameSetupData::validate(GameSetupData::inputOf($locked, $data->rounds), $players);
            [$pack, $rounds] = $this->startGame->build($players, $setup, $locked->seed);

            GameRound::query()->where('game_session_id', $locked->id)->delete();
            $this->startGame->storeRounds($locked, $players, $rounds);

            $locked->pack_version = $pack->version;
            $locked->last_activity_at = now();
            $locked->save();
        });

        $game->refresh();

        return BoardState::of($game);
    }

    /**
     * Whether anything was played: a result on a round, or an event stored.
     */
    private function begun(GameSession $game): bool
    {
        $rounds = GameRound::query()->where('game_session_id', $game->id)->get();

        return $rounds->contains(static fn (GameRound $round): bool => $round->progress !== []
            || $round->misses > 0
            || $round->points > 0
            || $round->solved_at !== null
            || $round->skipped_at !== null)
            || GameAnswer::query()->whereIn('game_round_id', $rounds->modelKeys())->exists();
    }
}
