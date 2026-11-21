<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Models\RoomGame;
use Illuminate\Support\Facades\Config;
use Random\Randomizer;

/**
 * A room game's random source (docs/architecture.md §12 "The race", ADR 021
 * decision 3). A game stores a seed, so its rounds can be told again from
 * it; the seed is never served. The source is a keyed one
 * (`RoomRandomEngine`), its key derived from the seed and the instance's
 * `app.key`: the seed fits in 31 bits, so a player who knew only the
 * algorithm could otherwise try every seed against round 0's shown items
 * and their ids; and a statistical generator's state can be worked back
 * from enough of its output. Either way the later rounds would be known
 * before they open. Without the key, the shown items say nothing of the
 * others.
 */
final class RoomSeed
{
    /** A new game's seed. */
    public static function fresh(): int
    {
        return random_int(1, RoomGame::MAX_SEED);
    }

    /**
     * The source the computer takes its move at a ply of a game from (K3,
     * ADR 036 decision 7): the same each time, and nothing a page can work
     * out ahead.
     */
    public static function computer(string $gameId, int $ply): Randomizer
    {
        $key = Config::get('app.key');

        return new Randomizer(new RoomRandomEngine(hash_hmac('sha256', 'room-computer:'.$gameId.':'.$ply, is_string($key) ? $key : '', true)));
    }

    /** The source a game with this seed builds its rounds from, the same each time. */
    public static function random(int $seed): Randomizer
    {
        $key = Config::get('app.key');

        return new Randomizer(new RoomRandomEngine(hash_hmac('sha256', 'room-game:'.$seed, is_string($key) ? $key : '', true)));
    }
}
