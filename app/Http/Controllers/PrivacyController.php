<?php

namespace App\Http\Controllers;

use App\Support\GoogleSignIn;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The privacy notice (docs/architecture.md §7): what is stored, why, for how
 * long, how to delete it, who operates this instance, and a note for parents.
 * Public, in every interface language.
 */
final class PrivacyController extends Controller
{
    public function __invoke(): Response
    {
        $name = Config::get('elifba.operator.name');
        $email = Config::get('elifba.operator.email');

        return Inertia::render('privacy', [
            'retentionDays' => Config::integer('elifba.retention_days'),
            // What Google sign-in shares, when this instance offers it (ADR 008).
            'googleSignIn' => GoogleSignIn::enabled(),
            'operator' => [
                'name' => is_string($name) && trim($name) !== '' ? trim($name) : null,
                'email' => is_string($email) && trim($email) !== '' ? trim($email) : null,
            ],
        ]);
    }
}
