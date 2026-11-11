<?php

namespace App\Domain\Play\Data;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Family\Models\Child;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Play\Models\GameSession;
use App\Models\User;
use LogicException;

/**
 * Who a game is for: a class or one parent's child (docs/architecture.md
 * §1, §2 "Game setup", ADR 011). It is the one thing Play knows about it:
 * the owner whose packs and words the game plays, the learned letters that
 * filter its reading levels, and the class or the child the game and its
 * rounds are stored for. In a home game the child is always the one at the
 * board, so no student is ever read or stored. A third kind of player later
 * is one more constructor here.
 */
final readonly class GamePlayers
{
    private function __construct(
        public User $owner,
        public ?SchoolClass $class,
        public ?Child $child,
    ) {}

    public static function forClass(SchoolClass $class): self
    {
        return new self($class->teacher, $class, null);
    }

    public static function forChild(Child $child): self
    {
        return new self($child->parent, null, $child);
    }

    /**
     * Who a stored game is for.
     *
     * @throws LogicException when the game has neither a class nor a child
     */
    public static function of(GameSession $game): self
    {
        if ($game->child !== null) {
            return self::forChild($game->child);
        }

        return self::forClass($game->schoolClass ?? throw new LogicException("Game {$game->id} is for nobody."));
    }

    /**
     * Whether the game is a parent's home game for one child.
     */
    public function isHome(): bool
    {
        return $this->child !== null;
    }

    /**
     * The learned letters of the class or the child, lowercase in alphabet
     * order (all 32 when they have not been narrowed).
     *
     * @return list<string>
     */
    public function learned(): array
    {
        return ($this->child !== null ? $this->child->learned_letters : $this->class?->learned_letters) ?? Alphabet::letters();
    }

    /**
     * The columns a game and each of its rounds store: exactly one is set.
     *
     * @return array{school_class_id: string|null, child_id: string|null}
     */
    public function columns(): array
    {
        return [
            'school_class_id' => $this->class?->id,
            'child_id' => $this->child?->id,
        ];
    }
}
