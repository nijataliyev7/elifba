<?php

use App\Domain\Rooms\Enums\RoomActKind;
use App\Domain\Rooms\Enums\RoomMatchColor;
use App\Domain\Rooms\Enums\RoomMatchEnding;
use App\Domain\Rooms\Enums\RoomMatchOutcome;
use App\Domain\Rooms\Enums\RoomMatchPhase;
use App\Domain\Rooms\Support\Draughts\DraughtsAct;
use App\Domain\Rooms\Support\Draughts\DraughtsMatch;
use App\Domain\Rooms\Support\Draughts\DraughtsPosition;
use Tests\Support\Draughts;

/*
 * A board game replayed from its acts (docs/architecture.md §12 "Board
 * games", ADR 034 decisions 10–15): the position, the clock, the draw
 * offer and the outcome are derived, never stored; the outcome is the
 * earliest ending.
 */

const MATCH_STARTS = 1_800_000_000_000;
const MATCH_CLOCK = 300_000;
const MATCH_INCREMENT = 3_000;

/**
 * @param  list<DraughtsAct>  $acts
 */
function draughtsMatch(array $acts, int $nowMs, ?int $endedMs = null): DraughtsMatch
{
    return DraughtsMatch::replay(MATCH_STARTS, MATCH_CLOCK, MATCH_INCREMENT, $endedMs, $acts, $nowMs);
}

/** A move of a line by the side to move at `$ply`, `$secondsIn` seconds after the clock started. */
function draughtsMove(string $line, int $ply, int $secondsIn): DraughtsAct
{
    return Draughts::act(RoomActKind::Move, RoomMatchColor::toMove($ply), $ply, MATCH_STARTS + $secondsIn * 1000, Draughts::path($line));
}

it('counts down before the clock starts, white to move and nothing to play yet', function (): void {
    $match = draughtsMatch([], MATCH_STARTS - 2_000);

    expect($match->phase)->toBe(RoomMatchPhase::Countdown)
        ->and($match->turn())->toBe(RoomMatchColor::White)
        ->and($match->ply())->toBe(0)
        ->and($match->sinceMs())->toBe(MATCH_STARTS)
        ->and($match->flagMs())->toBe(MATCH_STARTS + MATCH_CLOCK)
        ->and($match->left(RoomMatchColor::White))->toBe(MATCH_CLOCK)
        ->and($match->legal())->toBe([])
        ->and($match->canOffer(RoomMatchColor::Black))->toBeFalse()
        ->and($match->position)->toEqual(DraughtsPosition::initial())
        ->and($match->outcome)->toBeNull();
});

it('plays the moves in order: the position, the last move, the list and the clocks', function (): void {
    $match = draughtsMatch([
        draughtsMove('c3-d4', 0, 5),
        draughtsMove('f6-e5', 1, 12),
        draughtsMove('d4:f6', 2, 15),
    ], MATCH_STARTS + 20_000);

    expect($match->phase)->toBe(RoomMatchPhase::Play)
        ->and($match->ply())->toBe(3)
        ->and($match->turn())->toBe(RoomMatchColor::Black)
        ->and($match->moves)->toBe(['c3-d4', 'f6-e5', 'd4:f6'])
        ->and($match->last?->path)->toBe(['d4', 'f6'])
        ->and($match->last?->captured)->toBe(['e5'])
        ->and($match->position->count(RoomMatchColor::Black))->toBe(11)
        ->and($match->sinceMs())->toBe(MATCH_STARTS + 15_000)
        ->and($match->left(RoomMatchColor::White))->toBe(MATCH_CLOCK - 5_000 - 3_000 + 2 * MATCH_INCREMENT)
        ->and($match->left(RoomMatchColor::Black))->toBe(MATCH_CLOCK - 7_000 + MATCH_INCREMENT - 5_000)
        ->and($match->flagMs())->toBe(MATCH_STARTS + 15_000 + MATCH_CLOCK - 7_000 + MATCH_INCREMENT)
        // A capture is compulsory: black must take back.
        ->and(array_map(static fn ($move): bool => $move->isCapture(), $match->legal()))->each->toBeTrue();
});

