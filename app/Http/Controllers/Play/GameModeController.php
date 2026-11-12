<?php

namespace App\Http\Controllers\Play;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Family\Models\Child;
use App\Domain\Play\Actions\RememberSetupMode;
use App\Domain\Play\Data\GamePlayers;
use App\Domain\Play\Data\SetupModeData;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Asan» or «Normal» on «Yeni oyun» (docs/architecture.md §2 "Remembered
 * setup"), for a class (`PUT /classes/{class}/games/mode`, the teacher
 * area) or a parent's child (`PUT /children/{child}/games/mode`, the parent
 * area); the setup page and the phone's guest board send it alike, and the
 * answer is a redirect back. An archived class is hidden from games.
 */
final class GameModeController extends Controller
{
    public function update(Request $request, SchoolClass $class, RememberSetupMode $remember): RedirectResponse
    {
        abort_if($class->archived_at !== null, 404);

        $remember->handle(GamePlayers::forClass($class), SetupModeData::validate($request->all()));

        return back();
    }

    public function updateForChild(Request $request, Child $child, RememberSetupMode $remember): RedirectResponse
    {
        $remember->handle(GamePlayers::forChild($child), SetupModeData::validate($request->all()));

        return back();
    }
}
