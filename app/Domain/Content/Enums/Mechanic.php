<?php

namespace App\Domain\Content\Enums;

/**
 * The rules of a round (docs/architecture.md §2). The rules themselves are
 * code in Play (round builders, scenes); the enum lives in Content because
 * `content/levels.json` and `content/prompts.json` name mechanics and the
 * validator must check them, and Content may not use Play.
 */
enum Mechanic: string
{
    /** A prompt names a target; one hit on it solves the round. */
    case Find = 'find';

    /** Build the shown syllable from letters. */
    case BuildSyllable = 'build_syllable';

    /** Build a word from its syllables. */
    case BuildWord = 'build_word';

    /** Put a sentence's words in order. */
    case BuildSentence = 'build_sentence';

    /**
     * The units a level may combine this mechanic with.
     *
     * @return list<Unit>
     */
    public function units(): array
    {
        return match ($this) {
            self::Find => [Unit::Letter, Unit::Syllable, Unit::Word],
            self::BuildSyllable => [Unit::Syllable],
            self::BuildWord => [Unit::Word],
            self::BuildSentence => [Unit::Sentence],
        };
    }

    public function supports(Unit $unit): bool
    {
        return in_array($unit, $this->units(), true);
    }

    /**
     * Whether a round is solved by putting tiles into slots in order (and so
     * adds decoy tiles) rather than by one hit.
     */
    public function builds(): bool
    {
        return $this !== self::Find;
    }
}
