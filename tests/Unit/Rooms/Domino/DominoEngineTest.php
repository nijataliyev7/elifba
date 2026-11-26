<?php

use App\Domain\Rooms\Enums\RoomComputerLevel;
use App\Domain\Rooms\Enums\RoomOpenerWhy;
use App\Domain\Rooms\Enums\RoomTable;
use App\Domain\Rooms\Support\Domino\Domino101;
use App\Domain\Rooms\Support\Domino\DominoDeal;
use App\Domain\Rooms\Support\Domino\DominoEngine;
use App\Domain\Rooms\Support\Domino\DominoGame;
use App\Domain\Rooms\Support\Domino\DominoHand;
use App\Domain\Rooms\Support\Domino\DominoRules;
use App\Domain\Rooms\Support\Domino\DominoTile;
use App\Domain\Rooms\Support\Domino\DominoUnseen;
use App\Domain\Rooms\Support\Domino\DominoView;
use Illuminate\Support\Facades\Config;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Tests\Support\Domino;

/*
 * The computer of domino «101» (docs/architecture.md §12 "Domino (Y4–Y6)"
 * → "The computer", ADR 038 decisions 9, 54–56): it chooses from what its
 * seat has seen alone, one of the judge's plays with an end listed for it,
 * the same play for the same view, level and source — and «Çətin» plays
 * its plays out in deals of the unseen tiles that agree with every pass
 * and draw the seat saw, which the judge, given such a deal and the same
 * plays, shows the seat exactly as it saw them.
 *
 * The positions are every turn of the oracle's hands (`hands.json`) and of
 * every hand of its games (`games.json`), at every table: the seat to move
 * before its act — a play, or «Sən aç» where the fixture gave the opening.
 */

/** What a seat of a hand has seen, as a game shows it in the hand's own terms. */
function dominoEngineView(DominoHand $hand, int $seat): DominoView
{
    return new DominoView(
        seat: $seat,
        table: $hand->table,
        hand: $hand->number,
        opener: $hand->opener,
        why: $hand->why,
        turn: $hand->turn,
        tiles: $hand->tiles[$seat],
        line: $hand->line,
        ends: $hand->ends(),
        held: array_map(count(...), $hand->tiles),
        boneyard: count($hand->boneyard),
        drawable: $hand->drawable(),
        closed: $hand->closed(),
        events: $hand->events,
        legal: $hand->turn === $seat ? $hand->legal() : [],
        sides: (new Domino101)->ledger($hand->table->sides())->sides,
        bank: 0,
    );
}

/**
 * Every position of the fixtures where a seat is to move, one at a time:
 * the hand before its act, the fixture's hand it is of and how many of its
 * acts came before. Walked again for each test (a few tens of
 * milliseconds) and never held whole, so the suite's memory does not carry
 * them.
 *
 * @return Generator<int, array{cite: string, entry: array<string, mixed>, number: int, acts: int, hand: DominoHand}>
 */
function dominoEnginePositions(int $every = 1, int $from = 0): Generator
{
    $index = 0;
    $entries = [];

    foreach (Domino::fixture('hands')['hands'] as $entry) {
        $entries[] = ["hands.json {$entry['id']}", $entry, 1];
    }

    foreach (Domino::fixture('games')['games'] as $game) {
        foreach ($game['hands'] as $hand) {
            $entries[] = ["games.json {$game['game']} · hand {$hand['hand']}", [...$hand, 'table' => $game['table']], $hand['hand']];
        }
    }

    foreach ($entries as [$cite, $entry, $number]) {
        $hand = Domino::opened($entry, $number);

        foreach ($entry['turns'] as $turnIndex => $turn) {
            if ($index++ % $every === $from) {
                yield ['cite' => "{$cite} · turn ".($turnIndex + 1), 'entry' => $entry, 'number' => $number, 'acts' => $turnIndex, 'hand' => $hand];
            }

            $hand = Domino::act($hand, $turn['act'], $turnIndex + 1) ?? throw new RuntimeException("{$cite}: the judge refused turn ".($turnIndex + 1).'.');
        }
    }
}

function dominoEngineRandom(int $seed): Randomizer
{
    return new Randomizer(new Xoshiro256StarStar($seed));
}

/**
 * A view with its tiles as a set, so a deal that orders a seat's tiles
 * otherwise compares equal.
 *
 * @return array<string, mixed>
 */
function dominoEngineSeen(DominoView $view): array
{
    $seen = get_object_vars($view);
    $seen['tiles'] = Domino::canon(DominoTile::names($view->tiles));
    $seen['legal'] = Domino::canon(array_map(static fn (array $play): array => [$play['tile']->name(), $play['ends']], $view->legal));

    return $seen;
}

