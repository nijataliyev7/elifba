<?php

use App\Domain\Content\Data\CreditData;
use App\Domain\Content\Enums\License;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\CreditsDocument;

/*
 * CREDITS.md and /credits from content/credits.json (docs/architecture.md
 * §8): every entry and the license statement of the READMEs.
 */

function realCredits(): array
{
    return (new ContentRepository(base_path('content')))->credits();
}

it('keeps CREDITS.md up to date with content/credits.json', function (): void {
    expect(file_get_contents(base_path('CREDITS.md')))
        ->toBe(CreditsDocument::render(realCredits()), 'CREDITS.md is stale: run `php artisan content:credits`.');
});

it('states which license covers what, like the READMEs', function (): void {
    $document = CreditsDocument::render(realCredits());

    expect($document)->toContain(CreditsDocument::STATEMENT);

    foreach (['README.md', 'README.az.md'] as $readme) {
        expect(file_get_contents(base_path($readme)))->toContain('content/assets/fluent/')->toContain('CREDITS.md');
    }

    expect(file_get_contents(base_path('README.md')))->toContain(CreditsDocument::STATEMENT);
});

it('lists every entry with its files, author, license and source', function (): void {
    $document = CreditsDocument::render(realCredits());

    foreach (realCredits() as $credit) {
        expect($document)
            ->toContain("### {$credit->title}")
            ->toContain("- Author: {$credit->author}")
            ->toContain("({$credit->license->url()}) (`{$credit->license->value}`)")
            ->toContain("- Source: <{$credit->source}>");

        foreach ($credit->files as $file) {
            expect($document)->toContain("`{$file}`");
        }
    }
});

it('says what Elifba changed in an entry\'s files', function (): void {
    $changed = new CreditData(['content/assets/sounds/chime.mp3'], 'Chime', 'Someone', 'https://example.test/chime', License::Cc0, 'Trimmed to one second.');
    $font = new CreditData(['resources/fonts/inter/*'], 'Inter', 'The Inter Project Authors', 'https://github.com/rsms/inter', License::Ofl);

    expect(CreditsDocument::render([$changed, $font]))
        ->toContain("### Chime\n\n- Files: `content/assets/sounds/chime.mp3`")
        ->toContain('- Changes: Trimmed to one second.')
        ->and(substr_count(CreditsDocument::render([$changed, $font]), '- Changes:'))->toBe(1);
});
