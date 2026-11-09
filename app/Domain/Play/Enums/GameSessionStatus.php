<?php

namespace App\Domain\Play\Enums;

/**
 * The life of a game (docs/architecture.md §1). A game is created `live` by
 * `StartGame`; only `TransitionGameSession` changes it, along
 * `allowedTransitions()`:
 *
 *     live ──▶ paused ──▶ live
 *     live | paused ──▶ finished    (last round solved or skipped, or the teacher ends it)
 *     live | paused ──▶ abandoned   (no activity for elifba.abandon_after_hours)
 *     finished, abandoned: final
 */
enum GameSessionStatus: string
{
    case Live = 'live';
    case Paused = 'paused';
    case Finished = 'finished';
    case Abandoned = 'abandoned';

    /**
     * The statuses this one may change to.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Live => [self::Paused, self::Finished, self::Abandoned],
            self::Paused => [self::Live, self::Finished, self::Abandoned],
            self::Finished, self::Abandoned => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    /**
     * Live or paused: the game can still be played, and its channel and board
     * stay open.
     */
    public function isOpen(): bool
    {
        return $this === self::Live || $this === self::Paused;
    }

    /**
     * Finished or abandoned: nothing writes to the game any more.
     */
    public function isFinal(): bool
    {
        return ! $this->isOpen();
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $status): bool => $status->isOpen()));
    }
}