/**
 * The plays the view lists, as `tile` or `tile end`.
 *
 * @return list<string>
 */
function dominoEngineLegal(DominoView $view): array
{
    $plays = [];

    foreach ($view->legal as $play) {
        foreach ($play['ends'] === [] ? [null] : $play['ends'] as $end) {
            $plays[] = trim("{$play['tile']->name()} {$end}");
        }
    }

    return $plays;
}

/**
 * @param  array{tile: DominoTile, end: string|null}  $choice
 */
function dominoEnginePlay(array $choice): string
{
    return trim("{$choice['tile']->name()} {$choice['end']}");
}

it('deals the unseen tiles only in ways the judge, given the same plays, shows the seat exactly as it saw them (ADR 038 decisions 54–55)', function (): void {
    $checked = 0;

    foreach (dominoEnginePositions(3) as $position) {
        $hand = $position['hand'];
        $seat = $hand->turn ?? throw new RuntimeException('No turn.');
        $view = dominoEngineView($hand, $seat);
        $unseen = DominoUnseen::of($view);
        $random = dominoEngineRandom(crc32($position['cite']));

        foreach (range(1, 4) as $try) {
            $cite = "{$position['cite']} · deal {$try}";
            $deal = $unseen->deal($random);
            $replay = DominoHand::open(
                new Domino101,
                DominoDeal::set($hand->table, array_map(DominoTile::names(...), $deal['dealt']), DominoTile::names($deal['stock'])),
                $position['number'],
                Domino::rightOf($position['entry']['right']),
                0,
            );

            foreach (array_slice($position['entry']['turns'], 0, $position['acts']) as $index => $turn) {
                $replay = Domino::act($replay, $turn['act'], $index + 1) ?? throw new RuntimeException("{$cite}: the judge refused turn ".($index + 1).' of the guessed deal.');
            }

            expect(dominoEngineSeen(dominoEngineView($replay, $seat)))->toEqual(dominoEngineSeen($view), $cite)
                ->and(array_map(static fn (array $tiles): array => Domino::canon(DominoTile::names($tiles)), $deal['hands']))
                ->toBe(array_map(static fn (array $tiles): array => Domino::canon(DominoTile::names($tiles)), $replay->tiles), "{$cite} · the hands now")
                ->and(DominoTile::names($deal['boneyard']))->toBe(DominoTile::names($replay->boneyard), "{$cite} · the boneyard now");
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(1500);
});

/** A deal at a table that R4 throws away: seat 0 holds five doubles. */
function dominoEngineThrown(RoomTable $table): DominoDeal
{
    $first = ['0-0', '1-1', '2-2', '3-3', '4-4', '1-0', '2-1'];
    $rest = array_values(array_diff(array_keys(DominoTile::all()), $first));
    $hands = [$first];

    foreach (range(1, $table->seats() - 1) as $seat) {
        $hands[] = array_slice($rest, ($seat - 1) * RoomTable::HAND, RoomTable::HAND);
    }

    return DominoDeal::set($table, $hands, array_slice($rest, ($table->seats() - 1) * RoomTable::HAND));
}

/**
 * A game of `games.json` replayed as a room holds it, just before each of
 * its acts: the game a seat's view is taken from in the room.
 *
 * @param  array<string, mixed>  $game
 * @return list<array{cite: string, game: DominoGame}>
 */
function dominoEngineGames(array $game, int $every): array
{
    $table = RoomTable::from($game['table']);
    $tries = [];
    $acts = [];
    $before = [];
    $at = Domino::STARTS;

    foreach ($game['hands'] as $hand) {
        $tries[$hand['hand']] = [...array_fill(0, count($hand['thrown']), dominoEngineThrown($table)), Domino::dealOf($table, $hand['deal'])];

        foreach ($hand['turns'] as $index => $turn) {
            $actedMs = $at + ($index + 1) * 1000;
            $before[] = ["games.json {$game['game']} · hand {$hand['hand']} · turn ".($index + 1), $actedMs - 500];
            $acts[] = $turn['act'][0] === 'yield'
                ? Domino::acts(['give'], count($acts), $actedMs - 1000)[0]
                : Domino::acts([implode(' ', array_slice($turn['act'], 1))], count($acts), $actedMs - 1000)[0];
        }

        $at += count($hand['turns']) * 1000 + Domino::REVEAL;
    }

    $games = [];

    foreach ($before as $index => [$cite, $nowMs]) {
        if ($index % $every === 0) {
            $games[] = ['cite' => $cite, 'game' => Domino::game($table, Domino::source($tries), $acts, $nowMs)];
        }
    }

    return $games;
}

it('chooses one of the judge\'s plays with an end listed for it, at every position, table and level — and never gives the opening (ADR 038 decisions 54–56)', function (RoomComputerLevel $level): void {
    // «Çətin» looks at a few deals here; how many it looks at changes which play, never whether it is one.
    Config::set('elifba.rooms.computer.domino.3.deals', 3);
    $tables = [];

    foreach (dominoEnginePositions() as $position) {
        $hand = $position['hand'];
        $view = dominoEngineView($hand, $hand->turn ?? throw new RuntimeException('No turn.'));
        $choice = DominoEngine::choose($view, $level, dominoEngineRandom(crc32($position['cite'])));

        expect(dominoEnginePlay($choice))->toBeIn(dominoEngineLegal($view), $position['cite'])
            ->and($choice['end'] === null)->toBe($view->line === [], $position['cite']);
        $tables[$hand->table->value] = true;
    }

    expect(array_keys($tables))->toEqualCanonicalizing(array_map(static fn (RoomTable $table): string => $table->value, RoomTable::cases()));
})->with(RoomComputerLevel::cases());

it('chooses from the view a room game gives its seat, at every level (ADR 038 decisions 28, 54)', function (string $name): void {
    Config::set('elifba.rooms.computer.domino.3.deals', 3);
    $game = array_column(Domino::fixture('games')['games'], null, 'game')[$name];

    foreach (dominoEngineGames($game, 4) as ['cite' => $cite, 'game' => $replayed]) {
        $view = $replayed->view($replayed->turn() ?? throw new RuntimeException("{$cite}: no turn."));

        foreach (RoomComputerLevel::cases() as $level) {
            expect(dominoEnginePlay(DominoEngine::choose($view, $level, dominoEngineRandom(crc32($cite)))))->toBeIn(dominoEngineLegal($view), "{$cite} · level {$level->value}");
        }
    }
})->with(fn (): array => array_column(Domino::fixture('games')['games'], 'game'));

it('chooses the same play for the same view, level and source (ADR 038 decisions 55, 56)', function (RoomComputerLevel $level): void {
    foreach (dominoEnginePositions(25, 7) as $position) {
        $view = dominoEngineView($position['hand'], $position['hand']->turn ?? throw new RuntimeException('No turn.'));
        $seed = crc32($position['cite']);

        expect(dominoEnginePlay(DominoEngine::choose($view, $level, dominoEngineRandom($seed))))
            ->toBe(dominoEnginePlay(DominoEngine::choose($view, $level, dominoEngineRandom($seed))), $position['cite']);
    }
})->with(RoomComputerLevel::cases());

it('chooses the same play for two views equal in all the seat may know — another deal of what it has not seen, its own tiles got in another order (ADR 038 decisions 9, 54)', function (RoomComputerLevel $level): void {
    Config::set('elifba.rooms.computer.domino.3.deals', 6);
    $differed = 0;

    foreach (dominoEnginePositions(9, 4) as $position) {
        $hand = $position['hand'];
        $seat = $hand->turn ?? throw new RuntimeException('No turn.');
        $view = dominoEngineView($hand, $seat);
        $deal = DominoUnseen::of($view)->deal(dominoEngineRandom(crc32("other {$position['cite']}")));
        $other = DominoHand::open(
            new Domino101,
            DominoDeal::set($hand->table, array_map(DominoTile::names(...), $deal['dealt']), DominoTile::names($deal['stock'])),
            $position['number'],
            Domino::rightOf($position['entry']['right']),
            0,
        );

        foreach (array_slice($position['entry']['turns'], 0, $position['acts']) as $index => $turn) {
            $other = Domino::act($other, $turn['act'], $index + 1) ?? throw new RuntimeException("{$position['cite']}: refused.");
        }

        $seed = crc32($position['cite']);

        if ($other->tiles !== $hand->tiles || $other->boneyard !== $hand->boneyard) {
            $differed++;
        }

        expect(dominoEnginePlay(DominoEngine::choose(dominoEngineView($other, $seat), $level, dominoEngineRandom($seed))))
            ->toBe(dominoEnginePlay(DominoEngine::choose($view, $level, dominoEngineRandom($seed))), $position['cite']);
    }

    expect($differed)->toBeGreaterThan(200);
})->with(RoomComputerLevel::cases());

it('chooses the same play in two games whose other hands differ, at every seat that cannot tell them apart (ADR 038 decisions 9, 54)', function (RoomComputerLevel $level): void {
    // The game of `DominoViewTest`: seats 1 and 3 hold `6-6` and `5-5` the other way round.
    $hands = static fn (string $one, string $three): array => [
        ['5-0', '0-0', '4-1', '4-0', '5-3', '4-3', '6-0'],
        ['6-3', $one, '3-1', '1-0', '3-0', '6-2', '2-0'],
        ['4-2', '5-1', '1-1', '4-4', '6-5', '2-1', '6-1'],
        ['3-3', '3-2', $three, '5-2', '5-4', '6-4', '2-2'],
    ];
    $plays = ['1-1', '4-1 left', '3-1 right', '4-4 left', '3-3 right', '4-3 right', '4-2 left', '3-2 left', '4-0 right', '1-0 right', '2-1 right', '2-2 right', '5-3 left', '2-0 right', '5-1 left'];
    $one = Domino::hand(Domino::deal(RoomTable::Pairs, $hands('6-6', '5-5')));
    $other = Domino::hand(Domino::deal(RoomTable::Pairs, $hands('5-5', '6-6')));
    $chose = 0;

    foreach ($plays as $ply => $play) {
        foreach ([0, 2] as $seat) {
            if ($one->turn === $seat) {
                $mine = dominoEngineView($one, $seat);

                expect($mine)->toEqual(dominoEngineView($other, $seat))
                    ->and(DominoEngine::choose($mine, $level, dominoEngineRandom($ply)))->toBe(DominoEngine::choose(dominoEngineView($other, $seat), $level, dominoEngineRandom($ply)), "ply {$ply}");
                $chose++;
            }
        }

        $one = Domino::play($one, $play);
        $other = Domino::play($other, $play);
    }

    expect($chose)->toBeGreaterThan(5);
})->with(RoomComputerLevel::cases());

/**
 * A seat to move in the middle of a hand: its tiles and the line's two
 * ends, the line two tiles it does not hold. What «Asan» and «Orta» read.
 *
 * @param  list<string>  $tiles
 */
function dominoEngineAt(array $tiles, int $left, int $right): DominoView
{
    $held = DominoTile::list($tiles);

    foreach (range(0, 6) as $middle) {
        $line = [
            ['tile' => DominoTile::pair($left, $middle), 'left' => $left, 'right' => $middle],
            ['tile' => DominoTile::pair($middle, $right), 'left' => $middle, 'right' => $right],
        ];

        if ($line[0]['tile'] !== $line[1]['tile'] && ! in_array($line[0]['tile'], $held, true) && ! in_array($line[1]['tile'], $held, true)) {
            $ends = ['left' => $left, 'right' => $right];

            return new DominoView(
                seat: 0,
                table: RoomTable::Four,
                hand: 1,
                opener: 0,
                why: RoomOpenerWhy::Finished,
                turn: 0,
                tiles: $held,
                line: $line,
                ends: $ends,
                held: [count($held), 7, 7, 7],
                boneyard: 0,
                drawable: 0,
                closed: false,
                events: [],
                legal: DominoRules::plays($held, $ends, []),
                sides: (new Domino101)->ledger(4)->sides,
                bank: 0,
            );
        }
    }

    throw new RuntimeException('No line shows those ends.');
}

it('plays as «Orta»: the most pips first (ADR 038 decision 55)', function (): void {
    expect(dominoEnginePlay(DominoEngine::choose(dominoEngineAt(['1-0', '6-2', '6-5'], 6, 1), RoomComputerLevel::Medium, dominoEngineRandom(1))))->toBe('6-5 left');
});

it('plays as «Orta»: of the same pips a double first, before the numbers it keeps open (ADR 038 decision 55)', function (): void {
    // `6-2` on the right would leave 4 and 2 open — three of its tiles — and `4-4` on the left 4 and 6, one.
    expect(dominoEnginePlay(DominoEngine::choose(dominoEngineAt(['6-2', '2-0', '2-1', '4-4'], 4, 6), RoomComputerLevel::Medium, dominoEngineRandom(1))))->toBe('4-4 left');
});

it('plays as «Orta»: the end that leaves open the numbers it holds most of (ADR 038 decision 55)', function (): void {
    expect(dominoEnginePlay(DominoEngine::choose(dominoEngineAt(['5-3', '3-1', '3-0'], 5, 3), RoomComputerLevel::Medium, dominoEngineRandom(1))))->toBe('5-3 left')
        ->and(dominoEnginePlay(DominoEngine::choose(dominoEngineAt(['5-3', '5-1', '5-0'], 5, 3), RoomComputerLevel::Medium, dominoEngineRandom(1))))->toBe('5-3 right');
});

it('plays as «Asan»: any of its plays, by its source (ADR 038 decision 55)', function (): void {
    $view = dominoEngineAt(['6-2', '2-0', '2-1', '4-4', '6-6'], 4, 6);
    $chosen = [];

    foreach (range(1, 64) as $seed) {
        $chosen[] = dominoEnginePlay(DominoEngine::choose($view, RoomComputerLevel::Easy, dominoEngineRandom($seed)));
    }

    expect(array_values(array_unique($chosen)))->toEqualCanonicalizing(dominoEngineLegal($view));
});

it('plays as «Orta» at «Çətin» when it may look at no deal, and otherwise sometimes not (ADR 038 decision 55)', function (): void {
    $differ = 0;

    foreach (dominoEnginePositions(20, 3) as $position) {
        $view = dominoEngineView($position['hand'], $position['hand']->turn ?? throw new RuntimeException('No turn.'));
        $medium = dominoEnginePlay(DominoEngine::choose($view, RoomComputerLevel::Medium, dominoEngineRandom(1)));
        Config::set('elifba.rooms.computer.domino.3.deals', 0);

        expect(dominoEnginePlay(DominoEngine::choose($view, RoomComputerLevel::Hard, dominoEngineRandom(1))))->toBe($medium, $position['cite']);

        Config::set('elifba.rooms.computer.domino.3.deals', 8);

        if (dominoEnginePlay(DominoEngine::choose($view, RoomComputerLevel::Hard, dominoEngineRandom(1))) !== $medium) {
            $differ++;
        }
    }

    expect($differ)->toBeGreaterThan(5);
});

it('refuses a view whose seat has no play', function (): void {
    $position = dominoEnginePositions()->current();
    $hand = $position['hand'];
    $waiting = (($hand->turn ?? 0) + 1) % $hand->table->seats();

    DominoEngine::choose(dominoEngineView($hand, $waiting), RoomComputerLevel::Medium, dominoEngineRandom(1));
})->throws(InvalidArgumentException::class);

it('chooses the pinned play at each level with the configured deals: integers and counts, no clock and no hash order, so every machine agrees (ADR 038 decision 55)', function (): void {
    // «Asan», «Orta», «Çətin» from the source `crc32(cite)`; a change of `elifba.rooms.computer.domino` changes «Çətin»'s.
    $pinned = [
        'hands.json two-first-3 · turn 10' => ['3-3 right', '6-5 left', '6-2 left'],
        'hands.json four-later-1 · turn 1' => ['5-3', '6-4', '5-3'],
        'hands.json pairs-later-3 · turn 2' => ['5-4 left', '5-4 left', '4-2 left'],
        'hands.json four-r16-lone-0-0 · turn 12' => ['6-1 right', '6-2 right', '6-1 right'],
        'games.json two-2 · hand 5 · turn 11' => ['4-2 left', '6-4 left', '4-1 left'],
        'games.json three-2 · hand 2 · turn 3' => ['6-6 right', '6-6 right', '5-5 left'],
        'games.json four-1 · hand 1 · turn 11' => ['4-1 right', '6-0 left', '4-1 right'],
        'games.json pairs-1 · hand 2 · turn 1' => ['2-1', '6-6', '4-4'],
        'games.json pairs-2 · hand 3 · turn 5' => ['1-0 left', '3-2 right', '2-2 right'],
        'games.json pairs-3 · hand 6 · turn 7' => ['3-3 left', '6-3 left', '3-3 left'],
    ];
    $hands = [];

    foreach (dominoEnginePositions() as $position) {
        if (isset($pinned[$position['cite']])) {
            $hands[$position['cite']] = $position['hand'];
        }
    }

    expect(RoomComputerLevel::Hard->deals())->toBe(40)
        ->and(array_keys($hands))->toEqualCanonicalizing(array_keys($pinned));

    foreach ($pinned as $cite => $plays) {
        $hand = $hands[$cite];
        $view = dominoEngineView($hand, $hand->turn ?? throw new RuntimeException('No turn.'));

        foreach (RoomComputerLevel::cases() as $index => $level) {
            expect(dominoEnginePlay(DominoEngine::choose($view, $level, dominoEngineRandom(crc32($cite)))))->toBe($plays[$index], "{$cite} · level {$level->value}");
        }
    }
});
