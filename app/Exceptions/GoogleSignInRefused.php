<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Google answered, but Elifba does not let this Google account in (ADR 008).
 * The reason is a translation key: the message carries no address or name.
 */
final class GoogleSignInRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
