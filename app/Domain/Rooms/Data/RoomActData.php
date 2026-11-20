<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomActKind;
use App\Domain\Rooms\Support\Draughts\DraughtsNotation;
use Closure;
use Illuminate\Support\Facades\Validator;
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
 * @phpstan-type RoomActArray array{event_id: string, game_id: string, kind: string, ply?: int, path?: list<string>}
 */
final readonly class RoomActData
{
    /** The most squares a path holds: the start and twelve landings. */
    public const int MAX_PATH = 13;

    /**
     * @param  int|null  $ply  null for a resignation
     * @param  list<string>|null  $path  a move's; null for the other kinds
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
            ($ply === null) !== ($kind === RoomActKind::Resign) => 'Every act but a resignation names its ply.',
            ($path === null) === ($kind === RoomActKind::Move) => 'A move, and only a move, has a path.',
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

        return [
            'event_id' => ['required', 'string', 'ulid'],
            'game_id' => ['required', 'string', 'ulid'],
            'kind' => ['required', 'string', Rule::in($kinds)],
            'ply' => ['required_unless:kind,resign', 'prohibited_if:kind,resign', 'integer', 'min:0', 'max:10000'],
            'path' => ['required_if:kind,move', 'prohibited_unless:kind,move', 'array', 'list', 'min:2', 'max:'.self::MAX_PATH],
            'path.*' => ['required', 'string', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! DraughtsNotation::isSquare($value)) {
                    $fail(__('rooms.match.illegal'));
                }
            }],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        // The size first: the rule for each square would run over every one of a huge list.
        Validator::make($input, ['path' => self::rules()['path']])->validate();

        /** @var array{event_id: string, game_id: string, kind: string, ply?: int|string, path?: list<string>} $validated */
        $validated = Validator::make($input, self::rules())->validate();

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
