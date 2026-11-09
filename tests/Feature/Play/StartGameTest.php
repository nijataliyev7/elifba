<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Actions\StartGame;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;
use Tests\Support\Games;

/*
 * StartGame (docs/architecture.md §2): seeded, made of the class's learned
 * letters, never the same target twice in a row, stored in one
 * transaction.
 */

beforeEach(function (): void {
    $this->class = SchoolClass::factory()->create();
});

/**
 * @return list<mixed>
 */
function storedPayloads(GameSession $game): array
{
    return array_map(static fn (GameRound $round): array => $round->payload, Games::rounds($game));
}

it('builds the same rounds from the same seed', function (string $level): void {
    $first = Games::start($this->class, ['level' => $level, 'rounds' => 12], seed: 2026);
    $second = Games::start($this->class, ['level' => $level, 'rounds' => 12], seed: 2026);
    $other = Games::start($this->class, ['level' => $level, 'rounds' => 12], seed: 2027);

    expect(storedPayloads($first))->toBe(storedPayloads($second))
        ->and(storedPayloads($first))->not->toBe(storedPayloads($other))
        ->and($first->seed)->toBe(2026);
})->with(['letters', 'syllables-open', 'words-short', 'words-long']);

it('stores every round in order with an empty result', function (): void {
    $game = Games::start($this->class, ['rounds' => 8]);
    $rounds = Games::rounds($game);

    expect($rounds)->toHaveCount(8)
        ->and(array_map(static fn (GameRound $round): int => $round->position, $rounds))->toBe(range(0, 7));

    foreach ($rounds as $round) {
        expect($round->school_class_id)->toBe($this->class->id)
            ->and($round->progress)->toBe([])
            ->and($round->misses)->toBe(0)
            ->and($round->points)->toBe(0)
            ->and($round->solved_at)->toBeNull()
            ->and($round->skipped_at)->toBeNull()
            ->and($round->student_id)->toBeNull()
            ->and($round->played_on)->toBeNull()
            ->and($round->week_start)->toBeNull()
            ->and(array_keys($round->payload))->toBe(['mechanic', 'unit', 'pack', 'level', 'prompt', 'items', 'slots', 'solution', 'effect']);
    }
});

it('never stores the same target twice in a row', function (): void {
    $game = Games::start($this->class, ['level' => 'syllables-open', 'rounds' => 30], seed: 5);
    $targets = array_map(
        static fn (GameRound $round): string => collect($round->payload['items'])->firstWhere('id', Games::target($round))['content_key'],
        Games::rounds($game),
    );

    foreach (array_slice($targets, 1) as $index => $target) {
        expect($target)->not->toBe($targets[$index]);
    }
});

it('uses only the letters the class has learned', function (): void {
    $learned = ['a', 'b', 'd', 'e', 'ə', 'i', 'k', 'l', 'm', 'n', 'r', 's', 't', 'u', 'y', 'z', 'o', 'p', 'q', 'ş'];
    $this->class->update(['learned_letters' => $learned]);

    foreach (['letters', 'words-short', 'syllables-open'] as $level) {
        foreach (Games::rounds(Games::start($this->class, ['level' => $level, 'rounds' => 30])) as $round) {
            foreach ($round->payload['items'] as $item) {
                $text = explode(':', $item['content_key'], 2)[1];

                expect(array_diff(mb_str_split(Az::lower($text)), $learned))->toBe([], $item['content_key']);
            }
        }
    }
});

it('stores the game and its rounds together or not at all', function (): void {
    $setup = GameSetupData::validate(['mechanic' => 'find', 'pack' => 'balloons', 'level' => 'letters', 'rounds' => 5], $this->class);
    $saved = 0;

    GameRound::creating(function () use (&$saved): void {
        if (++$saved === 3) {
            throw new RuntimeException('The database went away.');
        }
    });

    expect(fn () => app(StartGame::class)->handle($this->class, $setup, 1))->toThrow(RuntimeException::class);

    expect(GameSession::query()->count())->toBe(0)
        ->and(GameRound::query()->count())->toBe(0);
});

it('gives the game to the class\'s teacher', function (): void {
    $game = Games::start($this->class);

    expect($game->user_id)->toBe($this->class->user_id)
        ->and($game->teacher->is($this->class->teacher))->toBeTrue();
});
