<?php

namespace App\Domain\Play\Data;

use App\Domain\Play\Enums\HitSource;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The body of `POST /games/{game}/sync` (docs/architecture.md §4; zod
 * `SyncRequest`): the board's epoch and its outbox, 1–50 events in order.
 *
 * The rules check only the shape. Whether an id names something real is a
 * question for each event (`RecordBoardEvents` answers it in that event's
 * result), because a 422 for the whole batch would block the board's
 * outbox for ever.
 *
 * @phpstan-import-type HitArray from HitData
 * @phpstan-import-type SkipArray from SkipData
 *
 * @phpstan-type BoardEventArray array{type: 'hit', event_id: string, round_id: string, item_id: string, source: string, student_id: string|null, client_at: string|null}|array{type: 'skip', event_id: string, round_id: string, client_at: string|null}
 * @phpstan-type BoardEventsArray array{board_epoch: int, events: list<BoardEventArray>}
 */
final readonly class BoardEventsData
{
    public const int MAX_EVENTS = 50;

    /**
     * @param  list<HitData|SkipData>  $events
     */
    public function __construct(
        public int $board_epoch,
        public array $events,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'board_epoch' => ['required', 'integer', 'min:0'],
            'events' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_EVENTS],
            'events.*' => ['required', 'array'],
            'events.*.type' => ['required', 'string', Rule::in(['hit', 'skip'])],
            'events.*.event_id' => ['required', 'string', 'ulid'],
            'events.*.round_id' => ['required', 'string', 'max:64'],
            'events.*.item_id' => ['exclude_unless:events.*.type,hit', 'required', 'string', 'max:64'],
            'events.*.source' => ['exclude_unless:events.*.type,hit', 'required', 'string', Rule::enum(HitSource::class)],
            'events.*.student_id' => ['exclude_unless:events.*.type,hit', 'present', 'nullable', 'string', 'max:64'],
            'events.*.client_at' => ['present', 'nullable', 'string', 'date'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException when the body is malformed
     */
    public static function validate(array $input): self
    {
        /** @var BoardEventsArray $validated */
        $validated = Validator::make($input, self::rules())->validate();

        return self::fromArray($validated);
    }

    /**
     * @param  BoardEventsArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            board_epoch: (int) $data['board_epoch'],
            events: array_map(
                static fn (array $event): HitData|SkipData => $event['type'] === 'hit'
                    ? HitData::fromArray($event)
                    : SkipData::fromArray($event),
                $data['events'],
            ),
        );
    }

    /**
     * @return BoardEventsArray
     */
    public function toArray(): array
    {
        return [
            'board_epoch' => $this->board_epoch,
            'events' => array_map(
                static fn (HitData|SkipData $event): array => $event instanceof HitData
                    ? ['type' => 'hit', ...$event->toArray()]
                    : ['type' => 'skip', ...$event->toArray()],
                $this->events,
            ),
        ];
    }
}
