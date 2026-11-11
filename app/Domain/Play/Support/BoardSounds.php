<?php

namespace App\Domain\Play\Support;

use App\Domain\Content\Data\PackData;
use App\Domain\Content\Enums\Sound;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\SoundChoices;
use App\Models\User;

/**
 * The sound URLs a board plays (docs/architecture.md §3 "Sounds"), one per
 * moment: for a choosable one the sound the game's owner chose in Settings
 * → Oyun, when `content/sounds.json` still lists it and it suits the pack's
 * effect (a pop is never chosen for a pack whose items sparkle, §8);
 * otherwise, and for `arrive` and `leave` always, the pack's own or null.
 * Each URL carries the version of the file that lists it.
 */
final class BoardSounds
{
    /**
     * @return array<value-of<Sound>, string|null>
     */
    public static function of(PackData $pack, User $owner): array
    {
        $chosen = $owner->board_sounds ?? [];
        $sounds = [];

        foreach (Sound::cases() as $sound) {
            $key = $sound->isChoosable() ? ($chosen[$sound->value] ?? null) : null;
            $choice = is_string($key) ? SoundChoices::find($sound, $key) : null;

            if ($choice !== null && $choice->suits($pack->style->effect)) {
                $sounds[$sound->value] = SoundChoices::url($choice);

                continue;
            }

            $path = $pack->style->sound($sound);
            $sounds[$sound->value] = $path === null ? null : ContentAssets::url($path, $pack->version);
        }

        return $sounds;
    }
}
