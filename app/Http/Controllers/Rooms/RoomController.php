<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Content\Data\RoomTauntData;
use App\Domain\Content\Enums\RoomSpeaker;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TauntVoices;
use App\Domain\Rooms\Actions\DeleteRoom;
use App\Domain\Rooms\Actions\LockRoom;
use App\Domain\Rooms\Actions\OpenRoom;
use App\Domain\Rooms\Actions\RecordRoomRead;
use App\Domain\Rooms\Data\LockRoomData;
use App\Domain\Rooms\Data\OpenRoomData;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Exceptions\RoomGameRunning;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomFinishVoice;
use App\Domain\Rooms\Support\RoomJoinLink;
use App\Domain\Rooms\Support\RoomList;
use App\Domain\Rooms\Support\RoomPlaces;
use App\Domain\Rooms\Support\RoomResults;
use App\Domain\Rooms\Support\RoomState;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RoomsArea;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Oyunlar», «Keçmiş oyunlar» and a room's page, in the player area
 * (docs/architecture.md §12 "Rooms and joining", "Games first", ADRs 020
 * and 026); the pages are docs/frontend.md rule 15's.
 *
 * «Oyunlar» (`rooms/index`) gets `catalog`, the games a room plays with
 * what each level holds (`RoomGameSetupData::options()`); `open`, each
 * open room the account hosts or is present in, with the game of its
 * choice and whether a game is running (`RoomList::open()`); and
 * `entrance`, the code field's address (`RoomJoinLink::entrance()`).
 * Choosing a game there posts it (`OpenRoom`, told whether the instance's
 * live voice is on — a new room's voice is on from its opening while it
 * is, ADR 031 as amended 2026-11-19): the room opens behind it, or the
 * host's open room takes it, and either way the host lands in it —
 * while a game runs there, with `rooms.errors.game_running` and nothing
 * changed. «Keçmiş oyunlar» (`rooms/past`) gets the rooms the account was
 * in (`RoomList::past()`) and `retention_days`, for its line on how long
 * the results are kept.
 *
 * A room's page gets the state (`RoomState`, names read now) for the host
 * and a present player of an open room, and for everyone who was in a
 * closed one — its results; a player who left an open room sees the page
 * without it and comes back with the code. The state in the props is the
 * one `GET /rooms/{room}/state` answers, phase by phase, with nothing more
 * of a round; like that endpoint, the page records a player's read first
 * (`RecordRoomRead`), so a round waits for a player from the moment their
 * room page opens — the page a join leads to. Places are labelled here
 * (`place_labels`: the label of place n at n − 1, `Ordinal::label()`), as
 * many as the room has players or may have, since a page never suffixes a
 * number itself (`RoomPlaces`). The host of an open room also gets
 * `catalog`, what the room's choice may be — the state's `setup` is the
 * choice itself; whoever gets the state of an open room gets `join`, the
 * addresses its QR and its code field are at (`RoomJoinLink`); and
 * everyone who was in a closed room gets `games`, each game with its
 * ranking (`RoomResults`), named from the state's players. Whoever follows
 * an open room's state also gets `sounds`, the URLs of a phone's own quiet
 * cues (ADR 025 "Sound": its taps, its answer sent and its verdict; never
 * the room's moments, which are the screen's), `rivalry`, the URLs of
 * the rivalry strip's three emoji (ADR 027), and `taunts`, the list a
 * player may throw from — each key with its emoji's URL and, from T4, its
 * clip's in the board's voice (`TauntVoices`, null until made), which the
 * page says only for a taunt aimed at its viewer; the phrase is the page's
 * own translation (T2, ADR 029 decisions 28 and 50). No other account's id or
 * address reaches a room page — the shared `auth.user` is the viewer's own
 * — and no name goes into a URL or a flash.
 */
final class RoomController extends Controller
{
    public function index(#[CurrentUser] User $account): Response
    {
        return Inertia::render('rooms/index', [
            'catalog' => RoomGameSetupData::options(),
            'open' => RoomList::open($account),
            'entrance' => RoomJoinLink::entrance(),
        ]);
    }

    public function past(#[CurrentUser] User $account): Response
    {
        return Inertia::render('rooms/past', [
            'past' => RoomList::past($account, app()->getLocale()),
            'retention_days' => Config::integer('elifba.retention_days'),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $account, OpenRoom $openRoom): RedirectResponse
    {
        try {
            $room = $openRoom->handle($account, OpenRoomData::validate($request->all()), voice: RoomsArea::voice());
        } catch (RoomGameRunning $running) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('rooms.errors.game_running')]);

            return to_route('rooms.show', $running->room);
        }

        return to_route('rooms.show', $room);
    }

    public function show(Room $room, #[CurrentUser] User $account, ContentRepository $content, TauntVoices $tauntVoices, RecordRoomRead $recordRead, RoomFinishVoice $finish): Response
    {
        $gate = Gate::forUser($account);
        $open = $room->isOpen();
        $watches = $gate->allows('watch', $room);

        if ($watches) {
            $recordRead->handle($room, $account);
        }

        return Inertia::render('rooms/show', [
            'room' => ['id' => $room->id, 'status' => $room->status()->value],
            'state' => $watches || ! $open ? RoomState::for($room, $account, voice: RoomsArea::voice())->toArray() : null,
            'place_labels' => RoomPlaces::labels($room, app()->getLocale()),
            'catalog' => $open && $gate->allows('host', $room) ? RoomGameSetupData::options() : null,
            'join' => $open && $watches ? RoomJoinLink::of($room) : null,
            'games' => $open ? null : RoomResults::games($room, RoomClock::nowMs()),
            'sounds' => $open && $watches ? $content->roomSounds()->urls(RoomSpeaker::Phone, RoomSpeaker::Screen) : null,
            'rivalry' => $open && $watches ? $content->roomRivalry()->urls() : null,
            'taunts' => $open && $watches ? RoomTauntData::props($content->roomTaunts(), $tauntVoices) : null,
            'finish' => $open && $watches ? $finish->urls() : null,
            'can' => [
                'host' => $gate->allows('host', $room),
                'leave' => $gate->allows('play', $room),
                'delete' => $gate->allows('delete', $room),
                'forget' => $gate->allows('forget', $room),
            ],
        ]);
    }

    public function update(Request $request, Room $room, LockRoom $lockRoom): RedirectResponse
    {
        $lockRoom->handle($room, LockRoomData::validate($request->all()));

        return back();
    }

    public function destroy(Room $room, DeleteRoom $deleteRoom): RedirectResponse
    {
        $deleteRoom->handle($room);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('rooms.flash.deleted')]);

        return to_route('rooms.index');
    }
}
