<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Language\Support\DateLabel;
use App\Domain\Language\Support\Ordinal;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Config;

/**
 * An account's rooms (docs/architecture.md §12 "Rooms and joining",
 * "Scoring and what is kept", "Games first"): the rooms it hosts or has a
 * row in that was not removed — nobody else's, and none it took its name
 * out of. Open are those it hosts or is present in, for «Oyunlar» (`open()`):
 * each with its code, the game of its choice (`Room::setup()`) and whether
 * a game is running in it. The others (closed, or left) are past, for
 * «Keçmiş oyunlar» (`past()`), newest first, with the day in the interface
 * language, the games played in them (mechanic and difficulty, in order)
 * and the account's own row of the room's standing — its points and its
 * place, labelled here (`Ordinal::label()`), derived now by `RoomRanking`
 * and never stored; null for a host who did not play. No name is listed
 * here: a room has none, and its players are on its own page.
 *
 * @phpstan-type OpenRoomArray array{id: string, code: string|null, host: bool, players: int, mechanic: string, board: string|null, running: bool}
 * @phpstan-type PastRoomArray array{id: string, date: string, host: bool, players: int, games: list<array{mechanic: string, difficulty: int}>, mine: array{points: int, place: string|null}|null}
 */
final class RoomList
{
    /**
     * The rooms the account hosts or is present in, open now.
     *
     * @return list<OpenRoomArray>
     */
    public static function open(User $account): array
    {
        $open = [];
        $nowMs = RoomClock::nowMs();

        foreach (self::rooms($account) as $room) {
            $host = $room->isHostedBy($account);
            /** @var RoomPlayer|null $row */
            $row = $room->players->firstWhere('user_id', $account->id);

            if ($room->isOpen() && ($host || ($row?->isPresent() ?? false))) {
                $open[] = [
                    'id' => $room->id,
                    'code' => $room->code,
                    'host' => $host,
                    'players' => (int) $room->getAttribute('present_count'),
                    'mechanic' => $room->setup()->mechanic->value,
                    'board' => $room->board?->value,
                    'running' => $room->runningGame($nowMs) !== null || $room->runningMatch() !== null,
                ];
            }
        }

        return $open;
    }

    /**
     * The rooms the account was in that are closed, or that it left.
     *
     * @return list<PastRoomArray>
     */
    public static function past(User $account, string $locale): array
    {
        $past = [];
        $timezone = Config::string('elifba.timezone');
        $nowMs = RoomClock::nowMs();

        foreach (self::rooms($account) as $room) {
            $host = $room->isHostedBy($account);
            /** @var RoomPlayer|null $row */
            $row = $room->players->firstWhere('user_id', $account->id);

            if ($room->isOpen() && ($host || ($row?->isPresent() ?? false))) {
                continue;
            }

            $own = RoomResults::own($room, $row, $nowMs, $room->players);

            $past[] = [
                'id' => $room->id,
                'date' => DateLabel::day($room->created_at->setTimezone($timezone), $locale),
                'host' => $host,
                'players' => $room->players->count(),
                'games' => array_values($room->games->map(static fn (RoomGame $game): array => [
                    'mechanic' => $game->mechanic->value,
                    'difficulty' => $game->difficulty,
                ])->all()),
                'mine' => $own === null ? null : [
                    'points' => $own['points'],
                    'place' => $own['place'] === null ? null : Ordinal::label($own['place'], $locale),
                ],
            ];
        }

        return $past;
    }

    /**
     * @return Collection<int, Room>
     */
    private static function rooms(User $account): Collection
    {
        return Room::query()
            ->reachableBy($account)
            ->withCount(['players as present_count' => static fn (Builder $players) => $players
                ->whereNotNull('user_id')->whereNull('left_at')->whereNull('removed_at')])
            ->with([
                // The rows a ranking lists, as `RoomRanking::players()` reads them.
                'players' => static fn (HasMany $players) => $players
                    ->whereNull('removed_at')
                    ->with('user:id,name')
                    ->orderBy('joined_at')
                    ->orderBy('id'),
                'games.rounds:id,room_game_id,position,study_ms,answer_ms,closed_ms',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}
