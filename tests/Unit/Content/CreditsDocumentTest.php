<?php

use App\Domain\Content\Data\CreditData;
use App\Domain\Content\Enums\License;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\CreditsDocument;

/*
 * CREDITS.md and /credits from content/credits.json (docs/architecture.md
 * §8): OpenMoji's attribution verbatim, the changed OpenMoji files and the
 * license statement of the READMEs.
 */

function realCredits(): array
{
    return (new ContentRepository(base_path('content')))->credits();
}

it('keeps CREDITS.md up to date with content/credits.json', function (): void {
    expect(file_get_contents(base_path('CREDITS.md')))
        ->toBe(CreditsDocument::render(realCredits()), 'CREDITS.md is stale: run `php artisan content:credits`.');
});

it('carries OpenMoji\'s attribution verbatim and says no OpenMoji file was changed', function (): void {
    $document = CreditsDocument::render(realCredits());

    expect($document)
        ->toContain("\nAll emojis designed by OpenMoji – the open-source emoji and icon project. License: CC BY-SA 4.0\n")
        ->toContain('Changed files: none.')
        ->and(CreditsDocument::openMoji(realCredits()))
        ->toMatchArray(['attribution' => CreditsDocument::OPENMOJI_ATTRIBUTION, 'changed' => []]);
});

it('states which license covers what, like the READMEs', function (): void {
    $document = CreditsDocument::render(realCredits());

    expect($document)->toContain(CreditsDocument::STATEMENT);

    foreach (['README.md', 'README.az.md'] as $readme) {
        expect(file_get_contents(base_path($readme)))->toContain('content/assets/openmoji/')->toContain('CREDITS.md');
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

it('lists changed OpenMoji files, and leaves the section out without OpenMoji', function (): void {
    $openMoji = new CreditData(['content/assets/openmoji/1F34E.svg'], 'OpenMoji', 'OpenMoji', 'https://openmoji.org/', License::CcBySa, 'Stem recoloured.');
    $font = new CreditData(['resources/fonts/inter/*'], 'Inter', 'The Inter Project Authors', 'https://github.com/rsms/inter', License::Ofl);

    expect(CreditsDocument::render([$openMoji, $font]))
        ->toContain("- Changed files:\n    - `content/assets/openmoji/1F34E.svg`: Stem recoloured.")
        ->and(CreditsDocument::openMoji([$openMoji, $font])['changed'] ?? null)
        ->toBe([['files' => ['content/assets/openmoji/1F34E.svg'], 'changes' => 'Stem recoloured.']])
        ->and(CreditsDocument::render([$font]))->not->toContain('## OpenMoji')
        ->and(CreditsDocument::openMoji([$font]))->toBeNull();
});
