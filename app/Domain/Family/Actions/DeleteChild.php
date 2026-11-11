<?php

namespace App\Domain\Family\Actions;

use App\Domain\Family\Models\Child;

/**
 * Deletes a child for good (docs/architecture.md §7 "Parents and their
 * children", ADR 011): the row, and by cascade the child's games, their
 * rounds and answers — the stars are gone. There is no archive: at home
 * there is no class practice to keep. A guest board that showed one of the
 * games shows nothing (`guest_boards.game_session_id` is nulled).
 */
final readonly class DeleteChild
{
    public function handle(Child $child): void
    {
        $child->delete();
    }
}
