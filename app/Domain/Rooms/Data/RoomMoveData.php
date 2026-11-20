<?php

namespace App\Domain\Rooms\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A player's move in a board match (ADR 034 decision 5): `POST
 * /rooms/{room}/match/moves` with a fresh `event_id`, the `ply` the page
 * saw — a move made against an older position is late — and the path:
 * the square the piece stands on, then every square it lands on. The
 * player is the session's account, never a field of the body.
 */
final readonly class RoomMoveData
{
    /** The longest path: a king's capture can land at most twelve times on an 8 × 8 board. */
    public const int MAX_PATH = 13;

    /**
     * @param  list<int>  $path
     */
    public function __construct(
        public string $event_id,
        public int $ply,
        public array $path,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'ulid'],
            'ply' => ['required', 'integer', 'min:0'],
            'path' => ['required', 'array', 'list', 'min:2', 'max:'.self::MAX_PATH],
            'path.*' => ['required', 'integer', 'between:0,63'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        /** @var array{event_id: string, ply: int|string, path: list<int|string>} $validated */
        $validated = Validator::make($input, self::rules())->validate();

        return new self(
            strtolower($validated['event_id']),
            (int) $validated['ply'],
            array_map(static fn (int|string $square): int => (int) $square, $validated['path']),
        );
    }
}
