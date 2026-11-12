<?php

namespace App\Domain\Play\Support;

use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Support\NameVoices;
use App\Domain\Play\Data\BoardVoiceData;
use App\Domain\Play\Data\GamePlayers;
use App\Domain\Play\Models\GameSession;
use App\Models\User;

/**
 * How a board gets the clip of a player's first name (docs/architecture.md
 * §3 "Voice", ADR 016 decision 8): through its game, by ids. A name's hash
 * can be computed from the public list and settings, so its clip has no
 * public URL; the board props carry `GET /games/{game}/players/{player}/voice`
 * (two ULIDs, never a name or a hash) and `PlayerVoiceController` answers
 * it with the file this class finds.
 *
 * A player is one of the game's players, read through `GamePlayers`: an
 * active student of the game's class, or the game's child. The clip is
 * given only while the game's owner has praise and names on, and only for
 * a name `NameVoices` can say.
 */
final readonly class PlayerVoice
{
    public function __construct(private NameVoices $names) {}

    /**
     * The clip file of a player's first name, or null: not one of the
     * game's players, a name without a clip, or names or praise off.
     */
    public function file(GameSession $game, string $player): ?string
    {
        $players = GamePlayers::of($game);

        if (! BoardVoiceData::fromStored($players->owner->board_voice)->saysNames()) {
            return null;
        }

        $name = $this->displayName($players, $player);

        return $name === null ? null : $this->names->file($name);
    }

    /**
     * The URL the board fetches a player's clip from, ids only, when there
     * is one to fetch; null otherwise. `$displayName` is the player's, which
     * the caller has already read.
     */
    public function url(GameSession $game, string $player, string $displayName): ?string
    {
        if (! BoardVoiceData::fromStored($game->owner->board_voice)->saysNames() || ! $this->names->has($displayName)) {
            return null;
        }

        return route('games.players.voice', ['game' => $game->id, 'player' => $player], absolute: false);
    }

    /**
     * Whether the boards of `$owner` say a display name, for the class page
     * and a child's card and page (ADR 016 decision 10): null while the
     * account says no name (names or praise off), else whether the name has
     * a clip. Derived on every request, never stored.
     */
    public function says(User $owner, string $displayName): ?bool
    {
        return BoardVoiceData::fromStored($owner->board_voice)->saysNames() ? $this->names->has($displayName) : null;
    }

    /**
     * The display name spelled as Elifba's name list spells its first name
     * («Lale» → «Lalə», `NameVoices::corrected()`), for the pages' one-tap
     * fix: null when it is spelled so already, when it is more than a first
     * name and an initial, or while the account says no name.
     */
    public function spelling(User $owner, string $displayName): ?string
    {
        return BoardVoiceData::fromStored($owner->board_voice)->saysNames() ? $this->names->corrected($displayName) : null;
    }

    /**
     * The display name of one of the game's players, or null when the id is
     * none of them (another class's student, an archived one, another child).
     */
    private function displayName(GamePlayers $players, string $player): ?string
    {
        if ($players->child !== null) {
            return $players->child->id === $player ? $players->child->display_name : null;
        }

        $student = $players->class?->students()->active()->whereKey($player)->first(['id', 'display_name']);

        return $student instanceof Student ? $student->display_name : null;
    }
}
