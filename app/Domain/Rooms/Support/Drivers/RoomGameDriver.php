<?php

namespace App\Domain\Rooms\Support\Drivers;

use App\Domain\Content\Enums\RoomGameKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Data\RoomActData;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Data\RoomMatchData;
use App\Domain\Rooms\Data\RoomRoundData;
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
 * timed rounds (the code a room had before D3, moved), or the
 * `BoardDriver`, two seats taking turns on one board. Each concern is one
 * method: the choice's rules, the start, whether the game is over, what
 * each player earned and what its taunts cost, who may taunt whom and the
 * balance, the state's part, an act, a presence that changed and a read.
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
     * whether it is live, and a live race's round or a board game's match.
     *
     * @param  Collection<int, RoomPlayer>  $rows  the room's players not removed, in order
     * @return array{live: bool, round: RoomRoundData|null, match: RoomMatchData|null}
     */
    abstract public function part(Room $room, RoomGame $game, Collection $rows, ?RoomPlayer $mine, int $nowMs): array;

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

    /** A read: the moment from which a page's read at `$nowMs` counts, or null when it changes nothing. */
    abstract public function readCountsFrom(RoomGame $game, int $nowMs): ?int;
}
