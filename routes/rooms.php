<?php

use App\Enums\Role;
use App\Http\Controllers\Rooms\RoomAnswerController;
use App\Http\Controllers\Rooms\RoomCloseController;
use App\Http\Controllers\Rooms\RoomController;
use App\Http\Controllers\Rooms\RoomGameController;
use App\Http\Controllers\Rooms\RoomInvitedController;
use App\Http\Controllers\Rooms\RoomJoinController;
use App\Http\Controllers\Rooms\RoomLeaveController;
use App\Http\Controllers\Rooms\RoomMatchController;
use App\Http\Controllers\Rooms\RoomMemberController;
use App\Http\Controllers\Rooms\RoomMemberVoiceController;
use App\Http\Controllers\Rooms\RoomNameController;
use App\Http\Controllers\Rooms\RoomPlayerVoiceController;
use App\Http\Controllers\Rooms\RoomPlaysController;
use App\Http\Controllers\Rooms\RoomScreenController;
use App\Http\Controllers\Rooms\RoomSetupController;
use App\Http\Controllers\Rooms\RoomStateController;
use App\Http\Controllers\Rooms\RoomTauntController;
use App\Http\Controllers\Rooms\RoomVoiceController;
use App\Http\Controllers\Rooms\RoomVoiceTicketController;
use Illuminate\Support\Facades\Route;

/*
 * The player area: adults' games and their rooms (docs/architecture.md §1
 * "Accounts and roles", §12 "The area and the switch", "Rooms and joining",
 * "Games first", ADRs 019, 020 and 026). Every room route is here and in no other group, and nothing
 * here is a class, a student, a child or a game of theirs. `rooms.enabled`
 * comes first: while the instance has the area switched off, every route
 * below is a 404 to everyone, a visitor too. An account without the player
 * role is sent to take it, and back (`RoomsArea::toThePlayerRole`).
 *
 * Isolation as in routes/classroom.php: `{room}` binds only a room the
 * current account hosts or has a row in that the host did not remove
 * (`Room::resolveRouteBinding`, 404 before any controller code), `{member}`
 * only a row of that room (`scopeBindings()`), and every such route passes
 * `RoomPolicy` (`->can()`). Both are ULIDs; a name never goes into a URL. A
 * new route with `{room}` or `{member}` must be added to
 * tests/Feature/Classroom/TeacherIsolationTest.php, which fails otherwise.
 *
 * The one exception is the invitation's door (§12 "The invitation link
 * (I2)", ADR 033 decision 11), at the end of this file: a visitor who
 * opened a room's link by hand makes an account there, with the player
 * role, so it is a guest route — `rooms.enabled` and `guest`, no `auth` —
 * and it reads no room: the page is one and the same for any code.
 */
