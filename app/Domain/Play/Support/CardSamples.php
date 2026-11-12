<?php

namespace App\Domain\Play\Support;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Support\Mechanics\RoundParts;

/**
 * What a game card's scene shows (docs/architecture.md §2 "Game setup",
 * docs/frontend.md rule 13): at most three strings, as the board would show
 * them, from the pack's built-in content the owner has not hidden (never a
 * teacher word) and the players' learned letters, at the card's default
 * level, cased with the remembered case. No randomness, so the setup's
 * props are the same on every visit: the first items of the level's pool,
 * in pack order.
 */
final class CardSamples
{
    private const int MOST = 3;

    /**
     * `pop` and `find`: three items (letters, syllables, words; at a number
     * level three numbers of its range); `build_syllable` the letters of its
     * first syllable, `build_word` the syllables of its first word,
     * `build_sentence` the words of its first sentence; `count` three
     * numbers of the range around 3; nothing at a picture level.
     *
     * @param  PackData  $pack  the built-in pack without the words the owner hid
     * @param  list<string>  $learned  lowercase, alphabet order
     * @return list<string>
     */
    public static function of(Mechanic $mechanic, PackData $pack, LevelData $level, array $learned, LetterCase $case): array
    {
        if ($level->unit === Unit::Picture) {
            return [];
        }

        $written = match ($mechanic) {
            Mechanic::Count => self::counted($level),
            Mechanic::BuildSyllable, Mechanic::BuildWord, Mechanic::BuildSentence => self::parts($mechanic, $pack, $level, $learned),
            Mechanic::Find, Mechanic::Pop => $level->unit === Unit::Letter
                ? $learned
                : array_map(static fn (string $key): string => RoundParts::written($key, $pack), $level->keysFrom($pack, $learned)),
        };

        return array_map(
            $level->unit->reads() ? $case->apply(...) : static fn (string $text): string => $text,
            array_slice($written, 0, self::MOST),
        );
    }

    /**
     * The parts of a build game's first answer, as written.
     *
     * @param  list<string>  $learned
     * @return list<string>
     */
    private static function parts(Mechanic $mechanic, PackData $pack, LevelData $level, array $learned): array
    {
        foreach ($level->keysFrom($pack, $learned) as $key) {
            $value = RoundParts::value($key);
            $word = $mechanic === Mechanic::BuildWord ? $pack->word($value) : null;

            $parts = match ($mechanic) {
                Mechanic::BuildSyllable => mb_str_split($value),
                // `build_word` asks with the word's picture.
                Mechanic::BuildWord => $word?->image === null ? null : $word->syllables,
                default => $pack->sentence(explode(':', $value, 2)[1] ?? $value)?->words(),
            };

            if ($parts !== null && $parts !== []) {
                return $parts;
            }
        }

        return [];
    }

    /**
     * Three numbers of a number level's range, the middle one 3 where the
     * range allows (the scene rings it under three pictures).
     *
     * @return list<string>
     */
    private static function counted(LevelData $level): array
    {
        if ($level->numbers === null) {
            return [];
        }

        $first = max($level->numbers->min, min($level->numbers->min + 1, $level->numbers->max - self::MOST + 1));

        return array_map(strval(...), range($first, min($level->numbers->max, $first + self::MOST - 1)));
    }
}
