<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TeacherWordRule;
use App\Domain\Language\Support\Az;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A built-in word a teacher hides from their own games, or shows again
 * (docs/architecture.md §3 "Teacher words", ADR 007): the pack and the word,
 * compared with Azerbaijani casing. Both travel in a request body, never in
 * a URL.
 */
final readonly class HiddenWordData
{
    /**
     * The longest word the input may hold; built-in words are far shorter.
     */
    public const int MAX_LENGTH = 64;

    public function __construct(
        public string $pack,
        public string $word,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'pack' => ['required', 'string', Rule::in(array_keys(app(ContentRepository::class)->packs()))],
            'word' => ['required', 'string', 'max:'.self::MAX_LENGTH, TeacherWordRule::builtIn()],
        ];
    }

    /**
     * The field names in validation messages, in the interface language.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        $name = static function (string $field): string {
            $name = __("words.attributes.{$field}");

            return is_string($name) ? $name : $field;
        };

        return ['pack' => $name('pack'), 'word' => $name('text')];
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function prepare(array $input): array
    {
        if (is_string($input['word'] ?? null)) {
            $input['word'] = Az::normalize($input['word']);
        }

        return $input;
    }

    /**
     * @param  array<array-key, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            pack: is_string($validated['pack'] ?? null) ? $validated['pack'] : '',
            word: is_string($validated['word'] ?? null) ? $validated['word'] : '',
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
     * `word:{lower}`, the key the built-in word plays under.
     */
    public function key(): string
    {
        return 'word:'.Az::lower($this->word);
    }
}
