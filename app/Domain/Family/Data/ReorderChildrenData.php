<?php

namespace App\Domain\Family\Data;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The parent's new order of their children, first to last. The ids are not
 * route-bound, so `ReorderChildren` checks that they are exactly the
 * parent's children; that check is their isolation guard.
 */
final readonly class ReorderChildrenData
{
    /**
     * @param  list<string>  $child_ids
     */
    public function __construct(public array $child_ids) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'child_ids' => ['required', 'array', 'list', 'min:1', 'max:'.Config::integer('elifba.children.max')],
            'child_ids.*' => ['required', 'string', 'max:26', 'distinct'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $ids = is_array($validated['child_ids'] ?? null) ? $validated['child_ids'] : [];

        return new self(array_values(array_filter($ids, is_string(...))));
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        return self::fromArray(Validator::make($input, self::rules())->validate());
    }
}
