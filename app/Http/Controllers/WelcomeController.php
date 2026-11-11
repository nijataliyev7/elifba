<?php

namespace App\Http\Controllers;

use App\Domain\Content\Data\PackData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\Avatars;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Support\Playable;
use App\Domain\Play\Support\SampleRounds;
use App\Domain\Play\Support\SchoolCalendar;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The welcome page (docs/frontend.md rule 10): what Elifba is, for a teacher
 * who opens a shared link, and after the teacher's story what it gives a
 * parent at home (R7). Its facts come from the content and the code, never
 * from text retyped in `lang/` or TSX. Public; a signed-in account sees the
 * same page with other actions.
 */
final class WelcomeController extends Controller
{
    /**
     * How many avatars the privacy part shows: the first ones of the file.
     */
    private const int AVATARS = 6;

    public function __invoke(ContentRepository $content, SampleRounds $samples): Response
    {
        $locale = app()->getLocale();

        return Inertia::render('welcome', [
            'sourceUrl' => Config::string('elifba.source_url'),
            'mechanics' => array_map(static fn (Mechanic $mechanic): string => $mechanic->value, Playable::mechanics()),
            'packs' => array_values(array_map(static fn (PackData $pack): array => [
                'key' => $pack->key,
                'title' => $pack->title->in($locale),
                'palette' => $pack->style->palette,
            ], $content->packs())),
            // The packs a child who cannot read yet plays (R5, R6): those
            // that list a unit nobody reads, in the packs' order.
            'picturePacks' => array_keys(array_filter(
                $content->packs(),
                static fn (PackData $pack): bool => array_any($pack->units, static fn (Unit $unit): bool => ! $unit->reads()),
            )),
            'letters' => array_map(
                static fn (string $letter): array => ['lower' => $letter, 'upper' => Az::upper($letter)],
                Alphabet::letters(),
            ),
            'avatars' => array_slice(Avatars::all(), 0, self::AVATARS),
            'sample' => $samples->on(SchoolCalendar::today(), $locale),
        ]);
    }
}
