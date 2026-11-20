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

    // Adults' rooms (§12 "The area and the switch", ADR 019): a separate
    // area behind a switch, off unless ELIFBA_ROOMS is set. Read it only
    // through App\Support\RoomsArea. The tables and the `player` role row
    // exist either way.
    'rooms' => [
        'enabled' => filter_var(env('ELIFBA_ROOMS', false), FILTER_VALIDATE_BOOL),

        // Present players in one room at most (§12 "Rooms and joining").
        'max_players' => 20,

        // How long a link opened by hand is kept in the browser's session to
        // join at the end of a login, a registration or a confirmation
        // (§12 "The invitation link (I2)", ADR 033 decision 8): long enough
        // to read the mail, short enough that a forgotten tab joins no game
        // an evening later.
        'invitation_minutes' => 60,

        // A room with no join, game or answer for this long is closed: it
        // counts as closed at once (`Room::isOpen()`), and `rooms:close-idle`
        // writes it within the hour.
        'idle_hours' => 3,

        // A room game's rounds (§12 "The race", ADR 021): how many the host
        // may choose; the countdown before round 0 opens; how long the
        // solution, the round's results and the ranking stay on screen
        // after a round closes. A game copies `reveal_ms` when it starts
        // (`room_games.reveal_ms`), so a change never moves a live game.
        'rounds' => [
            'min' => 5,
            'max' => 20,
        ],
        'countdown_ms' => 3_000,
        'reveal_ms' => 3_000,

        // A new room's difficulty and rounds when the host has no game to
        // take them from (§12 "Games first", ADR 026 decision 10: derived
        // by `RoomDefaults` at each opening, never stored on the account).
        'defaults' => [
            'difficulty' => 2,
            'rounds' => 10,
        ],

        // Taunts (§12 "Taunts (T2)", ADR 029): one thrower throws at most one
        // per `cooldown_ms`, by the server's clock; a taunt is in its target's
        // and the screen's state for `show_ms` after the throw; and whom it
        // was aimed at is emptied `forget_target_hours` after it
        // (`PruneOldRooms`). The price is `RoomPointsRule::TAUNT_COST`, never
        // config.
        // Board games (ADR 034): each player's time in a checkers match.
        'checkers' => [
            'clock_ms' => 300_000,
        ],

        'taunts' => [
            'cooldown_ms' => 10_000,
            'show_ms' => 4_000,
            'forget_target_hours' => 24,
        ],

        // The big screen (§12 "A room without a screen", ADR 028 as amended
        // 2026-11-19): while it is shown it reads the state at least every
        // `beat_ms`, and a room whose screen has not read within three
        // beats (`RoomScreen::goneMs()`) has none — then every phone plays
        // the room's cues too.
        'screen' => [
            'beat_ms' => 10_000,
        ],

        // Live voice (§12 "Live voice", ADR 031 decision 28): the switch,
        // false unless set, read only through `RoomsArea::voice()`; the voice
        // server's API key and secret (also its `LIVEKIT_KEYS`), which sign
        // the tickets and the app's own calls and never reach a page; the
        // address a browser opens and the one the app calls. A ticket lets
        // its holder join for `ticket_seconds`; a call to the voice server
        // connects and answers within `timeout_ms`, or the write it follows
        // goes on without it; at most `max_rooms` voice rooms are in use at
        // once — counted at the ticket, among voice rooms someone is in
        // (ADR 031 decision 38, as amended 2026-11-19).
        'voice' => [
            'enabled' => filter_var(env('ELIFBA_ROOMS_VOICE', false), FILTER_VALIDATE_BOOL),
            'key' => env('ELIFBA_ROOMS_VOICE_KEY') ?: null,
            'secret' => env('ELIFBA_ROOMS_VOICE_SECRET') ?: null,
            'url' => env('ELIFBA_ROOMS_VOICE_URL') ?: null,
            'api_url' => env('ELIFBA_ROOMS_VOICE_API_URL') ?: null,
            'ticket_seconds' => 60,
            'timeout_ms' => 1_500,
            'max_rooms' => 5,
        ],
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
