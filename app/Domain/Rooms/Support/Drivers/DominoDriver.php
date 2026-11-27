<?php

namespace App\Domain\Rooms\Support\Drivers;

use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Actions\PlayComputerMove;
use App\Domain\Rooms\Data\RoomActData;
use App\Domain\Rooms\Data\RoomDominoData;
use App\Domain\Rooms\Data\RoomDominoHandData;
use App\Domain\Rooms\Data\RoomDominoLastData;
use App\Domain\Rooms\Data\RoomDominoMineData;
use App\Domain\Rooms\Data\RoomDominoResultData;
use App\Domain\Rooms\Data\RoomDominoSeatData;
use App\Domain\Rooms\Data\RoomDominoSheetData;
use App\Domain\Rooms\Data\RoomDominoSideData;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Data\RoomSpentData;
use App\Domain\Rooms\Enums\RoomActKind;
use App\Domain\Rooms\Enums\RoomActStatus;
use App\Domain\Rooms\Enums\RoomComputerLevel;
use App\Domain\Rooms\Enums\RoomDominoPhase;
use App\Domain\Rooms\Enums\RoomSeatStanding;
use App\Domain\Rooms\Enums\RoomTable;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAct;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\Domino\Domino101;
use App\Domain\Rooms\Support\Domino\DominoAct;
use App\Domain\Rooms\Support\Domino\DominoDeal;
use App\Domain\Rooms\Support\Domino\DominoEngine;
use App\Domain\Rooms\Support\Domino\DominoGame;
use App\Domain\Rooms\Support\Domino\DominoTile;
use App\Domain\Rooms\Support\Domino\DominoVariant;
use App\Domain\Rooms\Support\RoomPointsRule;
use App\Domain\Rooms\Support\RoomRanking;
use App\Domain\Rooms\Support\RoomSeats;
use App\Domain\Rooms\Support\RoomSeed;
use App\Domain\Rooms\Support\RoomTableSeats;
use App\Domain\Rooms\Support\RoomTauntCost;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;
use WeakMap;

/**
 * A domino game (docs/architecture.md §12 "Domino", ADR 038): two to four
 * seats, each holding tiles nobody else sees, play hands to a target while
 * the rest of the room watches.
 *
 * - The choice is a level — a turn's time —, a table, its seats and the
 *   computer's level (`RoomTableSeats`); the start seats the table, copies
 *   the turn's time and the pause between hands, draws the seed, and
 *   refuses a table nobody sits at. The seats never change during a game,
 *   nor at «Yenidən oyna».
 * - Everything else — every hand's deal, line and count, each seat's
 *   tiles, the seat to move and its flag, the account, the outcome — is
 *   replayed from the game's start facts, its acts and the seed
 *   (`DominoGame`), never stored; a replay is remembered within one request
 *   at most, for the same game, moment, presence and acts. Which rules the
 *   game plays by is asked here alone (`variantOf()`: «101»).
 * - It has one close, its end: each player of the side that reached the
 *   target earns `MATCH_WIN`, `DRY_WIN` after a dry win, present or not; a
 *   game stopped by the host or a close gives nothing.
 * - A seat's player taunts a player of another side, from the start of the
 *   countdown to the end, paying from what the game would give: `MATCH_WIN`.
 * - Its part of the state is built for its viewer: a seat's own tiles reach
 *   that seat's player alone — never a watcher, a host who does not sit, or
 *   the screen —, every other seat's tiles are a count, the boneyard is a
 *   count, and a tile is named only on the table, in the latest play, and
 *   on a hand's sheet once the hand is over (decisions 5–8).
 * - An act of a seat: `play` and `yield` from the seat to move, in its
 *   turn, at the game's ply and before its flag — `late` otherwise —, a
 *   play the judge does not take refused with one answer whatever was
 *   wrong with it (422, `rooms.domino.illegal`); `back` from a seat that is
 *   away.
 * - A presence that changed never ends the game: a seat whose player left
 *   is played for by the server, and a player who comes back while away is
 *   back (a `back` written for it).
 * - The server plays a turn (`computerTurn()`, `computerChoice()`) for a
 *   seat without a player, a seat whose player left or was removed and an
 *   away seat `elifba.rooms.domino.think_ms` after the turn began, and for
 *   any other seat at its flag: a play `DominoEngine` chooses from that
 *   seat's `DominoView` alone, at the game's level for a computer's seat
 *   and at «Orta» for a player's.
 */
