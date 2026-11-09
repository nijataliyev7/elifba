<?php

namespace App\Domain\Play\Models;

use App\Domain\Play\Policies\GuestBoardPolicy;
use App\Domain\Play\Support\Requester;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A board on a shared school computer (docs/architecture.md §1, §4): `/lovhe`
 * opens it with a pairing code, the teacher's phone claims it and sends it a
 * game. It is the `board` guard's user (`GuestBoardGuard`): the bearer
 * `{id}.{secret}` reaches only the game it shows now — never classes,
 * rankings or other games — and never logs anyone in.
 *
 * Active while it is neither closed nor past `expires_at`; an unclaimed
 * board also dies with its code. `{board}` binds only the board itself (for
 * its bearer) or the active boards the current teacher claimed.
 *
 * @property string $id
 * @property string|null $code pairing code while unclaimed; cleared on claim
 * @property CarbonImmutable|null $code_expires_at
 * @property string $secret_hash SHA-256 of the secret only the board's tab holds
 * @property int|null $user_id the teacher who claimed it
 * @property string|null $game_session_id the game it shows now
 * @property CarbonImmutable|null $claimed_at
 * @property CarbonImmutable $expires_at creation + elifba.guest_board_hours
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $teacher
 * @property-read GameSession|null $game
 */
#[UsePolicy(GuestBoardPolicy::class)]
#[Hidden(['secret_hash'])]
class GuestBoard extends Model implements Authenticatable
{
    use HasUlids;

    /**
     * The secret's length in random bytes (§4).
     */
    public const int SECRET_BYTES = 32;

    /**
     * A new random secret, URL-safe base64 without padding (43 characters).
     */
    public static function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::SECRET_BYTES)), '+/', '-_'), '=');
    }

    /**
     * What the row stores of a secret. The secret is 32 random bytes, so a
     * fast hash is enough; `hash_equals` compares it.
     */
    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public function matchesSecret(string $secret): bool
    {
        return hash_equals($this->secret_hash, self::hashSecret($secret));
    }

    /**
     * Neither closed nor expired; unclaimed, it also needs a valid code.
     */
    public function isActive(): bool
    {
        return $this->closed_at === null
            && $this->expires_at->isFuture()
            && ($this->claimed_at !== null || ($this->code_expires_at?->isFuture() ?? false));
    }

    /**
     * Open for a teacher's phone: active, unclaimed, with its code.
     */
    public function isClaimable(): bool
    {
        return $this->isActive() && $this->claimed_at === null && $this->code !== null;
    }

    /**
     * Whether this board shows the game now, so its bearer may drive it.
     */
    public function showsGame(string $gameId): bool
    {
        return $this->isActive() && $this->claimed_at !== null && $this->game_session_id === $gameId;
    }

    public function isClaimedBy(User $teacher): bool
    {
        return $this->user_id === $teacher->id;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<GameSession, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(GameSession::class, 'game_session_id');
    }

    /**
     * Its own bearer finds the board itself; a teacher finds the active
     * boards they claimed; anyone else finds nothing (404).
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $who = Requester::current();

        if ($who instanceof self) {
            return $who->id === $value ? $who : null;
        }

        if ($who === null) {
            return null;
        }

        return static::query()->active()->where('user_id', $who->id)->whereKey($value)->first();
    }

    public function getAuthIdentifierName(): string
    {
        return $this->getKeyName();
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getAuthPasswordName(): string
    {
        return 'secret_hash';
    }

    public function getAuthPassword(): string
    {
        return $this->secret_hash;
    }

    /**
     * A board has no "remember me".
     */
    public function getRememberToken(): ?string
    {
        return null;
    }

    /**
     * @param  string  $value
     */
    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code_expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Neither closed nor expired; unclaimed ones with a valid code.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $now = now();

        $query->whereNull('closed_at')
            ->where('expires_at', '>', $now)
            ->where(static fn (Builder $query) => $query
                ->whereNotNull('claimed_at')
                ->orWhere('code_expires_at', '>', $now));
    }

    /**
     * The open, unclaimed board with this code.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function claimable(Builder $query, string $code): void
    {
        $query->active()->whereNull('claimed_at')->where('code', $code);
    }
}
