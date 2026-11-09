<?php

namespace App\Domain\Play\Actions;

use App\Domain\Play\Models\GuestBoard;
use Illuminate\Database\Eloquent\Builder;

/**
 * Deletes guest boards that can no longer be used (docs/architecture.md
 * §1, §4): closed, past `expires_at`, or never claimed before their code
 * expired. Scheduled hourly (`play:prune-guest-boards` in
 * routes/console.php). Games are untouched.
 */
final readonly class PruneGuestBoards
{
    /**
     * @return int how many boards were deleted
     */
    public function handle(): int
    {
        $now = now();

        return (int) GuestBoard::query()
            ->where(static fn (Builder $query) => $query
                ->whereNotNull('closed_at')
                ->orWhere('expires_at', '<=', $now)
                ->orWhere(static fn (Builder $query) => $query
                    ->whereNull('claimed_at')
                    ->where(static fn (Builder $query) => $query
                        ->whereNull('code_expires_at')
                        ->orWhere('code_expires_at', '<=', $now))))
            ->delete();
    }
}