final class DominoDriver extends RoomGameDriver
{
    /** The level a seat played for its player is played at (ADR 038 decision 55). */
    public const RoomComputerLevel STAND_IN = RoomComputerLevel::Medium;

    /** @var WeakMap<RoomGame, array{key: string, game: DominoGame}>|null */
    private ?WeakMap $replays = null;

    /** Which rules a domino game plays by (ADR 038 decision 2): today always «101». */
    public static function variantOf(RoomGame $game): DominoVariant
    {
        return new Domino101;
    }

    public function choiceRules(bool $kept = false): array
    {
        // Absent, each is the room's stand (`RoomGameSetupData::validate()`, `ChooseRoomGame`); a table null leaves it to the number of players.
        return [
            'table' => ['sometimes', 'nullable', 'string', Rule::enum(RoomTable::class)],
            'seats' => ['sometimes', 'array', 'list', 'min:2', 'max:4'],
            'seats.*' => ['present', 'nullable', 'string', 'ulid'],
            'computer' => ['nullable', 'integer', Rule::enum(RoomComputerLevel::class)],
        ];
    }

    /**
     * The room's seated choice (`Room::setup()`): its table, its seats and
     * the computer's level are copied to the game's row with the level's
     * turn and the pause between hands, and a fresh seed is drawn.
     */
    public function start(Room $locked, RoomGame $game, RoomGameSetupData $setup): void
    {
        $seats = $setup->seats ?? [];

        if ($setup->table === null || count($seats) !== $setup->table->seats() || array_filter($seats, static fn (?string $seat): bool => $seat !== null) === []) {
            throw ValidationException::withMessages(['setup' => __('rooms.errors.needs_one')]);
        }

        $game->seed = RoomSeed::fresh();
        $game->reveal_ms = Config::integer('elifba.rooms.domino.sheet_ms');
        $game->setDominoTable($setup->table);
        $game->seats = $seats;
        $game->computer = in_array(null, $seats, true) ? ($setup->computer ?? RoomSeats::ALONE) : null;
        $game->turn_ms = (int) app(ContentRepository::class)->roomLevel($setup->mechanic, $setup->difficulty)->turnMs;
        $game->save();
    }

    public function isOver(RoomGame $game, int $nowMs): bool
    {
        return $this->replay($game, $nowMs)->phase === RoomDominoPhase::Over;
    }

    public function earned(RoomGame $game, int $nowMs): array
    {
        return self::outcomePoints($game, $this->replay($game, $nowMs));
    }

    /** Every taunt of the game counts at its one close, its end. */
    public function tauntCounts(RoomGame $game, int $nowMs): Closure
    {
        $over = $this->isOver($game, $nowMs);

        return static fn (int $sentMs): bool => $over;
    }

    /** The live game, its countdown and its pauses included (ADR 038 decision 32). */
    public function tauntsOpen(RoomGame $game, int $nowMs): bool
    {
        return ! $this->isOver($game, $nowMs);
    }

    /** A seat's player, while a player sits on another side. */
    public function mayThrow(RoomGame $game, RoomPlayer $thrower): bool
    {
        $seat = self::seatOf($game, $thrower->id);
        $table = self::table($game);

        if ($seat === null) {
            return false;
        }

        foreach (self::seats($game) as $other => $player) {
            if ($player !== null && $table->sideOf($other) !== $table->sideOf($seat)) {
                return true;
            }
        }

        return false;
    }

    /** At a player who holds a seat of another side: never a partner, a computer or a watcher. */
    public function mayAim(RoomGame $game, RoomPlayer $thrower, string $targetId): bool
    {
        $seat = self::seatOf($game, $thrower->id);
        $target = self::seatOf($game, $targetId);
        $table = self::table($game);

        return $seat !== null && $target !== null && $table->sideOf($seat) !== $table->sideOf($target);
    }

    /** What the game can pay a seat's player: a win; a watcher nothing. */
    public function tauntBudget(RoomGame $game, RoomPlayer $row, int $nowMs): int
    {
        return self::seatOf($game, $row->id) === null ? 0 : RoomPointsRule::MATCH_WIN;
    }

