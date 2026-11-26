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

        // The computer's seat in a board game (§12 "The computer's seat",
        // ADR 036 decisions 4–6): what each of its levels — 1 «Asan»,
        // 2 «Orta», 3 «Çətin» (`RoomComputerLevel`) — means to the search
        // (`DraughtsEngine`): the plies it looks ahead at most, the
        // positions it may look at, and how far below the best move (a man
        // is 100) a move it takes may be. The positions are a count, not a
        // time, so the same position gets the same answer on every machine;
        // `max_ms` only stops a search on a machine too slow for the count.
        'computer' => [
            'levels' => [
                1 => ['depth' => 2, 'nodes' => 4_000, 'margin' => 130],
                2 => ['depth' => 5, 'nodes' => 30_000, 'margin' => 15],
                3 => ['depth' => 14, 'nodes' => 60_000, 'margin' => 2],
            ],
            'max_ms' => 3_000,

            // Domino «101» (§12 "Domino (Y4–Y6)" → "The computer", ADR 038
            // decision 55): «Asan» plays one of its plays at random, «Orta»
            // the one that sheds the most pips; «Çətin» deals the tiles it
            // has not seen `deals` times, in ways that agree with every pass
            // and draw it saw, and plays each of its plays out to the hand's
            // end in each (`DominoEngine`). A count, never a time: the same
            // view gets the same play on every machine.
            'domino' => [
                3 => ['deals' => 40],
            ],

            // Its move is stored no sooner than this after its turn began
            // (ADR 036 decision 8), so a page draws the player's own move
            // before the answer arrives.
            'think_ms' => 800,
        ],

        // A domino game (§12 "Domino", ADR 038 decisions 14, 24–28): the
        // server plays a turn for a computer's seat, a seat whose player
        // left and an away seat no sooner than `think_ms` after the turn
        // began, the same time whatever the seat holds; a seat is away
        // after `away_after` turns in a row were played for it; a game
        // copies `sheet_ms`, the pause on a hand's sheet before the next
        // hand opens, as `room_games.reveal_ms` at its start. `offered`
        // offers «101» (`RoomMechanic::offered()`) before Y6 draws it: false
        // here, true only in the tests that play it; Y6 removes the key (ADR
        // 038 as amended 2026-11-26, Y5).
        'domino' => [
            'offered' => false,
            'think_ms' => 1_200,
            'away_after' => 2,
            'sheet_ms' => 10_000,
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
