<?php

namespace App\Http\Controllers\Play;

use App\Domain\Play\Actions\ClaimGuestBoard;
use App\Domain\Play\Data\GuestBoardGameData;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Models\GuestBoard;
use App\Domain\Play\Support\PairingCode;
use App\Domain\Play\Support\PultChoices;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Play\Concerns\OffersMyWords;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `/pult`, where the teacher's phone pairs (docs/architecture.md §4). The
 * phone is always logged in as the teacher; a code only says which board.
 * All three routes share one limit, 10 a minute per teacher.
 *
 * - `GET /pult`: the teacher's live games; with exactly one it opens its
 *   remote at once (`?list=1` keeps the list, to type a code instead) —
 *   unless the teacher turned the phone remote off (Settings → Oyun): their
 *   teacher boards join no channel, so the list always stays.
 *   `?code=` (typed) goes to `/pult/{code}`.
 * - `GET /pult/{code}`: the teacher's own live game with that join code →
 *   its remote; else the open guest board with that code → what to show on
 *   it (`PultChoices`: a live game, or a new one with the setup form;
 *   for a teacher, `myWords` for «Sözlərim», `OffersMyWords`);
 *   anything else → the same 404 (`PairingCode::resolve()`).
 * - `POST /pult/{code}`: claim that guest board and send it a game
 *   (`ClaimGuestBoard`), then open the remote.
 */
final class PultController extends Controller
{
    use OffersMyWords;

    public function index(Request $request, #[CurrentUser] User $teacher): Response|RedirectResponse
    {
        if ($request->query->has('code')) {
            $code = PairingCode::normalize($request->query('code'));

            if ($code === null) {
                throw ValidationException::withMessages(['code' => __('pult.errors.code')]);
            }

            return to_route('pult.code', ['code' => $code]);
        }

        $games = PultChoices::liveGames($teacher);

        if (count($games) === 1 && $teacher->phone_remote && ! $request->boolean('list')) {
            return to_route('games.remote', ['game' => $games[0]['id']]);
        }

        return Inertia::render('pult/index', ['games' => $games]);
    }

    public function show(string $code, #[CurrentUser] User $teacher, PairingCode $codes): Response|RedirectResponse
    {
        $found = $codes->resolve($code, $teacher);

        if ($found instanceof GameSession) {
            return to_route('games.remote', $found);
        }

        abort_unless($found instanceof GuestBoard, 404);

        return Inertia::render('pult/guest-board', [
            'code' => (string) $found->code,
            'board' => null,
            ...PultChoices::forGuestBoard($teacher),
            ...$this->myWords($teacher),
        ]);
    }

    public function store(Request $request, string $code, #[CurrentUser] User $teacher, ClaimGuestBoard $claimGuestBoard): RedirectResponse
    {
        $board = GuestBoard::query()->claimable((string) PairingCode::normalize($code))->first();

        abort_if($board === null, 404);

        $game = $claimGuestBoard->handle($board, $teacher, GuestBoardGameData::validate($request->all(), $teacher));

        return to_route('games.remote', $game);
    }
}
