<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\RoomMoveData;
use App\Domain\Rooms\Enums\RoomAnswerStatus;
use App\Domain\Rooms\Enums\RoomMatchEnding;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomMove;
use App\Domain\Rooms\Support\Checkers;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A player's move in the room's board match (docs/architecture.md §12
 * "Board games", ADR 034 decision 5), the only writer of a move. In one
 * transaction under the room row's lock:
 *
 * 1. the `event_id` is stored → `duplicate`;
 * 2. no match runs — a side out of time has lost it — it is not the
 *    player's turn, or the page saw another position (`ply`) → `late`,
 *    nothing written;
 * 3. the path is not one of the side's legal moves (`Checkers`) → 422;
 * 4. otherwise the move is stored, the position, the turn and the draw
 *    count follow it, and the match ends when the other side has no move
 *    (the mover wins) or after fifteen quiet moves each (a draw).
 *
 * An account that plays neither side gets a 403.
 */
final readonly class PlayRoomMove
{
    /**
     * @throws AuthorizationException when the account plays no side
     * @throws ValidationException when the path is no legal move
     */
    public function handle(Room $room, User $account, RoomMoveData $data): RoomAnswerStatus
    {
        return DB::transaction(static function () use ($room, $account, $data): RoomAnswerStatus {
            $locked = RoomLock::room($room);
            $row = $locked?->rowOf($account);

            if ($locked === null || $row === null || ! $row->isPresent()) {
                throw new AuthorizationException;
            }

            if (RoomMove::query()->where('event_id', $data->event_id)->exists()) {
                return RoomAnswerStatus::Duplicate;
            }

            $match = $locked->runningMatch();

            if ($match === null || ! $locked->isOpen()) {
                return RoomAnswerStatus::Late;
            }

            $side = $match->sideOf($row->id);

            if ($side === null) {
                throw new AuthorizationException;
            }

            if ($side !== $match->turn || $data->ply !== $match->ply) {
                return RoomAnswerStatus::Late;
            }

            $move = Checkers::find($match->board, $side, $data->path);

            if ($move === null) {
                throw ValidationException::withMessages(['path' => __('rooms.match.illegal')]);
            }

            $nowMs = RoomClock::nowMs();

            $record = new RoomMove;
            $record->event_id = $data->event_id;
            $record->room_match_id = $match->id;
            $record->ply = $match->ply;
            $record->room_player_id = $row->id;
            $record->path = $move['path'];
            $record->captured = $move['captured'];
            $record->created_ms = $nowMs;
            $record->save();

            // The mover's clock stops: it keeps what it had left; the other side's starts.
            if ($side === 'w') {
                $match->white_left_ms = $match->leftMs('w', $nowMs);
            } else {
                $match->black_left_ms = $match->leftMs('b', $nowMs);
            }

            $match->turn_since_ms = $nowMs;

            $quiet = Checkers::isQuiet($match->board, $move);
            $match->board = Checkers::apply($match->board, $move);
            $match->turn = Checkers::other($side);
            $match->ply++;
            $match->quiet_plies = $quiet ? $match->quiet_plies + 1 : 0;
            $match->last_path = $move['path'];

            if (Checkers::moves($match->board, $match->turn) === []) {
                $match->ended_ms = $nowMs;
                $match->result = $side;
                $match->ending = RoomMatchEnding::NoMoves;
            } elseif ($match->quiet_plies >= $match::QUIET_DRAW) {
                $match->ended_ms = $nowMs;
                $match->result = 'draw';
                $match->ending = RoomMatchEnding::Quiet;
            }

            $match->save();

            $locked->last_activity_at = now();
            RoomSignal::raise($locked);

            return RoomAnswerStatus::Accepted;
        });
    }
}
