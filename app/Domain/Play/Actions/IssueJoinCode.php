<?php

namespace App\Domain\Play\Actions;

use App\Domain\Play\Data\GameStatusData;
use App\Domain\Play\Exceptions\GameOver;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Support\PairingCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Puts a pairing code on a teacher board's game (docs/architecture.md §4):
 * the board asks for one when it shows the pairing card (on load and on
 * «Pultu qoş»). A code still valid is kept, so a QR already on the wall
 * keeps working and a phone can scan it again; an expired one is replaced.
 * `TransitionGameSession` clears it when the game ends; a game that has
 * ended gets none.
 */
final readonly class IssueJoinCode
{
    public function __construct(private PairingCode $codes) {}

    /**
     * @throws GameOver when the game is finished or abandoned
     */
    public function handle(GameSession $game): GameSession
    {
        $locked = $this->current($game) ?? $this->codes->issue(
            fn (string $code, CarbonImmutable $expiresAt): GameSession => DB::transaction(function () use ($game, $code, $expiresAt): GameSession {
                $locked = $this->lock($game);

                if ($locked->join_code === null || ! ($locked->join_code_expires_at?->isFuture() ?? false)) {
                    $locked->join_code = $code;
                    $locked->join_code_expires_at = $expiresAt;
                    $locked->save();
                }

                return $locked;
            }),
        );

        return $game->setRawAttributes($locked->getAttributes(), true);
    }

    /**
     * The game, locked, when its code is still valid; null when it needs one.
     */
    private function current(GameSession $game): ?GameSession
    {
        return DB::transaction(function () use ($game): ?GameSession {
            $locked = $this->lock($game);

            return $locked->join_code !== null && ($locked->join_code_expires_at?->isFuture() ?? false) ? $locked : null;
        });
    }

    /**
     * @throws GameOver
     */
    private function lock(GameSession $game): GameSession
    {
        $locked = GameSession::query()->whereKey($game->id)->lockForUpdate()->firstOrFail();

        if (! $locked->status->isOpen()) {
            throw new GameOver(GameStatusData::of($locked));
        }

        return $locked;
    }
}
