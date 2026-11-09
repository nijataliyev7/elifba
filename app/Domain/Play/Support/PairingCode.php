<?php

namespace App\Domain\Play\Support;

use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Models\GuestBoard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Every pairing code (docs/architecture.md §4): 6 digits, unique across the
 * live games' join codes and the open guest boards — one code space over
 * two tables — and valid for `elifba.pairing_code_ttl_minutes`. A code only
 * says which board; it never authorizes anything.
 *
 * No database constraint spans the two tables, so `issue()` skips a code in
 * use in either and retries when the store hits a unique index (an expired
 * code still sits in its column, and a request may race this one).
 * `resolve()` decides deterministically when a code is found in both.
 */
final class PairingCode
{
    public const int DIGITS = 6;

    private const int ATTEMPTS = 20;

    /**
     * @param  (Closure(): string)|null  $generator  tests force codes with it
     */
    public function __construct(private ?Closure $generator = null) {}

    /**
     * Calls `$store` with a free code until one is stored. `$store` writes
     * in its own transaction, so a unique violation rolls back only that
     * attempt (a savepoint inside an outer transaction).
     *
     * @template T
     *
     * @param  Closure(string, CarbonImmutable): T  $store
     * @return T
     */
    public function issue(Closure $store): mixed
    {
        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $code = $this->next();

            if ($this->inUse($code)) {
                continue;
            }

            try {
                return $store($code, self::expiresAt());
            } catch (UniqueConstraintViolationException) {
                // Taken a moment ago, or an expired code still in its column.
            }
        }

        throw new RuntimeException('No free pairing code after '.self::ATTEMPTS.' attempts.');
    }

    /**
     * Whether a valid code is held by a live game or an open guest board.
     */
    public function inUse(string $code): bool
    {
        return GameSession::query()->open()->where('join_code', $code)->where('join_code_expires_at', '>', now())->exists()
            || GuestBoard::query()->claimable($code)->exists();
    }

    /**
     * What a code opens for this teacher, in this order: their own live game
     * with that join code (→ its remote), else the open guest board showing
     * it (→ claim). Anything else — unknown, expired, another teacher's
     * game, a finished one — is null, so the caller answers the same 404.
     */
    public function resolve(string $code, User $teacher): GameSession|GuestBoard|null
    {
        $code = self::normalize($code);

        if ($code === null) {
            return null;
        }

        return GameSession::query()
            ->open()
            ->where('user_id', $teacher->id)
            ->where('join_code', $code)
            ->where('join_code_expires_at', '>', now())
            ->first()
            ?? GuestBoard::query()->claimable($code)->first();
    }

    /**
     * The 6 digits of what a teacher typed ("482 913", "482-913"), or null.
     */
    public static function normalize(mixed $input): ?string
    {
        if (! is_string($input) && ! is_int($input)) {
            return null;
        }

        $digits = preg_replace('/[\s\-]+/u', '', (string) $input) ?? '';

        return preg_match('/^\d{'.self::DIGITS.'}$/D', $digits) === 1 ? $digits : null;
    }

    public static function expiresAt(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMinutes(Config::integer('elifba.pairing_code_ttl_minutes'));
    }

    private function next(): string
    {
        if ($this->generator !== null) {
            return ($this->generator)();
        }

        return str_pad((string) random_int(0, 10 ** self::DIGITS - 1), self::DIGITS, '0', STR_PAD_LEFT);
    }
}
