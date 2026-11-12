<?php

namespace App\Domain\Play\Actions;

use App\Domain\Play\Data\GamePlayers;
use App\Domain\Play\Data\SetupModeData;
use App\Domain\Play\Support\SetupMemory;
use Illuminate\Support\Facades\DB;

/**
 * Keeps «Asan» or «Normal» for a class or a child the moment it is tapped
 * on «Yeni oyun» (docs/architecture.md §2 "Remembered setup",
 * docs/frontend.md rule 13), in its `setup_choices` row.
 */
final readonly class RememberSetupMode
{
    public function handle(GamePlayers $players, SetupModeData $data): void
    {
        DB::transaction(static fn () => SetupMemory::rememberMode($players, $data->mode));
    }
}
