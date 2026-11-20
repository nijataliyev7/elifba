<?php

namespace App\Domain\Rooms\Support\Drivers;

use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Data\RoomActData;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Data\RoomLastMoveData;
use App\Domain\Rooms\Data\RoomLegalData;
use App\Domain\Rooms\Data\RoomMatchData;
use App\Domain\Rooms\Data\RoomMatchResultData;
use App\Domain\Rooms\Data\RoomMatchStandingData;
use App\Domain\Rooms\Data\RoomPieceData;
use App\Domain\Rooms\Data\RoomSideData;
use App\Domain\Rooms\Data\RoomSpentData;
use App\Domain\Rooms\Enums\RoomActKind;
use App\Domain\Rooms\Enums\RoomActStatus;
use App\Domain\Rooms\Enums\RoomMatchColor;
use App\Domain\Rooms\Enums\RoomMatchOutcome;
use App\Domain\Rooms\Enums\RoomMatchPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAct;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomTaunt;
use App\Domain\Rooms\Support\Draughts\DraughtsAct;
use App\Domain\Rooms\Support\Draughts\DraughtsMatch;
use App\Domain\Rooms\Support\Draughts\DraughtsRules;
use App\Domain\Rooms\Support\RoomPointsRule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use LogicException;
use WeakMap;

/**
 * A board game (docs/architecture.md §12 "Board games", ADR 034): two
 * seats take turns on one board while the rest of the room watches.
 *
 * - The choice is a level — its clock — and two seats (`white`, `black`:
 *   two different present players, which `ChooseRoomGame` checks).
 * - The start seats the two players and copies the level's clock to the
 *   game's row, so a running match keeps its clock when the content
 *   changes, and writes the room's seats the other way round for the next
 *   game; it refuses a choice with fewer than two seats.
 * - Whether it is over, its outcome, the position, the clocks and the
 *   standing offer are replayed from the game's acts (`DraughtsMatch`),
 *   never stored; a replay is remembered within one request at most, for
 *   the same game, moment and acts.
 * - It has one close, its end: then a win gives `MATCH_WIN`, a draw
 *   `MATCH_DRAW` to each seat, a loss and a game without a result nothing,
 *   and every taunt of the game counts.
 * - A seat taunts the other seat alone, from the start of the countdown
 *   to the end; its balance is what the match can still pay, `MATCH_WIN`
 *   less `TAUNT_COST` for each taunt it threw — at most three. A watcher
 *   throws nothing and is thrown nothing.
 * - Its part of the state is the match, live or over.
 * - An act of a seat is judged against the match as it stands.
 * - A seat of a live match that is no longer present gets a `leave` act
 *   and has lost; a page's read changes nothing, since no round waits.
 */
final class BoardDriver extends RoomGameDriver
{
    /** @var WeakMap<RoomGame, array{key: string, match: DraughtsMatch}>|null */
    private ?WeakMap $replays = null;

    public function choiceRules(): array
    {
        return [
            'white' => ['required', 'string', 'ulid'],
            'black' => ['required', 'string', 'ulid', 'different:white'],
        ];
    }

    public function start(Room $locked, RoomGame $game, RoomGameSetupData $setup): void
    {
        if ($setup->white === null || $setup->black === null) {
            throw ValidationException::withMessages(['setup' => __('rooms.errors.needs_two')]);
        }

        $level = app(ContentRepository::class)->roomLevel($setup->mechanic, $setup->difficulty);

        $game->seed = 0;
        $game->reveal_ms = 0;
        $game->white_player_id = $setup->white;
        $game->black_player_id = $setup->black;
        $game->clock_ms = (int) $level->clockMs;
        $game->increment_ms = (int) $level->incrementMs;
        $game->save();

        // The colours change with every game: «Yenidən oyna» seats the same two the other way round.
        $locked->setup_white_id = $setup->black;
        $locked->setup_black_id = $setup->white;
    }

    public function isOver(RoomGame $game, int $nowMs): bool
    {
        return $this->replay($game, $nowMs)->phase === RoomMatchPhase::Over;
    }

    public function earned(RoomGame $game, int $nowMs): array
    {
        $match = $this->replay($game, $nowMs);

        return $match->phase === RoomMatchPhase::Over ? self::outcomePoints($game, $match) : [];
    }

    public function spent(RoomGame $game, int $nowMs, iterable $taunts): array
    {
        $spent = [];

        if (! $this->isOver($game, $nowMs)) {
            return $spent;
        }

        foreach ($taunts as $taunt) {
            $spent[$taunt->room_player_id] = ($spent[$taunt->room_player_id] ?? 0) + 1;
        }

        return $spent;
    }

