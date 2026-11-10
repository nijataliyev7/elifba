<?php

namespace App\Domain\Play\Support;

use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TeacherPacks;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The rule on `level` in `GameSetupData::rules()`: the chosen mechanic,
 * pack and level make a game the board can draw, with enough content made
 * of the class's learned letters (`Playable::reason()`) in the pack as the
 * class's teacher plays it (`TeacherPacks`, with only their own words when
 * `own_words` is on), the pack `StartGame` builds from. It stays quiet when another field is invalid; that field's own rule
 * reports it.
 */
final class StartableGame implements DataAwareRule, ValidationRule
{
    /**
     * @var array<array-key, mixed>
     */
    private array $data = [];

    /**
     * @param  list<string>  $learned
     */
    public function __construct(
        private readonly array $learned,
        private readonly User $teacher,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $content = app(ContentRepository::class);
        $mechanic = is_string($this->data['mechanic'] ?? null) ? Mechanic::tryFrom($this->data['mechanic']) : null;
        $pack = is_string($this->data['pack'] ?? null) && $content->hasPack($this->data['pack'])
            ? app(TeacherPacks::class)->pack($this->teacher, $this->data['pack'], $this->ownWords())
            : null;
        $level = is_string($value) ? ($content->levels()[$value] ?? null) : null;

        if ($mechanic === null || $pack === null || $level === null) {
            return;
        }

        $reason = Playable::reason($mechanic, $pack, $level, $this->learned, $this->letters(), $this->ownWords());

        if ($reason !== null) {
            $fail($reason);
        }
    }

    /**
     * Whether only the teacher's own words are asked for; an invalid value
     * counts as no (its own rule reports it).
     */
    private function ownWords(): bool
    {
        return filter_var($this->data['own_words'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }

    /**
     * The chosen letters that are learned, or null when none were chosen.
     *
     * @return list<string>|null
     */
    private function letters(): ?array
    {
        $letters = $this->data['letters'] ?? null;

        if (! is_array($letters) || $letters === []) {
            return null;
        }

        return array_values(array_intersect($this->learned, array_filter($letters, is_string(...))));
    }
}
