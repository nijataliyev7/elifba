<?php

namespace Tests\Support;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Family\Models\Child;
use App\Domain\Play\Actions\StartGame;
use App\Domain\Play\Data\GamePlayers;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Games for feature tests: started through the real setup rules and
 * `StartGame` with a fixed seed, and board events shaped like the zod
 * `BoardEvent`.
 */
final class Games
{
    /**
     * A five-round "find the letter" game with the balloons pack, unless the
     * setup says otherwise, for a class or (a home game) for a child.
     *
     * @param  array<string, mixed>  $setup
     */
    public static function start(SchoolClass|Child $for, array $setup = [], int $seed = 1): GameSession
    {
        $players = self::players($for);
        $data = GameSetupData::validate([
            'mechanic' => 'find',
            'pack' => 'balloons',
            'level' => 'letters',
            'rounds' => 5,
            ...$setup,
        ], $players);

        return app(StartGame::class)->handle($players, $data, $seed);
    }

    public static function players(SchoolClass|Child $for): GamePlayers
    {
        return $for instanceof Child ? GamePlayers::forChild($for) : GamePlayers::forClass($for);
    }

    /**
     * @return list<GameRound>
     */
    public static function rounds(GameSession $game): array
    {
        return array_values(GameRound::query()->where('game_session_id', $game->id)->orderBy('position')->get()->all());
    }

    public static function round(GameSession $game, int $position = 0): GameRound
    {
        return self::rounds($game)[$position] ?? throw new RuntimeException("The game has no round {$position}.");
    }

    /**
     * The id of the item that solves a find round.
     */
    public static function target(GameRound $round): string
    {
        return $round->payload['solution']['item_ids'][0];
    }

    /**
     * The id of a wrong item of a find round.
     */
    public static function decoy(GameRound $round, int $nth = 0): string
    {
        $decoys = array_values(array_diff(array_column($round->payload['items'], 'id'), $round->payload['solution']['item_ids']));

        return $decoys[$nth] ?? throw new RuntimeException("The round has no decoy {$nth}.");
    }

    /**
     * @return array{type: 'hit', event_id: string, round_id: string, item_id: string, source: string, student_id: string|null, client_at: string|null}
     */
    public static function hit(GameRound|string $round, string $itemId, ?string $studentId = null, ?string $eventId = null, string $source = 'board'): array
    {
        return [
            'type' => 'hit',
            'event_id' => $eventId ?? (string) Str::ulid(),
            'round_id' => $round instanceof GameRound ? $round->id : $round,
            'item_id' => $itemId,
            'source' => $source,
            'student_id' => $studentId,
            'client_at' => '2026-11-09T08:15:30.125Z',
        ];
    }

    /**
     * @return array{type: 'skip', event_id: string, round_id: string, client_at: string|null}
     */
    public static function skip(GameRound|string $round, ?string $eventId = null): array
    {
        return [
            'type' => 'skip',
            'event_id' => $eventId ?? (string) Str::ulid(),
            'round_id' => $round instanceof GameRound ? $round->id : $round,
            'client_at' => null,
        ];
    }
}
