<?php

namespace App\Domain\Family\Data;

use App\Domain\Classroom\Data\LearnedLettersData;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The letters a parent's child has learned (docs/architecture.md §3
 * "Learned letters", ADR 011): the same 32 toggles as a class, lowercase,
 * in alphabet order, all 32 stored as null. They filter the reading levels
 * of the child's games only.
 */
final readonly class ChildLettersData
{
    /**
     * @var list<string>|null
     */
    public ?array $letters;

    /**
     * @param  list<string>|null  $letters  lowercase letters of the alphabet; null = all
     */
    public function __construct(?array $letters)
    {
        $this->letters = (new LearnedLettersData($letters))->letters;
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return LearnedLettersData::rules();
    }

    /**
     * @param  array<array-key, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(LearnedLettersData::fromArray($validated)->letters);
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        return self::fromArray(Validator::make($input, self::rules())->validate());
    }
}
