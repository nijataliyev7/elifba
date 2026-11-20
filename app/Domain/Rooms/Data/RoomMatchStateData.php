<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomMatch;
use App\Domain\Rooms\Models\RoomMatchTaunt;
use App\Domain\Rooms\Support\Checkers;
use App\Domain\Rooms\Support\RoomClock;
use App\Models\User;
use Illuminate\Support\Facades\Config;

/**
 * A board room's match as one account sees it (docs/architecture.md §12
 * "Board games", ADR 034 decision 7; `GET /rooms/{room}/match`): the
 * revision it was read at, the viewer's side, and the latest match — its
 * position, whose turn it is, the last move, each side's time left now
 * (`clock`: the side to move's runs down from `server_ms`), and while it
 * runs the legal moves of the side to move, which the page offers and
 * never computes. A side out of time has lost (`RoomMatch::settled()`).
 * A player is known by `room_players.id`; names come from the room's
 * state.
 *
 * `taunts` (decision 11): whether the viewer may throw one now and when
 * its cooldown ends, the taunts aimed at the viewer within
 * `elifba.rooms.taunts.show_ms` — and for the big screen (`$screen`) every
 * taunt of that window with its target. No other device gets a taunt.
 *
 * @phpstan-type RoomMatchTauntArray array{id: string, from: string, to?: string, taunt: string, sent_ms: int}
 * @phpstan-type RoomMatchArray array{id: string, position: int, board: string, turn: string, ply: int, white: string, black: string, last_path: list<int>|null, over: bool, result: string|null, ending: string|null, moves: list<list<int>>, capture: bool, clock: array{total_ms: int, white_ms: int, black_ms: int}}
 * @phpstan-type RoomMatchStateArray array{revision: int, server_ms: int, board_game: string, me: array{player_id: string|null, side: string|null}, match: RoomMatchArray|null, taunts: array{open: bool, ready_ms: int|null, received: list<RoomMatchTauntArray>, shown: list<RoomMatchTauntArray>|null}}
 */
final class RoomMatchStateData
{
    /**
     * @return RoomMatchStateArray
     */
    public static function for(Room $room, User $viewer, bool $screen = false): array
    {
        $nowMs = RoomClock::nowMs();
        $match = $room->latestMatch();
        $mine = $room->rowOf($viewer);
        $side = $match?->sideOf($mine?->id);

        return [
            'revision' => $room->revision,
            'server_ms' => $nowMs,
            'board_game' => $room->board->value ?? 'checkers',
            'me' => [
                'player_id' => $mine?->id,
                'side' => $side,
            ],
            'match' => $match === null ? null : self::match($match, $nowMs),
            'taunts' => self::taunts($room, $match, $mine?->id, $side !== null && ($mine?->isPresent() ?? false), $nowMs, $screen),
        ];
    }

    /**
     * @return RoomMatchArray
     */
    private static function match(RoomMatch $match, int $nowMs): array
    {
        $legal = $match->isOver() ? [] : Checkers::moves($match->board, $match->turn);

        return [
            'id' => $match->id,
            'position' => $match->position,
            'board' => $match->board,
            'turn' => $match->turn,
            'ply' => $match->ply,
            'white' => $match->white_player_id,
            'black' => $match->black_player_id,
            'last_path' => $match->last_path,
            'over' => $match->isOver(),
            'result' => $match->result,
            'ending' => $match->ending?->value,
            'moves' => array_map(static fn (array $move): array => $move['path'], $legal),
            'capture' => ($legal[0]['captured'] ?? []) !== [],
            'clock' => [
                'total_ms' => Config::integer('elifba.rooms.checkers.clock_ms'),
                'white_ms' => $match->leftMs('w', $nowMs),
                'black_ms' => $match->leftMs('b', $nowMs),
            ],
        ];
    }

    /**
     * @return array{open: bool, ready_ms: int|null, received: list<RoomMatchTauntArray>, shown: list<RoomMatchTauntArray>|null}
     */
    private static function taunts(Room $room, ?RoomMatch $match, ?string $mine, bool $plays, int $nowMs, bool $screen): array
    {
        $running = $match !== null && ! $match->isOver();

        if (! $running || ! $room->taunts) {
            return ['open' => false, 'ready_ms' => null, 'received' => [], 'shown' => $screen ? [] : null];
        }

        $latest = $plays ? RoomMatchTaunt::query()->where('room_match_id', $match->id)->where('room_player_id', $mine)->max('sent_ms') : null;
        $readyMs = $latest === null ? null : (int) $latest + Config::integer('elifba.rooms.taunts.cooldown_ms');
        $window = RoomMatchTaunt::query()
            ->where('room_match_id', $match->id)
            ->where('sent_ms', '>', $nowMs - Config::integer('elifba.rooms.taunts.show_ms'))
            ->where('sent_ms', '<=', $nowMs)
            ->orderByDesc('sent_ms')
            ->limit(RoomTauntsData::MAX_LISTED)
            ->get();

        return [
            'open' => $plays,
            'ready_ms' => $readyMs !== null && $readyMs > $nowMs ? $readyMs : null,
            'received' => $mine === null ? [] : array_values($window
                ->where('target_player_id', $mine)
                ->map(static fn (RoomMatchTaunt $taunt): array => ['id' => $taunt->id, 'from' => $taunt->room_player_id, 'taunt' => $taunt->taunt, 'sent_ms' => $taunt->sent_ms])
                ->all()),
            'shown' => ! $screen ? null : array_values($window
                ->filter(static fn (RoomMatchTaunt $taunt): bool => $taunt->target_player_id !== null)
                ->map(static fn (RoomMatchTaunt $taunt): array => ['id' => $taunt->id, 'from' => $taunt->room_player_id, 'to' => (string) $taunt->target_player_id, 'taunt' => $taunt->taunt, 'sent_ms' => $taunt->sent_ms])
                ->all()),
        ];
    }
}
