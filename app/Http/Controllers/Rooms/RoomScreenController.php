<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Content\Data\RoomTauntData;
use App\Domain\Content\Enums\RoomSpeaker;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TauntVoices;
use App\Domain\Rooms\Actions\RecordScreenRead;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Data\RoomMatchStateData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomFinishVoice;
use App\Domain\Rooms\Support\RoomJoinLink;
use App\Domain\Rooms\Support\RoomPlaces;
use App\Domain\Rooms\Support\RoomScreen;
use App\Domain\Rooms\Support\RoomState;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RoomsArea;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The big screen (docs/architecture.md §12 "The big screen", "Games first",
 * ADRs 021 and 026): `GET /rooms/{room}/screen`, page `rooms/screen`, for
 * the host of an open room in their own session on a laptop or a
 * television's browser. It gets what the room page gets to follow the
 * room — the state (`RoomState`, which it then reads from
 * `GET /rooms/{room}/state` as a host may, its `setup` included) and the
 * place labels — and `join`, the addresses of the QR and the code field
 * (`RoomJoinLink`), built on the server; `catalog`, the games the room's
 * choice may be, as the host's phone gets it (`RoomGameSetupData::
 * options()`); `sounds`, the URLs of the room's moments it plays as the
 * room's speaker (ADR 025 "Sound"), never a phone's own cues;
 * `rivalry`, the URLs of the rivalry strip's three emoji (ADR 027); and
 * `taunts`, the list's keys with their emoji's URLs and, from T4, their
 * clips' (`TauntVoices`, null until made), for the taunts its state shows
 * (T2, ADR 029 decisions 21, 28 and 50); `finish`, the URLs of
 * «Əla oynadın!» and «Əla oynadınız!» it says after the final's fanfare,
 * after the winner's name (`RoomFinishVoice`, ADR 030); and `beat_ms`, how
 * often it reads the state at least while shown, which tells every phone
 * that the room has its screen (`RoomScreen`, ADR 028 as amended
 * 2026-11-19). Its state is the
 * screen's (`RoomState::for(…, screen: true)`): the taunts shown now with
 * their targets.
 *
 * Opening it, and each of its state requests, writes only the screen's own
 * read (`RecordScreenRead`) — never a player's (`RecordRoomRead`): the
 * screen is not the host's phone, and a round never waits for the host
 * because the screen is on. Before a game and after one the page sends
 * the host's own requests, `rooms.setup.update` and `rooms.games.store`,
 * which `RoomPolicy::start` lets through for the host and nothing about
 * the page decides (ADR 026 decision 12); it holds no credential, route or
 * policy of its own, and it is not the guest board: `/lovhe`, the `board`
 * guard and its bearer never show a room. The page is docs/frontend.md
 * rule 15's.
 */
final class RoomScreenController extends Controller
{
    public function __invoke(Room $room, #[CurrentUser] User $account, ContentRepository $content, TauntVoices $tauntVoices, RoomFinishVoice $finish, RecordScreenRead $recordScreen): Response
    {
        $recordScreen->handle($room);

        return Inertia::render('rooms/screen', [
            'room' => ['id' => $room->id, 'status' => $room->status()->value],
            'state' => RoomState::for($room, $account, screen: true, voice: RoomsArea::voice())->toArray(),
            'place_labels' => RoomPlaces::labels($room, app()->getLocale()),
            'join' => RoomJoinLink::of($room),
            'catalog' => RoomGameSetupData::options(),
            'sounds' => $content->roomSounds()->urls(RoomSpeaker::Screen),
            'rivalry' => $content->roomRivalry()->urls(),
            'taunts' => RoomTauntData::props($content->roomTaunts(), $tauntVoices),
            'finish' => $finish->urls(),
            'beat_ms' => RoomScreen::beatMs(),
            // A room opened for a board game (ADR 034): the screen draws its board.
            'board' => $room->board?->value,
            'match' => $room->board !== null ? RoomMatchStateData::for($room, $account, screen: true) : null,
        ]);
    }
}
