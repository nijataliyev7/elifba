<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TeacherWordRule;
use App\Domain\Language\Support\Az;
use App\Domain\Language\Support\Syllabifier;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A teacher's own word as the teacher adds or edits it
 * (docs/architecture.md §3 "Teacher words", ADR 007): the pack, the word as
 * written and an optional syllable override. `prepare()` normalizes the
 * input (phone keyboards type Cyrillic ә) and drops an override equal to
 * the Syllabifier's split; `rules()` holds the pack-word rules
 * (`WordRules`), the length cap, no collision with the pack's built-in
 * words and a word level that fits. The teacher's own duplicates and the
 * count cap need the teacher: `AddCustomWord` and `UpdateCustomWord` check
 * them.
 */
final readonly class CustomWordData
{
    /**
     * @param  list<string>|null  $syllables  the override; null = the Syllabifier's split
     */
    public function __construct(
        public string $pack,
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
            'pack' => ['required', 'string', Rule::in(array_keys(app(ContentRepository::class)->packs()))],
            'text' => ['required', 'string', 'max:'.$max, TeacherWordRule::text()],
            'syllables' => ['sometimes', 'nullable', 'array', 'list', 'max:'.$max, TeacherWordRule::syllables()],
            'syllables.*' => ['required', 'string', 'max:'.$max],
        ];
    }

    /**
     * Normalizes the word and each override part; an empty override, or one
     * equal to the Syllabifier's split, becomes null.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function prepare(array $input): array
    {
        if (is_string($input['text'] ?? null)) {
            $input['text'] = Az::normalize($input['text']);
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
            pack: is_string($validated['pack'] ?? null) ? $validated['pack'] : '',
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
        return self::fromArray(Validator::make(self::prepare($input), self::rules())->validate());
    }

    /**
     * The model's attributes.
     *
     * @return array{pack: string, text: string, syllables: list<string>|null}
     */
    public function toArray(): array
    {
        return [
            'pack' => $this->pack,
            'text' => $this->text,
            'syllables' => $this->syllables,
        ];
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