    /**
     * The game as its viewer may see it — in a room that is not open, ended
     * at the moment read, as its close will end it. The viewer's own tiles
     * only for the player who holds a seat, never the screen's read.
     */
    public function part(Room $room, RoomGame $game, Collection $rows, ?RoomPlayer $mine, int $nowMs, bool $screen = false): array
    {
        $domino = $this->replay($game, $nowMs, $room->isOpen() ? null : $nowMs);
        $table = $domino->table;
        $seats = self::seats($game);
        $hand = $domino->hand();
        $own = $screen || $mine === null ? null : self::seatOf($game, $mine->id);
        $standing = $domino->ledger->standing();
        $places = array_column($standing, 'place', 'side');
        $over = $domino->phase === RoomDominoPhase::Over;

        return ['live' => ! $over, 'round' => null, 'match' => null, 'domino' => new RoomDominoData(
            phase: $domino->phase,
            starts_ms: $domino->startsMs,
            seats: array_map(static fn (int $seat): RoomDominoSeatData => new RoomDominoSeatData(
                $seat,
                $seats[$seat],
                $table->sideOf($seat),
                $hand === null ? 0 : count($hand->tiles[$seat]),
                $domino->standing($seat),
            ), array_keys($seats)),
            sides: array_map(static function (array $place) use ($domino, $table): RoomDominoSideData {
                $side = $domino->ledger->sides[$place['side']];

                return new RoomDominoSideData($place['side'], $table->seatsOf($place['side']), $side['balance'], $side['open'], $side['waiting'], $side['tries'], $place['place']);
            }, $standing),
            bank: $domino->ledger->bank,
            hand: $hand === null ? null : new RoomDominoHandData($hand->number, $hand->opensMs, $hand->opener, $hand->why),
            ply: $domino->ply,
            turn: $domino->turn(),
            since_ms: $domino->sinceMs(),
            flag_ms: $domino->flagMs(),
            line: $hand === null ? [] : array_map(static fn (array $placed): array => ['tile' => $placed['tile']->name(), 'left' => $placed['left'], 'right' => $placed['right']], $hand->line),
            first: $hand?->first,
            ends: $hand === null ? [] : array_map(static fn (string $end, int $number): array => ['end' => $end, 'number' => $number], array_keys($hand->ends()), array_values($hand->ends())),
            boneyard: [
                'tiles' => $hand === null ? 0 : count($hand->boneyard),
                'reserve' => $domino->reserve(),
                'closed' => $hand !== null && $hand->closed(),
            ],
            last: $hand?->last === null ? null : new RoomDominoLastData(
                $hand->last['seat'],
                $hand->last['kind'],
                $hand->last['tile']?->name(),
                $hand->last['end'],
                $hand->last['stoodIn'],
                $hand->last['then'],
            ),
            mine: $own === null ? null : self::mine($domino, $own),
            sheet: self::sheet($domino),
            result: $over ? $this->result($game, $domino, $nowMs, $places) : null,
        )];
    }

    /**
     * In the order of §12 "An act", step 3: a seat only (403 otherwise); a
     * `play` or a `yield` in the phase `play`, of the seat to move, at the
     * game's ply and before its flag — late otherwise —; a play the judge
     * takes (422 otherwise, one answer for every refusal); a `yield` the
     * seat may make; a `back` from a seat that is away, at the ply the game
     * stands at.
     */
    public function act(RoomGame $game, RoomPlayer $row, RoomActData $act, int $nowMs): array
    {
        $seat = self::seatOf($game, $row->id) ?? throw new AuthorizationException;
        $domino = $this->replay($game, $nowMs);
        $late = [RoomActStatus::Late, null];

        if ($domino->phase === RoomDominoPhase::Over) {
            return $late;
        }

        if ($act->kind === RoomActKind::Back) {
            return $domino->away($seat) ? [RoomActStatus::Accepted, $domino->ply] : $late;
        }

        $flag = $domino->flagMs();
        $mine = $domino->turn() === $seat && $act->ply === $domino->ply && $flag !== null && $nowMs < $flag;

        return match ($act->kind) {
            RoomActKind::Play => $mine ? (self::takes($domino, $act->path ?? [], $nowMs) ? [RoomActStatus::Accepted, $domino->ply] : throw ValidationException::withMessages(['path' => __('rooms.domino.illegal')])) : $late,
            RoomActKind::Yield => $mine && $domino->mayGive() ? [RoomActStatus::Accepted, $domino->ply] : $late,
            default => $late,
        };
    }

