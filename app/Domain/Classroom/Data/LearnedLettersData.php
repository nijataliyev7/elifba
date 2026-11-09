<?php

namespace App\Domain\Classroom\Data;

use App\Domain\Language\Support\Alphabet;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The letters a class has learned (docs/architecture.md §3): lowercase, in
 * alphabet order, without repeats. All 32 are stored as null ("all"), so a
 * class that has learned everything needs no update when nothing changes.
 */
final readonly class LearnedLettersData
{
    /**
     * @var list<string>|null
     */
    public ?array $letters;

    /**
     * @param  list<string>|null  $letters  lowercase letters of the alphabet; null = all
     *
     * @throws InvalidArgumentException when a letter is not in the alphabet
     */
    public function __construct(?array $letters)
    {
        $sorted = $letters === null ? null : Alphabet::sort($letters);

        $this->letters = $sorted !== null && count($sorted) === Alphabet::count() ? null : $sorted;
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'letters' => ['present', 'nullable', 'array', 'list', 'max:'.Alphabet::count()],
            'letters.*' => ['required', 'string', 'distinct', Rule::in(Alphabet::letters())],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $letters = $validated['letters'] ?? null;

        return new self(is_array($letters) ? array_values(array_filter($letters, is_string(...))) : null);
    }

    /**
     * @param  array<array-key, mixed>  $input
     */
    public static function validate(array $input): self
    {
        return self::fromArray(Validator::make($input, self::rules())->validate());
    }
}
