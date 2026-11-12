<?php

namespace App\Domain\Play\Enums;

use App\Domain\Content\Enums\Mechanic;

/**
 * «Asan» or «Normal» on «Yeni oyun» (docs/frontend.md rule 13,
 * docs/architecture.md §2 "Remembered setup"): which games the catalog
 * shows. «Asan» is `pop` alone — the child taps anything and nothing is
 * wrong; «Normal» is every game where the board asks and the child answers.
 * A class starts in «Normal», a child in «Asan».
 */
enum SetupMode: string
{
    case Easy = 'easy';
    case Normal = 'normal';

    /**
     * The mode a mechanic's games are shown in.
     */
    public static function of(Mechanic $mechanic): self
    {
        return $mechanic === Mechanic::Pop ? self::Easy : self::Normal;
    }

    /**
     * The mode before anything was chosen: «Asan» for a child at home,
     * «Normal» for a class.
     */
    public static function first(bool $home): self
    {
        return $home ? self::Easy : self::Normal;
    }
}