it('passes over an act the server would not have taken', function (): void {
    $match = draughtsMatch([
        draughtsMove('c3-d4', 0, -1),                    // before the clock started
        Draughts::act(RoomActKind::Move, RoomMatchColor::Black, 0, MATCH_STARTS + 1_000, ['f6', 'e5']),   // out of turn
        draughtsMove('c3-d5', 0, 2),                     // no legal move
        draughtsMove('c3-d4', 1, 3),                     // another ply
        Draughts::act(RoomActKind::Accept, RoomMatchColor::Black, 0, MATCH_STARTS + 4_000),               // no offer stands
    ], MATCH_STARTS + 10_000);

    expect($match->ply())->toBe(0)
        ->and($match->phase)->toBe(RoomMatchPhase::Play)
        ->and($match->moves)->toBe([]);
});

it('ends when the side to move has no piece or no move: the mover wins', function (): void {
    foreach (Draughts::fixture('games')['games'] as $game) {
        $acts = Draughts::moves($game['moves'], MATCH_STARTS);
        $match = draughtsMatch($acts, MATCH_STARTS + 1_000_000);
        $mover = RoomMatchColor::toMove(count($game['moves']) - 1);

        expect($match->ending)->toBe(RoomMatchEnding::NoMoves, "game {$game['game']}")
            ->and($match->outcome)->toBe(RoomMatchOutcome::won($mover))
            ->and($match->overMs)->toBe(end($acts)->actedMs)
            ->and($match->phase)->toBe(RoomMatchPhase::Over)
            ->and($match->turn())->toBeNull()
            ->and($match->flagMs())->toBeNull()
            ->and($match->legal())->toBe([]);
    }
});

it('draws a game the moment a draw rule holds, and takes no act after it', function (): void {
    $case = collect(Draughts::fixture('draws')['cases'])->firstWhere('fen', 'W:Wa1,a3,b2,c1,c3,d2,e1,e3,f2,g1,g3,h2:Ba7,b6,b8,c7,d6,d8,e7,f6,f8,g7,h6,h8');
    $acts = Draughts::moves($case['moves'], MATCH_STARTS);
    $last = end($acts)->actedMs;
    $acts[] = Draughts::act(RoomActKind::Resign, RoomMatchColor::Black, count($case['moves']), $last + 500);

    $match = draughtsMatch($acts, $last + 1_000);

    expect($match->outcome)->toBe(RoomMatchOutcome::Draw)
        ->and($match->ending)->toBe(RoomMatchEnding::Repetition)
        ->and($match->overMs)->toBe($last)
        ->and($match->ply())->toBe(count($case['moves']));
});

it('gives the game to the other side when a seat resigns or leaves, even in the countdown', function (): void {
    $resigned = draughtsMatch([draughtsMove('c3-d4', 0, 4), Draughts::act(RoomActKind::Resign, RoomMatchColor::White, 1, MATCH_STARTS + 9_000)], MATCH_STARTS + 10_000);
    $left = draughtsMatch([Draughts::act(RoomActKind::Leave, RoomMatchColor::Black, 0, MATCH_STARTS - 1_000)], MATCH_STARTS + 10_000);

    expect($resigned->outcome)->toBe(RoomMatchOutcome::Black)
        ->and($resigned->ending)->toBe(RoomMatchEnding::Resigned)
        ->and($resigned->overMs)->toBe(MATCH_STARTS + 9_000)
        ->and($resigned->left(RoomMatchColor::Black))->toBe(MATCH_CLOCK - 5_000)
        ->and($left->outcome)->toBe(RoomMatchOutcome::White)
        ->and($left->ending)->toBe(RoomMatchEnding::Left)
        ->and($left->phase)->toBe(RoomMatchPhase::Over);
});

