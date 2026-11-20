<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Data\RoomGameData;
use App\Domain\Rooms\Data\RoomMineData;
use App\Domain\Rooms\Data\RoomMomentData;
use App\Domain\Rooms\Data\RoomPlayerData;
use App\Domain\Rooms\Data\RoomResultData;
use App\Domain\Rooms\Data\RoomRevealData;
use App\Domain\Rooms\Data\RoomRoundData;
use App\Domain\Rooms\Data\RoomSetupData;
use App\Domain\Rooms\Data\RoomSpentData;
use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Data\RoomSummaryData;
use App\Domain\Rooms\Data\RoomTauntsData;
use App\Domain\Rooms\Data\RoomTauntSeenData;
use App\Domain\Rooms\Data\RoomViewerData;
use App\Domain\Rooms\Data\RoomVoiceData;
use App\Domain\Rooms\Enums\RoomGamePhase;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAnswer;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Models\RoomTaunt;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
        $moment = $game === null || ! $room->isOpen() ? RoomMomentData::over() : $game->timetable()->moment($nowMs);
        $live = $game !== null && ! $moment->isOver();
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
            round: $live ? self::round($game, $moment, $rows, $mine) : null,
            ranking: match (true) {
                $game === null => $room->board === null ? [] : RoomRanking::ofRoom($room, $nowMs, $rows),
                $live => RoomRanking::ofGame($game, $nowMs, $rows),
                default => RoomRanking::ofRoom($room, $nowMs, $rows),
            },
            setup: $setup === null ? null : new RoomSetupData(
                mechanic: $setup->mechanic,
                difficulty: $setup->difficulty,
                rounds: $setup->rounds,
                played: $game !== null && ! $live
                    && $game->mechanic === $setup->mechanic
                    && $game->difficulty === $setup->difficulty
                    && $game->rounds->count() === $setup->rounds,
            ),
            taunts: $live ? self::taunts($room, $game, $mine, $nowMs, $screen) : null,
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
            open: $plays && $room->taunts && RoomTauntWindow::open($room, $game, $nowMs),
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

    /**
     * The current round of a live game, as its phase allows.
     *
     * @param  Collection<int, RoomPlayer>  $rows  the room's players not removed, in order
     */
    private static function round(RoomGame $game, RoomMomentData $moment, Collection $rows, ?RoomPlayer $mine): RoomRoundData
    {
        $timetable = $game->timetable();
        $index = $moment->round ?? 0;
        /** @var RoomRound $round */
        $round = $game->rounds->firstWhere('position', $index);
        $phase = $moment->phase;
        $asked = $phase === RoomPhase::Answer || $phase === RoomPhase::Reveal;
        $revealed = $phase === RoomPhase::Reveal;

        /** @var Collection<string, RoomAnswer> $answers */
        $answers = $asked ? $round->answers()->get()->keyBy('room_player_id') : new Collection;
        // Counted as the early close counts (`RoomWaits`): a full count is a closed round.
        $since = RoomWaits::since($game, $index);
        $waited = $rows->filter(static fn (RoomPlayer $row): bool => RoomWaits::waitsFor($row, $since, $answers->has($row->id)));
        $own = $mine === null ? null : $answers->get($mine->id);
        $spent = $revealed ? RoomTauntCost::spentAt($game, $index) : [];

        return new RoomRoundData(
            id: $round->id,
            index: $index,
            total: $timetable->count(),
            phase: $phase,
            opens_ms: $timetable->opens($index),
            answer_from_ms: $timetable->answerFrom($index),
            bell_ms: $timetable->bell($index),
            closes_ms: $revealed ? $timetable->closes($index) : null,
            next_ms: $revealed ? $timetable->next($index) : null,
            study: $phase === RoomPhase::Study ? $round->studyData() : null,
            ask: $asked ? $round->askData() : null,
            answered: $own !== null,
            answered_count: $waited->filter(static fn (RoomPlayer $row): bool => $answers->has($row->id))->count(),
            present_count: $waited->count(),
            reveal: $revealed ? new RoomRevealData(
                solution: $round->solutionData()->ids,
                word: $round->solutionData()->word,
                mine: $own === null ? null : RoomMineData::of($own),
                results: array_values($rows
                    ->filter(static fn (RoomPlayer $row): bool => $answers->has($row->id))
                    ->map(static function (RoomPlayer $row) use ($answers): RoomResultData {
                        /** @var RoomAnswer $answer */
                        $answer = $answers->get($row->id);

                        return new RoomResultData($row->id, $answer->correct, $answer->points);
                    })
                    ->all()),
                spent: array_values($rows
                    ->filter(static fn (RoomPlayer $row): bool => ($spent[$row->id] ?? 0) > 0)
                    ->map(static fn (RoomPlayer $row): RoomSpentData => new RoomSpentData($row->id, $spent[$row->id]))
                    ->all()),
            ) : null,
        );
    }
}
