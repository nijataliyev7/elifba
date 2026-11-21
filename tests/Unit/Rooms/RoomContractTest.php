<?php

use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\RoomWords;
use App\Domain\Rooms\Data\RoomActData;
use App\Domain\Rooms\Data\RoomActReceiptData;
use App\Domain\Rooms\Data\RoomAnswerData;
use App\Domain\Rooms\Data\RoomAnswerReceiptData;
use App\Domain\Rooms\Data\RoomAskData;
use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Data\RoomTauntData;
use App\Domain\Rooms\Data\RoomTauntReceiptData;
use App\Domain\Rooms\Support\Mechanics\AnagramOrders;

/*
 * The room's contract on the PHP side (docs/architecture.md §12 "Room
 * games", "The state"): every fixture of tests/Fixtures/rooms — the ones
 * resources/js/rooms/contract.test.ts parses with the zod schemas — goes
 * through its DTO and comes back byte for byte, and the DTOs refuse what
 * the zod schemas refuse: a key of the reveal, the material or the
 * question in a phase that may not serve it. judge.json is the judge's
 * (RoomJudgeTest), not a payload. From D3 (ADR 034 decision 17) a board
 * game's match in each of its phases, a seat's act and its receipt. From
 * ADR 037 an anagram's question with its clue and its orders (`v: 8`).
 */

const ROOM_FIXTURES = __DIR__.'/../../Fixtures/rooms';

/**
 * @return array<string, mixed>
 */
