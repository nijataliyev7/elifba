<?php

/*
 * Elifba's own settings. docs/architecture.md explains each value; the code
 * reads them through config('elifba.*'), never through env() directly.
 */

return [

    // School dates (points, daily and weekly rankings) are Baku dates (§5).
    'timezone' => 'Asia/Baku',

    // Interface languages: code => native name. The first is the default and
    // the base of the translation parity test (§9).
    'locales' => [
        'az' => 'Azərbaycanca',
        'en' => 'English',
    ],

    // Archived classes and students, and old games, are deleted after this (§7).
    'retention_days' => 400,

    // Pairing codes for the remote and guest boards (§4).
    'pairing_code_ttl_minutes' => 10,

    // A guest board closes itself after this many hours (§4).
    'guest_board_hours' => 12,

    // A live or paused game with no activity for this long is abandoned (§1).
    'abandon_after_hours' => 6,

    // Rounds per game (§2).
    'rounds' => [
        'min' => 5,
        'max' => 30,
        'default' => 10,
    ],

    // Teachers' own words, played in every theme pack (§3 "Teacher words",
    // ADR 007, ADR 009): the longest word, in characters (at most 32, the
    // column), and how many words one teacher may keep.
    'custom_words' => [
        'max_length' => 20,
        'max_per_teacher' => 200,
    ],

    // A parent's own children (§1, §7 "Parents and their children", ADR 011,
    // ADR 013): a family fits, a class does not.
    'children' => [
        'max' => 10,
    ],

    // The board's voice (§3 "Voice", ADR 015): only `php artisan
    // content:voice` reads the key, on the machine that makes the clips once
    // and commits them. An instance never needs it, so `.env.example` does
    // not name it: put it in your shell or the gitignored `.env`, and never
    // run `config:cache` while it is there.
    'voice' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
        'api_url' => 'https://api.elevenlabs.io',
    ],

    // Who runs this instance; shown on /privacy (§7).
    'operator' => [
        'name' => env('ELIFBA_OPERATOR_NAME'),
        'email' => env('ELIFBA_OPERATOR_EMAIL'),
    ],

    // Where the source code lives: the welcome page's GitHub link
    // (docs/frontend.md rule 10). A fork points it at its own repository.
    'source_url' => env('ELIFBA_SOURCE_URL') ?: 'https://github.com/nijataliyev7/elifba',

];
