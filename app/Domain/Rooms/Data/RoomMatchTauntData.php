<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Content\Support\ContentRepository;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A player's taunt in a board match (ADR 034 decision 11): `POST
 * /rooms/{room}/match/taunts` `{event_id, taunt}`. The target is always the
 * opponent, so the body never names one; the thrower is the session's
 * account.
 */
final readonly class RoomMatchTauntData
{
    public function __construct(
        public string $event_id,
        public string $taunt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'ulid'],
            'taunt' => ['required', 'string', Rule::in(array_keys(app(ContentRepository::class)->roomTaunts()))],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        /** @var array{event_id: string, taunt: string} $validated */
        $validated = Validator::make($input, self::rules())->validate();

        return new self(Str::lower($validated['event_id']), $validated['taunt']);
    }
}
