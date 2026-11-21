<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The host changes the room's choice of the next game (docs/architecture.md
 * §12 "Games first", ADR 026 decision 8): `PUT /rooms/{room}/setup` with the
 * whole choice, from the host's phone or the big screen alike. Under the
 * room row's lock it refuses a closed room and a room whose game is still
 * running — `RoomPolicy::start` refused them already, and a start at the
 * same moment meets here — then writes the three columns, marks the room
 * active, raises its revision and signals it: every device, the one that
 * sent it too, learns the choice from the state. The choice the room
 * already has changes nothing and signals nothing.
 *
 * A board game's choice (D3, ADR 034 decision 7) is `{mechanic,
 * difficulty, white, black}`: two different present players of the room,
 * stored as its seats (`setup_white_id`, `setup_black_id`) — 422 when one
 * of them is not present now. Its number of rounds stays as it is, for the
 * next race; a race's choice leaves the seats as they are. A choice of the
 * other kind than the room's names only the game and its level (D5): a
 * board game's then keeps the room's seats — read through `RoomSeats` —
 * and a race's takes the rounds the room keeps (`Room::roundsKept()`).
 *
 * From K3 (ADR 036 decision 2) a board game's choice may name the computer
 * (`computer`, its level, stored as `setup_computer`) with at most one
 * seat: the other colour is then the computer's. A board game's choice
 * always says it — null turns the computer off — and a race's leaves it.
 */
final readonly class ChooseRoomGame
{
    /**
     * @throws AuthorizationException when the room is closed or a game is running in it
     * @throws ValidationException on `white` or `black` when a seat is not a present player of the room
     */
    public function handle(Room $room, RoomGameSetupData $setup): void
    {
        DB::transaction(static function () use ($room, $setup): void {
            $locked = RoomLock::room($room);

            if ($locked === null || ! $locked->isOpen() || $locked->runningGame(RoomClock::nowMs()) !== null) {
                throw new AuthorizationException;
            }

            foreach (['white' => $setup->white, 'black' => $setup->black] as $seat => $player) {
                if ($player !== null && ! $locked->players()->present()->whereKey($player)->exists()) {
                    throw ValidationException::withMessages([$seat => __('rooms.errors.seat_gone')]);
                }
            }

            $rounds = $setup->rounds ?? $locked->setup_rounds;
            // Seats named — one of them with the computer — are the seats; none named, the room's stand.
            $named = $setup->white !== null || $setup->black !== null;
            $white = $named ? $setup->white : $locked->setup_white_id;
            $black = $named ? $setup->black : $locked->setup_black_id;
            // A board game's choice says whether the computer plays; a race's leaves it.
            $computer = $setup->rounds === null ? $setup->computer : $locked->setup_computer;

            if ($locked->setup_mechanic === $setup->mechanic && $locked->setup_difficulty === $setup->difficulty && $locked->setup_rounds === $rounds
                && $locked->setup_white_id === $white && $locked->setup_black_id === $black && $locked->setup_computer === $computer) {
                return;
            }

            $locked->setup_mechanic = $setup->mechanic;
            $locked->setup_difficulty = $setup->difficulty;
            $locked->setup_rounds = $rounds;
            $locked->setup_white_id = $white;
            $locked->setup_black_id = $black;
            $locked->setup_computer = $computer;
            $locked->last_activity_at = now();
            RoomSignal::raise($locked);
        });
    }
}
