<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\CreditData;

/**
 * `CREDITS.md` and the `/credits` page from `content/credits.json`
 * (docs/architecture.md §8): who made every asset file, under which license,
 * what Elifba changed, OpenMoji's attribution sentence verbatim and the list
 * of changed OpenMoji files.
 */
final class CreditsDocument
{
    /**
     * The attribution OpenMoji asks for, verbatim.
     */
    public const string OPENMOJI_ATTRIBUTION = 'All emojis designed by OpenMoji – the open-source emoji and icon project. License: CC BY-SA 4.0';

    public const string OPENMOJI_URL = 'https://openmoji.org/';

    /**
     * The license statement of the READMEs, in English.
     */
    public const string STATEMENT = "Elifba's code and its own art are under the [MIT License](LICENSE). Files made by others keep their own licenses: the OpenMoji pictures in `content/assets/openmoji/` are under CC BY-SA 4.0, the fonts in `resources/fonts/` under the SIL Open Font License 1.1, and Kenney's avatars and sounds are CC0 1.0 (public domain). Showing a picture does not put the code that shows it under the picture's license.";

    /**
     * OpenMoji's part of the credits, or null when no share-alike files are
     * used. Share-alike files can only be OpenMoji's (they must stay in
     * `content/assets/openmoji/`), so every share-alike entry with `changes`
     * names changed OpenMoji files.
     *
     * @param  list<CreditData>  $credits
     * @return array{attribution: string, url: string, license_url: string, changed: list<array{files: list<string>, changes: string}>}|null
     */
    public static function openMoji(array $credits): ?array
    {
        $shareAlike = array_values(array_filter($credits, static fn (CreditData $credit): bool => $credit->license->shareAlike()));

        if ($shareAlike === []) {
            return null;
        }

        $changed = [];

        foreach ($shareAlike as $credit) {
            if ($credit->changes !== null) {
                $changed[] = ['files' => $credit->files, 'changes' => $credit->changes];
            }
        }

        return [
            'attribution' => self::OPENMOJI_ATTRIBUTION,
            'url' => self::OPENMOJI_URL,
            'license_url' => $shareAlike[0]->license->url(),
            'changed' => $changed,
        ];
    }

    /**
     * @param  list<CreditData>  $credits
     */
    public static function render(array $credits): string
    {
        $lines = [
            '# Credits',
            '',
            '<!-- Generated from content/credits.json by `php artisan content:credits`. Do not edit by hand; a test fails when it is stale. -->',
            '',
            self::STATEMENT,
            '',
            'The app shows the same list on its `/credits` page.',
        ];

        $openMoji = self::openMoji($credits);

        if ($openMoji !== null) {
            array_push($lines, '', '## OpenMoji', '', $openMoji['attribution'], '', "- Website: <{$openMoji['url']}>", "- License: <{$openMoji['license_url']}>");

            if ($openMoji['changed'] === []) {
                $lines[] = '- Changed files: none. The files in `content/assets/openmoji/` are copied unmodified.';
            } else {
                $lines[] = '- Changed files:';

                foreach ($openMoji['changed'] as $changed) {
                    $lines[] = '    - '.self::files($changed['files']).': '.$changed['changes'];
                }
            }
        }

        array_push($lines, '', '## Files');

        foreach ($credits as $credit) {
            array_push(
                $lines,
                '',
                "### {$credit->title}",
                '',
                '- Files: '.self::files($credit->files),
                "- Author: {$credit->author}",
                "- License: [{$credit->license->title()}]({$credit->license->url()}) (`{$credit->license->value}`)",
                "- Source: <{$credit->source}>",
            );

            if ($credit->changes !== null) {
                $lines[] = "- Changes: {$credit->changes}";
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<string>  $files
     */
    private static function files(array $files): string
    {
        return implode(', ', array_map(static fn (string $file): string => "`{$file}`", $files));
    }
}
