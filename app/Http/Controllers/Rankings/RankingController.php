<?php

namespace App\Http\Controllers\Rankings;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Content\Support\Avatars;
use App\Domain\Rankings\Data\PeriodData;
use App\Domain\Rankings\Data\RankedStudentData;
use App\Domain\Rankings\Support\ClassRanking;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A class's daily or weekly ranking on the teacher's own page
 * (docs/architecture.md §5). The query is `PeriodData`'s; an invalid or
 * future `period` or `date` redirects to the query without it, so the
 * defaults (the day, today in Baku) apply. An archived class's ranking
 * still opens, as its class page does: it only reads. The period label and
 * the place labels are formatted here in the interface language; the page
 * gets the dates of the windows before and after, never dates to compute.
 */
final class RankingController extends Controller
{
    public function __invoke(Request $request, SchoolClass $class): Response|RedirectResponse
    {
        $query = $request->query->all();
        $valid = PeriodData::valid($query);

        if ($valid !== $query) {
            return to_route('classes.rankings', [...$valid, 'class' => $class]);
        }

        $period = PeriodData::fromArray($valid);
        $locale = app()->getLocale();

        return Inertia::render('rankings/show', [
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
            'students' => array_map(
                static fn (RankedStudentData $row): array => $row->toArray($locale),
                ClassRanking::for($class, $period),
            ),
            'avatars' => Avatars::all(),
        ]);
    }
}
