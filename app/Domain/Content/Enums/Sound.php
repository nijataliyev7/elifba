<?php

namespace App\Domain\Content\Enums;

/**
 * The moments a pack may give a sound to (`style.sounds` in a pack,
 * docs/architecture.md §3 "Sounds"). An account may choose its own sound
 * for the `choosable()` ones; `arrive` and `leave` (a round coming in and
 * going) belong to the pack alone.
 */
enum Sound: string
{
    case Hit = 'hit';
    case Miss = 'miss';
    case Solved = 'solved';
    case Arrive = 'arrive';
    case Leave = 'leave';

    /**
     * The moments `content/sounds.json` lists sounds for and an account
     * chooses in Settings → Oyun. Every choice path reads this, never
     * `cases()`.
     *
     * @return list<self>
     */
    public static function choosable(): array
    {
        return [self::Hit, self::Miss, self::Solved];
    }

    public function isChoosable(): bool
    {
        return in_array($this, self::choosable(), true);
    }
}