describe('the flag', function (): void {
    it('falls on the side to move that has used its time: the other side wins', function (): void {
        $flag = MATCH_STARTS + 4_000 + MATCH_CLOCK;
        $acts = [draughtsMove('c3-d4', 0, 4)];

        expect(draughtsMatch($acts, $flag - 1)->phase)->toBe(RoomMatchPhase::Play)
            ->and(draughtsMatch($acts, $flag - 1)->left(RoomMatchColor::Black))->toBe(1);

        $match = draughtsMatch($acts, $flag);

        expect($match->outcome)->toBe(RoomMatchOutcome::White)
            ->and($match->ending)->toBe(RoomMatchEnding::Time)
            ->and($match->overMs)->toBe($flag)
            ->and($match->left(RoomMatchColor::Black))->toBe(0)
            ->and($match->left(RoomMatchColor::White))->toBe(MATCH_CLOCK - 4_000 + MATCH_INCREMENT);
    });

    it('takes no move received at it or after it', function (): void {
        $flag = MATCH_STARTS + MATCH_CLOCK;
        $match = draughtsMatch([Draughts::act(RoomActKind::Move, RoomMatchColor::White, 0, $flag, ['c3', 'd4'])], $flag + 5_000);

        expect($match->ending)->toBe(RoomMatchEnding::Time)
            ->and($match->outcome)->toBe(RoomMatchOutcome::Black)
            ->and($match->ply())->toBe(0)
            ->and(draughtsMatch([Draughts::act(RoomActKind::Move, RoomMatchColor::White, 0, $flag - 1, ['c3', 'd4'])], $flag + 5_000)->ply())->toBe(1);
    });

    it('beats the host\'s end that comes after it, and loses to one before it', function (): void {
        $flag = MATCH_STARTS + MATCH_CLOCK;

        expect(draughtsMatch([], $flag + 10_000, $flag + 1)->ending)->toBe(RoomMatchEnding::Time)
            ->and(draughtsMatch([], $flag + 10_000, $flag)->ending)->toBe(RoomMatchEnding::Time)
            ->and(draughtsMatch([], $flag + 10_000, $flag - 1)->ending)->toBe(RoomMatchEnding::Stopped);
    });
});

describe('the host\'s end', function (): void {
    it('stops the game without a result, the countdown too', function (): void {
        $match = draughtsMatch([draughtsMove('c3-d4', 0, 4)], MATCH_STARTS + 60_000, MATCH_STARTS + 30_000);

        expect($match->outcome)->toBe(RoomMatchOutcome::None)
            ->and($match->ending)->toBe(RoomMatchEnding::Stopped)
            ->and($match->overMs)->toBe(MATCH_STARTS + 30_000)
            ->and($match->left(RoomMatchColor::Black))->toBe(MATCH_CLOCK - 26_000)
            ->and(draughtsMatch([], MATCH_STARTS - 1_000, MATCH_STARTS - 2_000)->ending)->toBe(RoomMatchEnding::Stopped);
    });

    it('changes nothing of a game already over, and an act at its moment came first', function (): void {
        $resign = Draughts::act(RoomActKind::Resign, RoomMatchColor::Black, 0, MATCH_STARTS + 5_000);

        expect(draughtsMatch([$resign], MATCH_STARTS + 60_000, MATCH_STARTS + 30_000)->ending)->toBe(RoomMatchEnding::Resigned)
            ->and(draughtsMatch([$resign], MATCH_STARTS + 60_000, MATCH_STARTS + 5_000)->ending)->toBe(RoomMatchEnding::Resigned)
            ->and(draughtsMatch([$resign], MATCH_STARTS + 60_000, MATCH_STARTS + 4_999)->ending)->toBe(RoomMatchEnding::Stopped);
    });
});

