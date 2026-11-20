<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Language\Support\AzCollator;
use App\Domain\Rankings\Support\DenseRank;
use App\Domain\Rooms\Data\RoomRankedPlayerData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Models\RoomTaunt;
use App\Domain\Rooms\Support\Drivers\RoomGameDriver;
use Illuminate\Database\Eloquent\Collection;

/**
 * A room's ranking (docs/architecture.md §12 "Scoring and what is kept",
 * ADR 022 decision 4), derived at each request and never stored: the
 * points of `room_answers` per player over **closed** rounds only — a round
 * is closed once its close (the early close or the bell) has come, by the
 * timetable at `$nowMs`, before the game was over: a round whose close is
 * the game's end was never revealed, and counts no more than one the end
 * cut off. The open round
 * never counts, so the list cannot tell who has just been right; a round
 * the host's end or the room's close cut off never counts at all.
 *
 * From T2 (§12 "Taunts (T2)", ADR 029 decisions 1–2) a game's points are
 * its answers' points over its closed rounds less `RoomPointsRule::
 * TAUNT_COST` for each taunt the player threw in it that counts by then
 * (`RoomTauntCost`: from the first close after the throw, every one once
 * the game is over), never below 0 — so a total can go down from one
 * reveal to the next, and still changes only at a close. `room_answers`
 * never changes for it, and nothing of it is stored.
 *
 * From D3 (§12 "Board games", ADR 034 decision 21) what a game earned
 * and when its taunts count are its driver's (`RoomGameDriver::earned()`,
 * `RoomTauntCost::spent()`): a race's as above; a board game's at its one
 * close, its end — 3 to the winner, 1 to each seat of a draw — less its
 * taunts, netted on its own like any game.
 *
 * `ofGame()` ranks one game, `ofRoom()` every game of the room. The rows
 * are the room's players the host did not remove, nameless ones included
 * («Oyunçu», with their points). Places are dense (`DenseRank`), with no
 * tie-breaker; inside a place names stand in Azerbaijani order
 * (`AzCollator`), a nameless player last; a player with no points is
 * listed after the places, without one.
 */
final class RoomRanking
{
    /**
     * What a room's games are read with for its standing: a race's rounds
     * and their answers, a board game's acts.
     *
     * @var list<string>
     */
    public const array GAME_RELATIONS = [
        'rounds:id,room_game_id,position,study_ms,answer_ms,closed_ms',
        'rounds.answers:id,room_round_id,room_player_id,points',
        'acts',
    ];

    /**
     * The rows a ranking lists: the room's players the host did not
     * remove, with their accounts' names, in the order they joined.
     *
     * @return Collection<int, RoomPlayer>
     */
    public static function players(Room $room): Collection
    {
        return $room->players()
            ->whereNull('removed_at')
            ->with('user:id,name')
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, RoomPlayer>|null  $players  the room's rows not removed, in order; read when null
     * @return list<RoomRankedPlayerData>
     */
    public static function ofGame(RoomGame $game, int $nowMs, ?Collection $players = null): array
    {
        return self::rank($players ?? self::players($game->room), self::net(RoomGameDriver::of($game->mechanic)->earned($game, $nowMs), RoomTauntCost::spent($game, $nowMs)));
    }

    /**
     * The room's games are read with their rounds, their answers and their
     * acts unless they are loaded (`RoomList` loads them for many rooms at
     * once).
     *
     * @param  Collection<int, RoomPlayer>|null  $players  the room's rows not removed, in order; read when null
     * @return list<RoomRankedPlayerData>
     */
    public static function ofRoom(Room $room, int $nowMs, ?Collection $players = null): array
    {
        $games = $room->relationLoaded('games') ? $room->games : $room->games()->with(self::GAME_RELATIONS)->get();
        $taunts = $games->isEmpty() ? collect() : RoomTaunt::query()
            ->whereIn('room_game_id', $games->modelKeys())
            ->get(['room_game_id', 'room_player_id', 'sent_ms'])
            ->groupBy('room_game_id');
        $points = [];

        // Each game's net on its own, so that no game's taunts reach into another's points.
        foreach ($games as $game) {
            $earned = RoomGameDriver::of($game->mechanic)->earned($game, $nowMs);

            foreach (self::net($earned, RoomTauntCost::spent($game, $nowMs, $taunts->get($game->id, []))) as $player => $net) {
                $points[$player] = ($points[$player] ?? 0) + $net;
            }
        }

        return self::rank($players ?? self::players($room), $points);
    }

    /**
     * The ids of the game's rounds that closed by `$nowMs` and before the
     * game was over.
     *
     * @return list<string>
     */
    public static function closedRounds(RoomGame $game, int $nowMs): array
    {
        $timetable = $game->timetable();

        return array_values($game->rounds
            ->filter(static fn (RoomRound $round): bool => $timetable->closes($round->position) <= $nowMs && $timetable->closes($round->position) < $timetable->overMs())
            ->map(static fn (RoomRound $round): string => $round->id)
            ->all());
    }

    /**
     * One game's points less the cost of its counted taunts, never below 0.
     *
     * @param  array<string, int>  $points  by player row
     * @param  array<string, int>  $spent  counted taunts by player row
     * @return array<string, int>
     */
    private static function net(array $points, array $spent): array
    {
        foreach ($spent as $player => $count) {
            $points[$player] = max(0, ($points[$player] ?? 0) - RoomPointsRule::TAUNT_COST * $count);
        }

        return $points;
    }

    /**
     * @param  Collection<int, RoomPlayer>  $players
     * @param  array<string, int>  $points
     * @return list<RoomRankedPlayerData>
     */
    private static function rank(Collection $players, array $points): array
    {
        $scored = [];

        foreach ($players as $player) {
            if (($points[$player->id] ?? 0) > 0) {
                $scored[$player->id] = $points[$player->id];
            }
        }

        $places = DenseRank::places($scored);
        $rows = array_values($players->all());

        // Stable: rows that compare equal keep the order they joined in.
        usort($rows, static function (RoomPlayer $a, RoomPlayer $b) use ($places): int {
            $placeA = $places[$a->id] ?? PHP_INT_MAX;
            $placeB = $places[$b->id] ?? PHP_INT_MAX;
            $nameA = $a->user?->name;
            $nameB = $b->user?->name;

            return ($placeA <=> $placeB)
                ?: (($nameA === null) <=> ($nameB === null))
                ?: ($nameA !== null && $nameB !== null ? AzCollator::compare($nameA, $nameB) : 0);
        });

        return array_map(static fn (RoomPlayer $player): RoomRankedPlayerData => new RoomRankedPlayerData(
            player_id: $player->id,
            points: $points[$player->id] ?? 0,
            place: $places[$player->id] ?? null,
        ), $rows);
    }
}
