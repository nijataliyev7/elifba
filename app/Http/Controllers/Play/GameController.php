<?php

namespace App\Http\Controllers\Play;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Play\Actions\StartGame;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Support\GameSetupOptions;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The game setup for a class (docs/architecture.md §2) and `StartGame`. The
 * page lists what the class can start (`GameSetupOptions`, the same check
 * `GameSetupData::rules()` makes) and a started game opens on the board.
 * `drawable` tells a pack the board cannot draw yet ("coming soon") from
 * one the class lacks content for. An archived class is hidden from games.
 */
final class GameController extends Controller
{
    public function create(SchoolClass $class): Response
    {
        abort_if($class->archived_at !== null, 404);

        return Inertia::render('games/setup', [
            'schoolClass' => ['id' => $class->id, 'name' => $class->name],
            ...GameSetupOptions::shared(),
            ...GameSetupOptions::forClass($class),
        ]);
    }

    public function store(Request $request, SchoolClass $class, StartGame $startGame): RedirectResponse
    {
        abort_if($class->archived_at !== null, 404);

        $game = $startGame->handle($class, GameSetupData::validate($request->all(), $class));

        return to_route('games.board', $game);
    }
}