    /**
     * A player who comes back to the room while its seat is away is back
     * (ADR 038 decision 26): the server writes its `back` at the ply the
     * game stands at. A leave changes nothing written: the seat is played
     * for from then on, as the replay reads its presence.
     */
    public function whenPresenceChanges(Room $locked, RoomGame $game, RoomPlayer $row, int $nowMs): void
    {
        $seat = self::seatOf($game, $row->id);

        if ($seat === null || ! $row->isPresent()) {
            return;
        }

        $domino = $this->replay($game, $nowMs);

        if ($domino->phase === RoomDominoPhase::Over || ! $domino->away($seat)) {
            return;
        }

        $back = new RoomAct;
        $back->room_game_id = $game->id;
        $back->room_player_id = $row->id;
        $back->kind = RoomActKind::Back;
        $back->ply = $domino->ply;
        $back->acted_ms = $nowMs;
        $back->save();

        $game->unsetRelation('acts');
    }

    public function readCountsFrom(RoomGame $game, int $nowMs): ?int
    {
        return null;
    }

    /**
     * The server is to play the seat to move's turn (ADR 038 decision 28):
     * a seat without a player, a seat whose player left and an away seat
     * `think_ms` after its turn began, any other seat at its flag.
     */
    public function computerTurn(RoomGame $game, int $nowMs): ?array
    {
        $domino = $this->replay($game, $this->turnMoment($game, $nowMs));
        $thinkMs = Config::integer('elifba.rooms.domino.think_ms');
        $due = $domino->serverPlaysFrom($thinkMs);

        // Stored no sooner than think_ms after the search began, so its time never tells what the seat held (ADR 038 decision 7).
        return $due === null ? null : ['ply' => $domino->ply, 'due_ms' => $due, 'least_ms' => $thinkMs];
    }

    /**
     * A play `DominoEngine` chooses from the seat to move's own view alone —
     * at the game's level for a computer's seat, at «Orta» for a player's —
     * with the game's random source for that ply, so a play chosen twice is
     * the same play. It never gives the opening and never comes back.
     */
    public function computerChoice(RoomGame $game, int $nowMs): ?array
    {
        $domino = $this->replay($game, $this->turnMoment($game, $nowMs));
        $seat = $domino->turn();

        if ($seat === null) {
            return null;
        }

        $level = $domino->standing($seat) === RoomSeatStanding::Computer ? ($game->computer ?? RoomSeats::ALONE) : self::STAND_IN;
        $choice = DominoEngine::choose($domino->view($seat), $level, RoomSeed::computer($game->id, $domino->ply));

        return ['kind' => RoomActKind::Play, 'path' => $choice['end'] === null ? [$choice['tile']->name()] : [$choice['tile']->name(), $choice['end']]];
    }

    /** The turn's time, the table, the computer's level and the balances the game ended with, by side. */
    public function listed(RoomGame $game, int $nowMs, bool $rounds): array
    {
        $domino = $this->replay($game, $nowMs);

        return [
            'turn_ms' => (int) $game->turn_ms,
            'table' => self::table($game)->value,
            'computer' => $game->computer?->value,
            'balances' => array_map(static fn (array $side): int => $side['balance'], $domino->ledger->sides),
        ];
    }

