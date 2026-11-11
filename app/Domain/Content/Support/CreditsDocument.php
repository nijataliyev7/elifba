<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\CreditData;

/**
 * `CREDITS.md` and the `/credits` page from `content/credits.json`
 * (docs/architecture.md §8): who made every asset file, under which license
 * and what Elifba changed.
 */
final class CreditsDocument
{
    /**
     * The license statement of the READMEs, in English.
     */
    public const string STATEMENT = "Elifba's code and its own art are under the [MIT License](LICENSE). Files made by others keep their own licenses: the Fluent Emoji pictures in `content/assets/fluent/` are © Microsoft Corporation under the MIT License, the fonts in `resources/fonts/` under the SIL Open Font License 1.1, and Kenney's avatars and sounds are CC0 1.0 (public domain). The board's voice in `content/assets/voice/` is synthetic speech made for Elifba with ElevenLabs, under the MIT License too.";

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
            '',
            '## Files',
        ];

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
