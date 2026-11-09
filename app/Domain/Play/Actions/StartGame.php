<?php

namespace App\Domain\Play\Actions;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Content\Support\TeacherPacks;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Support\Playable;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Starts a game for a class (docs/architecture.md §2): builds every round
 * from the pack as the class's teacher plays it (`TeacherPacks`: the
 * built-in words they have not hidden, then their own words) with a seeded
 * generator (`Random\Randomizer` on `Mt19937($seed)`, so a seed always gives
 * the same rounds) and stores the game, `live`, with its round snapshots in
 * one transaction. The game belongs to the class's teacher. `$setup` has
 * passed `GameSetupData::rules()` for this class, which asked about the
 * same pack.
 */
final readonly class StartGame
{
    /**
     * Seeds fit a signed 32-bit integer column on every engine.
     */
    public const int MAX_SEED = 2_147_483_647;

    public function __construct(private TeacherPacks $packs) {}

    public function handle(SchoolClass $class, GameSetupData $setup, ?int $seed = null): GameSession
    {
        $seed ??= random_int(1, self::MAX_SEED);
        $pack = $this->packs->pack($class->teacher, $setup->pack);
        $rounds = Playable::builder($setup->mechanic)
            ->build($setup, $pack, GameSetupData::learned($class), new Randomizer(new Mt19937($seed)));

        return DB::transaction(function () use ($class, $setup, $seed, $pack, $rounds): GameSession {
            $now = now();
            $game = new GameSession([
                'mechanic' => $setup->mechanic,
                'pack' => $pack->key,
                'pack_version' => $pack->version,
                'level' => $setup->level,
                'letter_case' => $setup->letter_case,
                'seed' => $seed,
                'status' => GameSessionStatus::Live,
                'board_epoch' => 0,
                'started_at' => $now,
                'last_activity_at' => $now,
            ]);
            $game->user_id = $class->user_id;
            $game->schoolClass()->associate($class);
            $game->save();

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
                $row->school_class_id = $class->id;
                $row->save();
            }

            return $game;
        });
    }
}
