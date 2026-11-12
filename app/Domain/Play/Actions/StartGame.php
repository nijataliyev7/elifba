<?php

namespace App\Domain\Play\Actions;

use App\Domain\Content\Data\PackData;
use App\Domain\Content\Support\TeacherPacks;
use App\Domain\Play\Data\GamePlayers;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Support\Playable;
use App\Domain\Play\Support\SetupMemory;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Starts a game for a class or for a parent's child (docs/architecture.md
 * §2, ADR 011): builds every round from the pack as the game's owner plays
 * it (`TeacherPacks`: the built-in words they have not hidden, then their
 * own words; only their own words when `$setup->own_words`) and the learned
 * letters of the class or the child, with a seeded generator
 * (`Random\Randomizer` on `Mt19937($seed)`, so a seed always gives the same
 * rounds) and stores the game, `live`, with its round snapshots in one
 * transaction, with the rest of its setup (the letters asked for and
 * `own_words`), so its rounds can be built again (`ChangeRoundCount`,
 * `ReplayGame`), and keeps that setup for the class or the child's next
 * «Yeni oyun» (`SetupMemory::remember()`, §2 "Remembered setup"; a replay
 * remembers the same). The game belongs to the class's teacher or the child's
 * parent; it and each round store exactly one of the class and the child
 * (`GamePlayers::columns()`). `$setup` has passed `GameSetupData::rules()`
 * for the same players, which asked about the same pack.
 */
final readonly class StartGame
{
    /**
     * Seeds fit a signed 32-bit integer column on every engine.
     */
    public const int MAX_SEED = 2_147_483_647;

    public function __construct(private TeacherPacks $packs) {}

    public function handle(GamePlayers $players, GameSetupData $setup, ?int $seed = null): GameSession
    {
        $seed ??= random_int(1, self::MAX_SEED);
        [$pack, $rounds] = $this->build($players, $setup, $seed);

        return DB::transaction(function () use ($players, $setup, $seed, $pack, $rounds): GameSession {
            $now = now();
            $game = new GameSession([
                'mechanic' => $setup->mechanic,
                'pack' => $pack->key,
                'pack_version' => $pack->version,
                'level' => $setup->level,
                'letter_case' => $setup->letter_case,
                'letters' => $setup->letters,
                'own_words' => $setup->own_words,
                'seed' => $seed,
                'status' => GameSessionStatus::Live,
                'board_epoch' => 0,
                'started_at' => $now,
                'last_activity_at' => $now,
            ]);
            $game->user_id = $players->owner->id;
            $game->forceFill($players->columns());
            $game->save();

            $this->storeRounds($game, $players, $rounds);
            SetupMemory::remember($players, $setup);

            return $game;
        });
    }

    /**
     * The pack as the game's owner plays it and every round the seed gives
     * with it; `ChangeRoundCount` builds a game's rounds again with this.
     *
     * @return array{PackData, list<RoundData>}
     */
    public function build(GamePlayers $players, GameSetupData $setup, int $seed): array
    {
        $pack = $this->packs->pack($players->owner, $setup->pack, $setup->own_words);

        return [$pack, Playable::builder($setup->mechanic)
            ->build($setup, $pack, $players->learned(), new Randomizer(new Mt19937($seed)))];
    }

    /**
     * Stores a game's round snapshots, each with the game's class or child,
     * inside the caller's transaction.
     *
     * @param  list<RoundData>  $rounds
     */
    public function storeRounds(GameSession $game, GamePlayers $players, array $rounds): void
    {
        foreach ($rounds as $round) {
            $row = new GameRound([
                'id' => $round->id,
                'position' => $round->index,
                'payload' => $round->payload(),
                'progress' => [],
                'misses' => 0,
                'points' => 0,
            ]);
            $row->game_session_id = $game->id;
            $row->forceFill($players->columns());
            $row->save();
        }
    }
}
