<?php

namespace Database\Factories;

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Enums\RoomComputerLevel;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomPlayable;
use App\Domain\Rooms\Support\RoomSeed;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Config;

/**
 * A room game started now at `recall_order` «Asan», the next game of its
 * room (an open room of its own unless `for()` names one): round 0 opens after `elifba.rooms.countdown_ms`, and the game keeps
 * the configured `reveal_ms`. It has no rounds unless `withRounds()` builds
 * them, from the game's level and seed with the mechanic's builder, as the
 * start of a game does. `ended()` gives it the host's end.
 *
 * Its phase at any moment is its timetable's (`$game->timetable()`); a
 * test travels to one with `$this->travelTo(RoomClock::at($ms))`.
 *
 * @extends Factory<RoomGame>
 */
class RoomGameFactory extends Factory
{
    /**
     * @var class-string<RoomGame>
     */
    protected $model = RoomGame::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            // the room's next game
            'position' => fn (array $attributes): int => RoomGame::query()->where('room_id', $attributes['room_id'])->count(),
            'mechanic' => RoomMechanic::RecallOrder,
            'difficulty' => 1,
            'content_version' => app(ContentRepository::class)->roomVersion(),
            'seed' => fake()->numberBetween(1, RoomGame::MAX_SEED),
            'reveal_ms' => Config::integer('elifba.rooms.reveal_ms'),
            'started_ms' => RoomClock::nowMs() + Config::integer('elifba.rooms.countdown_ms'),
            'ended_ms' => null,
            'created_at' => now(),
        ];
    }

    /**
     * Builds `$count` rounds from the game's level and seed, from the
     * source `StartRoomGame` uses (`RoomSeed`).
     */
    public function withRounds(int $count = 5): static
    {
        return $this->afterCreating(static function (RoomGame $game) use ($count): void {
            $level = app(ContentRepository::class)->roomLevel($game->mechanic, $game->difficulty);
            $built = RoomPlayable::builder($game->mechanic)->build($level, $count, RoomSeed::random($game->seed));

            foreach ($built as $position => $round) {
                RoomRound::factory()->for($game, 'game')->built($round)->create(['position' => $position]);
            }

            $game->unsetRelation('rounds');
        });
    }

    /**
     * A board game of draughts (ADR 034) at `$difficulty`, its two seats and
     * its level's clock on the row as `StartRoomGame` copies them; no
     * rounds, seed or reveal. Its clock starts after the countdown.
     */
    public function draughts(RoomPlayer $white, RoomPlayer $black, int $difficulty = 2): static
    {
        $level = app(ContentRepository::class)->roomLevel(RoomMechanic::Draughts, $difficulty);

        return $this->state(fn (array $attributes) => [
            'room_id' => $white->room_id,
            'mechanic' => RoomMechanic::Draughts,
            'difficulty' => $difficulty,
            'seed' => 0,
            'reveal_ms' => 0,
            'white_player_id' => $white->id,
            'black_player_id' => $black->id,
            'clock_ms' => $level->clockMs,
            'increment_ms' => $level->incrementMs,
        ]);
    }

    /**
     * A match against the computer (ADR 036) at its `$level`: `$player` on
     * white — on black when `$white` is false — and the other seat nobody's.
     */
    public function againstComputer(RoomPlayer $player, RoomComputerLevel $level = RoomComputerLevel::Medium, bool $white = true, int $difficulty = 2): static
    {
        return $this->draughts($player, $player, $difficulty)->state(fn (array $attributes) => [
            'white_player_id' => $white ? $player->id : null,
            'black_player_id' => $white ? null : $player->id,
            'computer' => $level,
        ]);
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'ended_ms' => RoomClock::nowMs(),
        ]);
    }
}
