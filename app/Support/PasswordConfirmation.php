<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;

/**
 * Whether the teacher confirmed who they are recently — with their password
 * or Google (ADR 008) — counted as Laravel's `RequirePassword` middleware
 * counts it.
 */
final class PasswordConfirmation
{
    public static function isRecent(Request $request): bool
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');

        if (! is_int($confirmedAt) && ! (is_string($confirmedAt) && ctype_digit($confirmedAt))) {
            return false;
        }

        return Date::now()->unix() - (int) $confirmedAt <= Config::integer('auth.password_timeout', 10800);
    }
}
