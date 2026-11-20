<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomGameKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Enums\RoomSequenceRule;
use App\Domain\Content\Support\ContentRepository;
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
 * players of white and black, either null while the room has fewer than
 * two present players to seat (`RoomSeats`). What the choice asks beyond
 * the game and its level is its kind's (`RoomGameDriver::choiceRules()`).
 */
final readonly class RoomGameSetupData
{
    /**
     * @param  int|null  $rounds  a race's; null for a board game
     * @param  string|null  $white  a board game's seat of white; null for a race, or while nobody is seated
     * @param  string|null  $black  a board game's seat of black
     */
    public function __construct(
        public RoomMechanic $mechanic,
        public int $difficulty,
        public ?int $rounds = null,
        public ?string $white = null,
        public ?string $black = null,
    ) {
        $race = $mechanic->kind() === RoomGameKind::Race;

        if ($race && ($rounds === null || $white !== null || $black !== null)) {
            throw new InvalidArgumentException('A race has its rounds, and no seats.');
        }

        if (! $race && ($rounds !== null || ($white !== null && $white === $black))) {
            throw new InvalidArgumentException('A board game has no rounds, and two different seats.');
        }
    }

    /**
     * A choice of this game at this level, in its kind's shape: a race with
     * `$rounds` — or none, when there are no rounds to give it — and a board
     * game without them, its seats from `$seats` (`RoomSeats`), asked only
     * for a board game.
     *
     * @param  (Closure(): array{0: string|null, 1: string|null})|null  $seats
     */
    public static function of(RoomMechanic $mechanic, int $difficulty, ?int $rounds, ?Closure $seats = null): ?self
    {
        if ($mechanic->kind() === RoomGameKind::Race) {
            return $rounds === null ? null : new self($mechanic, $difficulty, $rounds);
        }

        [$white, $black] = $seats === null ? [null, null] : $seats();

        return new self($mechanic, $difficulty, null, $white, $black);
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

        /** @var array{mechanic: string, difficulty: int|string, rounds?: int|string, white?: string, black?: string} $validated */
        $validated = Validator::make($input, self::rules($mechanic, $kept))->validate();
        $rounds = isset($validated['rounds']) ? (int) $validated['rounds'] : null;

        if ($kept && $rounds === null && $mechanic->kind() === RoomGameKind::Race) {
            $rounds = $room->roundsKept($mechanic);
        }

        $seats = isset($validated['white'], $validated['black']) ? [strtolower($validated['white']), strtolower($validated['black'])] : [null, null];
        $setup = self::of($mechanic, (int) $validated['difficulty'], $rounds, static fn (): array => $seats);

        // Each value may be a level's while the pair is none.
        if ($setup === null || ! in_array($setup->mechanic->value.'.'.$setup->difficulty, self::playablePairs(), true)) {
            throw ValidationException::withMessages(['difficulty' => __('validation.in', ['attribute' => 'difficulty'])]);
        }

        return $setup;
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
     * The choice with the room's seats, for a board game (`RoomSeats`, asked
     * only then); a race's as it is.
     *
     * @param  Closure(): array{0: string|null, 1: string|null}  $seats
     */
    public function seated(Closure $seats): self
    {
        return self::of($this->mechanic, $this->difficulty, $this->rounds, $seats) ?? $this;
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

        $seats = [$this->white, $this->black];
        $played = [$game->white_player_id, $game->black_player_id];
        sort($seats);
        sort($played);

        return $this->white !== null && $this->black !== null && $seats === $played;
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
     * @return array{mechanics: list<array{mechanic: string, family: string, difficulties: list<int>, levels: list<array{difficulty: int, study_ms: int, answer_ms: int, count: array{min: int, max: int}|null, grid: array{cols: int, rows: int}|null, rules: list<string>|null, terms: int|null, range: array{min: int, max: int}|null, source: string|null, options: int|null, letters: array{min: int, max: int}|null, swaps: int|null, clock?: array{base_ms: int, increment_ms: int}}>}>, rounds: array{min: int, max: int}}
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
     * @return array{difficulty: int, study_ms: int, answer_ms: int, count: array{min: int, max: int}|null, grid: array{cols: int, rows: int}|null, rules: list<string>|null, terms: int|null, range: array{min: int, max: int}|null, source: string|null, options: int|null, letters: array{min: int, max: int}|null, swaps: int|null, clock?: array{base_ms: int, increment_ms: int}}
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
