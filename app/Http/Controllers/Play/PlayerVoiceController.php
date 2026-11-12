<?php

namespace App\Http\Controllers\Play;

use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Support\PlayerVoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * `GET /games/{game}/players/{player}/voice` (docs/architecture.md §3
 * "Voice", ADR 016 decision 8): the clip of a player's first name, for the
 * board of the game — two ids in the URL, never a name or a hash. `{game}`
 * binds only the account's own games, or a guest board's one game, and the
 * route passes `can:play`; `{player}` must be one of the game's players
 * with a voiced name (`PlayerVoice`), else 404 before a file is read.
 *
 * Kept by no shared cache and by no disk of a shared school computer
 * (`no-store, private`), never sniffed as anything but audio, and with no
 * header that names the file.
 */
final class PlayerVoiceController extends Controller
{
    public function __invoke(GameSession $game, string $player, PlayerVoice $voice): Response
    {
        $file = $voice->file($game, $player);
        $audio = $file === null ? false : file_get_contents($file);

        abort_if($audio === false, 404);

        return new Response($audio, 200, [
            'Content-Type' => 'audio/mpeg',
            'Content-Length' => (string) strlen($audio),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
