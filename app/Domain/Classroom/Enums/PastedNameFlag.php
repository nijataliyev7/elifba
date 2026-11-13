<?php

namespace App\Domain\Classroom\Enums;

/**
 * What the paste preview says about one line (docs/architecture.md §7).
 * A line is addable unless it is `invalid`, a `duplicate` or `taken`.
 */
enum PastedNameFlag: string
{
    /** No usable first name: no letters left, only a surname or initials, a letter outside the alphabet, or too long. */
    case Invalid = 'invalid';

    /** The same full name as an earlier line; the earlier line is the one to add. */
    case Duplicate = 'duplicate';

    /** Read as "Surname First…", as school registers write names. */
    case SurnameFirst = 'surname_first';

    /** An active student of the class already has this first name; it may be the same child. A warning only (ADR 018). */
    case InClass = 'in_class';

    /** The suggestion equals another line's or an active student's name; the teacher adds a patronymic or another word. */
    case Taken = 'taken';

    public function blocksAdding(): bool
    {
        return in_array($this, [self::Invalid, self::Duplicate, self::Taken], true);
    }
}
