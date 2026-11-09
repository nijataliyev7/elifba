<?php

namespace App\Http\Controllers;

use App\Domain\Content\Data\CreditData;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\CreditsDocument;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The credits (docs/architecture.md §8): who made every picture, sound and
 * font, under which license, OpenMoji's attribution and the changed OpenMoji
 * files, from `content/credits.json` like `CREDITS.md`. Public, in every
 * interface language.
 */
final class CreditsController extends Controller
{
    public function __invoke(ContentRepository $content): Response
    {
        $credits = $content->credits();

        return Inertia::render('credits', [
            'credits' => array_map(static fn (CreditData $credit): array => $credit->toArray(), $credits),
            'openmoji' => CreditsDocument::openMoji($credits),
        ]);
    }
}
