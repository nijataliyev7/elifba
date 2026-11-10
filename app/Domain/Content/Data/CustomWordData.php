<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\TeacherWordRule;
use App\Domain\Language\Support\Az;
use App\Domain\Language\Support\Syllabifier;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A teacher's own word as the teacher adds or edits it
 * (docs/architecture.md §3 "Teacher words", ADR 007, ADR 009): the word as
 * written and an optional syllable override; it plays in every pack. `prepare()` reads an override
 * typed as one string with hyphens (`pom-i-dor`, the page's field) as its
 * parts, normalizes the input (phone keyboards type Cyrillic ә) and drops an
 * override equal to the Syllabifier's split; `rules()` holds the pack-word rules
 * (`WordRules`), the length cap and a word level that fits. The teacher's own duplicates and the
 * count cap need the teacher: `AddCustomWord` and `UpdateCustomWord` check
 * them.
 */
final readonly class CustomWordData
{
    /**
     * @param  list<string>|null  $syllables  the override; null = the Syllabifier's split
     */
    public function __construct(
        public string $text,
        public ?array $syllables = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $max = Config::integer('elifba.custom_words.max_length');

        return [
            'text' => ['required', 'string', 'max:'.$max, TeacherWordRule::text()],
            'syllables' => ['sometimes', 'nullable', 'array', 'list', 'max:'.$max, TeacherWordRule::syllables()],
            'syllables.*' => ['required', 'string', 'max:'.$max],
        ];
    }

    /**
     * The field names in validation messages, in the interface language.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'text' => self::attribute('text'),
            'syllables' => self::attribute('syllables'),
            'syllables.*' => self::attribute('syllables'),
        ];
    }

    /**
     * An override typed as one string, split at its hyphens (a dash a phone
     * keyboard puts in place of one counts too); empty parts are dropped,
     * so a blank field is no override.
     *
     * @return list<string>
     */
    public static function parseSyllables(string $typed): array
    {
        $parts = preg_split('/[-\x{2010}\x{2011}\x{2012}\x{2013}\x{2014}\x{2212}]/u', $typed) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $part): string => Az::normalize($part), $parts),
            static fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * Normalizes the word and each override part; an override typed as one
     * string is split at its hyphens first. An empty override, or one equal
     * to the Syllabifier's split, becomes null.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function prepare(array $input): array
    {
        if (is_string($input['text'] ?? null)) {
            $input['text'] = Az::normalize($input['text']);
        }

        if (is_string($input['syllables'] ?? null)) {
            $input['syllables'] = self::parseSyllables($input['syllables']);
        }

        if (is_array($input['syllables'] ?? null)) {
            $parts = array_map(static fn (mixed $part): mixed => is_string($part) ? Az::normalize($part) : $part, $input['syllables']);
            $input['syllables'] = $parts === [] || self::byRule($input['text'] ?? null, $parts) ? null : $parts;
        }

        return $input;
    }

    /**
     * @param  array<array-key, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $text = is_string($validated['text'] ?? null) ? $validated['text'] : '';
        $syllables = is_array($validated['syllables'] ?? null)
            ? array_values(array_filter($validated['syllables'], is_string(...)))
            : null;

        return new self(
            text: $text,
            syllables: $syllables === [] || self::byRule($text, $syllables ?? []) ? null : $syllables,
        );
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        return self::fromArray(Validator::make(self::prepare($input), self::rules(), [], self::attributes())->validate());
    }

    /**
     * The model's attributes.
     *
     * @return array{text: string, syllables: list<string>|null}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'syllables' => $this->syllables,
        ];
    }

    private static function attribute(string $field): string
    {
        $name = __("words.attributes.{$field}");

        return is_string($name) ? $name : $field;
    }

    /**
     * Whether the parts are exactly the Syllabifier's split of the text.
     *
     * @param  array<array-key, mixed>  $parts
     */
    private static function byRule(mixed $text, array $parts): bool
    {
        return is_string($text) && $parts === Syllabifier::split($text);
    }
}
