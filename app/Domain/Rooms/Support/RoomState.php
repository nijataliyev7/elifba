<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Data\RoomGameData;
use App\Domain\Rooms\Data\RoomPlayerData;
use App\Domain\Rooms\Data\RoomSetupData;
use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Data\RoomSummaryData;
use App\Domain\Rooms\Data\RoomTauntsData;
use App\Domain\Rooms\Data\RoomTauntSeenData;
use App\Domain\Rooms\Data\RoomViewerData;
use App\Domain\Rooms\Data\RoomVoiceData;
use App\Domain\Rooms\Enums\RoomGamePhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomTaunt;
use App\Domain\Rooms\Support\Drivers\RoomGameDriver;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;

/**
 * A room's state as one account sees it (docs/architecture.md §12 "The
 * state", "Adults' privacy", ADR 021 decision 6): the room, the viewer,
 * the players by the names their accounts have now — read here, never
 * stored in a room table — the latest game, its current round and the
 * ranking. A removed player is not listed; a row without an account has no
 * name. Nothing in it is an account id or an address.
 *
 * Everything time decides comes from the timetable at `RoomClock::nowMs()`,
 * and nothing here writes: a request after the bell is answered from the
 * reveal without anyone closing the round. A round serves only what its
 * phase allows (`RoomRoundData`): its material while it is studied, its
 * question from the answer phase, and the solution, the results and its
 * points only in the reveal. A round's `answered_count` of `present_count`
 * counts the present players it waits for (`RoomWaits`), as the early
 * close does, so a full count is a closed round; a player whose phone is
 * gone is not in it after the round they vanished in. A player's page
 * records its read first (`RecordRoomRead`), so the reader counts in the
 * state they get; the big screen records none. The ranking of a live
 * game counts its closed rounds only; once a game is over it is the
 * room's standing; before the first game there is none. A closed room —
 * an idle one too, before the scheduler writes it — has no live game.
 *
 * An open room's state says the host's choice of the next game (`setup`,
 * §12 "Games first", ADR 026 decision 11) to everyone who gets the state —
 * after joining, never on the join page — and whether it is the game that
 * was just played: the latest game is over and its mechanic, difficulty
 * and number of rounds are the choice's. A closed room has none.
 *
 * A live game's state also carries its taunts as the viewer may see them
 * (§12 "Taunts (T2)", ADR 029 decision 15): whether a throw is open, the
 * viewer's balance and cooldown, the taunts aimed at the viewer within
 * `elifba.rooms.taunts.show_ms` — and, for the big screen's request
 * (`$screen`, `?for=screen`), every taunt of that window with its target.
 * No other phone gets a taunt, and nothing of one is left once the game is
 * over. A reveal lists the taunts counted at its close (`spent`).
 *
 * While the instance's voice is on (`$voice`: the caller reads the switch), an open room's
 * state says its live voice (§12 "The state's voice", ADR 031): whether
 * the host has it on, whether the viewer's account agreed, and each
 * player's standing while it is on (`RoomVoice::stateOf()`). Reading it
 * asks the voice server nothing: who is in the voice, whose microphone is
 * open and who speaks are never in it.
 *
 * What depends on the latest game's kind is its driver's (§12 "Board
 * games", ADR 034 decision 3): whether it is live, and its part — a live
 * race's round, or a board game's match, live or over
 * (`RoomGameDriver::part()`) — and who may throw a taunt in it.
 */
