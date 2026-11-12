<?php

namespace App\Http\Controllers;

use App\Support\GoogleSignIn;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The privacy notice (docs/architecture.md §7): what is stored, why, for how
 * long, how to delete it, what the board says aloud (a first name only from
 * the built-in name list, sent nowhere; ADR 016), who operates this
 * instance, a section for parents who play with their own children at home
 * (§7 "Parents and their children", ADR 013) and the note a teacher copies
 * for a class's parents.
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
            // At most this many children per parent (ADR 013).
            'childrenMax' => Config::integer('elifba.children.max'),
            // What Google sign-in shares, when this instance offers it (ADR 008).
            'googleSignIn' => GoogleSignIn::enabled(),
            'operator' => [
                'name' => is_string($name) && trim($name) !== '' ? trim($name) : null,
                'email' => is_string($email) && trim($email) !== '' ? trim($email) : null,
            ],
        ]);
    }
}
