<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\WordData;
use App\Domain\Content\Enums\WordProblem;
use App\Domain\Language\Support\Syllabifier;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The rules on a teacher's word (docs/architecture.md §3 "Teacher words"),
 * on the input `CustomWordData::prepare()` and `HiddenWordData::prepare()`
 * have normalized:
 *
 * - `text()` on a teacher word's `text`: the pack-word rules of `WordRules`
 *   (letters, casing, a vowel), not a built-in word of the pack (hidden or
 *   not), and it fits a word level (with the override when there is one);
 * - `syllables()` on its `syllables`: the override joins back to the word
 *   exactly, with one vowel in each part;
 * - `builtIn()` on a hidden word's `word`: a built-in word of the pack.
 *
 * Each stays quiet when another field is invalid; that field's own rule
 * reports it. Messages are `words.errors.*` and never contain the word.
 */
final class TeacherWordRule implements DataAwareRule, ValidationRule
{
    private const string TEXT = 'text';

    private const string SYLLABLES = 'syllables';

    private const string BUILT_IN = 'built_in';

    /**
     * @var array<array-key, mixed>
     */
    private array $data = [];

    private function __construct(private readonly string $check) {}

    public static function text(): self
    {
        return new self(self::TEXT);
    }

    public static function syllables(): self
    {
        return new self(self::SYLLABLES);
    }

    public static function builtIn(): self
    {
        return new self(self::BUILT_IN);
    }

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
        if (! is_string($value) && ! is_array($value)) {
            return;
        }

        $reason = match ($this->check) {
            self::TEXT => is_string($value) ? $this->textProblem($value) : null,
            self::SYLLABLES => is_array($value) ? $this->splitProblem($value) : null,
            default => is_string($value) ? $this->builtInProblem($value) : null,
        };

        if ($reason !== null) {
            $fail(WordRules::message(...$reason));
        }
    }

    /**
     * @return array{0: string, 1?: array<string, int>}|null
     */
    private function textProblem(string $text): ?array
    {
        $parts = $this->parts();
        $problems = WordRules::problems($text, $parts);

        foreach ([WordProblem::Letters, WordProblem::Casing, WordProblem::NoVowel] as $problem) {
            if (in_array($problem, $problems, true)) {
                return [$problem->value];
            }
        }

        $pack = $this->pack();

        if ($pack === null) {
            return null;
        }

        if ($pack->word($text) !== null) {
            return ['built_in'];
        }

        // A broken override is the `syllables` rule's to report.
        if ($this->hasOverride() && ($parts === null || in_array(WordProblem::Split, $problems, true))) {
            return null;
        }

        $word = new WordData($text, null, $parts ?? Syllabifier::split($text), $parts !== null);

        return WordRules::fitsALevel($word, app(ContentRepository::class)->levels())
            ? null
            : ['no_level', ['count' => count($word->syllables)]];
    }

    /**
     * @param  array<array-key, mixed>  $parts
     * @return array{0: string}|null
     */
    private function splitProblem(array $parts): ?array
    {
        $text = $this->data['text'] ?? null;

        if ($parts === [] || ! is_string($text) || ! WordRules::isWord($text) || ! self::isListOfStrings($parts)) {
            return null;
        }

        /** @var list<string> $parts */
        return in_array(WordProblem::Split, WordRules::problems($text, $parts), true) ? [WordProblem::Split->value] : null;
    }

    /**
     * @return array{0: string}|null
     */
    private function builtInProblem(string $word): ?array
    {
        $pack = $this->pack();

        return $pack !== null && $pack->word($word) === null ? ['not_in_pack'] : null;
    }

    private function pack(): ?PackData
    {
        $key = $this->data['pack'] ?? null;
        $content = app(ContentRepository::class);

        return is_string($key) && $content->hasPack($key) ? $content->pack($key) : null;
    }

    private function hasOverride(): bool
    {
        return ($this->data['syllables'] ?? null) !== null;
    }

    /**
     * The override when it is a non-empty list of strings, null otherwise.
     *
     * @return list<string>|null
     */
    private function parts(): ?array
    {
        $parts = $this->data['syllables'] ?? null;

        return is_array($parts) && $parts !== [] && self::isListOfStrings($parts) ? $parts : null;
    }

    /**
     * @param  array<array-key, mixed>  $value
     *
     * @phpstan-assert-if-true list<string> $value
     */
    private static function isListOfStrings(array $value): bool
    {
        return array_is_list($value) && array_filter($value, static fn (mixed $part): bool => ! is_string($part)) === [];
    }
}