    public function tauntsOpen(RoomGame $game, int $nowMs): bool
    {
        return ! $this->isOver($game, $nowMs);
    }

    public function mayThrow(RoomGame $game, RoomPlayer $thrower): bool
    {
        return self::sideOf($game, $thrower->id) !== null;
    }

    public function mayAim(RoomGame $game, RoomPlayer $thrower, string $targetId): bool
    {
        $side = self::sideOf($game, $thrower->id);

        return $side !== null && self::sideOf($game, $targetId) === $side->other();
    }

    public function balance(RoomGame $game, RoomPlayer $row, int $nowMs): int
    {
        if (self::sideOf($game, $row->id) === null) {
            return 0;
        }

        $thrown = RoomTaunt::query()
            ->where('room_game_id', $game->id)
            ->where('room_player_id', $row->id)
            ->count();

        return max(0, RoomPointsRule::MATCH_WIN - RoomPointsRule::TAUNT_COST * $thrown);
    }

    /**
     * The match as the viewer sees it — in a room that is not open, ended
     * at the moment read, as its close will end it.
     */
    public function part(Room $room, RoomGame $game, Collection $rows, ?RoomPlayer $mine, int $nowMs): array
    {
        $match = $this->replay($game, $nowMs, $room->isOpen() ? null : $nowMs);
        $over = $match->phase === RoomMatchPhase::Over;
        $side = $mine === null ? null : self::sideOf($game, $mine->id);
        $legal = $side !== null && $side === $match->turn() ? $match->legal() : [];
        $position = $match->position;

        return ['live' => ! $over, 'round' => null, 'match' => new RoomMatchData(
            phase: $match->phase,
            starts_ms: $match->startsMs,
            sides: array_map(static fn (RoomMatchColor $color): RoomSideData => new RoomSideData(
                $color,
                (string) self::seatOf($game, $color),
                $match->left($color),
                $position->count($color),
                $position->kings($color),
            ), [RoomMatchColor::White, RoomMatchColor::Black]),
            turn: $match->turn(),
            ply: $match->ply(),
            since_ms: $match->sinceMs(),
            flag_ms: $match->flagMs(),
            position: array_map(
                static fn (array $piece): RoomPieceData => new RoomPieceData($piece['square'], $piece['color'], $piece['king']),
                $position->pieces(),
            ),
            last: $match->last === null ? null : RoomLastMoveData::of($match->last),
            moves: $match->moves,
            legal: $legal === [] ? null : RoomLegalData::of($legal),
            offer: $match->offer,
            can_offer: $side !== null && $match->canOffer($side),
            result: $over ? $this->result($game, $match, $nowMs) : null,
        )];
    }

    /**
     * In the order of §12 "An act", step 3: a seat only (403 otherwise); a
     * move in play, of the side to move, at the match's ply — late
     * otherwise — and a legal one (422 otherwise); an offer the seat may
     * make now; an answer to the other seat's standing offer at this ply; a
     * resignation while the match is live, at the ply it stands at.
     */
    public function act(RoomGame $game, RoomPlayer $row, RoomActData $act, int $nowMs): array
    {
        $side = self::sideOf($game, $row->id) ?? throw new AuthorizationException;
        $match = $this->replay($game, $nowMs);
        $late = [RoomActStatus::Late, null];

        if ($match->phase === RoomMatchPhase::Over) {
            return $late;
        }

        $playing = $match->phase === RoomMatchPhase::Play && $act->ply === $match->ply();
        $mine = $playing && $match->turn() === $side;

        if ($act->kind === RoomActKind::Move && $mine && DraughtsRules::among($match->legal(), $act->path ?? []) === null) {
            throw ValidationException::withMessages(['path' => __('rooms.match.illegal')]);
        }

        $taken = match ($act->kind) {
            RoomActKind::Move => $mine,
            RoomActKind::Offer => $playing && $match->canOffer($side),
            RoomActKind::Accept, RoomActKind::Decline => $playing && $match->offer === $side->other(),
            RoomActKind::Resign => true,
            RoomActKind::Leave => false,
        };

        return $taken ? [RoomActStatus::Accepted, $match->ply()] : $late;
    }

    /**
     * A seat of the running match that is no longer present has lost: the
     * server writes its `leave` at the ply the match stands at (ADR 034
     * decision 13). A return, or anyone else's presence, changes nothing.
     */
    public function whenPresenceChanges(Room $locked, RoomGame $game, RoomPlayer $row, int $nowMs): void
    {
        if ($row->isPresent() || self::sideOf($game, $row->id) === null) {
            return;
        }

        $leave = new RoomAct;
        $leave->room_game_id = $game->id;
        $leave->room_player_id = $row->id;
        $leave->kind = RoomActKind::Leave;
        $leave->ply = $this->replay($game, $nowMs)->ply();
        $leave->acted_ms = $nowMs;
        $leave->save();

        $game->unsetRelation('acts');
    }

