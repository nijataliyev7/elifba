<?php

namespace App\Domain\Language\Support;

use SensitiveParameter;

/**
 * How Azerbaijani names are written (docs/architecture.md §7 "Pasting a
 * list", §3 "Voice"): which word of a full name is a surname, read from its
 * ending without a dictionary of names, and which words are a patronymic.
 * One place for the rule the paste parser (`NameListParser`), the display
 * name rule (`DisplayName`) and the board's voice (`NameVoices`) share:
 * «Nihat Əliyev», «Əliyev Nihat» and «Nihat Rəşid oğlu» are all Nihat.
 *
 * A name is never logged: the entry points mark it `#[SensitiveParameter]`.
 */
final class PersonName
{
    /** A sure surname: «Məmmədov», «Əliyeva». */
    public const int SURE = 2;

    /** A likely surname: «Rəsulzadə», «Kərimli». */
    public const int LIKELY = 1;

    /** The word after a patronymic, also as typed without Azerbaijani letters. */
    private const array PATRONYMIC_WORDS = ['oğlu', 'qızı', 'oglu', 'qizi', 'ogly', 'kızı', 'kyzy'];

    /** A patronymic written the Russian way: "Rəşidovna", "Kamaloviç". */
    private const string PATRONYMIC_SUFFIX = '/.{3,}(?:ovna|evna|oviç|eviç|ovich|evich)$/u';

    /** Surname endings that first names do not have (with at least five letters). */
    private const string SURNAME_SUFFIX = '/(?:ov|ova|ev|eva|oğlu|oglu)$/u';

    /** Surname endings that a few first names share ("Şahzadə"). */
    private const string WEAK_SURNAME_SUFFIX = '/(?:zadə|zade)$/u';

    /** The -lı/-li/-lu/-lü surname suffix ("Kərimli"), told apart from "Əli", "Vəli", "Nazlı" by length and the letter before it. */
    private const string LI_SUFFIX = '/l[ıiuü]$/u';

    /** A surname initial at the end of a display name (`Aysel M.`). */
    private const string INITIAL = '/ \p{L}\.$/u';

    /**
     * `SURE` for a sure surname ("Məmmədov", "Əliyeva"), `LIKELY` for a
     * likely one ("Rəsulzadə", "Kərimli"), 0 for a first name ("Əli",
     * "Nazlı"). One word alone may still be a first name with such an
     * ending («Xosrov»): only a word beside another name is taken for a
     * surname.
     */
    public static function surnameScore(#[SensitiveParameter] string $word): int
    {
        $lower = Az::lower($word);
        $length = mb_strlen($lower);

        if ($length >= 5 && preg_match(self::SURNAME_SUFFIX, $lower) === 1) {
            return self::SURE;
        }

        if ($length >= 6 && preg_match(self::WEAK_SURNAME_SUFFIX, $lower) === 1) {
            return self::LIKELY;
        }

        if ($length >= 6 && preg_match(self::LI_SUFFIX, $lower) === 1 && ! Alphabet::isVowel(mb_substr($lower, -3, 1))) {
            return self::LIKELY;
        }

        return 0;
    }

    /**
     * A word of a name as it is written: title case («ƏLİYEV» → «Əliyev»,
     * «ayşən-nur» → «Ayşən-Nur»), and the patronymic's «oğlu», «qızı»
     * lowercase («Rəşid oğlu»).
     */
    public static function cased(#[SensitiveParameter] string $word): string
    {
        return in_array(Az::lower($word), self::PATRONYMIC_WORDS, true) ? Az::lower($word) : Az::title($word);
    }

    /**
     * Removes one patronymic: "Rəşid oğlu" (two words) or "Rəşidovna" (one).
     * The second value says whether it ended the name (null: there was none).
     *
     * @param  list<string>  $words
     * @return array{0: list<string>, 1: bool|null}
     */
    public static function withoutPatronymic(#[SensitiveParameter] array $words): array
    {
        $last = count($words) - 1;

        foreach ($words as $index => $word) {
            if ($index > 0 && in_array(Az::lower($word), self::PATRONYMIC_WORDS, true)) {
                array_splice($words, $index - 1, 2);

                return [$words, $index === $last];
            }
        }

        foreach ($words as $index => $word) {
            if ($index > 0 && preg_match(self::PATRONYMIC_SUFFIX, Az::lower($word)) === 1) {
                array_splice($words, $index, 1);

                return [$words, $index === $last];
            }
        }

        return [$words, null];
    }

    /**
     * Whether a name holds a surname or a patronymic that a display name
     * must not: «Nihat Əliyev», «Əliyeva Aysel», «Nihat Rəşid oğlu». Only a
     * sure surname beside another word counts, so «Xosrov» alone, «Şahzadə»
     * and a first name of two words («Məhəmməd Əli», «Ayşən Nur») pass.
     */
    public static function hasSurname(#[SensitiveParameter] string $name): bool
    {
        $words = self::words($name);
        [$rest, $patronymic] = self::withoutPatronymic($words);

        if ($patronymic !== null) {
            return true;
        }

        if (count($rest) < 2) {
            return false;
        }

        foreach ($rest as $word) {
            if (self::surnameScore($word) === self::SURE) {
                return true;
            }
        }

        return false;
    }

    /**
     * The one first name a name is called by, after a patronymic is dropped
     * («Nihat Rəşid oğlu»): the name itself when it is one word («Nihat»,
     * and «Nihat M.» without the initial); of more, the first word, and
     * whatever follows it is taken as the surname («Nihat Əliyev», «Nicat
     * mp», «Nicat M», «Ayşən Nur» → «Nihat», «Nicat», «Ayşən»; the owner's
     * rule of 2026-11-13, ADR 016) — unless the first word is the surname,
     * written first as in a register («Əliyev Nihat», «Rəsulzadə Aysel»),
     * when it is the second. Null for no name.
     */
    public static function firstName(#[SensitiveParameter] string $name): ?string
    {
        [$words] = self::withoutPatronymic(self::words($name));

        if ($words === []) {
            return null;
        }

        $surnameFirst = count($words) > 1
            && self::surnameScore($words[0]) > self::surnameScore($words[count($words) - 1]);

        return $surnameFirst ? $words[1] : $words[0];
    }

    /**
     * The words of a normalized name, without a trailing surname initial.
     *
     * @return list<string>
     */
    private static function words(#[SensitiveParameter] string $name): array
    {
        $name = preg_replace(self::INITIAL, '', Az::normalize($name)) ?? '';

        return $name === '' ? [] : explode(' ', $name);
    }
}
