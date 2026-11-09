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

    // Who runs this instance; shown on /privacy (§7).
    'operator' => [
        'name' => env('ELIFBA_OPERATOR_NAME'),
        'email' => env('ELIFBA_OPERATOR_EMAIL'),
    ],

];
