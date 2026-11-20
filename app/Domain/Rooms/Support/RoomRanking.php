<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Language\Support\AzCollator;
use App\Domain\Rankings\Support\DenseRank;
use App\Domain\Rooms\Data\RoomRankedPlayerData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAnswer;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Models\RoomTaunt;
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
        return self::rank($players ?? self::players($game->room), self::net(self::points(self::closedRounds($game, $nowMs)), RoomTauntCost::spent($game, $nowMs)));
    }

    /**
     * The room's games are read with their rounds unless they are loaded
     * (`RoomList` loads them for many rooms at once).
     *
     * @param  Collection<int, RoomPlayer>|null  $players  the room's rows not removed, in order; read when null
     * @return list<RoomRankedPlayerData>
     */
    public static function ofRoom(Room $room, int $nowMs, ?Collection $players = null): array
    {
        $games = $room->relationLoaded('games') ? $room->games : $room->games()->with('rounds:id,room_game_id,position,study_ms,answer_ms,closed_ms')->get();
        $closed = [];

        foreach ($games as $game) {
            foreach (self::closedRounds($game, $nowMs) as $round) {
                $closed[$round] = $game->id;
            }
        }

        $answers = self::pointsByGame($closed);
        $taunts = $games->isEmpty() ? collect() : RoomTaunt::query()
            ->whereIn('room_game_id', $games->modelKeys())
            ->get(['room_game_id', 'room_player_id', 'sent_ms'])
            ->groupBy('room_game_id');
        $points = [];

        // Each game's net on its own, so that no game's taunts reach into another's points.
        foreach ($games as $game) {
            foreach (self::net($answers[$game->id] ?? [], RoomTauntCost::spent($game, $nowMs, $taunts->get($game->id, []))) as $player => $net) {
                $points[$player] = ($points[$player] ?? 0) + $net;
            }
        }

        foreach (self::matchPoints($room, $nowMs) as $player => $won) {
            $points[$player] = ($points[$player] ?? 0) + $won;
        }

        return self::rank($players ?? self::players($room), $points);
    }

    /**
     * The room's board matches that are over by `$nowMs` (ADR 034 decision
     * 10): `MATCH_WIN` to the winner, `MATCH_DRAW` to each side of a draw,
     * nothing for a match the host ended. A side out of time has lost
     * (`RoomMatch::settled()`).
     *
     * @return array<string, int> by player row
     */
    public static function matchPoints(Room $room, int $nowMs): array
    {
        $points = [];

        foreach ($room->matches()->get() as $match) {
            $match->settled($nowMs);

            if (! $match->isOver() || $match->ended_ms > $nowMs) {
                continue;
            }

            if ($match->result === 'draw') {
                foreach ([$match->white_player_id, $match->black_player_id] as $player) {
                    $points[$player] = ($points[$player] ?? 0) + RoomPointsRule::MATCH_DRAW;
                }
            } elseif ($match->result !== null) {
                $winner = $match->playerOf($match->result);
                $points[$winner] = ($points[$winner] ?? 0) + RoomPointsRule::MATCH_WIN;
            }
        }

        return $points;
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
     * Points per player row over these rounds.
     *
     * @param  list<string>  $rounds
     * @return array<string, int>
     */
    private static function points(array $rounds): array
    {
        $points = [];

        if ($rounds === []) {
            return $points;
        }

        foreach (RoomAnswer::query()->whereIn('room_round_id', $rounds)->get(['room_player_id', 'points']) as $answer) {
            $points[$answer->room_player_id] = ($points[$answer->room_player_id] ?? 0) + $answer->points;
        }

        return $points;
    }

    /**
     * Points per game and player row over these rounds.
     *
     * @param  array<string, string>  $rounds  each round's game, by the round's id
     * @return array<string, array<string, int>>
     */
    private static function pointsByGame(array $rounds): array
    {
        $points = [];

        if ($rounds === []) {
            return $points;
        }

        foreach (RoomAnswer::query()->whereIn('room_round_id', array_keys($rounds))->get(['room_round_id', 'room_player_id', 'points']) as $answer) {
            $game = $rounds[$answer->room_round_id];
            $points[$game][$answer->room_player_id] = ($points[$game][$answer->room_player_id] ?? 0) + $answer->points;
        }

        return $points;
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
