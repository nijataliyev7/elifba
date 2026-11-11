<?php

namespace App\Domain\Classroom\Support;

use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A student's display name (docs/architecture.md §7): words of Azerbaijani
 * letters separated by single spaces or hyphens, optionally ending in a
 * surname initial with a dot ("Aysel M."), at most 40 characters. Checked on
 * the normalized value, so stray spaces and look-alike letters never count.
 *
 * A parent's child has a first name only (§7 "Parents and their children",
 * ADR 013): `new DisplayName(initial: false, …)` refuses the initial, and any
 * word of one letter, which is an initial without its dot.
 */
final class DisplayName implements ValidationRule
{
    public const MAX_LENGTH = 40;

    private const SHAPE = '/^\p{L}+(?:-\p{L}+)*(?: \p{L}+(?:-\p{L}+)*)*(?: \p{L}\.)?$/u';

    private const FIRST_NAME_SHAPE = '/^\p{L}{2,}(?:-\p{L}{2,})*(?: \p{L}{2,}(?:-\p{L}{2,})*)*$/u';

    /**
     * @param  bool  $initial  whether a surname initial may end the name
     * @param  string  $message  the translation key of the refusal
     */
    public function __construct(
        private readonly bool $initial = true,
        private readonly string $message = 'students.errors.invalid_name',
    ) {}

    /**
     * Whether an already normalized name is a valid display name.
     */
    public static function isValid(string $name, bool $initial = true): bool
    {
        return $name !== ''
            && mb_strlen($name) <= self::MAX_LENGTH
            && preg_match($initial ? self::SHAPE : self::FIRST_NAME_SHAPE, $name) === 1
            && Alphabet::covers($name);
    }

    /**
     * The key two names are compared by: "İlkin" and "ilkin" are the same
     * name, "Ilkin" (with I = ı) is another.
     */
    public static function key(string $name): string
    {
        return Az::lower(Az::normalize($name));
    }

    /**
     * The first word: "Aysel" for "Aysel M.".
     */
    public static function firstName(string $name): string
    {
        return explode(' ', Az::normalize($name), 2)[0];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid(Az::normalize($value), $this->initial)) {
            $fail(__($this->message));
        }
    }
}
