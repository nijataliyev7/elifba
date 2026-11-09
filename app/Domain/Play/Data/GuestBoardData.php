<?php

namespace App\Domain\Play\Data;

use App\Domain\Play\Models\GuestBoard;

/**
 * A guest board as its own tab sees it (docs/architecture.md §4): while
 * unclaimed, its pairing code; once claimed, the claiming teacher's name
 * («Mənim deyil — yeni kod») and the game it shows. Never the secret.
 *
 * @phpstan-import-type PairingArray from PairingData
 *
 * @phpstan-type GuestBoardArray array{id: string, expires_at: string, pairing: PairingArray|null, teacher: array{name: string}|null, game: array{id: string, status: string}|null}
 */
final readonly class GuestBoardData
{
    public function __construct(private GuestBoard $board) {}

    /**
     * @return GuestBoardArray
     */
    public function toArray(): array
    {
        $board = $this->board;
        $game = $board->game;

        return [
            'id' => $board->id,
            'expires_at' => $board->expires_at->utc()->toIso8601ZuluString(),
            'pairing' => $board->claimed_at === null && $board->code !== null && $board->code_expires_at !== null
                ? (new PairingData($board->code, $board->code_expires_at))->toArray()
                : null,
            'teacher' => $board->claimed_at !== null && $board->teacher !== null ? ['name' => $board->teacher->name] : null,
            'game' => $game === null ? null : ['id' => $game->id, 'status' => $game->status->value],
        ];
    }
}