function roomFixture(string $name): array
{
    $decoded = json_decode((string) file_get_contents(ROOM_FIXTURES."/{$name}.json"), true, 64, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : throw new RuntimeException("{$name} is not an object.");
}

/**
 * Every fixture a device receives or sends, by name.
 *
 * @return array<string, string>
 */
function roomPayloads(): array
{
    $names = array_map(static fn (string $path): string => basename($path, '.json'), glob(ROOM_FIXTURES.'/*.json') ?: []);
    $names = array_values(array_filter($names, static fn (string $name): bool => $name !== 'judge'));

    return array_combine($names, $names);
}

it('round-trips every fixture', function (string $name): void {
    $fixture = roomFixture($name);

    $again = match (strstr($name, '.', true) ?: $name) {
        'state' => RoomStateData::fromArray($fixture)->toArray(),
        'answer' => RoomAnswerData::fromArray($fixture)->toArray(),
        'receipt' => RoomAnswerReceiptData::fromArray($fixture)->toArray(),
        'taunt' => RoomTauntData::fromArray($fixture)->toArray(),
        'taunt-receipt' => RoomTauntReceiptData::fromArray($fixture)->toArray(),
        'act' => RoomActData::fromArray($fixture)->toArray(),
        'act-receipt' => RoomActReceiptData::fromArray($fixture)->toArray(),
    };

    expect($again)->toBe($fixture);
})->with(roomPayloads());

it('has every phase\'s fixture, the answer and the receipt', function (): void {
    expect(array_keys(roomPayloads()))->toEqualCanonicalizing([
        'state.lobby', 'state.countdown', 'state.study', 'state.answer', 'state.reveal', 'state.over', 'answer', 'receipt',
        // B4: a grid of cells to study, answer and reveal, and a row of shapes.
        'state.study.cells', 'state.answer.cells', 'state.reveal.cells', 'state.study.shapes',
        // B5: a row to continue and an odd one to find, asked and revealed.
        'state.answer.sequence', 'state.reveal.sequence', 'state.answer.odd', 'state.reveal.odd',
        // B6: each word game's question, answer and reveal.
        'state.answer.anagram', 'answer.anagram', 'state.reveal.anagram',
        'state.answer.spelling', 'answer.spelling', 'state.reveal.spelling',
        // G2: a game over and another chosen.
        'state.chosen',
        // T2: a target's phone and the screen with taunts, a taunt and its receipt.
        'state.taunted', 'state.screen-taunted', 'taunt', 'taunt-receipt',
        // C3: the start screen with the voice on, a player of each standing; the host's view with it off.
        'state.voice', 'state.voice.off',
        // D3: a board game chosen, its countdown, the seat to move with a capture to make, a watcher, a draw offered, its end; an act and its receipt.
        'state.match.chosen', 'state.match.countdown', 'state.match.play', 'state.match.watch', 'state.match.offer', 'state.match.over', 'act', 'act-receipt',
        // K3 (ADR 036): a match against the computer, and the choice of one.
        'state.match.computer', 'state.match.computer.chosen',
    ]);
});

it('has no solution in any state before the reveal', function (): void {
    $reveal = roomFixture('state.reveal');
    $study = roomFixture('state.study');
    $answer = roomFixture('state.answer');

    foreach (['state.lobby', 'state.countdown', 'state.study', 'state.answer', 'state.over', 'state.chosen'] as $name) {
        $wire = (string) json_encode(roomFixture($name));

        expect($wire)->not->toMatch('/"(solution|accept|correct|mine|results|picked|elapsed_ms|seed)"/', $name)
            ->and($wire)->not->toContain(json_encode($reveal['round']['reveal']['solution']), $name);
    }

    // A grid: neither the study's ids nor the solution's order are in the question before the reveal.
    $cells = roomFixture('state.reveal.cells');

    foreach (['state.study.cells', 'state.answer.cells', 'state.study.shapes'] as $name) {
        $wire = (string) json_encode(roomFixture($name));

        expect($wire)->not->toMatch('/"(solution|accept|correct|mine|results|picked|elapsed_ms|seed)"/', $name)
            ->and($wire)->not->toContain(json_encode($cells['round']['reveal']['solution']), $name);
    }

    // A word game: the word only in the reveal; an anagram's tiles never in the order of a word they spell.
    foreach (['anagram', 'spelling'] as $game) {
        $revealed = roomFixture("state.reveal.{$game}")['round']['reveal'];
        $wire = (string) json_encode(roomFixture("state.answer.{$game}"), JSON_UNESCAPED_UNICODE);

        expect($revealed['word'])->not->toBeNull()
            ->and($wire)->not->toMatch('/"(solution|accept|correct|mine|results|picked|elapsed_ms|seed|word)":/', $game)
            ->and($wire)->not->toContain(json_encode($revealed['solution']));
    }

    expect(implode('', array_column(roomFixture('state.answer.anagram')['round']['ask']['items'], 'text')))->not->toBe('ALMA')
        ->and(array_column(roomFixture('state.answer.spelling')['round']['ask']['items'], 'text'))->toContain('kitab');

    $studied = array_column(roomFixture('state.study.cells')['round']['study']['items'], 'id');
    $asked = roomFixture('state.answer.cells')['round']['ask']['items'];

    expect(array_intersect($studied, array_column($asked, 'id')))->toBe([])
        ->and(array_column($asked, 'cell'))->toBe([[0, 0], [1, 0], [2, 0], [0, 1], [1, 1], [2, 1], [0, 2], [1, 2], [2, 2]]);

    // The DTOs refuse a key of a later or an earlier phase, as the zod schemas do.
    $refused = static function (array $state, array $round, string $why): void {
        expect(fn () => RoomStateData::fromArray([...$state, 'round' => [...$state['round'], ...$round]]))
            ->toThrow(InvalidArgumentException::class, $why);
    };

    foreach (['state.countdown', 'state.study', 'state.answer'] as $name) {
        $state = roomFixture($name);
        $refused($state, ['reveal' => $reveal['round']['reveal']], 'reveal');
        $refused($state, ['closes_ms' => $reveal['round']['closes_ms'], 'next_ms' => $reveal['round']['next_ms']], 'close');
    }

    $refused($answer, ['study' => $study['round']['study']], 'studied');
    $refused($study, ['ask' => $answer['round']['ask']], 'question');
    $refused(roomFixture('state.countdown'), ['ask' => $answer['round']['ask']], 'question');
    $refused($study, ['answered' => true, 'answered_count' => 1], 'Nobody answers');
    $refused($reveal, ['reveal' => null], 'reveal');

    // A round only while a game is live.
    expect(fn () => RoomStateData::fromArray([...roomFixture('state.over'), 'round' => $answer['round']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RoomStateData::fromArray([...$answer, 'game' => null]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RoomStateData::fromArray([...$answer, 'round' => null]))->toThrow(InvalidArgumentException::class);
});

it('says the choice of the next game in every open room\'s state, and nothing else in it', function (): void {
    foreach (array_filter(array_keys(roomPayloads()), static fn (string $name): bool => str_starts_with($name, 'state.')) as $name) {
        $state = roomFixture($name);
        $setup = $state['setup'];
        $game = $state['game'];
        $board = $setup['kind'] === 'board';

        expect($state['v'])->toBe(RoomStateData::VERSION)
            ->and(array_keys($setup))->toBe($board ? ['kind', 'mechanic', 'difficulty', 'clock', 'white', 'black', 'computer', 'played'] : ['kind', 'mechanic', 'difficulty', 'rounds', 'played'], $name)
            // Played only once the game is over and was this very choice: a race's rounds, a board game's two players in either colour.
            ->and($setup['played'])->toBe($game !== null && $game['phase'] === 'over'
                && [$game['mechanic'], $game['difficulty']] === [$setup['mechanic'], $setup['difficulty']]
                && ($board
                    ? array_diff([$setup['white'], $setup['black']], array_column($state['match']['sides'], 'player_id')) === []
                    : $game['rounds'] === $setup['rounds']), $name);
    }

    expect(roomFixture('state.over')['setup']['played'])->toBeTrue()
        ->and(roomFixture('state.chosen')['setup'])->toBe(['kind' => 'race', 'mechanic' => 'anagram', 'difficulty' => 2, 'rounds' => 5, 'played' => false])
        ->and(roomFixture('state.match.over')['setup']['played'])->toBeTrue();

    // An open room has a choice and a closed one none; a state of another version is refused.
    $lobby = roomFixture('state.lobby');
    $closed = [...$lobby, 'room' => [...$lobby['room'], 'status' => 'closed', 'code' => null]];

    expect(fn () => RoomStateData::fromArray([...$lobby, 'setup' => null]))->toThrow(InvalidArgumentException::class, 'choice')
        ->and(fn () => RoomStateData::fromArray($closed))->toThrow(InvalidArgumentException::class, 'choice')
        ->and(RoomStateData::fromArray([...$closed, 'setup' => null])->setup)->toBeNull()
        ->and(fn () => RoomStateData::fromArray([...$lobby, 'v' => 1]))->toThrow(InvalidArgumentException::class, 'version');
});

it('carries a live game\'s taunts, and nothing of them anywhere else', function (): void {
    foreach (array_filter(array_keys(roomPayloads()), static fn (string $name): bool => str_starts_with($name, 'state.')) as $name) {
        $state = roomFixture($name);
        $live = $state['game'] !== null && $state['game']['phase'] === 'live';

        expect(array_keys($state['room']))->toBe(['id', 'status', 'locked', 'code', 'taunts', 'screen'], $name)
            ->and($state['taunts'] === null)->toBe(! $live, $name);

        if ($live) {
            expect(array_keys($state['taunts']))->toBe(['open', 'balance', 'ready_ms', 'received', 'shown'], $name);

            // The target's own list never names a target; only the screen's does.
            foreach ($state['taunts']['received'] as $taunt) {
                expect(array_keys($taunt))->toBe(['id', 'from', 'taunt', 'sent_ms'], $name);
            }

            foreach ($state['taunts']['shown'] ?? [] as $taunt) {
                expect(array_keys($taunt))->toBe(['id', 'from', 'to', 'taunt', 'sent_ms'], $name);
            }
        }

        // The cost of a close only in its reveal.
        if ($state['round'] !== null && $state['round']['reveal'] !== null) {
            expect(array_keys($state['round']['reveal']))->toBe(['solution', 'word', 'mine', 'results', 'spent'], $name);
        }
    }

    expect(roomFixture('state.taunted')['taunts']['received'])->toHaveCount(1)
        ->and(roomFixture('state.screen-taunted')['taunts']['shown'])->toHaveCount(2)
        ->and(roomFixture('state.reveal')['round']['reveal']['spent'])->toBe([['player_id' => '01kaqrx2ab3c4d5e6f7g8h9jkm', 'count' => 1]]);

    // A live game has its taunts and nothing else has; a received taunt never names its target, a shown one always does.
    $answer = roomFixture('state.answer');
    $taunted = roomFixture('state.taunted');
    $screen = roomFixture('state.screen-taunted');

    expect(fn () => RoomStateData::fromArray([...$answer, 'taunts' => null]))->toThrow(InvalidArgumentException::class, 'taunts')
        ->and(fn () => RoomStateData::fromArray([...roomFixture('state.over'), 'taunts' => $answer['taunts']]))->toThrow(InvalidArgumentException::class, 'taunts')
        ->and(fn () => RoomStateData::fromArray([...$answer, 'taunts' => [...$taunted['taunts'], 'received' => $screen['taunts']['shown']]]))->toThrow(InvalidArgumentException::class, 'target')
        ->and(fn () => RoomStateData::fromArray([...$answer, 'taunts' => [...$screen['taunts'], 'shown' => $taunted['taunts']['received']]]))->toThrow(InvalidArgumentException::class, 'target')
        ->and(fn () => RoomStateData::fromArray([...$answer, 'taunts' => [...$answer['taunts'], 'balance' => -1]]))->toThrow(InvalidArgumentException::class, 'below 0');
});

it('says each player\'s «Hazıram» at v 7: never the host\'s, an absent player\'s, in a closed room or while a game is live', function (): void {
    // ADR 035 decision 6: `ready` is derived; a fixture never says it where it cannot count.
    $marked = [];

    foreach (array_filter(array_keys(roomPayloads()), static fn (string $name): bool => str_starts_with($name, 'state.')) as $name) {
        $state = roomFixture($name);

        foreach ($state['players'] as $player) {
            expect($player['ready'])->toBeBool();

            if ($player['ready']) {
                expect($player['host'])->toBeFalse($name)
                    ->and($player['present'])->toBeTrue($name)
                    ->and($state['room']['status'])->toBe('open', $name)
                    ->and($state['game']['phase'] ?? null)->not->toBe('live', $name);
                $marked[] = $name;
            }
        }
    }

    expect($marked)->toBe(['state.lobby', 'state.over']);
});

it('carries an anagram\'s clue and its orders at v 8, and in every other question none', function (): void {
    // ADR 037 decision 21: `clue` and `orders` last in every question; null and [] but in an anagram.
    foreach (array_filter(array_keys(roomPayloads()), static fn (string $name): bool => str_starts_with($name, 'state.')) as $name) {
        $ask = roomFixture($name)['round']['ask'] ?? null;

        if ($ask === null) {
            continue;
        }

        expect(array_keys($ask))->toBe(['shape', 'pick', 'layout', 'grid', 'shown', 'items', 'clue', 'orders'], $name);

        if (! str_contains($name, '.anagram')) {
            expect($ask['clue'])->toBeNull($name)
                ->and($ask['orders'])->toBe([], $name);
        }
    }

    $ask = roomFixture('state.answer.anagram')['round']['ask'];
    $says = array_column($ask['items'], 'text', 'id');
    $readings = array_map(static fn (array $order): string => implode('', array_map(static fn (string $id): string => $says[$id], $order)), $ask['orders']);

    // «alma»: twelve orders, two of them words — a third of them, four, none a word.
    expect($ask['clue'])->toBe(app(ContentRepository::class)->roomClues()->of('alma'))
        ->and($ask['orders'][0])->toBe(array_column($ask['items'], 'id'))
        ->and($readings)->toBe(['MALA', 'LAMA', 'AMLA', 'LMAA'])
        ->and(count($ask['orders']))->toBe(AnagramOrders::count(RoomWords::arrangements('alma'), 2));

    // A question stored before ADR 037 reads with no clue and no orders.
    $older = array_diff_key($ask, ['clue' => true, 'orders' => true]);

    expect(RoomAskData::fromArray($older)->toArray())->toBe([...$older, 'clue' => null, 'orders' => []]);

    // The DTO refuses what zod refuses: not the items' order first, an item missing or twice, two orders reading alike, more than six.
    [$first, $second] = $ask['orders'];

    foreach ([
        [$second, $first],
        [$first, array_slice($second, 1)],
        [$first, [$first[0], $first[0], $first[2], $first[3]]],
        [$first, [$first[0], $first[3], $first[2], $first[1]]],
        array_fill(0, AnagramOrders::MAX + 1, $first),
    ] as $orders) {
        expect(fn () => RoomAskData::fromArray([...$ask, 'orders' => $orders]))->toThrow(InvalidArgumentException::class);
    }
});

it('says the room\'s voice at v 5, each player\'s standing only while it is on, and nothing of it in a closed room', function (): void {
    foreach (array_filter(array_keys(roomPayloads()), static fn (string $name): bool => str_starts_with($name, 'state.')) as $name) {
        $state = roomFixture($name);

        expect($state['v'])->toBe(8, $name)
            ->and(RoomStateData::VERSION)->toBe(8)
            ->and(array_key_last($state))->toBe('voice', $name);

        foreach ($state['players'] as $player) {
            // A standing, never whether they are in the voice, their microphone or whether they speak.
            expect(array_keys($player))->toBe(['id', 'name', 'present', 'host', 'voice', 'ready'], $name)
                ->and($player['voice'] !== null)->toBe(($state['voice']['on'] ?? false) && ($player['host'] || $player['present']), $name);
        }

        if ($state['voice'] !== null) {
            expect(array_keys($state['voice']))->toBe(['on', 'agreed'], $name)
                ->and($state['room']['status'])->toBe('open', $name);
        }
    }

    $voice = roomFixture('state.voice');
    $off = roomFixture('state.voice.off');

    expect($voice['voice'])->toBe(['on' => true, 'agreed' => true])
        ->and(array_column($voice['players'], 'voice'))->toBe(['open', 'open', 'muted', null, 'waiting'])
        ->and($off['voice'])->toBe(['on' => false, 'agreed' => false])
        ->and($off['me']['host'])->toBeTrue()
        ->and(array_unique(array_column($off['players'], 'voice')))->toBe([null]);

    // A key of voice in a closed room fails, as does a standing while the voice is off, or none while it is on.
    $closed = [...$voice, 'room' => [...$voice['room'], 'status' => 'closed', 'code' => null], 'setup' => null];
    $quiet = static fn (array $state): array => [...$state, 'players' => array_map(static fn (array $player): array => [...$player, 'voice' => null], $state['players'])];

    expect(fn () => RoomStateData::fromArray($quiet($closed)))->toThrow(InvalidArgumentException::class, 'closed room has no voice')
        ->and(fn () => RoomStateData::fromArray([...$quiet($closed), 'voice' => null])->voice)->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => RoomStateData::fromArray([...$voice, 'voice' => ['on' => false, 'agreed' => true]]))->toThrow(InvalidArgumentException::class, 'voice standing')
        ->and(fn () => RoomStateData::fromArray([...$voice, 'voice' => null]))->toThrow(InvalidArgumentException::class, 'voice standing')
        ->and(fn () => RoomStateData::fromArray($quiet($voice)))->toThrow(InvalidArgumentException::class, 'voice standing')
        ->and(fn () => RoomStateData::fromArray([...$voice, 'v' => 4]))->toThrow(InvalidArgumentException::class, 'version')
        ->and(fn () => RoomStateData::fromArray([...$voice, 'players' => [...array_slice($voice['players'], 0, 3), [...$voice['players'][3], 'voice' => 'open'], $voice['players'][4]]]))->toThrow(InvalidArgumentException::class, 'voice standing');
});

it('carries a board game\'s match in its game\'s state, shaped by its phase, and refuses one out of place', function (): void {
    foreach (array_filter(array_keys(roomPayloads()), static fn (string $name): bool => str_starts_with($name, 'state.')) as $name) {
        $state = roomFixture($name);
        $board = ($state['game']['kind'] ?? null) === 'board';

        expect($state['match'] !== null)->toBe($board, $name)
            ->and($state['round'] !== null)->toBe(! $board && ($state['game']['phase'] ?? null) === 'live', $name)
            ->and(array_keys($state['game'] ?? ['kind' => 'race']))->toContain('kind');

        if ($board) {
            expect(array_keys($state['match']))->toBe(['phase', 'starts_ms', 'sides', 'turn', 'ply', 'since_ms', 'flag_ms', 'position', 'last', 'moves', 'legal', 'offer', 'can_offer', 'result'], $name)
                ->and($state['match']['phase'] === 'over')->toBe($state['game']['phase'] === 'over', $name)
                ->and(array_column($state['match']['sides'], 'color'))->toBe(['white', 'black'], $name)
                ->and(count($state['match']['moves']))->toBe($state['match']['ply'], $name);
        }
    }

    $play = roomFixture('state.match.play');
    $watch = roomFixture('state.match.watch');
    $offer = roomFixture('state.match.offer');
    $over = roomFixture('state.match.over');

    // The seat to move is offered the compulsory capture; the watcher sees the same board and nothing to play.
    expect($play['match']['legal'])->toBe(['capture' => true, 'paths' => [['d4', 'f6']]])
        ->and($watch['match']['legal'])->toBeNull()
        ->and(array_diff_key($watch['match'], ['legal' => true]))->toBe(array_diff_key($play['match'], ['legal' => true]))
        ->and([$offer['match']['offer'], $offer['match']['can_offer']])->toBe(['black', false])
        ->and($over['match']['result']['ending'])->toBe('resigned')
        ->and(roomFixture('state.match.countdown')['match']['phase'])->toBe('countdown');

    // A key of a match in a phase that has none fails, as the zod schemas do.
    $refused = static function (array $state, array $match, string $why): void {
        expect(fn () => RoomStateData::fromArray([...$state, 'match' => [...$state['match'], ...$match]]))
            ->toThrow(InvalidArgumentException::class, $why);
    };

    $refused($play, ['result' => $over['match']['result']], 'result');
    $refused($over, ['legal' => $play['match']['legal']], 'Legal moves');
    $refused($over, ['turn' => 'white'], 'side to move');
    $refused(roomFixture('state.match.countdown'), ['offer' => 'black'], 'Before the clock starts');
    $refused(roomFixture('state.match.countdown'), ['can_offer' => true], 'draw');
    $refused($play, ['offer' => 'white'], 'just moved');
    $refused($play, ['moves' => ['c3-d4']], 'moves made');
    $refused($play, ['sides' => array_reverse($play['match']['sides'])], 'white and then black');

    // A match only for a board game, and over exactly when its game is.
    expect(fn () => RoomStateData::fromArray([...$play, 'match' => null]))->toThrow(InvalidArgumentException::class, 'match')
        ->and(fn () => RoomStateData::fromArray([...roomFixture('state.answer'), 'match' => $play['match']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RoomStateData::fromArray([...$over, 'game' => [...$over['game'], 'phase' => 'live'], 'taunts' => $play['taunts']]))->toThrow(InvalidArgumentException::class, 'over exactly')
        ->and(fn () => RoomStateData::fromArray([...$play, 'round' => roomFixture('state.answer')['round']]))->toThrow(InvalidArgumentException::class, 'round')
        ->and(fn () => RoomStateData::fromArray([...$play, 'game' => [...$play['game'], 'kind' => 'race']]))->toThrow(InvalidArgumentException::class, 'kind')
        ->and(fn () => RoomStateData::fromArray([...$play, 'setup' => [...$play['setup'], 'rounds' => 5]]))->toThrow(InvalidArgumentException::class);
});

it('ends a match by each of its endings, its places by the outcome', function (string $ending, string $outcome, array $places): void {
    $over = roomFixture('state.match.over');
    $standing = $over['match']['result']['standing'];
    $result = ['outcome' => $outcome, 'ending' => $ending, 'standing' => [[...$standing[0], 'place' => $places[0]], [...$standing[1], 'place' => $places[1]]], 'spent' => []];
    $state = [...$over, 'match' => [...$over['match'], 'result' => $result]];

    expect(RoomStateData::fromArray($state)->toArray())->toBe($state);

    // The places follow the outcome, never the points; only a stopped game has none; an ending has its kind of outcome.
    $wrongPlaces = $places === [1, 2] ? [2, 1] : [1, 2];
    $misplaced = [[...$standing[0], 'place' => $wrongPlaces[0]], [...$standing[1], 'place' => $wrongPlaces[1]]];

    foreach ([['standing' => $misplaced], ['outcome' => $outcome === 'none' ? 'white' : 'none'], ['outcome' => $outcome === 'draw' ? 'white' : 'draw']] as $wrong) {
        expect(fn () => RoomStateData::fromArray([...$state, 'match' => [...$state['match'], 'result' => [...$result, ...$wrong]]]))
            ->toThrow(InvalidArgumentException::class);
    }
})->with([
    'no_moves' => ['no_moves', 'white', [1, 2]],
    'resigned' => ['resigned', 'black', [1, 2]],
    'left' => ['left', 'white', [1, 2]],
    'time' => ['time', 'black', [1, 2]],
    'agreed' => ['agreed', 'draw', [1, 1]],
    'repetition' => ['repetition', 'draw', [1, 1]],
    'three_kings' => ['three_kings', 'draw', [1, 1]],
    'main_road' => ['main_road', 'draw', [1, 1]],
    'balance' => ['balance', 'draw', [1, 1]],
    'kings_only' => ['kings_only', 'draw', [1, 1]],
    'stopped' => ['stopped', 'none', [null, null]],
]);

it('takes an act of each kind as the device sends it, and never a leave', function (): void {
    $move = roomFixture('act');

    $named = ['event_id' => $move['event_id'], 'game_id' => $move['game_id']];

    expect(RoomActData::fromArray($move)->toArray())->toBe($move)
        ->and(RoomActData::fromArray([...$named, 'kind' => 'resign'])->toArray())->toBe([...$named, 'kind' => 'resign'])
        ->and(RoomActData::fromArray([...$named, 'kind' => 'offer', 'ply' => 3])->toArray())->toBe([...$named, 'kind' => 'offer', 'ply' => 3]);

    foreach ([['kind' => 'leave', 'ply' => 2], ['kind' => 'move', 'ply' => 2], ['kind' => 'resign', 'ply' => 2], ['kind' => 'offer'], ['kind' => 'decline', 'ply' => 2, 'path' => ['d4', 'f6']]] as $wrong) {
        expect(fn () => RoomActData::fromArray([...$named, ...$wrong]))->toThrow(InvalidArgumentException::class);
    }
});
