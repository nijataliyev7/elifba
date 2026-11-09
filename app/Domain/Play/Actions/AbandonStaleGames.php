<?php

namespace App\Domain\Play\Actions;

use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Models\GameSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Abandons live and paused games with no activity (start, claim, sync,
 * status change) for `elifba.abandon_after_hours` (docs/architecture.md
 * §1). Each goes through `TransitionGameSession`. Scheduled hourly
 * (`play:abandon-stale` in routes/console.php).
 */
final readonly class AbandonStaleGames
{
    public function __construct(private TransitionGameSession $transition) {}

    /**
     * @return int how many games were abandoned
     */
    public function handle(): int
    {
        $cutoff = now()->subHours(Config::integer('elifba.abandon_after_hours'));
        $abandoned = 0;

        $ids = GameSession::query()->open()->where('last_activity_at', '<', $cutoff)->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            $abandoned += DB::transaction(fn (): int => $this->abandon((string) $id, $cutoff));
        }

        return $abandoned;
    }

    /**
     * Checks again under the row lock, so a board that synced a moment ago
     * keeps its game.
     */
    private function abandon(string $id, CarbonImmutable $cutoff): int
    {
        $game = GameSession::query()->whereKey($id)->lockForUpdate()->first();

        if ($game === null || ! $game->status->isOpen() || $game->last_activity_at->gte($cutoff)) {
            return 0;
        }

        $this->transition->handle($game, GameSessionStatus::Abandoned);

        return 1;
    }
}