describe('the draw offer', function (): void {
    it('is the right of the side that has just moved, and an acceptance draws the game', function (): void {
        $acts = [draughtsMove('c3-d4', 0, 4)];
        $before = draughtsMatch($acts, MATCH_STARTS + 5_000);

        expect(draughtsMatch([], MATCH_STARTS + 1_000)->canOffer(RoomMatchColor::White))->toBeFalse()
            ->and(draughtsMatch([], MATCH_STARTS + 1_000)->canOffer(RoomMatchColor::Black))->toBeFalse()
            ->and($before->canOffer(RoomMatchColor::White))->toBeTrue()
            ->and($before->canOffer(RoomMatchColor::Black))->toBeFalse();

        $acts[] = Draughts::act(RoomActKind::Offer, RoomMatchColor::White, 1, MATCH_STARTS + 6_000);
        $offered = draughtsMatch($acts, MATCH_STARTS + 7_000);

        expect($offered->offer)->toBe(RoomMatchColor::White)
            ->and($offered->canOffer(RoomMatchColor::White))->toBeFalse()
            ->and($offered->canOffer(RoomMatchColor::Black))->toBeFalse();

        $acts[] = Draughts::act(RoomActKind::Accept, RoomMatchColor::Black, 1, MATCH_STARTS + 8_000);
        $agreed = draughtsMatch($acts, MATCH_STARTS + 9_000);

        expect($agreed->outcome)->toBe(RoomMatchOutcome::Draw)
            ->and($agreed->ending)->toBe(RoomMatchEnding::Agreed)
            ->and($agreed->offer)->toBeNull()
            ->and($agreed->overMs)->toBe(MATCH_STARTS + 8_000);
    });

    it('is declined by the reply move, after which the other side may offer', function (): void {
        $match = draughtsMatch([
            draughtsMove('c3-d4', 0, 4),
            Draughts::act(RoomActKind::Offer, RoomMatchColor::White, 1, MATCH_STARTS + 5_000),
            draughtsMove('f6-g5', 1, 6),
        ], MATCH_STARTS + 7_000);

        expect($match->offer)->toBeNull()
            ->and($match->outcome)->toBeNull()
            ->and($match->canOffer(RoomMatchColor::Black))->toBeTrue();
    });

    it('is refused to the side that offered last until the other side has offered', function (): void {
        $acts = [
            draughtsMove('c3-d4', 0, 4),
            Draughts::act(RoomActKind::Offer, RoomMatchColor::White, 1, MATCH_STARTS + 5_000),
            Draughts::act(RoomActKind::Decline, RoomMatchColor::Black, 1, MATCH_STARTS + 6_000),
            draughtsMove('f6-g5', 1, 7),
            draughtsMove('g3-f4', 2, 8),
            Draughts::act(RoomActKind::Offer, RoomMatchColor::White, 3, MATCH_STARTS + 9_000),
        ];
        $refused = draughtsMatch($acts, MATCH_STARTS + 10_000);

        expect($refused->offer)->toBeNull()
            ->and($refused->canOffer(RoomMatchColor::White))->toBeFalse();

        $acts[] = draughtsMove('g5-h4', 3, 11);
        $acts[] = Draughts::act(RoomActKind::Offer, RoomMatchColor::Black, 4, MATCH_STARTS + 12_000);
        $acts[] = draughtsMove('b2-c3', 4, 13);

        expect(draughtsMatch($acts, MATCH_STARTS + 14_000)->canOffer(RoomMatchColor::White))->toBeTrue();
    });

    it('cannot be accepted or declined by the side that made it, nor at another ply', function (): void {
        $acts = [
            draughtsMove('c3-d4', 0, 4),
            Draughts::act(RoomActKind::Offer, RoomMatchColor::White, 1, MATCH_STARTS + 5_000),
            Draughts::act(RoomActKind::Accept, RoomMatchColor::White, 1, MATCH_STARTS + 6_000),
            Draughts::act(RoomActKind::Accept, RoomMatchColor::Black, 0, MATCH_STARTS + 7_000),
            Draughts::act(RoomActKind::Decline, RoomMatchColor::White, 1, MATCH_STARTS + 8_000),
        ];
        $match = draughtsMatch($acts, MATCH_STARTS + 9_000);

        expect($match->outcome)->toBeNull()
            ->and($match->offer)->toBe(RoomMatchColor::White);
    });
});
