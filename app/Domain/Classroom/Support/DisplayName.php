<?php

namespace App\Domain\Classroom\Support;

use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Language\Support\PersonName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A display name (docs/architecture.md §7): words of Azerbaijani letters
 * separated by single spaces or hyphens, optionally ending in a surname
 * initial with a dot ("Aysel M."), at most 40 characters. Checked on the
 * normalized value, so stray spaces and look-alike letters never count.
 *
 * A student's is the name as the teacher writes it, a surname and a
 * patronymic included («Nihat Əliyev Vəli oğlu»; ADR 018).
 *
 * A parent's child has a first name only (§7 "Parents and their children",
 * ADR 013): `new DisplayName(initial: false, surname: false, …)` refuses
 * the initial, any word of one letter (an initial without its dot), and a
 * name with a sure surname beside the first name or a patronymic («Nihat
 * Əliyev», «Nihat Rəşid oğlu»; `PersonName::hasSurname()`) with its own
 * message, which names no one. A first name of two words («Məhəmməd Əli»)
 * and one that ends like a surname («Xosrov») pass.
 */
final class DisplayName implements ValidationRule
{
    public const MAX_LENGTH = 40;

    private const SHAPE = '/^\p{L}+(?:-\p{L}+)*(?: \p{L}+(?:-\p{L}+)*)*(?: \p{L}\.)?$/u';

    private const FIRST_NAME_SHAPE = '/^\p{L}{2,}(?:-\p{L}{2,})*(?: \p{L}{2,}(?:-\p{L}{2,})*)*$/u';

    /**
     * @param  bool  $initial  whether a surname initial may end the name
     * @param  bool  $surname  whether a surname and a patronymic may follow the first name
     * @param  string  $message  the translation key of the refusal
     * @param  string  $surnameMessage  the translation key of the refusal of a surname
     */
    public function __construct(
        private readonly bool $initial = true,
        private readonly bool $surname = true,
        private readonly string $message = 'students.errors.invalid_name',
        private readonly string $surnameMessage = 'children.errors.surname',
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

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid(Az::normalize($value), $this->initial)) {
            $fail(__($this->message));
        } elseif (! $this->surname && PersonName::hasSurname($value)) {
            $fail(__($this->surnameMessage));
        }
    }
}
