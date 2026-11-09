<?php

namespace App\Domain\Classroom\Support;

use App\Domain\Classroom\Data\PastedNameData;
use App\Domain\Classroom\Enums\PastedNameFlag;
use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use SensitiveParameter;

/**
 * Turns a pasted list of names into preview lines: a suggested display name
 * and flags per line (docs/architecture.md §7, "Pasting a list"). It writes
 * nothing; the teacher confirms and `AddStudents` stores the result.
 *
 * Per line: tab columns without a name (numbers, dates) are dropped, the text
 * is normalized, numbering and bullets are stripped, words with digits are
 * dropped, a patronymic ("Rəşid oğlu", "Rəşidovna") is removed, and the order
 * (first name first, or surname first as in school registers) is read from
 * the surname suffixes. Only the first name is kept; a surname initial is
 * added only when two first names collide.
 *
 * The pasted text and its lines are marked `#[SensitiveParameter]` on the
 * entry points and line readers, so a stack trace in a log shows neither.
 */
final readonly class NameListParser
{
    /** The word after a patronymic, also as typed without Azerbaijani letters. */
    private const PATRONYMIC_WORDS = ['oğlu', 'qızı', 'oglu', 'qizi', 'ogly', 'kızı', 'kyzy'];

    /** A patronymic written the Russian way: "Rəşidovna", "Kamaloviç". */
    private const PATRONYMIC_SUFFIX = '/.{3,}(?:ovna|evna|oviç|eviç|ovich|evich)$/u';

    /** Surname endings that first names do not have (with at least five letters). */
    private const SURNAME_SUFFIX = '/(?:ov|ova|ev|eva|oğlu|oglu)$/u';

    /** Surname endings that a few first names share ("Şahzadə"). */
    private const WEAK_SURNAME_SUFFIX = '/(?:zadə|zade)$/u';

    /** The -lı/-li/-lu/-lü surname suffix ("Kərimli"), told apart from "Əli", "Vəli", "Nazlı" by length and the letter before it. */
    private const LI_SUFFIX = '/l[ıiuü]$/u';

    private const NUMBERING = '/^(?:\(?[№#]?\s?\d+\s?[.)\]:\-–—]*|[\-–—•·*◦▪●○■□►▸➢✓✔+>]+)\s*/u';

    private const FIRST = 'first';

    private const SURNAME = 'surname';

    /**
     * @param  iterable<string>  $activeNames  display names of the class's active students
     * @return list<PastedNameData>
     */
    public function parse(#[SensitiveParameter] string $text, iterable $activeNames = []): array
    {
        $lines = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $index => $raw) {
            $line = $this->read($raw, $index + 1);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        $lines = $this->resolveOrder($lines);

        return $this->suggest($lines, $activeNames);
    }

    /**
     * Parses against the class's active students (archived ones do not count).
     *
     * @return list<PastedNameData>
     */
    public function parseForClass(SchoolClass $class, #[SensitiveParameter] string $text): array
    {
        return $this->parse($text, Student::query()
            ->where('school_class_id', $class->id)
            ->active()
            ->pluck('display_name')
            ->all());
    }

    /**
     * @return array{line: int, text: string, key: string, names: list<string>, initials: list<string>, order: string|null, patronymic_at_end: bool|null, invalid: bool}|null
     */
    private function read(#[SensitiveParameter] string $raw, int $number): ?array
    {
        $cells = array_map(fn (string $cell): string => $this->stripNumbering(Az::normalize($cell)), explode("\t", $raw));

        if (count($cells) > 1) {
            // Columns pasted from Excel or Word: keep the ones with a name.
            $cells = array_filter($cells, static fn (string $cell): bool => preg_match('/\p{L}/u', $cell) === 1 && preg_match('/\d/u', $cell) !== 1);
        }

        $text = $this->stripNumbering(Az::normalize(implode(' ', $cells)));

        if (preg_match('/\p{L}/u', $text) !== 1) {
            return null; // blank, numbering only or a separator line
        }

        $words = [];
        $invalid = false;

        foreach (explode(' ', $text) as $word) {
            if (preg_match('/\d/u', $word) === 1) {
                continue; // a date, a number, an id
            }

            $core = preg_replace(['/^[^\p{L}]+|[^\p{L}]+$/u', '/[\'’ʼ‘`]/u'], '', $word) ?? '';

            if ($core === '') {
                continue;
            }

            if (preg_match('/^\p{L}+(?:-\p{L}+)*$/u', $core) !== 1 || ! Alphabet::covers($core)) {
                $invalid = true;
            }

            $words[] = $core;
        }

        [$words, $patronymicAtEnd] = $this->withoutPatronymic($words);

        $names = [];
        $initials = [];

        foreach ($words as $word) {
            if (mb_strlen($word) === 1) {
                $initials[] = $word;
            } else {
                $names[] = $word;
            }
        }

        $order = null;

        if (count($names) >= 2) {
            $first = $this->surnameScore($names[0]);
            $last = $this->surnameScore($names[count($names) - 1]);

            $order = match (true) {
                $first > $last => self::SURNAME,
                $last > $first => self::FIRST,
                $patronymicAtEnd === true => self::SURNAME,
                $patronymicAtEnd === false => self::FIRST,
                default => null,
            };
        } elseif ($names === [] || $this->surnameScore($names[0]) === 2) {
            $invalid = true; // only initials, or only a surname: no first name to keep
        }

        return [
            'line' => $number,
            'text' => $text,
            'key' => Az::lower(implode(' ', $words)),
            'names' => $names,
            'initials' => $initials,
            'order' => $order,
            'patronymic_at_end' => $patronymicAtEnd,
            'invalid' => $invalid,
        ];
    }

    private function stripNumbering(#[SensitiveParameter] string $text): string
    {
        for ($i = 0; $i < 3; $i++) {
            $stripped = preg_replace(self::NUMBERING, '', $text) ?? $text;

            if ($stripped === $text) {
                break;
            }

            $text = $stripped;
        }

        return $text;
    }

    /**
     * Removes one patronymic: "Rəşid oğlu" (two words) or "Rəşidovna" (one).
     * The second value says whether it ended the line (null: there was none).
     *
     * @param  list<string>  $words
     * @return array{0: list<string>, 1: bool|null}
     */
    private function withoutPatronymic(array $words): array
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
     * 2 for a sure surname ("Məmmədov", "Əliyeva"), 1 for a likely one
     * ("Rəsulzadə", "Kərimli"), 0 for a first name ("Əli", "Nazlı").
     */
    private function surnameScore(#[SensitiveParameter] string $word): int
    {
        $lower = Az::lower($word);
        $length = mb_strlen($lower);

        if ($length >= 5 && preg_match(self::SURNAME_SUFFIX, $lower) === 1) {
            return 2;
        }

        if ($length >= 6 && preg_match(self::WEAK_SURNAME_SUFFIX, $lower) === 1) {
            return 1;
        }

        if ($length >= 6 && preg_match(self::LI_SUFFIX, $lower) === 1 && ! Alphabet::isVowel(mb_substr($lower, -3, 1))) {
            return 1;
        }

        return 0;
    }

    /**
     * Lines whose order the suffixes do not reveal follow the rest of the
     * list; when the list does not say either, first name first.
     *
     * @param  list<array{line: int, text: string, key: string, names: list<string>, initials: list<string>, order: string|null, patronymic_at_end: bool|null, invalid: bool}>  $lines
     * @return list<array{line: int, text: string, key: string, names: list<string>, initials: list<string>, order: string|null, patronymic_at_end: bool|null, invalid: bool}>
     */
    private function resolveOrder(array $lines): array
    {
        $votes = [self::FIRST => 0, self::SURNAME => 0];

        foreach ($lines as $line) {
            if ($line['order'] !== null && ! $line['invalid']) {
                $votes[$line['order']]++;
            }
        }

        $default = $votes[self::SURNAME] > $votes[self::FIRST] ? self::SURNAME : self::FIRST;

        foreach ($lines as $index => $line) {
            $lines[$index]['order'] = $line['order'] ?? $default;
        }

        return $lines;
    }

    /**
     * @param  list<array{line: int, text: string, key: string, names: list<string>, initials: list<string>, order: string|null, patronymic_at_end: bool|null, invalid: bool}>  $lines
     * @param  iterable<string>  $activeNames
     * @return list<PastedNameData>
     */
    private function suggest(array $lines, iterable $activeNames): array
    {
        $existingNames = [];
        $existingFirstNames = [];

        foreach ($activeNames as $name) {
            $existingNames[DisplayName::key($name)] = true;
            $existingFirstNames[DisplayName::key(DisplayName::firstName($name))] = true;
        }

        // First name and surname initial of every usable line; repeats of a
        // full name are duplicates of the first occurrence.
        $parsed = [];
        $firstOccurrence = [];

        foreach ($lines as $index => $line) {
            if ($line['invalid']) {
                continue;
            }

            $surnameFirst = $line['order'] === self::SURNAME && count($line['names']) >= 2;
            $first = Az::title($surnameFirst ? $line['names'][1] : $line['names'][0]);
            $surname = match (true) {
                $surnameFirst => $line['names'][0],
                count($line['names']) >= 2 => $line['names'][count($line['names']) - 1],
                default => $line['initials'][0] ?? null,
            };

            if (mb_strlen($first) > DisplayName::MAX_LENGTH - 3) {
                $lines[$index]['invalid'] = true;

                continue;
            }

            $parsed[$index] = [
                'first' => $first,
                'initial' => $surname === null ? null : Az::upper(mb_substr($surname, 0, 1)),
                'surname_first' => $surnameFirst,
                'duplicate_of' => $firstOccurrence[$line['key']] ?? null,
            ];

            $firstOccurrence[$line['key']] ??= $index;
        }

        // How many distinct pasted children share each first name.
        $firstNameCount = [];

        foreach ($parsed as $entry) {
            if ($entry['duplicate_of'] === null) {
                $key = DisplayName::key($entry['first']);
                $firstNameCount[$key] = ($firstNameCount[$key] ?? 0) + 1;
            }
        }

        $suggestions = [];
        $suggestionCount = [];

        foreach ($parsed as $index => $entry) {
            if ($entry['duplicate_of'] !== null) {
                continue;
            }

            $key = DisplayName::key($entry['first']);
            $collides = $firstNameCount[$key] > 1 || isset($existingFirstNames[$key]);
            $withInitial = $collides && $entry['initial'] !== null;
            $suggestion = $withInitial ? "{$entry['first']} {$entry['initial']}." : $entry['first'];

            $suggestions[$index] = ['name' => $suggestion, 'initial_added' => $withInitial, 'in_class' => isset($existingFirstNames[$key])];
            $suggestionKey = DisplayName::key($suggestion);
            $suggestionCount[$suggestionKey] = ($suggestionCount[$suggestionKey] ?? 0) + 1;
        }

        $result = [];

        foreach ($lines as $index => $line) {
            if ($line['invalid'] || ! isset($parsed[$index])) {
                $result[] = new PastedNameData($line['line'], $line['text'], null, [PastedNameFlag::Invalid]);

                continue;
            }

            $entry = $parsed[$index];

            if ($entry['duplicate_of'] !== null) {
                $result[] = new PastedNameData($line['line'], $line['text'], $suggestions[$entry['duplicate_of']]['name'], [PastedNameFlag::Duplicate]);

                continue;
            }

            $suggestion = $suggestions[$index];
            $suggestionKey = DisplayName::key($suggestion['name']);
            $flags = [];

            if ($entry['surname_first']) {
                $flags[] = PastedNameFlag::SurnameFirst;
            }

            if ($suggestion['initial_added']) {
                $flags[] = PastedNameFlag::InitialAdded;
            }

            if ($suggestion['in_class']) {
                $flags[] = PastedNameFlag::InClass;
            }

            if ($suggestionCount[$suggestionKey] > 1 || isset($existingNames[$suggestionKey])) {
                $flags[] = PastedNameFlag::Taken;
            }

            $result[] = new PastedNameData($line['line'], $line['text'], $suggestion['name'], $flags);
        }

        return $result;
    }
}
