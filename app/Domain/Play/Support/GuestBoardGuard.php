<?php

namespace App\Domain\Play\Support;

use App\Domain\Play\Models\GuestBoard;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The `board` guard (`Auth::viaRequest('board', …)`, docs/architecture.md
 * §4): reads `Authorization: Bearer {board_id}.{secret}`, finds the board,
 * compares the secret's hash with `hash_equals` and refuses a closed or
 * expired board. It returns the `GuestBoard`, never a teacher.
 */
final class GuestBoardGuard
{
    public function __invoke(Request $request): ?GuestBoard
    {
        $token = $request->bearerToken();

        if ($token === null || ! str_contains($token, '.')) {
            return null;
        }

        [$id, $secret] = explode('.', $token, 2);

        if ($secret === '' || ! Str::isUlid($id)) {
            return null;
        }

        $board = GuestBoard::query()->find($id);

        if ($board === null || ! $board->matchesSecret($secret) || ! $board->isActive()) {
            return null;
        }

        return $board;
    }
}
