<?php

namespace App\Domain\Rooms\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A letter a player buys, as the device posts it (docs/architecture.md §12
 * "«Anaqram»: a clue, a shuffle and a bought letter (ADR 037)", ADR 037
 * decision 12; zod `RoomLetter`, fixture tests/Fixtures/rooms/letter.json):
 * `POST /rooms/{room}/letters` `{event_id, round_id, position}`. The event
 * id is the device's own ULID, so a buy sent twice is stored once; the
 * position is the place in the drawn word of the letter the device means
 * to buy — the next one — so a second tap names a letter already bought
 * and buys nothing. Nothing names a player or a letter: the buyer is the
 * session's account, the tile the round's (`BuyRoomLetter`).
 *
 * The ids are read in lowercase, as the rows store them.
 *
 * @phpstan-type RoomLetterArray array{event_id: string, round_id: string, position: int}
 */
final readonly class RoomLetterData
{
    /** The last place a word's letter may have: a question holds at most 36 items. */
    public const int MAX_POSITION = RoomAnswerData::MAX_PICKED - 1;

    public function __construct(
        public string $event_id,
        public string $round_id,
        public int $position,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'ulid'],
            'round_id' => ['required', 'string', 'ulid'],
            'position' => ['required', 'integer', 'min:0', 'max:'.self::MAX_POSITION],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        /** @var array{event_id: string, round_id: string, position: int|string} $validated */
        $validated = Validator::make($input, self::rules())->validate();

        return self::fromArray([...$validated, 'position' => (int) $validated['position']]);
    }

    /**
     * @param  RoomLetterArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Str::lower($data['event_id']), Str::lower($data['round_id']), $data['position']);
    }

    /**
     * @return RoomLetterArray
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->event_id,
            'round_id' => $this->round_id,
            'position' => $this->position,
        ];
    }
}
