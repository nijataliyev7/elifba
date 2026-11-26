<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomActKind;
use App\Domain\Rooms\Support\Domino\DominoTile;
use App\Domain\Rooms\Support\Draughts\DraughtsNotation;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A seat's act as the device posts it (docs/architecture.md §12 "Board
 * games", ADR 034 decision 9; zod `RoomAct`, fixture
 * tests/Fixtures/rooms/act.json): `POST /rooms/{room}/acts`
 * `{event_id, game_id, kind, ply, path}`. The event id is the device's own
 * ULID, so an act sent twice is stored once; the game is the one the device
 * saw (`room_games.id`), as an answer names its round, so an act sent again
 * after its game ended is never taken in the next. `kind` is one of the five a device may
 * send — never `leave`, which only the server writes; `ply` is the number
 * of moves the device saw made, for every kind but `resign`, which is taken
 * at whatever ply the match stands; `path` is a move's start and every
 * landing, 2–13 dark squares by name, for a move only. Nothing names a
 * player: the seat is the session's account (`RecordRoomAct`).
 *
 * From Y5 a domino game's kinds (§12 "Domino", ADR 038 decision 16; zod
 * `RoomAct`, fixtures `act.{play,yield,back}.json`): a `play` names its ply
 * and its `path` — `[tile]` for a hand's first tile, else `[tile, end]`, a
 * tile by its name (`6-5`, the larger number first) and an end `left` or
 * `right`; a `yield` («Sən aç») its ply alone; a `back` («Oyuna qayıt»)
 * neither. `path` is what an act did in its game's own notation, and these
 * rules check its form only: whether a tile is the seat's, fits or is the
 * seat's turn is the driver's to judge.
 *
 * @phpstan-type RoomActArray array{event_id: string, game_id: string, kind: string, ply?: int, path?: list<string>}
 */
final readonly class RoomActData
{
    /** The most squares a path holds: the start and twelve landings. */
    public const int MAX_PATH = 13;

    /**
     * @param  int|null  $ply  null for a resignation and a `back`
     * @param  list<string>|null  $path  a move's or a play's; null for the other kinds
     */
    public function __construct(
        public string $event_id,
        public string $game_id,
        public RoomActKind $kind,
        public ?int $ply,
        public ?array $path,
    ) {
        $wrong = match (true) {
            $kind === RoomActKind::Leave => 'A device never sends a leave.',
            ($ply === null) !== in_array($kind, [RoomActKind::Resign, RoomActKind::Back], true) => 'Every act but a resignation and a back names its ply.',
            ($path === null) === in_array($kind, [RoomActKind::Move, RoomActKind::Play], true) => 'A move and a play, and nothing else, have a path.',
            default => null,
        };

        if ($wrong !== null) {
            throw new InvalidArgumentException($wrong);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $kinds = array_map(
            static fn (RoomActKind $kind): string => $kind->value,
            array_filter(RoomActKind::cases(), static fn (RoomActKind $kind): bool => $kind !== RoomActKind::Leave),
        );

        $play = static fn (Fluent $input): bool => $input->get('kind') === RoomActKind::Play->value;

        return [
            'event_id' => ['required', 'string', 'ulid'],
            'game_id' => ['required', 'string', 'ulid'],
            'kind' => ['required', 'string', Rule::in($kinds)],
            'ply' => ['required_unless:kind,resign,back', 'prohibited_if:kind,resign,back', 'integer', 'min:0', 'max:10000'],
            // A play's tile, then its end: the form alone, never whether the seat may play it, and
            // refused with the judge's one answer (ADR 038 decision 7), so no refusal tells another.
            'path' => Rule::when(
                $play,
                ['required', static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! self::isPlayPath($value)) {
                        $fail(__('rooms.domino.illegal'));
                    }
                }],
                ['required_if:kind,move', 'prohibited_unless:kind,move', 'array', 'list', 'min:2', 'max:'.self::MAX_PATH],
            ),
            'path.*' => Rule::when(
                $play,
                ['present'],
                ['required', 'string', static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! DraughtsNotation::isSquare($value)) {
                        $fail(__('rooms.match.illegal'));
                    }
                }],
            ),
        ];
    }

    /** Whether a value is a play's path in form: `[tile]` or `[tile, end]`, the tile written the one way. */
    private static function isPlayPath(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) < 1 || count($value) > 2) {
            return false;
        }

        $tile = $value[0];
        $end = $value[1] ?? null;

        return is_string($tile) && DominoTile::tryOf($tile) !== null && ($end === null || (is_string($end) && DominoTile::isEnd($end)));
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        // A play without its path gets the judge's one answer too (ADR 038 decision 78).
        $messages = ['path.required' => __('rooms.domino.illegal')];

        // The size first: the rule for each square would run over every one of a huge list.
        Validator::make($input, ['path' => self::rules()['path']], $messages)->validate();

        /** @var array{event_id: string, game_id: string, kind: string, ply?: int|string, path?: list<string>} $validated */
        $validated = Validator::make($input, self::rules(), $messages)->validate();

        return new self(
            Str::lower($validated['event_id']),
            Str::lower($validated['game_id']),
            RoomActKind::from($validated['kind']),
            isset($validated['ply']) ? (int) $validated['ply'] : null,
            $validated['path'] ?? null,
        );
    }

    /**
     * @param  RoomActArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Str::lower($data['event_id']), Str::lower($data['game_id']), RoomActKind::from($data['kind']), $data['ply'] ?? null, $data['path'] ?? null);
    }

    /**
     * @return RoomActArray
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->event_id,
            'game_id' => $this->game_id,
            'kind' => $this->kind->value,
            ...($this->ply === null ? [] : ['ply' => $this->ply]),
            ...($this->path === null ? [] : ['path' => $this->path]),
        ];
    }
}
