<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\PlayComputerMove;
use App\Domain\Rooms\Actions\RecordRoomRead;
use App\Domain\Rooms\Actions\RecordScreenRead;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomState;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RoomsArea;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

use function Illuminate\Support\defer;

/**
 * `GET /rooms/{room}/state` (zod `RoomState`, docs/architecture.md §12 "The
 * state"): for the host and a present player of an open room, never kept
 * by a cache. 120 a minute per account (`throttle:room-state`).
 *
 * A player's room page reading it is the sign that their phone is still
 * in the game, which a round needs to know whom to wait for: the read is
 * recorded first (`RecordRoomRead`), so the reader counts in the state
 * they get. The big screen reads the same state with the host's account
 * and is not the host's phone: a request sent from the screen's page — its
 * `Referer` is `/rooms/{room}/screen` — records nothing. A request without
 * a `Referer` counts as the room page's: at worst the host's round waits
 * for the host, as every round did before.
 *
 * The screen asks with `?for=screen` (T2, ADR 029 decision 15), which only
 * `RoomPolicy::screen` — the host of an open room — may: its state then
 * lists the taunts of the last `show_ms` with their targets (`taunts.
 * shown`), and the request records no read either. It records the
 * screen's instead (`RecordScreenRead`, ADR 028 as amended 2026-11-19):
 * the screen asks only while it is shown, at least every
 * `elifba.rooms.screen.beat_ms`, and the state's `room.screen` says
 * whether it still does.
 *
 * A read that finds the computer to move in a match (K3, ADR 036 decision
 * 9) hands its move to `defer()`: `PlayComputerMove` runs after this
 * response went out, so the read itself writes no fact of the match.
 */
final class RoomStateController extends Controller
{
    public function __invoke(Request $request, Room $room, #[CurrentUser] User $account, RecordRoomRead $recordRead, RecordScreenRead $recordScreen, PlayComputerMove $playComputerMove): JsonResponse
    {
        $forScreen = $request->query('for') === 'screen';

        if ($forScreen) {
            Gate::forUser($account)->authorize('screen', $room);
            $recordScreen->handle($room);
        }

        if (! $forScreen && ! self::fromTheScreen($request, $room)) {
            $recordRead->handle($room, $account);
        }

        $state = RoomState::for($room, $account, $forScreen, RoomsArea::voice());

        if ($state->match?->computerToMove() === true) {
            defer(static fn () => $playComputerMove->handle($room));
        }

        return new JsonResponse($state->toArray(), 200, ['Cache-Control' => 'no-store, private']);
    }

    private static function fromTheScreen(Request $request, Room $room): bool
    {
        $referer = $request->headers->get('referer');

        return $referer !== null
            && parse_url($referer, PHP_URL_PATH) === parse_url(route('rooms.screen', $room), PHP_URL_PATH);
    }
}
