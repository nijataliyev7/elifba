<?php

namespace App\Domain\Rooms\Support\Drivers;

use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Data\RoomActData;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Data\RoomMineData;
use App\Domain\Rooms\Data\RoomMomentData;
use App\Domain\Rooms\Data\RoomResultData;
use App\Domain\Rooms\Data\RoomRevealData;
use App\Domain\Rooms\Data\RoomRoundData;
use App\Domain\Rooms\Data\RoomSpentData;
use App\Domain\Rooms\Enums\RoomActStatus;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAnswer;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Support\RoomLetters;
use App\Domain\Rooms\Support\RoomPlayable;
use App\Domain\Rooms\Support\RoomRanking;
use App\Domain\Rooms\Support\RoomRoundClose;
use App\Domain\Rooms\Support\RoomSeed;
use App\Domain\Rooms\Support\RoomTauntCost;
use App\Domain\Rooms\Support\RoomWaits;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;

/**
 * A race (docs/architecture.md §12 "The race", "Room games", ADRs 021–023,
 * 029): everyone answers the same timed rounds at once. The code a room
 * had for its games before D3, moved here unchanged (ADR 034 decision 3):
 * the choice has its rounds; the start builds and stores every round from a
 * fresh seed; the timetable says when the game is over; the answers of its
 * closed rounds are what each earned, and a taunt's cost counts at the
 * first close after it (`RoomTauntCost`); any present player may taunt any
 * other, paying from the points of closed rounds; the state's part is the
 * current round; a presence that changed may close the round open for
 * answers early (`RoomRoundClose`); a page's read tells a round whom to
 * wait for (`RoomWaits`). A race takes no act.
 */
final class RaceDriver extends RoomGameDriver
{
    public function choiceRules(bool $kept = false): array
    {
        return [
            // Left out of a race chosen after a board game: the room's own number then (`Room::roundsKept()`).
            'rounds' => [$kept ? 'sometimes' : 'required', 'integer', 'between:'.Config::integer('elifba.rooms.rounds.min').','.Config::integer('elifba.rooms.rounds.max')],
        ];
    }

    /**
     * Builds every round with the mechanic's builder from a fresh seed
     * (`RoomSeed`), stores each as its `payload` and its `solution`, and
     * keeps the configured `reveal_ms` as the game's.
     */
    public function start(Room $locked, RoomGame $game, RoomGameSetupData $setup): void
    {
        $content = app(ContentRepository::class);
        $seed = RoomSeed::fresh();
        $built = RoomPlayable::builder($setup->mechanic)->build(
            $content->roomLevel($setup->mechanic, $setup->difficulty),
            (int) $setup->rounds,
            RoomSeed::random($seed),
        );

        $game->seed = $seed;
        $game->reveal_ms = Config::integer('elifba.rooms.reveal_ms');
        $game->save();

        foreach ($built as $position => $round) {
            $row = new RoomRound;
            $row->room_game_id = $game->id;
            $row->position = $position;
            $row->payload = $round->payload();
            $row->solution = $round->solution->toArray();
            $row->study_ms = $round->studyMs;
            $row->answer_ms = $round->answerMs;
            $row->save();
        }
    }

    public function isOver(RoomGame $game, int $nowMs): bool
    {
        return $game->timetable()->moment($nowMs)->isOver();
    }

    /**
     * The points of the player's answers over the game's closed rounds
     * (`RoomRanking::closedRounds()`); the rounds' answers are read unless
     * they are loaded with them.
     */
    public function earned(RoomGame $game, int $nowMs): array
    {
        $closed = RoomRanking::closedRounds($game, $nowMs);
        $points = [];

        if ($closed === []) {
            return $points;
        }

        $loaded = $game->rounds->every(static fn (RoomRound $round): bool => $round->relationLoaded('answers'));
        $answers = $loaded
            ? $game->rounds->whereIn('id', $closed)->flatMap(static fn (RoomRound $round): Collection => $round->answers)
            : RoomAnswer::query()->whereIn('room_round_id', $closed)->get(['room_player_id', 'points']);

        foreach ($answers as $answer) {
            $points[$answer->room_player_id] = ($points[$answer->room_player_id] ?? 0) + $answer->points;
        }

        return $points;
    }

    /**
     * Each taunt from the first close of the game after it, and every one
     * once the game is over (`RoomTauntCost::counted()`).
     */
    public function tauntCounts(RoomGame $game, int $nowMs): Closure
    {
        $timetable = $game->timetable();

        return static fn (int $sentMs): bool => RoomTauntCost::counted($timetable, $sentMs, $nowMs);
    }

    /**
     * From the game's start — the countdown included — until its last
     * round closes: never in the last reveal, whose taunt would count after
     * the last close and change the final the room is about to see.
     */
    public function tauntsOpen(RoomGame $game, int $nowMs): bool
    {
        $timetable = $game->timetable();

        return $timetable->count() > 0
            && ! $timetable->moment($nowMs)->isOver()
            && $nowMs < $timetable->closes($timetable->count() - 1);
    }

    public function mayThrow(RoomGame $game, RoomPlayer $thrower): bool
    {
        return true;
    }

    public function mayAim(RoomGame $game, RoomPlayer $thrower, string $targetId): bool
    {
        return true;
    }

    /**
     * The points of the player's answers over the game's closed rounds: the
     * open round's are never read, so the −1 a thrower sees at once is the
     * same whatever anybody answered, and every budget is 0 until the first
     * round closes.
     */
    public function tauntBudget(RoomGame $game, RoomPlayer $row, int $nowMs): int
    {
        $rounds = RoomRanking::closedRounds($game, $nowMs);

        return $rounds === [] ? 0 : (int) RoomAnswer::query()
            ->whereIn('room_round_id', $rounds)
            ->where('room_player_id', $row->id)
            ->sum('points');
    }

