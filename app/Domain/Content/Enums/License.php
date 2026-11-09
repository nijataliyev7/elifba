<?php

namespace App\Domain\Content\Enums;

/**
 * The SPDX licenses an asset may have (docs/architecture.md §8). Nothing
 * non-commercial, no-derivatives or of unknown license enters the repository.
 */
enum License: string
{
    case Cc0 = 'CC0-1.0';
    case CcBy = 'CC-BY-4.0';
    case CcBySa = 'CC-BY-SA-4.0';
    case Ofl = 'OFL-1.1';
    case Mit = 'MIT';

    /**
     * Share-alike files stay in their own folder, so the boundary is visible
     * and they never reach the share card (§8).
     */
    public function shareAlike(): bool
    {
        return $this === self::CcBySa;
    }

    /**
     * The license's own name, as `CREDITS.md` and `/credits` print it.
     */
    public function title(): string
    {
        return match ($this) {
            self::Cc0 => 'CC0 1.0 Universal',
            self::CcBy => 'CC BY 4.0',
            self::CcBySa => 'CC BY-SA 4.0',
            self::Ofl => 'SIL Open Font License 1.1',
            self::Mit => 'MIT License',
        };
    }

    /**
     * Where the license text is published.
     */
    public function url(): string
    {
        return match ($this) {
            self::Cc0 => 'https://creativecommons.org/publicdomain/zero/1.0/',
            self::CcBy => 'https://creativecommons.org/licenses/by/4.0/',
            self::CcBySa => 'https://creativecommons.org/licenses/by-sa/4.0/',
            self::Ofl => 'https://openfontlicense.org/',
            self::Mit => 'https://opensource.org/license/mit',
        };
    }
}
