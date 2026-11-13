<?php

namespace App\Http\Controllers\Play;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Family\Models\Child;
use App\Domain\Play\Actions\StartGame;
use App\Domain\Play\Data\GamePlayers;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Support\GameSetupOptions;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Play\Concerns\OffersMyWords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The game setup (docs/architecture.md §2) and `StartGame`, for a class
 * (`/classes/{class}/games…`, the teacher area) or for a parent's child
 * (`/children/{child}/games…`, the parent area, ADR 011): the same page,
 * form and rules, through `GamePlayers`. The page lists what the class or
 * the child can start (`GameSetupOptions`, the same check
 * `GameSetupData::rules()` makes) and a started game opens on the board.
 * `drawable` tells a pack the board cannot draw yet ("coming soon") from
 * one there is not enough content for. An archived class is hidden from
 * games. A class's page also offers the teacher's words for «Sözlərim»
 * (`myWords`, `OffersMyWords`); a child's never does.
 */
final class GameController extends Controller
{
    use OffersMyWords;

    public function create(SchoolClass $class): Response
    {
        abort_if($class->archived_at !== null, 404);

        return $this->setup(GamePlayers::forClass($class));
    }

    public function store(Request $request, SchoolClass $class, StartGame $startGame): RedirectResponse
    {
        abort_if($class->archived_at !== null, 404);

        return $this->start($request, GamePlayers::forClass($class), $startGame);
    }

    public function createForChild(Child $child): Response
    {
        return $this->setup(GamePlayers::forChild($child));
    }

    public function storeForChild(Request $request, Child $child, StartGame $startGame): RedirectResponse
    {
        return $this->start($request, GamePlayers::forChild($child), $startGame);
    }

    private function setup(GamePlayers $players): Response
    {
        return Inertia::render('games/setup', [
            'schoolClass' => $players->class === null ? null : ['id' => $players->class->id, 'name' => $players->class->name],
            'child' => $players->child === null ? null : ['id' => $players->child->id, 'display_name' => $players->child->display_name],
            ...GameSetupOptions::shared(),
            ...GameSetupOptions::for($players),
            ...($players->isHome() ? [] : $this->myWords($players->owner)),
        ]);
    }

    private function start(Request $request, GamePlayers $players, StartGame $startGame): RedirectResponse
    {
        $game = $startGame->handle($players, GameSetupData::validate($request->all(), $players));

        return to_route('games.board', $game);
    }
}
