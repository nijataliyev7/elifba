<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Support\Avatars;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Play\Data\GameStateData;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Events\GameSessionTransitioned;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Games;

/*
 * The teacher board: its page props, the claim (fencing), the state a
 * reloaded board resumes from, and pause / resume / end
 * (docs/architecture.md §4).
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
    $this->class = SchoolClass::factory()->for($this->teacher, 'teacher')->create(['name' => '1A']);
    $this->actingAs($this->teacher);
});

describe('the board page', function (): void {
    it('carries the game, the pack\'s look with its sounds and the active students in order', function (): void {
        $avatar = Avatars::keys()[0];
        $second = Student::factory()->for($this->class)->create(['display_name' => 'Əli', 'avatar' => $avatar, 'position' => 2]);
        $first = Student::factory()->for($this->class)->create(['display_name' => 'Aysel', 'avatar' => 'no-such-avatar', 'position' => 1]);
        Student::factory()->for($this->class)->archived()->create(['display_name' => 'Leyla']);
        Student::factory()->for(SchoolClass::factory()->for($this->teacher, 'teacher'))->create(['display_name' => 'Vüsal']);
        $game = Games::start($this->class, ['rounds' => 6]);
        $pack = app(ContentRepository::class)->pack('balloons');

        $this->get(route('games.board', $game))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('games/board')
                ->where('game', [
                    'id' => $game->id,
                    'status' => 'live',
                    'board_epoch' => 0,
                    'mechanic' => 'find',
                    'level' => 'letters',
                    'letter_case' => 'upper',
                    'rounds' => 6,
                    'points' => [],
                ])
                ->where('schoolClass', ['id' => $this->class->id, 'name' => '1A'])
                ->where('pack', [
                    'key' => 'balloons',
                    'title' => 'Şarlar',
                    'style' => [
                        'item' => 'balloon',
                        'slot' => $pack->style->slot->value,
                        'effect' => 'burst',
                        'palette' => $pack->style->palette,
                        'backdrop' => $pack->style->backdrop === null ? null : ContentAssets::url($pack->style->backdrop, $pack->version),
                        'sounds' => [
                            'hit' => ContentAssets::url((string) $pack->style->sounds['hit'], $pack->version),
                            'miss' => ContentAssets::url((string) $pack->style->sounds['miss'], $pack->version),
                            'solved' => ContentAssets::url((string) $pack->style->sounds['solved'], $pack->version),
                        ],
                        'figures' => [],
                    ],
                    'pictures' => [],
                ])
                ->where('students', [
                    ['id' => $first->id, 'display_name' => 'Aysel', 'avatar' => ['key' => 'no-such-avatar', 'url' => null]],
                    ['id' => $second->id, 'display_name' => 'Əli', 'avatar' => ['key' => $avatar, 'url' => ContentAssets::url(Avatars::images()[$avatar])]],
                ]));
    });

    it('sends an animal or fruit look the pictures of each word, letter and syllable, and its figures with the words they show', function (string $key, string $item, array $pictures, array $alone, array $figures): void {
        $game = Games::start($this->class, ['pack' => $key]);
        $pack = app(ContentRepository::class)->pack($key);

        $this->get(route('games.board', $game))->assertInertia(function (Assert $page) use ($pack, $item, $pictures, $alone, $figures): void {
            $page->where('pack.style.item', $item)
                ->has('pack.style.figures', count($pack->style->figures));

            foreach ($pictures as $key => $paths) {
                $page->where("pack.pictures.{$key}", array_map(static fn (string $path): string => ContentAssets::url($path, $pack->version), $paths));
            }

            foreach ($alone as $key) {
                $page->missing("pack.pictures.{$key}");
            }

            foreach ($figures as $path => $words) {
                $index = array_search($path, $pack->style->figures, true);

                expect($index)->toBeInt();
                $page->where("pack.style.figures.{$index}", ['url' => ContentAssets::url($path, $pack->version), 'words' => $words]);
            }
        });
    })->with([
        // «dəvəquşu» stands in front of the ostrich, «A» in front of the
        // horse, the bear or the bee, «də» in front of the squirrel, the
        // camel or the ostrich, «ruz», which begins no word, in front of the
        // rooster (xo-ruz), and «Ə» and «ma» alone; the dinosaurs are no
        // word's picture, the bear and the cow are «ayı» and «inək».
        'animals' => ['animals', 'animal', [
            'word:dəvəquşu' => ['animals/devequsu.svg'],
            'letter:a' => ['openmoji/1F40E.svg', 'openmoji/1F43B.svg', 'openmoji/1F41D.svg'],
            'letter:i' => ['openmoji/1F415.svg', 'openmoji/1F404.svg'],
            'syllable:də' => ['openmoji/1F43F.svg', 'openmoji/1F42A.svg', 'animals/devequsu.svg'],
            'syllable:ruz' => ['openmoji/1F413.svg'],
        ], ['letter:ə', 'syllable:ma'], ['openmoji/1F995.svg' => [], 'openmoji/1F43B.svg' => ['word:ayı'], 'openmoji/1F404.svg' => ['word:inək']]],
        // «al» begins «alma», «albalı» and «alça», not «ananas» (a-na-nas);
        // «ma» begins none, so it takes the words it ends (al-ma, xur-ma).
        'fruit' => ['fruit', 'fruit', [
            'word:qreypfrut' => ['fruit/qreypfrut.svg'],
            'letter:ş' => ['openmoji/1F351.svg'],
            'letter:ə' => ['fruit/erik.svg', 'fruit/encir.svg', 'fruit/ezgil.svg'],
            'syllable:al' => ['openmoji/1F34E.svg', 'fruit/albali.svg', 'fruit/alca.svg'],
            'syllable:a' => ['openmoji/1F34D.svg', 'openmoji/1F951.svg'],
            'syllable:ma' => ['openmoji/1F34E.svg', 'fruit/xurma.svg'],
        ], ['letter:o', 'syllable:ruz'], ['openmoji/1F34E.svg' => ['word:alma'], 'openmoji/1F349.svg' => ['word:qarpız'], 'fruit/erik.svg' => ['word:ərik']]],
    ]);

    it('serves sounds from the app\'s own host, versioned', function (): void {
        $game = Games::start($this->class);

        $this->get(route('games.board', $game))->assertInertia(fn (Assert $page) => $page
            ->where('pack.style.sounds.hit', fn (string $url): bool => str_starts_with($url, url('/content/')) && str_ends_with($url, '?v='.app(ContentRepository::class)->pack('balloons')->version)));
    });

    it('carries the points of the rounds solved so far, for the result screen', function (): void {
        $game = Games::start($this->class, ['rounds' => 5]);
        $first = Games::round($game, 0);
        $second = Games::round($game, 1);

        $this->postJson(route('games.sync', $game), [
            'board_epoch' => 0,
            'events' => [
                Games::hit($first, Games::target($first)),
                Games::hit($second, Games::decoy($second)),
                Games::hit($second, Games::target($second)),
                Games::skip(Games::round($game, 2)),
            ],
        ])->assertOk();

        $this->get(route('games.board', $game))->assertInertia(fn (Assert $page) => $page
            ->where('game.points', [$first->id => 3, $second->id => 2]));
    });

    it('opens a finished game too, so the board can show the result', function (): void {
        $game = Games::start($this->class);
        $game->forceFill(['status' => GameSessionStatus::Finished, 'ended_at' => now()])->save();

        $this->get(route('games.board', $game))->assertOk()->assertInertia(fn (Assert $page) => $page->where('game.status', 'finished'));
    });
});

describe('claiming the board', function (): void {
    it('raises the epoch on every load', function (): void {
        $game = Games::start($this->class);
        $this->travelTo(now()->addHour()->startOfSecond());

        $this->postJson(route('games.board.claim', $game))->assertExactJson(['game' => ['status' => 'live', 'board_epoch' => 1]]);
        $this->postJson(route('games.board.claim', $game))->assertExactJson(['game' => ['status' => 'live', 'board_epoch' => 2]]);

        expect($game->fresh()?->board_epoch)->toBe(2)
            ->and($game->fresh()?->last_activity_at->equalTo(now()))->toBeTrue();
    });

    it('writes nothing to a game that has ended', function (GameSessionStatus $status): void {
        $game = Games::start($this->class);
        $game->forceFill(['status' => $status, 'board_epoch' => 3, 'ended_at' => now()])->save();

        $this->postJson(route('games.board.claim', $game))->assertExactJson(['game' => ['status' => $status->value, 'board_epoch' => 3]]);

        expect($game->fresh()?->board_epoch)->toBe(3);
    })->with([GameSessionStatus::Finished, GameSessionStatus::Abandoned]);
});

describe('the state', function (): void {
    it('lists every round and where it stands, in the contract\'s shape', function (): void {
        $game = Games::start($this->class, ['rounds' => 5]);
        $rounds = Games::rounds($game);
        $this->postJson(route('games.board.claim', $game))->assertOk();
        $this->postJson(route('games.sync', $game), ['board_epoch' => 1, 'events' => [
            Games::hit($rounds[0], Games::decoy($rounds[0])),
            Games::hit($rounds[0], Games::target($rounds[0])),
            Games::skip($rounds[1]),
            Games::hit($rounds[2], Games::decoy($rounds[2], 1)),
        ]])->assertOk();

        $json = $this->getJson(route('games.state', $game))->assertOk()->json();

        expect(GameStateData::fromArray($json)->toArray())->toBe($json)
            ->and($json['game'])->toBe(['id' => $game->id, 'status' => 'live', 'board_epoch' => 1, 'pack' => 'balloons', 'mechanic' => 'find'])
            ->and(array_column($json['rounds'], 'id'))->toBe(array_map(fn ($round) => $round->id, $rounds))
            ->and(array_column($json['rounds'], 'index'))->toBe([0, 1, 2, 3, 4])
            ->and(array_unique(array_column($json['rounds'], 'total')))->toBe([5])
            ->and($json['rounds'][0]['items'])->toBe($rounds[0]->payload['items'])
            ->and($json['progress'])->toBe([
                ['round_id' => $rounds[0]->id, 'hit_item_ids' => [Games::target($rounds[0])], 'misses' => 1, 'solved' => true, 'skipped' => false],
                ['round_id' => $rounds[1]->id, 'hit_item_ids' => [], 'misses' => 0, 'solved' => false, 'skipped' => true],
                ['round_id' => $rounds[2]->id, 'hit_item_ids' => [], 'misses' => 1, 'solved' => false, 'skipped' => false],
                ['round_id' => $rounds[3]->id, 'hit_item_ids' => [], 'misses' => 0, 'solved' => false, 'skipped' => false],
                ['round_id' => $rounds[4]->id, 'hit_item_ids' => [], 'misses' => 0, 'solved' => false, 'skipped' => false],
            ]);
    });

    it('carries no student name', function (): void {
        Student::factory()->for($this->class)->create(['display_name' => 'Aysel']);
        $game = Games::start($this->class);

        expect($this->getJson(route('games.state', $game))->getContent())->not->toContain('Aysel');
    });
});

describe('pausing, resuming and ending', function (): void {
    it('pauses, resumes and ends a game', function (): void {
        Event::fake([GameSessionTransitioned::class]);
        $game = Games::start($this->class);

        $this->patchJson(route('games.status.update', $game), ['status' => 'paused'])->assertExactJson(['game' => ['status' => 'paused', 'board_epoch' => 0]]);
        $this->patchJson(route('games.status.update', $game), ['status' => 'paused'])->assertExactJson(['game' => ['status' => 'paused', 'board_epoch' => 0]]);
        $this->patchJson(route('games.status.update', $game), ['status' => 'live'])->assertExactJson(['game' => ['status' => 'live', 'board_epoch' => 0]]);
        $this->patchJson(route('games.status.update', $game), ['status' => 'finished'])->assertExactJson(['game' => ['status' => 'finished', 'board_epoch' => 0]]);

        expect($game->fresh()?->ended_at)->not->toBeNull();

        Event::assertDispatchedTimes(GameSessionTransitioned::class, 3);
    });

    it('refuses a change the lifecycle does not allow', function (): void {
        $game = Games::start($this->class);
        $this->patchJson(route('games.status.update', $game), ['status' => 'finished'])->assertOk();

        $this->patchJson(route('games.status.update', $game), ['status' => 'live'])
            ->assertStatus(409)
            ->assertExactJson(['error' => 'invalid_transition', 'game' => ['status' => 'finished', 'board_epoch' => 0]]);
    });

    it('leaves abandoning to the scheduler', function (string $status): void {
        $game = Games::start($this->class);

        $this->patchJson(route('games.status.update', $game), ['status' => $status])->assertUnprocessable()->assertJsonValidationErrors('status');

        expect($game->fresh()?->status)->toBe(GameSessionStatus::Live);
    })->with(['abandoned', 'over', '']);
});
