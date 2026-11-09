<?php

namespace App\Http\Controllers\Rankings;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Content\Support\Avatars;
use App\Domain\Rankings\Data\PeriodData;
use App\Domain\Rankings\Data\PodiumPlaceData;
use App\Domain\Rankings\Data\ShareCardData;
use App\Domain\Rankings\Support\Podium;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A class's podium for a day or a week, with its share card
 * (docs/architecture.md §5, §6): the teacher's page for the projector and
 * the phone. The query is `PeriodData`'s, as on the rankings page: an
 * invalid or future `period` or `date` redirects to the query without it,
 * and an archived class's podium still opens.
 *
 * The page gets the podium as the teacher may see it (ids, names, avatar
 * keys) and, apart from it, `card`: exactly what the share card shows
 * (`ShareCardData`), or null when nobody has points.
 */
final class PodiumController extends Controller
{
    public function __invoke(Request $request, SchoolClass $class): Response|RedirectResponse
    {
        $query = $request->query->all();
        $valid = PeriodData::valid($query);

        if ($valid !== $query) {
            return to_route('classes.podium', [...$valid, 'class' => $class]);
        }

        $period = PeriodData::fromArray($valid);
        $locale = app()->getLocale();
        $podium = Podium::for($class, $period);

        return Inertia::render('podium', [
            'schoolClass' => [
                'id' => $class->id,
                'name' => $class->name,
                'archived' => $class->archived_at !== null,
            ],
            'period' => $period->period->value,
            'date' => $period->date->toDateString(),
            'label' => $period->label($locale),
            'current' => $period->isCurrent(),
            'previous' => $period->previous()->date->toDateString(),
            'next' => $period->next()?->date->toDateString(),
            'podium' => array_map(
                static fn (PodiumPlaceData $place): array => $place->toArray($locale),
                $podium,
            ),
            'avatars' => Avatars::all(),
            'card' => ShareCardData::of($period, $podium)?->toArray($locale),
        ]);
    }
}
