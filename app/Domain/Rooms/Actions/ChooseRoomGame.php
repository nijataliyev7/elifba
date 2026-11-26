<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSeats;
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
 * In a room of fewer than two present players the computer plays by
 * itself while none is chosen (`RoomSeats::ALONE`, ADR 036 as amended
 * 2026-11-22): a choice that only carries that level — a page sends the
 * whole choice, so «Yerləri dəyiş» or a clock does — stores none, and a
 * second player still takes its seat by joining; a level the state did not
 * show is stored as ever.
 *
 * From Y5 (ADR 038 decision 11) a domino game's choice is `{mechanic,
 * difficulty, table, seats, computer}`: the table (`setup_table`; null by
 * the number of present players) and the seats (`setup_seats`), each place
 * a present player of the room named once — 422 on `seats` otherwise — or
 * null for nobody placed there; `seats` absent keeps the room's. The
 * computer's level is `setup_computer`, the column a match's computer has;
 * «Orta», which every computer seat of a domino game plays at when none
 * is chosen, is stored as none, so a later board game does not read it as
 * a computer chosen. A race's or a board game's choice leaves the table
 * and the seats as they are, as a domino game's leaves the colours.
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

            // A domino game's level is a turn's time (ADR 038 decision 22): its choice holds a table.
            $domino = $setup->turnMs() !== null;
            $places = array_values(array_filter($setup->seats ?? [], static fn (?string $player): bool => $player !== null));

            if ($places !== [] && $locked->players()->present()->whereKey($places)->count() !== count($places)) {
                throw ValidationException::withMessages(['seats' => __('rooms.errors.seat_gone')]);
            }

            $rounds = $setup->rounds ?? $locked->setup_rounds;
            // Seats named — one of them with the computer — are the seats; none named, the room's stand.
            $named = $setup->white !== null || $setup->black !== null;
            $white = $named ? $setup->white : $locked->setup_white_id;
            $black = $named ? $setup->black : $locked->setup_black_id;
            // A board game's choice says whether the computer plays; a race's leaves it — but never a domino game's level, which
            // a board game chosen after the race would read as a computer chosen (ADR 038 decision 75).
            $computer = match (true) {
                $setup->rounds === null => $setup->computer,
                $locked->setup()->turnMs() !== null => null,
                default => $locked->setup_computer,
            };
            // The level a room of one plays by itself is not a choice: storing it would keep a joiner from its seat.
            if ($locked->setup_computer === null && $computer !== null && $computer === ($domino ? RoomSeats::ALONE : RoomSeats::computer($locked))) {
                $computer = null;
            }

            $table = $domino ? $setup->table : $locked->setup_table;
            $seats = $domino ? $setup->seats : $locked->setup_seats;

            if ($locked->setup_mechanic === $setup->mechanic && $locked->setup_difficulty === $setup->difficulty && $locked->setup_rounds === $rounds
                && $locked->setup_white_id === $white && $locked->setup_black_id === $black && $locked->setup_computer === $computer
                && $locked->setup_table === $table && $locked->setup_seats === $seats) {
                return;
            }

            $locked->setup_mechanic = $setup->mechanic;
            $locked->setup_difficulty = $setup->difficulty;
            $locked->setup_rounds = $rounds;
            $locked->setup_white_id = $white;
            $locked->setup_black_id = $black;
            $locked->setup_computer = $computer;
            $locked->setup_table = $table;
            $locked->setup_seats = $seats;
            $locked->last_activity_at = now();
            RoomSignal::raise($locked);
        });
    }
}