    /**
     * The game replayed from its acts at `$nowMs` (`DominoGame`), with the
     * host's end — or `$endedMs`, an end the reader knows of before it is
     * written — and each seat's presence now, remembered for the same game,
     * moment, presence and acts.
     */
    private function replay(RoomGame $game, int $nowMs, ?int $endedMs = null): DominoGame
    {
        $endedMs = $game->ended_ms ?? $endedMs;
        $seats = self::seats($game);
        $named = array_values(array_filter($seats, static fn (?string $seat): bool => $seat !== null));
        $present = $named === [] ? [] : RoomPlayer::query()->whereKey($named)->get()->filter(static fn (RoomPlayer $row): bool => $row->isPresent())->modelKeys();
        $presence = array_map(static fn (?string $player): RoomSeatStanding => match (true) {
            $player === null => RoomSeatStanding::Computer,
            in_array($player, $present, true) => RoomSeatStanding::Playing,
            default => RoomSeatStanding::Left,
        }, $seats);
        $acts = $game->acts;
        $key = $nowMs.'|'.$endedMs.'|'.$acts->count().'|'.($acts->last()->id ?? 0).'|'.implode(',', array_map(static fn (RoomSeatStanding $standing): string => $standing->value, $presence));
        $this->replays ??= new WeakMap;
        $known = $this->replays[$game] ?? null;

        if ($known !== null && $known['key'] === $key) {
            return $known['game'];
        }

        $table = self::table($game);
        $domino = DominoGame::replay(
            self::variantOf($game),
            $table,
            DominoDeal::source($table, $game->seed),
            $presence,
            $game->started_ms,
            (int) $game->turn_ms,
            $game->reveal_ms,
            max(1, Config::integer('elifba.rooms.domino.away_after')),
            $endedMs,
            array_values(array_filter($acts->map(static fn (RoomAct $act): ?DominoAct => match ($act->kind) {
                RoomActKind::Play => DominoAct::play($act->ply, $act->path ?? [], $act->acted_ms, $act->room_player_id === null),
                RoomActKind::Yield => DominoAct::yield($act->ply, $act->acted_ms),
                RoomActKind::Back => ($seat = self::seatOf($game, $act->room_player_id)) === null ? null : DominoAct::back($seat, $act->ply, $act->acted_ms),
                default => null,
            })->all())),
            $nowMs,
        );
        $this->replays[$game] = ['key' => $key, 'game' => $domino];

        return $domino;
    }

    /**
     * The moment a turn the server may play is asked at: now — or, in the
     * countdown or a pause between hands that ends within
     * `PlayComputerMove::PATIENCE_MS`, the opening of the hand, so the read
     * a page sends just before it arms the opener's play (ADR 038 decision
     * 83). The play is stored, and checked again, only once that moment came.
     */
    private function turnMoment(RoomGame $game, int $nowMs): int
    {
        $domino = $this->replay($game, $nowMs);
        $opens = match ($domino->phase) {
            RoomDominoPhase::Countdown => $domino->startsMs,
            RoomDominoPhase::Sheet => $domino->untilMs(),
            default => null,
        };

        return $opens !== null && $opens - $nowMs <= PlayComputerMove::PATIENCE_MS ? $opens : $nowMs;
    }

    /**
     * Whether the judge takes a play of the seat to move as a device posted
     * it: `[tile]` for a hand's first tile, else `[tile, end]`, a tile the
     * seat holds that fits that end.
     *
     * @param  list<string>  $path
     */
    private static function takes(DominoGame $domino, array $path, int $nowMs): bool
    {
        $hand = $domino->hand();
        $tile = DominoTile::tryOf($path[0] ?? '');
        $end = $path[1] ?? null;

        if ($hand === null || $tile === null || count($path) !== ($hand->line === [] ? 1 : 2) || ($end !== null && ! DominoTile::isEnd($end))) {
            return false;
        }

        return $hand->play($tile, $end, $nowMs, false) !== null;
    }

    /** A seat's own part: its tiles, what it drew, and its plays in its own turn. */
    private static function mine(DominoGame $domino, int $seat): RoomDominoMineData
    {
        $hand = $domino->hand();
        $turn = $domino->turn() === $seat;
        $legal = $turn ? $domino->legal() : [];

        return new RoomDominoMineData(
            seat: $seat,
            tiles: $hand === null ? [] : DominoTile::names($hand->tiles[$seat]),
            drawn: $hand === null ? [] : DominoTile::names($hand->drawn[$seat] ?? []),
            legal: $legal === [] ? null : array_map(static fn (array $play): array => ['tile' => $play['tile']->name(), 'ends' => $play['ends']], $legal),
            may_yield: $turn && $domino->mayGive(),
            away: $domino->away($seat),
        );
    }

