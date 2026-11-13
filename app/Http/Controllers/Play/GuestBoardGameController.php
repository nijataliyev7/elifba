<?php

namespace App\Http\Controllers\Play;

use App\Domain\Play\Actions\ShowGameOnGuestBoard;
use App\Domain\Play\Data\GuestBoardGameData;
use App\Domain\Play\Models\GuestBoard;
use App\Domain\Play\Support\PultChoices;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Play\Concerns\OffersMyWords;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The next game on a guest board the teacher claimed (docs/architecture.md
 * §4), without a new code.
 *
 * - `GET /guest-boards/{board}/game`: what to show next — the page of
 *   `/pult/{code}` (`PultChoices`, and `myWords` for a teacher), sending to
 *   the board instead of a code.
 * - `PUT /guest-boards/{board}/game`: one of the teacher's live games or a
 *   new one (`ShowGameOnGuestBoard`), then the phone opens its remote.
 */
final class GuestBoardGameController extends Controller
{
    use OffersMyWords;

    public function edit(GuestBoard $board, #[CurrentUser] User $teacher): Response
    {
        return Inertia::render('pult/guest-board', [
            'code' => null,
            'board' => ['id' => $board->id],
            ...PultChoices::forGuestBoard($teacher),
            ...$this->myWords($teacher),
        ]);
    }

    public function update(Request $request, GuestBoard $board, #[CurrentUser] User $teacher, ShowGameOnGuestBoard $showGameOnGuestBoard): RedirectResponse
    {
        $game = $showGameOnGuestBoard->handle($board, $teacher, GuestBoardGameData::validate($request->all(), $teacher));

        return to_route('games.remote', $game);
    }
}
