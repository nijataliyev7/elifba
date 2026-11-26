<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Data\RoomRankedPlayerData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\Drivers\RoomGameDriver;
use Illuminate\Database\Eloquent\Collection;

/**
 * A closed room's results page, game by game (docs/architecture.md §12
 * "Scoring and what is kept", ADR 022): each game's mechanic, difficulty
 * and number of rounds, and its ranking (`RoomRanking::ofGame()`), derived
 * now and never stored — a board game its clock in place of the rounds
 * (D3, ADR 034 decision 17), and from K3 the level of the computer that
 * played one of its seats, or null (ADR 036 decision 11), and from Y5 a
 * domino game its turn's time, its table, the computer's level and its
 * final balances (ADR 038 decision 38) — what its driver lists. A ranking names players by `room_players.id`; the
 * page reads their names from the state's `players`, read from the
 * accounts as they are now — a name taken out is null there.
 *
 * @phpstan-import-type RoomRankedPlayerArray from RoomRankedPlayerData
 *
 * @phpstan-type RoomResultGameArray array{id: string, mechanic: string, difficulty: int, rounds?: int, clock?: array{base_ms: int, increment_ms: int}, turn_ms?: int, table?: string, computer?: int|null, balances?: list<int>, ranking: list<RoomRankedPlayerArray>}
 */
final class RoomResults
{
    /**
     * @return list<RoomResultGameArray>
     */
    public static function games(Room $room, int $nowMs): array
    {
        $players = RoomRanking::players($room);

        return array_values($room->games()->with(['rounds', 'acts'])->get()->map(static fn (RoomGame $game): array => [
            'id' => $game->id,
            'mechanic' => $game->mechanic->value,
            'difficulty' => $game->difficulty,
            ...RoomGameDriver::of($game->mechanic)->listed($game, $nowMs, true),
            'ranking' => array_map(
                static fn (RoomRankedPlayerData $row): array => $row->toArray(),
                RoomRanking::ofGame($game, $nowMs, $players),
            ),
        ])->all());
    }

    /**
     * The account's own row of the room's standing (`RoomRanking::ofRoom()`
     * over every game): its points and its place, or null for an account
     * with no row (a host who did not play).
     *
     * @param  Collection<int, RoomPlayer>  $players  the room's rows not removed, in order
     * @return array{points: int, place: int|null}|null
     */
    public static function own(Room $room, ?RoomPlayer $row, int $nowMs, Collection $players): ?array
    {
        if ($row === null) {
            return null;
        }

        foreach (RoomRanking::ofRoom($room, $nowMs, $players) as $ranked) {
            if ($ranked->player_id === $row->id) {
                return ['points' => $ranked->points, 'place' => $ranked->place];
            }
        }

        return null;
    }
}