Route::middleware(['rooms.enabled', 'auth', 'role.chosen', 'verified', Role::middleware(Role::Player)])->group(function () {
    // «Oyunlar», the area's home: the games, the account's open rooms, the code field.
    Route::get('rooms', [RoomController::class, 'index'])->name('rooms.index');
    // A game chosen: the room opens behind it, or the host's open room takes it (G2).
    Route::post('rooms', [RoomController::class, 'store'])
        ->middleware('throttle:rooms-open')
        ->name('rooms.store');
    // «Keçmiş oyunlar»: the rooms the account was in (G2).
    Route::get('rooms/past', [RoomController::class, 'past'])->name('rooms.past');

    // The entrance with a code, `/oyun` (G2): 10 tries of a code a minute per account, the three ways together.
    Route::middleware('throttle:room-join')->group(function () {
        Route::get('oyun', [RoomJoinController::class, 'entrance'])->name('rooms.entrance');
        // A link opened by hand is kept before `auth` (ADR 033 decision 8): `rooms.invitation`.
        Route::get('oyun/{code}', [RoomJoinController::class, 'show'])
            ->where('code', '[0-9]{6}')
            ->middleware('rooms.invitation')
            ->name('rooms.join');
        Route::post('oyun/{code}', [RoomJoinController::class, 'store'])
            ->where('code', '[0-9]{6}')
            ->name('rooms.join.store');
    });

    // The entrance's address before G2: permanent redirects, a query kept; nothing posts here.
    Route::get('otaq', [RoomJoinController::class, 'oldEntrance'])->name('rooms.entrance.old');
    Route::get('otaq/{code}', [RoomJoinController::class, 'oldJoin'])
        ->where('code', '[0-9]{6}')
        ->name('rooms.join.old');

    Route::get('rooms/{room}', [RoomController::class, 'show'])
        ->whereUlid('room')
        ->can('view', 'room')
        ->name('rooms.show');
    Route::get('rooms/{room}/state', RoomStateController::class)
        ->whereUlid('room')
        ->middleware('throttle:room-state')
        ->can('watch', 'room')
        ->name('rooms.state');
    Route::post('rooms/{room}/leave', RoomLeaveController::class)
        ->whereUlid('room')
        ->can('play', 'room')
        ->name('rooms.leave');

    // The big screen: the host's own session on a laptop or a television (B4).
    Route::get('rooms/{room}/screen', RoomScreenController::class)
        ->whereUlid('room')
        ->can('screen', 'room')
        ->name('rooms.screen');

    // The final's winner, said after the fanfare by the screen — and by every phone in a room
    // without a screen: a member's first-name clip, by ids only, for the host and the present
    // players of an open room (ADR 030 as amended 2026-11-19).
    Route::get('rooms/{room}/members/{member}/voice', RoomMemberVoiceController::class)
        ->whereUlid(['room', 'member'])
        ->scopeBindings()
        ->can('hear', 'room')
        ->name('rooms.members.voice');

    // A present player's own answer to the round open now (B3).
    Route::post('rooms/{room}/answers', RoomAnswerController::class)
        ->whereUlid('room')
        ->middleware('throttle:room-answers')
        ->can('play', 'room')
        ->name('rooms.answers.store');

    // A present player's own taunt at another, for a point of their own (T2, ADR 029).
    Route::post('rooms/{room}/taunts', [RoomTauntController::class, 'store'])
        ->whereUlid('room')
        ->middleware('throttle:room-taunts')
        ->can('play', 'room')
        ->name('rooms.taunts.store');

    // The host's tools.
    Route::patch('rooms/{room}', [RoomController::class, 'update'])
        ->whereUlid('room')
        ->can('host', 'room')
        ->name('rooms.update');
    Route::delete('rooms/{room}/members/{member}', [RoomMemberController::class, 'destroy'])
        ->whereUlid(['room', 'member'])
        ->scopeBindings()
        ->can('remove', ['room', 'member'])
        ->name('rooms.members.destroy');
    Route::post('rooms/{room}/close', RoomCloseController::class)
        ->whereUlid('room')
        ->can('host', 'room')
        ->name('rooms.close');

    // The host's choice of the next game and «Mən də oynayıram», from the phone or the screen (G2).
    Route::put('rooms/{room}/setup', RoomSetupController::class)
        ->whereUlid('room')
        ->can('start', 'room')
        ->name('rooms.setup.update');
    Route::put('rooms/{room}/plays', RoomPlaysController::class)
        ->whereUlid('room')
        ->can('start', 'room')
        ->name('rooms.plays.update');

    // The host's switch «Atmacalar», from the phone or the screen, at any time in an open room (T2).
    Route::put('rooms/{room}/taunts', [RoomTauntController::class, 'update'])
        ->whereUlid('room')
        ->can('host', 'room')
        ->name('rooms.taunts.update');

    // Live voice (C3, ADR 031): behind `rooms.voice` as well, a 404 to everyone
    // while the instance's voice is off (it runs right after `rooms.enabled`).
    Route::middleware('rooms.voice')->group(function () {
        // The host's switch «Canlı səs», at any moment in an open room.
        Route::put('rooms/{room}/voice', RoomVoiceController::class)
            ->whereUlid('room')
            ->can('voice', 'room')
            ->name('rooms.voice.update');
        // The host admits, mutes or lets speak one player — never the host's own row.
        Route::put('rooms/{room}/members/{member}/voice', RoomPlayerVoiceController::class)
            ->whereUlid(['room', 'member'])
            ->scopeBindings()
            ->can('voice', ['room', 'member'])
            ->name('rooms.members.voice.update');
        // A ticket to the room's voice room: the host, or a present player let in.
        Route::post('rooms/{room}/voice/tickets', RoomVoiceTicketController::class)
            ->whereUlid('room')
            ->middleware('throttle:room-voice')
            ->can('listen', 'room')
            ->name('rooms.voice.tickets.store');
    });

    // A game: the room's choice started in an open room with none running, ended while it runs (B3, G2).
    Route::post('rooms/{room}/games', [RoomGameController::class, 'store'])
        ->whereUlid('room')
        ->can('start', 'room')
        ->name('rooms.games.store');
    Route::delete('rooms/{room}/game', [RoomGameController::class, 'destroy'])
        ->whereUlid('room')
        ->can('end', 'room')
        ->name('rooms.game.destroy');

    // A closed room: the host deletes it, a player takes their name out.
    // Board games (ADR 034): the match of a room opened for one.
    Route::get('rooms/{room}/match', [RoomMatchController::class, 'show'])
        ->whereUlid('room')
        ->middleware('throttle:room-state')
        ->can('watch', 'room')
        ->name('rooms.match.show');
    Route::post('rooms/{room}/match', [RoomMatchController::class, 'store'])
        ->whereUlid('room')
        ->can('startMatch', 'room')
        ->name('rooms.match.store');
    Route::delete('rooms/{room}/match', [RoomMatchController::class, 'destroy'])
        ->whereUlid('room')
        ->can('endMatch', 'room')
        ->name('rooms.match.destroy');
    Route::post('rooms/{room}/match/moves', [RoomMatchController::class, 'move'])
        ->whereUlid('room')
        ->middleware('throttle:room-answers')
        ->can('play', 'room')
        ->name('rooms.match.moves.store');
    Route::post('rooms/{room}/match/resign', [RoomMatchController::class, 'resign'])
        ->whereUlid('room')
        ->can('play', 'room')
        ->name('rooms.match.resign');
    Route::post('rooms/{room}/match/taunts', [RoomMatchController::class, 'taunt'])
        ->whereUlid('room')
        ->middleware('throttle:room-taunts')
        ->can('play', 'room')
        ->name('rooms.match.taunts.store');

    Route::delete('rooms/{room}', [RoomController::class, 'destroy'])
        ->whereUlid('room')
        ->can('delete', 'room')
        ->name('rooms.destroy');
    Route::delete('rooms/{room}/name', [RoomNameController::class, 'destroy'])
        ->whereUlid('room')
        ->can('forget', 'room')
        ->name('rooms.name.destroy');
});

// The invitation's door: a visitor's account with the player role, then the link (I2, ADR 033).
Route::middleware(['rooms.enabled', 'guest'])->group(function () {
    Route::get('oyun/qeydiyyat', [RoomInvitedController::class, 'create'])->name('rooms.invited');
    Route::post('oyun/qeydiyyat', [RoomInvitedController::class, 'store'])
        ->middleware('throttle:room-invited')
        ->name('rooms.invited.store');
});