final class RoomState
{
    /**
     * @param  bool  $screen  the big screen's request (`?for=screen`, `RoomPolicy::screen`): the taunts with their targets
     * @param  bool  $voice  whether the instance's live voice is on — the switch, which the caller reads (§12 "Live voice")
     */
    public static function for(Room $room, User $viewer, bool $screen = false, bool $voice = false): RoomStateData
    {
        $nowMs = RoomClock::nowMs();
        $rows = RoomRanking::players($room);
        $mine = $rows->first(static fn (RoomPlayer $row): bool => $row->user_id === $viewer->id);
        $game = $room->latestGame();
        $part = $game === null ? ['live' => false, 'round' => null, 'match' => null] : RoomGameDriver::of($game->mechanic)->part($room, $game, $rows, $mine, $nowMs);
        $live = $game !== null && $part['live'];
        $setup = $room->isOpen() ? $room->setup() : null;
        $voiceData = $voice && $room->isOpen() ? new RoomVoiceData($room->voiceIsOn(), $viewer->voice_agreed_at !== null) : null;

        return new RoomStateData(
            revision: $room->revision,
            server_ms: $nowMs,
            room: RoomSummaryData::of($room, $nowMs),
            me: new RoomViewerData($mine?->id, $room->isHostedBy($viewer)),
            players: array_values($rows->map(static fn (RoomPlayer $row): RoomPlayerData => new RoomPlayerData(
                id: $row->id,
                name: $row->user?->name,
                present: $row->isPresent(),
                host: $row->user_id !== null && $row->user_id === $room->user_id,
                voice: $voiceData === null ? null : RoomVoice::stateOf($room, $row),
            ))->all()),
            game: $game === null ? null : RoomGameData::of($game, $live ? RoomGamePhase::Live : RoomGamePhase::Over),
            round: $part['round'],
            match: $part['match'],
            ranking: match (true) {
                $game === null => [],
                $live => RoomRanking::ofGame($game, $nowMs, $rows),
                default => RoomRanking::ofRoom($room, $nowMs, $rows),
            },
            setup: $setup === null ? null : RoomSetupData::of($setup, $game !== null && ! $live && $setup->playedIn($game)),
            taunts: $game !== null && $live ? self::taunts($room, $game, $mine, $nowMs, $screen) : null,
            voice: $voiceData,
        );
    }

    /**
     * The live game's taunts as the viewer may see them.
     */
    private static function taunts(Room $room, RoomGame $game, ?RoomPlayer $mine, int $nowMs, bool $screen): RoomTauntsData
    {
        $plays = $mine !== null && $mine->isPresent();
        $showMs = Config::integer('elifba.rooms.taunts.show_ms');
        $latest = $plays ? RoomTaunt::query()
            ->where('room_game_id', $game->id)
            ->where('room_player_id', $mine->id)
            ->max('sent_ms') : null;
        $readyMs = $latest === null ? null : (int) $latest + Config::integer('elifba.rooms.taunts.cooldown_ms');

        // Thrown within show_ms before now, newest first: what a target's phone or the screen still shows.
        $window = static fn (): Builder => RoomTaunt::query()
            ->where('room_game_id', $game->id)
            ->where('sent_ms', '>', $nowMs - $showMs)
            ->where('sent_ms', '<=', $nowMs)
            ->orderByDesc('sent_ms')
            ->orderByDesc('id')
            ->limit(RoomTauntsData::MAX_LISTED);

        return new RoomTauntsData(
            open: $plays && $room->taunts && RoomTauntWindow::open($room, $game, $nowMs) && RoomGameDriver::of($game->mechanic)->mayThrow($game, $mine),
            balance: $plays ? RoomTauntBalance::of($game, $mine, $nowMs) : null,
            ready_ms: $readyMs !== null && $readyMs > $nowMs ? $readyMs : null,
            received: ! $plays || ! $room->taunts ? [] : array_values($window()
                ->where('target_player_id', $mine->id)
                ->get()
                ->map(static fn (RoomTaunt $taunt): RoomTauntSeenData => new RoomTauntSeenData($taunt->id, $taunt->room_player_id, null, $taunt->taunt, $taunt->sent_ms))
                ->all()),
            shown: ! $screen ? null : (! $room->taunts ? [] : array_values($window()
                ->whereNotNull('target_player_id')
                ->get()
                ->map(static fn (RoomTaunt $taunt): RoomTauntSeenData => new RoomTauntSeenData($taunt->id, $taunt->room_player_id, $taunt->target_player_id, $taunt->taunt, $taunt->sent_ms))
                ->all())),
        );
    }
}
