<?php

namespace App\Domain\Play\Actions;

use App\Domain\Play\Models\GameSession;
use Illuminate\Support\Facades\Config;

/**
 * Deletes games started more than `elifba.retention_days` ago, with their
 * rounds and answers (the foreign keys cascade; docs/architecture.md §7).
 * Their points leave the rankings with them. Scheduled daily
 * (`play:prune-old-games` in routes/console.php).
 */
final readonly class PruneOldGames
{
    /**
     * @return int how many games were deleted
     */
    public function handle(): int
    {
        $cutoff = now()->subDays(Config::integer('elifba.retention_days'));

        return (int) GameSession::query()->where('started_at', '<', $cutoff)->delete();
    }
}