    public function readCountsFrom(RoomGame $game, int $nowMs): ?int
    {
        return null;
    }

    /**
     * The game replayed from its acts at `$nowMs` (`DraughtsMatch`), with
     * the host's end — or `$endedMs`, an end the reader knows of before it
     * is written — and remembered for the same game, moment and acts.
     */
    private function replay(RoomGame $game, int $nowMs, ?int $endedMs = null): DraughtsMatch
    {
        $endedMs = $game->ended_ms ?? $endedMs;
        $acts = $game->acts;
        $key = $nowMs.'|'.$endedMs.'|'.$acts->count().'|'.($acts->last()->id ?? 0);
        $this->replays ??= new WeakMap;
        $known = $this->replays[$game] ?? null;

        if ($known !== null && $known['key'] === $key) {
            return $known['match'];
        }

        $match = DraughtsMatch::replay(
            $game->started_ms,
            (int) $game->clock_ms,
            (int) $game->increment_ms,
            $endedMs,
            array_values($acts->map(static fn (RoomAct $act): DraughtsAct => new DraughtsAct(
                $act->kind,
                self::sideOf($game, $act->room_player_id) ?? RoomMatchColor::Black,
                $act->ply,
                $act->path,
                $act->acted_ms,
            ))->all()),
            $nowMs,
        );
        $this->replays[$game] = ['key' => $key, 'match' => $match];

        return $match;
    }

    /**
     * A match's result: the outcome and its reason, its two seats with the
     * points it gave each — less its counted taunts, never below 0 — in
     * the order of their places, and those taunts.
     */
    private function result(RoomGame $game, DraughtsMatch $match, int $nowMs): RoomMatchResultData
    {
        $outcome = $match->outcome ?? RoomMatchOutcome::None;
        $earned = self::outcomePoints($game, $match);
        $spent = $this->spent($game, $nowMs, RoomTaunt::query()->where('room_game_id', $game->id)->get(['room_player_id']));
        $white = (string) $game->white_player_id;
        $black = (string) $game->black_player_id;
        $row = static fn (string $seat, ?int $place): RoomMatchStandingData => new RoomMatchStandingData(
            $seat,
            max(0, ($earned[$seat] ?? 0) - RoomPointsRule::TAUNT_COST * ($spent[$seat] ?? 0)),
            $place,
        );

        return new RoomMatchResultData(
            $outcome,
            $match->ending ?? throw new LogicException('A match that is over has its ending.'),
            match ($outcome) {
                RoomMatchOutcome::White => [$row($white, 1), $row($black, 2)],
                RoomMatchOutcome::Black => [$row($black, 1), $row($white, 2)],
                RoomMatchOutcome::Draw => [$row($white, 1), $row($black, 1)],
                RoomMatchOutcome::None => [$row($white, null), $row($black, null)],
            },
            array_values(array_map(
                static fn (string $seat): RoomSpentData => new RoomSpentData($seat, $spent[$seat]),
                array_filter([$white, $black], static fn (string $seat): bool => ($spent[$seat] ?? 0) > 0),
            )),
        );
    }

    /**
     * What the outcome gives each seat (ADR 034 decision 21).
     *
     * @return array<string, int>
     */
    private static function outcomePoints(RoomGame $game, DraughtsMatch $match): array
    {
        $white = (string) $game->white_player_id;
        $black = (string) $game->black_player_id;

        return match ($match->outcome) {
            RoomMatchOutcome::White => [$white => RoomPointsRule::MATCH_WIN],
            RoomMatchOutcome::Black => [$black => RoomPointsRule::MATCH_WIN],
            RoomMatchOutcome::Draw => [$white => RoomPointsRule::MATCH_DRAW, $black => RoomPointsRule::MATCH_DRAW],
            RoomMatchOutcome::None, null => [],
        };
    }

    /** The side a row plays in the game, or null for a row with no seat. */
    private static function sideOf(RoomGame $game, string $playerId): ?RoomMatchColor
    {
        return match ($playerId) {
            $game->white_player_id => RoomMatchColor::White,
            $game->black_player_id => RoomMatchColor::Black,
            default => null,
        };
    }

    private static function seatOf(RoomGame $game, RoomMatchColor $color): ?string
    {
        return $color === RoomMatchColor::White ? $game->white_player_id : $game->black_player_id;
    }
}
