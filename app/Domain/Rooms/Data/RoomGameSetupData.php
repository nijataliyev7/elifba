<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomGameKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Enums\RoomSequenceRule;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Enums\RoomComputerLevel;
use App\Domain\Rooms\Enums\RoomTable;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Support\Drivers\RoomGameDriver;
use App\Domain\Rooms\Support\RoomPlayable;
use Closure;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The host's choice of a game (docs/architecture.md §12 "The race",
 * "Room games", "Games first", ADRs 023 and 026): a mechanic at a
 * difficulty — the pair is a level of `content/rooms/levels.json` — and how
 * many rounds, between `elifba.rooms.rounds.min` and `.max`. Only a level
 * whose mechanic `RoomPlayable` lists is accepted, so content may run ahead
 * of the pages. From G2 it is the room's choice (`Room::setup()`), which
 * `ChooseRoomGame` writes after `rules()` and `StartRoomGame` starts.
 *
 * From D3 (ADR 034 decisions 6–8) the choice has the shape of its game's
 * kind, which the constructor holds to: a race has its rounds and no
 * seats; a board game has no rounds — `rooms.setup_rounds` keeps its number
 * for the next race — and its two seats, the `room_players.id` of the
 * players of white and black, either null while the room has nobody to
 * seat there; the computer's level, when it plays the colour without a
 * player, is chosen or — in a room of fewer than two present players —
 * the room's own (`RoomSeats`, ADR 036 as amended 2026-11-22). What the
 * choice asks beyond the game and its level is its kind's
 * (`RoomGameDriver::choiceRules()`).
 *
 * From Y5 (ADR 038 decisions 11–14) a domino game's choice has no rounds
 * and no colours: its table (`two`, `three`, `four`, `pairs`), its seats —
 * a list as long as the table, each place a `room_players.id` or null —
 * and the computer's level. As posted, the table may be null (by the
 * number of present players) and the seats null (the room's stand); the
 * room's choice (`Room::setup()`) is seated by `RoomTableSeats`: its table,
 * every seat a present player or null — the computer's — and the level
 * only while a seat is the computer's.
 */
final readonly class RoomGameSetupData
{
    /**
     * @param  int|null  $rounds  a race's; null for a board game
     * @param  string|null  $white  a board game's seat of white; null for a race, or while nobody is seated
     * @param  string|null  $black  a board game's seat of black
     * @param  RoomComputerLevel|null  $computer  a board game's: the level of the computer, which then plays the seat without a player (K3, ADR 036 decision 2); null: two players. A domino game's: the level of every seat without a player (ADR 038 decision 12)
     * @param  RoomTable|null  $table  a domino game's table; null for the other kinds, or a posted choice that leaves it to the number of players
     * @param  list<string|null>|null  $seats  a domino game's seats in the order of the turns, each a `room_players.id` or null; null for the other kinds, or a posted choice that keeps the room's
     */
    public function __construct(
        public RoomMechanic $mechanic,
        public int $difficulty,
        public ?int $rounds = null,
        public ?string $white = null,
        public ?string $black = null,
        public ?RoomComputerLevel $computer = null,
        public ?RoomTable $table = null,
        public ?array $seats = null,
    ) {
        $kind = $mechanic->kind();
        $named = array_values(array_filter($seats ?? [], static fn (?string $seat): bool => $seat !== null));

        $wrong = match (true) {
            $kind === RoomGameKind::Race && ($rounds === null || $white !== null || $black !== null || $computer !== null) => 'A race has its rounds, and no seats.',
            $kind !== RoomGameKind::Race && $rounds !== null => 'Only a race has rounds.',
            $kind === RoomGameKind::Board && (($white !== null && $white === $black) || ($computer !== null && $white !== null && $black !== null)) => 'A board game has two different seats — one of them the computer\'s when it plays.',
            $kind !== RoomGameKind::Domino && ($table !== null || $seats !== null) => 'Only a domino game has a table.',
            $kind === RoomGameKind::Domino && ($white !== null || $black !== null) => 'A domino game has no colours.',
            $kind === RoomGameKind::Domino && $seats !== null && (($table !== null && count($seats) !== $table->seats()) || count($named) !== count(array_unique($named))) => 'A domino game\'s seats are its table\'s, each player named once.',
            default => null,
        };

        if ($wrong !== null) {
            throw new InvalidArgumentException($wrong);
        }
    }

    /**
     * A choice of this game at this level, in its kind's shape: a race with
     * `$rounds` — or none, when there are no rounds to give it —, a board
     * game without them, its seats and the computer's level from `$seats`
     * (`RoomSeats`), asked only for a board game, and a domino game with its
     * table, seats and computer's level from `$table` (`RoomTableSeats`),
     * asked only for a domino game.
     *
     * @param  (Closure(): array{0: string|null, 1: string|null, 2: RoomComputerLevel|null})|null  $seats
     * @param  (Closure(): array{0: RoomTable|null, 1: list<string|null>|null, 2: RoomComputerLevel|null})|null  $table
     */
    public static function of(RoomMechanic $mechanic, int $difficulty, ?int $rounds, ?Closure $seats = null, ?Closure $table = null): ?self
    {
        if ($mechanic->kind() === RoomGameKind::Race) {
            return $rounds === null ? null : new self($mechanic, $difficulty, $rounds);
        }

        if ($mechanic->kind() === RoomGameKind::Domino) {
            [$at, $places, $computer] = $table === null ? [null, null, null] : $table();

            return new self($mechanic, $difficulty, null, null, null, $computer, $at, $places);
        }

        [$white, $black, $computer] = $seats === null ? [null, null, null] : $seats();

        return new self($mechanic, $difficulty, null, $white, $black, $computer);
    }

    /**
     * The rules of the whole choice of `$mechanic` — of the mechanic named
     * in the input when null: the game and its level, and what its kind asks
     * (`RoomGameDriver::choiceRules()`): a race's rounds, a board game's
     * two seats.
     *
     * @param  bool  $kept  the room's choice was of the other kind: the kind's part may be left out
     * @return array<string, mixed>
     */
    public static function rules(?RoomMechanic $mechanic = null, bool $kept = false): array
    {
        $levels = self::playableLevels();

        return [
            'mechanic' => ['required', 'string', Rule::in(array_values(array_unique(array_map(static fn (RoomLevelData $level): string => $level->mechanic->value, $levels))))],
            'difficulty' => ['required', 'integer', Rule::in(array_values(array_unique(array_map(static fn (RoomLevelData $level): int => $level->difficulty, $levels))))],
            ...($mechanic === null ? [] : RoomGameDriver::of($mechanic)->choiceRules($kept)),
        ];
    }

    /**
     * The choice as a page posts it. A choice of the other kind than the
     * room's may name only the game and its level: a race chosen after a
     * board game then takes the rounds `$room` keeps (`Room::roundsKept()`),
     * a board game chosen after a race keeps the room's seats — so a page
     * never fills what the state of the other kind did not carry.
     *
     * @param  array<array-key, mixed>  $input
     * @param  Room|null  $room  the room whose choice it is; without it a race must name its rounds
     *
     * @throws ValidationException
     */
    public static function validate(array $input, ?Room $room = null): self
    {
        /** @var array{mechanic: string, difficulty: int|string} $named */
        $named = Validator::make($input, self::rules())->validate();
        $mechanic = RoomMechanic::from($named['mechanic']);

        $kept = $room !== null && $room->setup()->mechanic->kind() !== $mechanic->kind();

        /** @var array{mechanic: string, difficulty: int|string, rounds?: int|string, white?: string|null, black?: string|null, computer?: int|string|null, table?: string|null, seats?: list<string|null>} $validated */
        $validated = Validator::make($input, self::rules($mechanic, $kept))->validate();
        $rounds = isset($validated['rounds']) ? (int) $validated['rounds'] : null;

        if ($kept && $rounds === null && $mechanic->kind() === RoomGameKind::Race) {
            $rounds = $room->roundsKept($mechanic);
        }

        $seats = [isset($validated['white']) ? strtolower($validated['white']) : null, isset($validated['black']) ? strtolower($validated['black']) : null];
        // A board game chosen after a race says nothing of the computer: the room's stands, as its seats do. A domino
        // game's level is never carried into a board game's choice, nor a board game's into a domino game's (ADR 038 decision 75).
        $computer = match (true) {
            isset($validated['computer']) => RoomComputerLevel::from((int) $validated['computer']),
            $kept && ! array_key_exists('computer', $validated) && $mechanic->kind() === RoomGameKind::Board && $room->setup()->mechanic->kind() === RoomGameKind::Race => $room->setup_computer,
            default => null,
        };
        // A domino game's table and seats (ADR 038 decision 11): absent, the room's stand.
        [$table, $places] = $mechanic->kind() === RoomGameKind::Domino ? self::table($validated, $room) : [null, null];

        $setup = self::of($mechanic, (int) $validated['difficulty'], $rounds, static fn (): array => [...$seats, $computer], static fn (): array => [$table, $places, $computer]);

        // Each value may be a level's while the pair is none.
        if ($setup === null || ! in_array($setup->mechanic->value.'.'.$setup->difficulty, self::playablePairs(), true)) {
            throw ValidationException::withMessages(['difficulty' => __('validation.in', ['attribute' => 'difficulty'])]);
        }

        return $setup;
    }

    /**
     * A domino game's table and seats as posted (ADR 038 decision 11): each
     * absent is the room's stand — its stored seats fitted to the table —,
     * a table null leaves it to the number of players, and posted seats
     * come with a table, as many as it has, each player named once.
     *
     * @param  array{table?: string|null, seats?: list<string|null>}  $validated
     * @return array{0: RoomTable|null, 1: list<string|null>|null}
     *
     * @throws ValidationException
     */
    private static function table(array $validated, ?Room $room): array
    {
        $table = array_key_exists('table', $validated) ? ($validated['table'] === null ? null : RoomTable::from($validated['table'])) : $room?->setup_table;

        if (! array_key_exists('seats', $validated)) {
            $kept = $room?->setup_seats;

            return [$table, $kept === null || $table === null ? $kept : array_pad(array_slice($kept, 0, $table->seats()), $table->seats(), null)];
        }

        $places = array_map(static fn (?string $seat): ?string => $seat === null ? null : strtolower($seat), $validated['seats']);
        $named = array_values(array_filter($places, static fn (?string $seat): bool => $seat !== null));

        if ($table === null || count($places) !== $table->seats() || count($named) !== count(array_unique($named))) {
            throw ValidationException::withMessages(['seats' => __('validation.in', ['attribute' => 'seats'])]);
        }

        return [$table, $places];
    }

    /**
     * Whether the choice is still one a room plays: its pair is a playable
     * level and a race's rounds are inside the bounds — a stored choice is
     * read through it, since content and config may change under a room.
     * Whether a board game has its two seats is for its start to ask.
     */
    public function isPlayable(): bool
    {
        return in_array($this->mechanic->value.'.'.$this->difficulty, self::playablePairs(), true)
            && ($this->rounds === null || ($this->rounds >= Config::integer('elifba.rooms.rounds.min')
                && $this->rounds <= Config::integer('elifba.rooms.rounds.max')));
    }

    /**
     * The same difficulty and rounds with another game — «Başqa oyun», or a
     * game chosen on «Oyunlar» while the host's room is open; null when the
     * game has no level at that difficulty, or is a race and this choice
     * has no rounds to give it. A board game's seats are the room's to say
     * (`Room::setup()`).
     */
    public function withMechanic(RoomMechanic $mechanic): ?self
    {
        $other = self::of($mechanic, $this->difficulty, $this->rounds);

        return $other !== null && $other->isPlayable() ? $other : null;
    }

    /**
     * The choice with the room's seats and the computer's level, for a
     * board game (`RoomSeats`, asked only then), and with the room's table,
     * seats and computer's level for a domino game (`RoomTableSeats`, asked
     * only then); a race's as it is.
     *
     * @param  Closure(): array{0: string|null, 1: string|null, 2: RoomComputerLevel|null}  $seats
     * @param  (Closure(): array{0: RoomTable, 1: list<string|null>, 2: RoomComputerLevel|null})|null  $table
     */
    public function seated(Closure $seats, ?Closure $table = null): self
    {
        return self::of($this->mechanic, $this->difficulty, $this->rounds, $seats, $table) ?? $this;
    }

    /**
     * Whether `$game` was played with exactly this choice (ADR 026 decision
     * 11, ADR 034 decision 8): the same game at the same level, and a race's
     * same number of rounds — a board game's same two players, whichever
     * their colours, since a start changes the colours for the next.
     */
    public function playedIn(RoomGame $game): bool
    {
        if ($game->mechanic !== $this->mechanic || $game->difficulty !== $this->difficulty) {
            return false;
        }

        if ($this->rounds !== null) {
            return $game->rounds->count() === $this->rounds;
        }

        // A domino game: the same table, the same players in the same seats, the same computer (ADR 038 decision 14).
        if ($this->table !== null) {
            return $game->dominoTable() === $this->table && $game->seats === $this->seats && $game->computer === $this->computer;
        }

        $seats = [$this->white, $this->black];
        $played = [$game->white_player_id, $game->black_player_id];
        sort($seats);
        sort($played);

        return $game->computer === $this->computer && $seats === $played
            && ($this->computer === null ? $this->white !== null && $this->black !== null : $this->white !== $this->black);
    }

    /**
     * A domino game's level as a turn's time (ADR 038 decision 22); null for
     * the other kinds.
     */
    public function turnMs(): ?int
    {
        return app(ContentRepository::class)->roomLevel($this->mechanic, $this->difficulty)->turnMs;
    }

    /**
     * A board game's level as its clock (ADR 034 decision 6); null for a race.
     */
    public function clock(): ?RoomClockLevelData
    {
        $level = app(ContentRepository::class)->roomLevel($this->mechanic, $this->difficulty);

        return RoomClockLevelData::ofLevel($level);
    }

    /**
     * The mechanics a room plays that have levels, in the order `options()`
     * lists them: what «Oyunlar» offers and `OpenRoomData::rules()` accepts.
     *
     * @return list<RoomMechanic>
     */
    public static function mechanics(): array
    {
        $mechanics = [];

        foreach (self::playableLevels() as $level) {
            $mechanics[$level->mechanic->value] ??= $level->mechanic;
        }

        return array_values($mechanics);
    }

    /**
     * The first of `mechanics()`: the game of a room that has no other to
     * take (a room opened before G2 with none played).
     */
    public static function firstMechanic(): RoomMechanic
    {
        return self::mechanics()[0] ?? RoomPlayable::mechanics()[0];
    }

    /**
     * The difficulties a mechanic has a playable level at, lowest first.
     *
     * @return list<int>
     */
    public static function difficultiesOf(RoomMechanic $mechanic): array
    {
        $difficulties = array_map(
            static fn (RoomLevelData $level): int => $level->difficulty,
            array_filter(self::playableLevels(), static fn (RoomLevelData $level): bool => $level->mechanic === $mechanic),
        );
        sort($difficulties);

        return array_values(array_unique($difficulties));
    }

    /**
     * What the host's setup offers, as `rules()` accepts it: each mechanic
     * `RoomPlayable` lists with its family, the difficulties of its levels
     * and what each of them holds — the times, how many items (a row's
     * length, the lit cells) and a grid; for a logic game (B5) the rules,
     * terms and range of a row, or the source and options; for a word game
     * (B6) a word's letters and a spelling's swaps; for a board game (D3) its
     * clock — and the bounds of the rounds. The room page names them
     * (docs/frontend.md rule 15); it never lists them itself.
     *
     * @return array{mechanics: list<array{mechanic: string, family: string, difficulties: list<int>, levels: list<array{difficulty: int, study_ms: int, answer_ms: int, count: array{min: int, max: int}|null, grid: array{cols: int, rows: int}|null, rules: list<string>|null, terms: int|null, range: array{min: int, max: int}|null, source: string|null, options: int|null, letters: array{min: int, max: int}|null, swaps: int|null, clock?: array{base_ms: int, increment_ms: int}, turn_ms?: int}>}>, rounds: array{min: int, max: int}}
     */
    public static function options(): array
    {
        $mechanics = [];

        foreach (self::playableLevels() as $level) {
            $mechanics[$level->mechanic->value][$level->difficulty] = $level;
        }

        $options = [];

        foreach ($mechanics as $mechanic => $levels) {
            ksort($levels);
            $options[] = [
                'mechanic' => $mechanic,
                'family' => RoomMechanic::from($mechanic)->family()->value,
                'difficulties' => array_keys($levels),
                'levels' => array_values(array_map(self::levelOption(...), $levels)),
            ];
        }

        return [
            'mechanics' => $options,
            'rounds' => ['min' => Config::integer('elifba.rooms.rounds.min'), 'max' => Config::integer('elifba.rooms.rounds.max')],
        ];
    }

    /**
     * A level as the setup describes it: `count` is how many items a round
     * asks to recall — a row's length, the lit cells — and `grid` a cell
     * game's grid; `rules`, `terms` and `range` say what a
     * `next_in_sequence` row follows, how long it is and its numbers;
     * `source` and `options` what an `odd_one_out` round offers; `letters`
     * a word game's word length and `swaps` a spelling's (1: two spellings
     * may differ in one letter; 2: any two differ in two). A key of another
     * mechanic is null. A board game's level adds its `clock` (D3), which a
     * race's level has not.
     *
     * @return array{difficulty: int, study_ms: int, answer_ms: int, count: array{min: int, max: int}|null, grid: array{cols: int, rows: int}|null, rules: list<string>|null, terms: int|null, range: array{min: int, max: int}|null, source: string|null, options: int|null, letters: array{min: int, max: int}|null, swaps: int|null, clock?: array{base_ms: int, increment_ms: int}, turn_ms?: int}
     */
    private static function levelOption(RoomLevelData $level): array
    {
        $count = $level->length ?? $level->lit;
        $clock = RoomClockLevelData::ofLevel($level);

        return [
            'difficulty' => $level->difficulty,
            'study_ms' => $level->studyMs,
            'answer_ms' => $level->answerMs,
            'count' => $count === null ? null : ['min' => $count->min, 'max' => $count->max],
            'grid' => $level->grid?->toArray(),
            'rules' => $level->rules === [] ? null : array_map(static fn (RoomSequenceRule $rule): string => $rule->value, $level->rules),
            'terms' => $level->terms,
            'range' => $level->range === null ? null : ['min' => $level->range->min, 'max' => $level->range->max],
            'source' => $level->source?->value,
            'options' => $level->options,
            'letters' => $level->wordLength === null ? null : ['min' => $level->wordLength->min, 'max' => $level->wordLength->max],
            'swaps' => $level->swaps,
            ...($clock === null ? [] : ['clock' => $clock->toArray()]),
            ...($level->turnMs === null ? [] : ['turn_ms' => $level->turnMs]),
        ];
    }

    /**
     * @return list<string> `mechanic.difficulty` of every playable level
     */
    private static function playablePairs(): array
    {
        return array_map(static fn (RoomLevelData $level): string => $level->mechanic->value.'.'.$level->difficulty, self::playableLevels());
    }

    /**
     * @return list<RoomLevelData>
     */
    private static function playableLevels(): array
    {
        return array_values(array_filter(
            app(ContentRepository::class)->roomLevels(),
            static fn (RoomLevelData $level): bool => RoomPlayable::plays($level->mechanic),
        ));
    }
}
