<?php

namespace App\Domain\Rooms\Support\Drivers;

use App\Domain\Content\Enums\RoomGameKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Data\RoomActData;
use App\Domain\Rooms\Data\RoomDominoData;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Data\RoomMatchData;
use App\Domain\Rooms\Data\RoomRoundData;
use App\Domain\Rooms\Enums\RoomActKind;
use App\Domain\Rooms\Enums\RoomActStatus;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Everything of a room game that depends on its kind (docs/architecture.md
 * §12 "Board games", ADR 034 decision 3), in one place: `of()` reads the
 * kind of a mechanic — the only place in `Rooms` that does, beside the data
 * classes that hold a shape by kind — and gives the `RaceDriver`, a race of
 * timed rounds (the code a room had before D3, moved), the `BoardDriver`,
 * two seats taking turns on one board, or from Y5 the `DominoDriver`, two
 * to four seats holding hands nobody else sees (ADR 038 decisions 2–3; the
 * shared machinery gained signatures, never behaviour). Each concern is one
 * method: the choice's rules, the start, whether the game is over, what
 * each player earned and what its taunts cost, who may taunt whom and the
 * balance, the state's part, an act, a presence that changed, a read and
 * the computer's seat (ADR 036).
 * Nothing else in `Rooms` asks for the kind, compares a mechanic with one
 * of them or looks at which driver it was given.
 */
abstract class RoomGameDriver
{
    public static function of(RoomMechanic $mechanic): self
    {
        static $drivers = [];

        return $drivers[$mechanic->kind()->value] ??= match ($mechanic->kind()) {
            RoomGameKind::Race => new RaceDriver,
            RoomGameKind::Board => new BoardDriver,
            RoomGameKind::Domino => new DominoDriver,
        };
    }

    /**
     * After a player of a room whose row the caller holds locked came,
     * left or was removed: whatever the running game does about it.
     */
    public static function presenceChanged(Room $locked, RoomPlayer $row, int $nowMs): void
    {
        $game = $locked->isOpen() ? $locked->runningGame($nowMs) : null;

        if ($game !== null) {
            self::of($game->mechanic)->whenPresenceChanges($locked, $game, $row, $nowMs);
        }
    }

    /**
     * The choice: what it holds beyond the game and its level, as rules
     * (`RoomGameSetupData::rules()`) — left out when `$kept`: the room's
     * choice was of the other kind, whose state carried none of this kind's
     * part, and the room fills it.
     *
     * @return array<string, mixed>
     */
    abstract public function choiceRules(bool $kept = false): array;

    /**
     * The start: fills `$game` — its room, position, mechanic, difficulty,
     * content version and start already set — with what its kind needs,
     * saves it with whatever else it needs, in a room the caller holds
     * locked.
     *
     * @throws ValidationException when the choice cannot start
     */
    abstract public function start(Room $locked, RoomGame $game, RoomGameSetupData $setup): void;

    /** When the game is over: whether it is by `$nowMs`. */
    abstract public function isOver(RoomGame $game, int $nowMs): bool;

    /**
     * Its closes and what each earned: the points of each player row by
     * `$nowMs`, before its taunts.
     *
     * @return array<string, int>
     */
    abstract public function earned(RoomGame $game, int $nowMs): array;

    /**
     * A taunt's cost: whether a taunt of the game thrown at `$sentMs` counts
     * by `$nowMs` — a race's from the first close after it, a board game's
     * at its end (`RoomTauntCost::spent()` counts them).
     *
     * @return Closure(int): bool
     */
    abstract public function tauntCounts(RoomGame $game, int $nowMs): Closure;

    /** A taunt: whether the game is where one may be thrown at `$nowMs` (the room's being open is the caller's). */
    abstract public function tauntsOpen(RoomGame $game, int $nowMs): bool;

    /** A taunt: whether a present player may throw one in this game at all. */
    abstract public function mayThrow(RoomGame $game, RoomPlayer $thrower): bool;

    /** A taunt: whether the thrower may aim at this present row of the room, never its own. */
    abstract public function mayAim(RoomGame $game, RoomPlayer $thrower, string $targetId): bool;

    /**
     * A taunt: what a player's taunts in this game are paid from by
     * `$nowMs`, before any of them (`RoomTauntBalance` takes them off).
     */
    abstract public function tauntBudget(RoomGame $game, RoomPlayer $row, int $nowMs): int;

    /**
     * The state's part of the room's latest game, as the viewer sees it:
     * whether it is live, and a live race's round, a board game's match or
     * a domino game's tiles as the viewer may see them (ADR 038 decision 6).
     *
     * @param  Collection<int, RoomPlayer>  $rows  the room's players not removed, in order
     * @param  bool  $screen  the big screen's read, with the host's account: nobody's phone, so no player's own purchase (ADR 037 decision 13) and no seat's own tiles (ADR 038 decision 6)
     * @return array{live: bool, round: RoomRoundData|null, match: RoomMatchData|null, domino: RoomDominoData|null}
     */
    abstract public function part(Room $room, RoomGame $game, Collection $rows, ?RoomPlayer $mine, int $nowMs, bool $screen = false): array;

    /**
     * An act of a seat, in a room the caller holds locked, of the running
     * game: `accepted` when the caller should store it as the ply it
     * returns, or `late`.
     *
     * @return array{0: RoomActStatus, 1: int|null} the status and the ply to store it at
     *
     * @throws AuthorizationException when the player holds no seat
     * @throws ValidationException when a move is no legal move
     */
    abstract public function act(RoomGame $game, RoomPlayer $row, RoomActData $act, int $nowMs): array;

    /** A presence that changed in a room the caller holds locked, while `$game` runs. */
    abstract public function whenPresenceChanges(Room $locked, RoomGame $game, RoomPlayer $row, int $nowMs): void;

    /**
     * A turn the server plays (ADR 036 decisions 8–9, ADR 038 decision 28):
     * when the game is live at `$nowMs` and the server is to play this turn
     * — the computer's seat, or in a domino game a seat whose player is out
     * of time, away or gone —, the ply its act answers and the moment from
     * which it may be stored; null otherwise — a race, a match of two
     * players, a game that is over. `least_ms`, when given, is the least
     * time from the start of the search to the store, so the moment a play
     * is stored never tells how long its search took (ADR 038 decision 7).
     *
     * @return array{ply: int, due_ms: int, least_ms?: int}|null
     */
    abstract public function computerTurn(RoomGame $game, int $nowMs): ?array;

    /**
     * A turn the server plays: the act to store for it in the game as it
     * stands at `$nowMs` — its kind and its `path`, in the game's own
     * notation —, or null when it has none to make.
     *
     * @return array{kind: RoomActKind, path: list<string>|null}|null
     */
    abstract public function computerChoice(RoomGame $game, int $nowMs): ?array;

    /**
     * What a list of games — «Keçmiş oyunlar» (`RoomList::past()`), a
     * closed room's results (`RoomResults::games()`) — says of a game beyond
     * its mechanic and level: a race its number of rounds when `$rounds`, a
     * board game its clock and the computer's level, a domino game its
     * turn's time, its table, the computer's level and the balances it
     * ended with (ADR 038 decision 38).
     *
     * @return array{rounds?: int, clock?: array{base_ms: int, increment_ms: int}, turn_ms?: int, table?: string, computer?: int|null, balances?: list<int>}
     */
    abstract public function listed(RoomGame $game, int $nowMs, bool $rounds): array;

    /** A read: the moment from which a page's read at `$nowMs` counts, or null when it changes nothing. */
    abstract public function readCountsFrom(RoomGame $game, int $nowMs): ?int;
}
