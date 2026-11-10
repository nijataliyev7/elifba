<?php

namespace App\Support;

use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * What Google says about the account a teacher signed in with (ADR 008): its
 * OpenID `sub`, address, name, whether Google verified the address and, for a
 * Google Workspace account, its domain (`hd`). Only `id` is ever stored on the
 * teacher; the rest is used once, at sign-in.
 */
final readonly class GoogleProfile
{
    public function __construct(
        public string $id,
        public string $email,
        public string $name,
        public bool $emailVerified,
        public ?string $hostedDomain = null,
    ) {}

    /**
     * Whether Google vouches that whoever holds this Google account holds the
     * address: a verified Gmail address, or a Google Workspace one (`hd`).
     * Any other address was verified once, when the Google account was made,
     * and may have changed hands since (Google's own guidance).
     */
    public function vouchesForAddress(): bool
    {
        return $this->emailVerified
            && ($this->hostedDomain !== null || str_ends_with($this->email, '@gmail.com'));
    }

    /**
     * Google's answer, read from the OpenID claims of its userinfo (`sub`,
     * `email`, `email_verified`, `name`); null when it has no account id or
     * address.
     */
    public static function fromGoogle(SocialiteUser $user): ?self
    {
        $claims = $user instanceof AbstractUser ? $user->getRaw() : [];
        $id = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        $name = $claims['name'] ?? null;
        $hostedDomain = $claims['hd'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($email) || trim($email) === '') {
            return null;
        }

        return new self(
            id: $id,
            email: Str::lower(trim($email)),
            name: is_string($name) ? mb_substr(trim($name), 0, 255) : '',
            // A JSON boolean in Google's userinfo; anything else is not verified.
            emailVerified: ($claims['email_verified'] ?? null) === true,
            hostedDomain: is_string($hostedDomain) && $hostedDomain !== '' ? Str::lower($hostedDomain) : null,
        );
    }

    /**
     * The profile as the session keeps it until the completion page.
     *
     * @return array{id: string, email: string, name: string, email_verified: bool, hd: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'email_verified' => $this->emailVerified,
            'hd' => $this->hostedDomain,
        ];
    }

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): ?self
    {
        $id = $data['id'] ?? null;
        $email = $data['email'] ?? null;
        $name = $data['name'] ?? null;

        if (! is_string($id) || ! is_string($email) || ! is_string($name)) {
            return null;
        }

        $hostedDomain = $data['hd'] ?? null;

        return new self($id, $email, $name, ($data['email_verified'] ?? null) === true, is_string($hostedDomain) ? $hostedDomain : null);
    }
}
