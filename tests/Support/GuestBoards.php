<?php

namespace Tests\Support;

use App\Domain\Play\Actions\ClaimGuestBoard;
use App\Domain\Play\Actions\OpenGuestBoard;
use App\Domain\Play\Data\GuestBoardGameData;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Models\GuestBoard;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;

/**
 * Guest boards for feature tests: opened and claimed through the real
 * actions, and the bearer headers a guest board's tab sends.
 */
final class GuestBoards
{
    /**
     * An open, unclaimed board and its bearer token.
     *
     * @return array{board: GuestBoard, token: string}
     */
    public static function open(): array
    {
        $opened = app(OpenGuestBoard::class)->handle();

        return ['board' => $opened->board, 'token' => $opened->token];
    }

    /**
     * A board the game's teacher claimed, showing that game.
     *
     * @return array{board: GuestBoard, token: string}
     */
    public static function showing(GameSession $game): array
    {
        $opened = self::open();
        $teacher = User::query()->findOrFail($game->user_id);

        app(ClaimGuestBoard::class)->handle($opened['board'], $teacher, GuestBoardGameData::existing($game));

        return ['board' => $opened['board']->refresh(), 'token' => $opened['token']];
    }

    /**
     * The headers of the board's `fetch` calls.
     *
     * @return array<string, string>
     */
    public static function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    /**
     * See TestCase::call(): a new request starts without the last bearer.
     */
    public static function forgetBearer(Application $app): void
    {
        $auth = $app->make('auth');
        $auth->guard('board')->forgetUser();

        if ($auth->getDefaultDriver() === 'board') {
            $auth->shouldUse('web');
        }
    }
}