    /**
     * The timetable's moment — over in a room that is not open — and, while
     * the game is live, its current round as the phase allows.
     */
    public function part(Room $room, RoomGame $game, Collection $rows, ?RoomPlayer $mine, int $nowMs, bool $screen = false): array
    {
        $moment = $room->isOpen() ? $game->timetable()->moment($nowMs) : RoomMomentData::over();
        $live = ! $moment->isOver();

        return ['live' => $live, 'round' => $live ? self::round($game, $moment, $rows, $mine, $screen) : null, 'match' => null, 'domino' => null];
    }

    public function act(RoomGame $game, RoomPlayer $row, RoomActData $act, int $nowMs): array
    {
        return [RoomActStatus::Late, null];
    }

    public function whenPresenceChanges(Room $locked, RoomGame $game, RoomPlayer $row, int $nowMs): void
    {
        RoomRoundClose::whenPresenceChanges($locked, $game, $nowMs);
    }

    /** A race has no seat for the computer (ADR 036 "What is not built"). */
    public function computerTurn(RoomGame $game, int $nowMs): ?array
    {
        return null;
    }

    public function computerChoice(RoomGame $game, int $nowMs): ?array
    {
        return null;
    }

    public function readCountsFrom(RoomGame $game, int $nowMs): ?int
    {
        return RoomWaits::readCountsFrom($game, $nowMs);
    }

    public function listed(RoomGame $game, int $nowMs, bool $rounds): array
    {
        return $rounds ? ['rounds' => $game->rounds->count()] : [];
    }

    /**
     * The current round of a live game, as its phase allows: its material
     * while it is studied, its question from the answer phase, and the
     * solution, the results and its points only in the reveal. A round's
     * `answered_count` of `present_count` counts the present players it
     * waits for (`RoomWaits`), as the early close does, so a full count is
     * a closed round. A reveal lists the taunts counted at its close.
     *
     * From ADR 037 a present player's own state of a round that sells
     * letters carries what that player bought (`letters`, decisions 13–16)
     * while it takes answers, and the reveal's `mine` how many. The screen
     * reads with the host's account but is nobody's phone: it gets neither,
     * and no `mine` at all, so it never says whether the host bought.
     *
     * @param  Collection<int, RoomPlayer>  $rows  the room's players not removed, in order
     */
    private static function round(RoomGame $game, RoomMomentData $moment, Collection $rows, ?RoomPlayer $mine, bool $screen): RoomRoundData
    {
        $timetable = $game->timetable();
        $index = $moment->round ?? 0;
        /** @var RoomRound $round */
        $round = $game->rounds->firstWhere('position', $index);
        $phase = $moment->phase;
        $asked = $phase === RoomPhase::Answer || $phase === RoomPhase::Reveal;
        $revealed = $phase === RoomPhase::Reveal;

        /** @var Collection<string, RoomAnswer> $answers */
        $answers = $asked ? $round->answers()->get()->keyBy('room_player_id') : new Collection;
        // Counted as the early close counts (`RoomWaits`): a full count is a closed round.
        $since = RoomWaits::since($game, $index);
        $waited = $rows->filter(static fn (RoomPlayer $row): bool => RoomWaits::waitsFor($row, $since, $answers->has($row->id)));
        $own = $mine === null ? null : $answers->get($mine->id);
        $spent = $revealed ? RoomTauntCost::spentAt($game, $index) : [];
        // The viewer's own purchases (ADR 037): never the screen's, and only a round that sells letters has them.
        $sells = ! $screen && $mine !== null && $asked && RoomLetters::sells($round);
        $bought = $sells ? RoomLetters::count($round, $mine) : 0;

        return new RoomRoundData(
            id: $round->id,
            index: $index,
            total: $timetable->count(),
            phase: $phase,
            opens_ms: $timetable->opens($index),
            answer_from_ms: $timetable->answerFrom($index),
            bell_ms: $timetable->bell($index),
            closes_ms: $revealed ? $timetable->closes($index) : null,
            next_ms: $revealed ? $timetable->next($index) : null,
            study: $phase === RoomPhase::Study ? $round->studyData() : null,
            ask: $asked ? $round->askData() : null,
            answered: $own !== null,
            answered_count: $waited->filter(static fn (RoomPlayer $row): bool => $answers->has($row->id))->count(),
            present_count: $waited->count(),
            reveal: $revealed ? new RoomRevealData(
                solution: $round->solutionData()->ids,
                word: $round->solutionData()->word,
                mine: $own === null || $screen ? null : RoomMineData::of($own, $bought),
                results: array_values($rows
                    ->filter(static fn (RoomPlayer $row): bool => $answers->has($row->id))
                    ->map(static function (RoomPlayer $row) use ($answers): RoomResultData {
                        /** @var RoomAnswer $answer */
                        $answer = $answers->get($row->id);

                        return new RoomResultData($row->id, $answer->correct, $answer->points);
                    })
                    ->all()),
                spent: array_values($rows
                    ->filter(static fn (RoomPlayer $row): bool => ($spent[$row->id] ?? 0) > 0)
                    ->map(static fn (RoomPlayer $row): RoomSpentData => new RoomSpentData($row->id, $spent[$row->id]))
                    ->all()),
            ) : null,
            letters: $sells && $phase === RoomPhase::Answer && $mine->isPresent() ? RoomLetters::of($game, $round, $bought) : null,
        );
    }
}
