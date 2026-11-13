<?php

namespace App\Domain\Classroom\Support;

use App\Domain\Classroom\Data\PastedNameData;
use App\Domain\Classroom\Enums\PastedNameFlag;
use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Language\Support\PersonName;
use SensitiveParameter;

/**
 * Turns a pasted list of names into preview lines: a suggested display name
 * and flags per line (docs/architecture.md §7, "Pasting a list"). It writes
 * nothing; the teacher confirms and `AddStudents` stores the result.
 *
 * Per line: tab columns without a name (numbers, dates) are dropped, the text
 * is normalized, numbering and bullets are stripped, words with digits are
 * dropped, a patronymic ("Rəşid oğlu", "Rəşidovna") is set aside, and the
 * order (first name first, or surname first as in school registers) is read
 * from the surname suffixes (`Language\Support\PersonName`, shared with the
 * display name rule and the board's voice). The whole name is suggested,
 * first name first and cased, the patronymic after it (ADR 018); a first
 * name the class already has is a warning, never an added initial.
 *
 * The pasted text and its lines are marked `#[SensitiveParameter]` on the
 * entry points and line readers, so a stack trace in a log shows neither.
 */
final readonly class NameListParser
{
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
     * @return array{line: int, text: string, key: string, names: list<string>, initials: list<string>, patronymic: list<string>, order: string|null, patronymic_at_end: bool|null, invalid: bool}|null
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

        [$rest, $patronymicAtEnd] = PersonName::withoutPatronymic($words);
        $patronymic = self::removed($words, $rest);
        $words = $rest;

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
            $first = PersonName::surnameScore($names[0]);
            $last = PersonName::surnameScore($names[count($names) - 1]);

            $order = match (true) {
                $first > $last => self::SURNAME,
                $last > $first => self::FIRST,
                $patronymicAtEnd === true => self::SURNAME,
                $patronymicAtEnd === false => self::FIRST,
                default => null,
            };
        } elseif ($names === [] || PersonName::surnameScore($names[0]) === PersonName::SURE) {
            $invalid = true; // only initials, or only a surname: no first name to keep
        }

        return [
            'line' => $number,
            'text' => $text,
            'key' => Az::lower(implode(' ', [...$words, ...$patronymic])),
            'names' => $names,
            'initials' => $initials,
            'patronymic' => $patronymic,
            'order' => $order,
            'patronymic_at_end' => $patronymicAtEnd,
            'invalid' => $invalid,
        ];
    }

    /**
     * The words `withoutPatronymic()` took out of `$all`, in their order.
     *
     * @param  list<string>  $all
     * @param  list<string>  $rest
     * @return list<string>
     */
    private static function removed(#[SensitiveParameter] array $all, #[SensitiveParameter] array $rest): array
    {
        $at = 0;

        while ($at < count($rest) && $rest[$at] === $all[$at]) {
            $at++;
        }

        return array_slice($all, $at, count($all) - count($rest));
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
     * Lines whose order the suffixes do not reveal follow the rest of the
     * list; when the list does not say either, first name first.
     *
     * @param  list<array{line: int, text: string, key: string, names: list<string>, initials: list<string>, patronymic: list<string>, order: string|null, patronymic_at_end: bool|null, invalid: bool}>  $lines
     * @return list<array{line: int, text: string, key: string, names: list<string>, initials: list<string>, patronymic: list<string>, order: string|null, patronymic_at_end: bool|null, invalid: bool}>
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
     * @param  list<array{line: int, text: string, key: string, names: list<string>, initials: list<string>, patronymic: list<string>, order: string|null, patronymic_at_end: bool|null, invalid: bool}>  $lines
     * @param  iterable<string>  $activeNames
     * @return list<PastedNameData>
     */
    private function suggest(array $lines, iterable $activeNames): array
    {
        $existingNames = [];
        $existingFirstNames = [];

        foreach ($activeNames as $name) {
            $existingNames[DisplayName::key($name)] = true;
            $existingFirstNames[DisplayName::key(PersonName::firstName($name) ?? $name)] = true;
        }

        // The whole name of every usable line, first name first; repeats of
        // a full name are duplicates of the first occurrence.
        $parsed = [];
        $firstOccurrence = [];

        foreach ($lines as $index => $line) {
            if ($line['invalid']) {
                continue;
            }

            $name = $this->wholeName($line);

            if ($name === null) {
                $lines[$index]['invalid'] = true;

                continue;
            }

            $surnameFirst = $line['order'] === self::SURNAME && count($line['names']) >= 2;
            $parsed[$index] = [
                'name' => $name,
                'first' => $surnameFirst ? $line['names'][1] : $line['names'][0],
                'surname_first' => $surnameFirst,
                'duplicate_of' => $firstOccurrence[$line['key']] ?? null,
            ];

            $firstOccurrence[$line['key']] ??= $index;
        }

        $suggestionCount = [];

        foreach ($parsed as $entry) {
            if ($entry['duplicate_of'] === null) {
                $key = DisplayName::key($entry['name']);
                $suggestionCount[$key] = ($suggestionCount[$key] ?? 0) + 1;
            }
        }

        $result = [];

        foreach ($lines as $index => $line) {
            if ($line['invalid'] || ! isset($parsed[$index])) {
                $result[] = new PastedNameData($line['line'], $line['text'], null, [PastedNameFlag::Invalid]);

                continue;
            }

            $entry = $parsed[$index];

            if ($entry['duplicate_of'] !== null) {
                $result[] = new PastedNameData($line['line'], $line['text'], $parsed[$entry['duplicate_of']]['name'], [PastedNameFlag::Duplicate]);

                continue;
            }

            $key = DisplayName::key($entry['name']);
            $flags = [];

            if ($entry['surname_first']) {
                $flags[] = PastedNameFlag::SurnameFirst;
            }

            if (isset($existingFirstNames[DisplayName::key($entry['first'])])) {
                $flags[] = PastedNameFlag::InClass;
            }

            if ($suggestionCount[$key] > 1 || isset($existingNames[$key])) {
                $flags[] = PastedNameFlag::Taken;
            }

            $result[] = new PastedNameData($line['line'], $line['text'], $entry['name'], $flags);
        }

        return $result;
    }

    /**
     * The line's name as it is suggested (ADR 018): first name first, then
     * the surname and any other names, the patronymic, and a lone initial
     * with its dot when there is no surname; every word cased. Without the
     * patronymic when the whole is too long; null when even that is.
     *
     * @param  array{line: int, text: string, key: string, names: list<string>, initials: list<string>, patronymic: list<string>, order: string|null, patronymic_at_end: bool|null, invalid: bool}  $line
     */
    private function wholeName(#[SensitiveParameter] array $line): ?string
    {
        $names = $line['names'];

        if ($line['order'] === self::SURNAME && count($names) >= 2) {
            [$names[0], $names[1]] = [$names[1], $names[0]];
        }

        $initial = count($names) === 1 && $line['initials'] !== [] ? [Az::upper($line['initials'][0]).'.'] : [];

        foreach ([[...$names, ...$line['patronymic']], $names] as $words) {
            $name = implode(' ', [...array_map(PersonName::cased(...), $words), ...$initial]);

            if (mb_strlen($name) <= DisplayName::MAX_LENGTH) {
                return $name;
            }
        }

        return null;
    }
}
