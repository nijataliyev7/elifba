<?php

namespace App\Domain\Play\Support\Mechanics;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Data\RoundData;
use Random\Randomizer;

/**
 * The rules of one mechanic on the server (docs/architecture.md §2): which
 * content it needs and how it builds a game's rounds. One implementation
 * per mechanic, listed by `Playable::builder()`.
 */
interface RoundBuilder
{
    /**
     * Why the content left by the class's learned letters cannot make this
     * game, as a message for the teacher; null when it can.
     *
     * @param  list<string>  $learned  the class's learned letters, lowercase
     * @param  list<string>|null  $letters  the teacher's choice for a letter level
     */
    public function shortage(PackData $pack, LevelData $level, array $learned, ?array $letters = null): ?string;

    /**
     * Every round of a game, in order, from `$pack`: the setup's pack as the
     * class's teacher plays it (`Content\Support\TeacherPacks`), the same
     * pack `shortage()` was asked about. The same setup, pack, letters and
     * seed give the same rounds (only the round ids differ).
     *
     * @param  list<string>  $learned
     * @return list<RoundData>
     */
    public function build(GameSetupData $setup, PackData $pack, array $learned, Randomizer $random): array;
}
