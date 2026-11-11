<?php

use App\Enums\Role;
use App\Http\Controllers\Play\BoardController;
use App\Http\Controllers\Play\GameController;
use App\Http\Controllers\Play\GameStateController;
use App\Http\Controllers\Play\GameStatusController;
use App\Http\Controllers\Play\GameSyncController;
use App\Http\Controllers\Play\GuestBoardController;
use App\Http\Controllers\Play\GuestBoardGameController;
use App\Http\Controllers\Play\JoinCodeController;
use App\Http\Controllers\Play\PultController;
use App\Http\Controllers\Play\RemoteController;
use App\Http\Middleware\ThrottleBoardEvents;
use Illuminate\Support\Facades\Route;

/*
 * Games, the boards, the remote and pairing (docs/architecture.md §2, §4,
 * §11). Isolation as in routes/classroom.php: `{class}`, `{child}` and
 * `{game}` bind only the current account's rows (`BelongsToOwner`, 404
 * before any controller code) and every route passes its policy
 * (`->can()`). Every route with `{class}`, `{child}` or `{game}` is in
 * tests/Feature/Classroom/TeacherIsolationTest.php.
 *
 * Two board modes, one protocol (§4): the board endpoints take the
 * teacher's session or a guest board's bearer (`auth:board,web` — a bearer
 * sent with the request wins over a session cookie in the same browser).
 * For a bearer, `{game}` binds only the game its board shows and the
 * policy's `play` ability is the only one it passes. Every other route
 * answers a bearer like a guest (tests/Feature/Play/BearerReachTest.php).
 *
 * Areas (§1 "Accounts and roles", ADR 010): a class's game is set up in
 * the teacher area, a child's home game (ADR 011) in the parent area; the
 * board page, the join code, the remote, `/pult` and a guest board's next
 * game are shared by both roles; the routes a bearer may call name no
 * role, because a bearer is not an account.
 */

// A class's game: the teacher area.
Route::middleware(['auth', 'role.chosen', 'verified', Role::middleware(Role::Teacher)])->group(function () {
    Route::get('classes/{class}/games/create', [GameController::class, 'create'])
        ->can('view', 'class')
        ->name('classes.games.create');
    Route::post('classes/{class}/games', [GameController::class, 'store'])
        ->can('update', 'class')
        ->name('classes.games.store');
});

// A child's home game: the parent area.
Route::middleware(['auth', 'role.chosen', 'verified', Role::middleware(Role::Parent)])->group(function () {
    Route::get('children/{child}/games/create', [GameController::class, 'createForChild'])
        ->whereUlid('child')
        ->can('view', 'child')
        ->name('children.games.create');
    Route::post('children/{child}/games', [GameController::class, 'storeForChild'])
        ->whereUlid('child')
        ->can('update', 'child')
        ->name('children.games.store');
});

// The board, the remote and pairing: an account's web session, either role.
Route::middleware(['auth', 'role.chosen', 'verified', Role::middleware(Role::Teacher, Role::Parent)])->group(function () {
    Route::get('games/{game}/board', [BoardController::class, 'show'])
        ->can('view', 'game')
        ->name('games.board');
    Route::post('games/{game}/join-code', JoinCodeController::class)
        ->can('update', 'game')
        ->name('games.join-code');
    Route::get('games/{game}/remote', RemoteController::class)
        ->can('update', 'game')
        ->name('games.remote');

    // Pairing from the phone: 10 a minute per teacher, all three together.
    Route::middleware('throttle:pult')->group(function () {
        Route::get('pult', [PultController::class, 'index'])->name('pult');
        Route::get('pult/{code}', [PultController::class, 'show'])
            ->whereNumber('code')
            ->name('pult.code');
        Route::post('pult/{code}', [PultController::class, 'store'])
            ->whereNumber('code')
            ->name('pult.claim');
    });

    // The next game on a guest board this teacher claimed.
    Route::get('guest-boards/{board}/game', [GuestBoardGameController::class, 'edit'])
        ->can('show', 'board')
        ->name('guest-boards.game.edit');
    Route::put('guest-boards/{board}/game', [GuestBoardGameController::class, 'update'])
        ->can('show', 'board')
        ->name('guest-boards.game.update');
});

// What a board does: the teacher's session or the guest board's bearer.
Route::middleware(['auth:board,web', 'verified'])->group(function () {
    Route::post('games/{game}/board/claim', [BoardController::class, 'claim'])
        ->can('play', 'game')
        ->name('games.board.claim');
    Route::get('games/{game}/state', GameStateController::class)
        ->can('play', 'game')
        ->name('games.state');
    Route::post('games/{game}/sync', GameSyncController::class)
        ->can('play', 'game')
        ->middleware(ThrottleBoardEvents::class)
        ->name('games.sync');
    Route::patch('games/{game}/status', GameStatusController::class)
        ->can('play', 'game')
        ->name('games.status.update');

    Route::delete('guest-boards/{board}', [GuestBoardController::class, 'destroy'])
        ->can('close', 'board')
        ->name('guest-boards.destroy');
});

// The guest board polls for its game (every 2 s): its bearer only, with
// its own limit, apart from the one for opening boards.
Route::get('guest-boards/{board}', [GuestBoardController::class, 'show'])
    ->middleware(['auth:board', 'throttle:guest-board'])
    ->can('view', 'board')
    ->name('guest-boards.show');

// A shared school computer: no login, ever (§4).
Route::inertia('lovhe', 'lovhe')->name('lovhe');
Route::post('guest-boards', [GuestBoardController::class, 'store'])
    ->middleware('throttle:guest-boards')
    ->name('guest-boards.store');