    /** The sheet of the hand that just ended, in the phases `sheet` and `over`. */
    private static function sheet(DominoGame $domino): ?RoomDominoSheetData
    {
        $hand = $domino->sheet();

        if ($hand === null) {
            return null;
        }

        $count = $hand->count ?? throw new LogicException('A hand that is over has its count.');
        $next = $domino->next();

        return new RoomDominoSheetData(
            hand: $hand->number,
            ending: $hand->ending ?? throw new LogicException('A hand that is over has its ending.'),
            by: $hand->by ?? throw new LogicException('A hand that is over has the seat that ended it.'),
            winner: $count['winner'],
            left: array_map(static fn (int $seat): array => ['seat' => $seat, 'tiles' => DominoTile::names($hand->tiles[$seat]), 'pips' => $count['left'][$seat]], array_keys($hand->tiles)),
            counts: array_map(static fn (int $side, int $pips): array => ['side' => $side, 'pips' => $pips], array_keys($count['counts']), $count['counts']),
            gain: $count['gain'],
            lines: $domino->lines[$hand->number] ?? [],
            next: $next === null ? null : ['seat' => $next[0], 'why' => $next[1]],
            until_ms: $domino->untilMs(),
        );
    }

    /**
     * A game's result: how it ended, each seat's row — its player, its
     * side, the points the game gave it less its counted taunts, never
     * below 0, and its side's place, none for a game stopped — the winning
     * side first, then by place and seat; and those taunts.
     *
     * @param  array<int, int>  $places  each side's dense place by balance
     */
    private function result(RoomGame $game, DominoGame $domino, int $nowMs, array $places): RoomDominoResultData
    {
        $spent = RoomTauntCost::spent($game, $nowMs);
        $points = RoomRanking::net(self::outcomePoints($game, $domino), $spent);
        $table = $domino->table;
        $won = $domino->winner !== null;
        $rows = array_map(static fn (?string $player, int $seat): array => [
            'player_id' => $player,
            'seat' => $seat,
            'side' => $table->sideOf($seat),
            'points' => $player === null ? 0 : ($points[$player] ?? 0),
            'place' => $won ? $places[$table->sideOf($seat)] : null,
        ], self::seats($game), array_keys(self::seats($game)));

        usort($rows, static fn (array $a, array $b): int => [$a['side'] !== $domino->winner, $a['place'] ?? 0, $a['seat']] <=> [$b['side'] !== $domino->winner, $b['place'] ?? 0, $b['seat']]);

        return new RoomDominoResultData(
            $won ? RoomDominoResultData::TARGET : RoomDominoResultData::STOPPED,
            $domino->winner,
            $domino->dry(),
            $rows,
            array_values(array_map(
                static fn (string $player): RoomSpentData => new RoomSpentData($player, $spent[$player]),
                array_filter(self::seats($game), static fn (?string $player): bool => $player !== null && ($spent[$player] ?? 0) > 0),
            )),
        );
    }

    /**
     * What the game gives each player (ADR 038 decisions 30–31): `MATCH_WIN`
     * to each player of the side that reached the target, `DRY_WIN` after a
     * dry win; nothing to anyone else, to a computer's seat or in a game
     * without a result.
     *
     * @return array<string, int>
     */
    private static function outcomePoints(RoomGame $game, DominoGame $domino): array
    {
        if ($domino->phase !== RoomDominoPhase::Over || $domino->winner === null) {
            return [];
        }

        $table = $domino->table;
        $points = [];

        foreach (self::seats($game) as $seat => $player) {
            if ($player !== null && $table->sideOf($seat) === $domino->winner) {
                $points[$player] = $domino->dry() ? RoomPointsRule::DRY_WIN : RoomPointsRule::MATCH_WIN;
            }
        }

        return $points;
    }

    /**
     * The game's seats in the order of the turns, each a `room_players.id`
     * or null — the computer's.
     *
     * @return list<string|null>
     */
    private static function seats(RoomGame $game): array
    {
        return $game->seats ?? [];
    }

    private static function table(RoomGame $game): RoomTable
    {
        return $game->dominoTable() ?? throw new LogicException('A domino game has its table.');
    }

    /** The seat a row holds in the game, or null for a row with no seat. */
    private static function seatOf(RoomGame $game, ?string $playerId): ?int
    {
        $seat = $playerId === null ? false : array_search($playerId, self::seats($game), true);

        return $seat === false ? null : $seat;
    }
}
