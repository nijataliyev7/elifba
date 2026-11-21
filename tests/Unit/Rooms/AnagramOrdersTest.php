<?php

use App\Domain\Content\Data\RangeData;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\RoomWords;
use App\Domain\Language\Support\Az;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Support\Mechanics\AnagramBuilder;
use App\Domain\Rooms\Support\Mechanics\AnagramOrders;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * The orders «Qarışdır» lays an anagram's tiles out in (docs/architecture.md
 * §12 "«Anaqram»: a clue, a shuffle and a bought letter (ADR 037)", ADR 037
 * decisions 5–9 and 16): a list bounded by a third of the letters' orders
 * and six, none reading an excluded spelling, no two alike — for the
 * question (nothing bought) and for a buyer with the word's first k tiles
 * taken out, for every k that leaves the tiles more than one order.
 */

/**
 * The word's tiles in its order, ids `t0`, `t1`… padded to eight.
 *
 * @return list<RoomItemData>
 */
function anagramTiles(string $word): array
{
    return array_map(
        static fn (int $at, string $letter): RoomItemData => new RoomItemData(str_pad("t{$at}", 8, 'x'), RoomItemKind::Letter, Az::upper($letter)),
        array_keys(mb_str_split($word)),
        mb_str_split($word),
    );
}

/**
 * @param  list<RoomItemData>  $tiles
 */
function anagramReads(array $tiles): string
{
    return implode('', array_map(static fn (RoomItemData $tile): string => $tile->says(), $tiles));
}

/**
 * Every spelling an anagram of the word accepts, in the tiles' capitals.
 *
 * @return list<list<string>>
 */
function anagramAccept(RoomWords $words, string $word): array
{
    return array_map(static fn (string $spelling): array => mb_str_split(Az::upper($spelling)), $words->accepted($word));
}

function anagramWords(): RoomWords
{
    $content = app(ContentRepository::class);

    return RoomWords::of($content->roomWords(), $content->names()->names);
}

it('bounds a list by a third of the orders the letters have, by six, and by those left', function (): void {
    expect(AnagramOrders::MAX)->toBe(6)
        ->and(AnagramOrders::SHARE)->toBe(3)
        // «baba»: six orders, one a word — two shown, four never.
        ->and(AnagramOrders::count(RoomWords::arrangements('baba'), 1))->toBe(2)
        // «alma»: twelve orders, «alma» and «amal» words — four.
        ->and(AnagramOrders::count(RoomWords::arrangements('alma'), 2))->toBe(4)
        // «qarpız»: 720 orders — six.
        ->and(AnagramOrders::count(RoomWords::arrangements('qarpız'), 1))->toBe(6)
        // Only what is left, and always one: three orders, two excluded; two letters left.
        ->and(AnagramOrders::count(3, 2))->toBe(1)
        ->and(AnagramOrders::count(2, 1))->toBe(1);
});

it('gives every word an anagram may draw a list within the bound, two orders at the least', function (): void {
    $words = anagramWords();
    $counts = [];

    foreach ($words->forAnagram(new RangeData(4, 8)) as $at => $word) {
        $tiles = anagramTiles($word);
        $accept = anagramAccept($words, $word);
        $orders = AnagramOrders::draw($tiles, $accept, 0, new Randomizer(new Mt19937($at)));
        $arrangements = RoomWords::arrangements($word);

        // Shown at most a third of the orders, so the ones never shown always outnumber them two to one.
        expect(count($orders))->toBe(AnagramOrders::count($arrangements, count($accept)), $word)
            ->and(count($orders) * AnagramOrders::SHARE)->toBeLessThanOrEqual($arrangements, $word)
            ->and(count($orders))->toBeGreaterThanOrEqual(2, $word);

        $counts[count($orders)] = ($counts[count($orders)] ?? 0) + 1;
    }

    // ADR 037 decision 7: what the bound gives the list today.
    ksort($counts);

    expect($counts)->toBe([2 => 3, 4 => 42, 6 => 507]);
});

it('never lays the tiles out as an accepted spelling, nor the rest of one once the word\'s first letters are bought', function (): void {
    // ADR 037 decisions 6 and 16: k = 0 is the question's list; 1 … n − 2 a buyer's, wherever the tiles left have more than one order.
    $words = anagramWords();
    $checked = 0;

    foreach ($words->forAnagram(new RangeData(4, 8)) as $at => $word) {
        $tiles = anagramTiles($word);
        $accept = anagramAccept($words, $word);
        $reading = mb_str_split(Az::upper($word));

        foreach (range(0, count($tiles) - 2) as $bought) {
            $left = array_slice($tiles, $bought);
            $arrangements = RoomWords::arrangements(anagramReads($left));

            if ($arrangements < 2) {
                continue;
            }

            $rests = array_values(array_unique(array_map(
                static fn (array $spelling): string => implode('', array_slice($spelling, $bought)),
                array_filter($accept, static fn (array $spelling): bool => array_slice($spelling, 0, $bought) === array_slice($reading, 0, $bought)),
            )));
            $orders = AnagramOrders::draw($tiles, $accept, $bought, new Randomizer(new Mt19937($at * 16 + $bought)));
            $read = array_map(anagramReads(...), $orders);
            $ids = static fn (array $order): array => array_map(static fn (RoomItemData $tile): string => $tile->id, $order);

            expect(array_intersect($read, $rests))->toBe([], "{$word} with {$bought} bought")
                ->and(array_unique($read))->toBe($read, "{$word} with {$bought} bought")
                ->and(count($orders))->toBe(AnagramOrders::count($arrangements, count($rests)), "{$word} with {$bought} bought");

            foreach ($orders as $order) {
                // Every tile left once, by its id: the bought ones never come back into the tray.
                expect($ids($order))->toEqualCanonicalizing($ids($left));
            }

            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(2_500);
});

it('hands back tiles that have one order only as they are', function (): void {
    // «baba» with two bought: «BA» left, two orders, one the rest; with three, one letter; with four, none.
    $tiles = anagramTiles('baba');
    $accept = [['B', 'A', 'B', 'A']];
    $random = new Randomizer(new Mt19937(1));

    expect(array_map(anagramReads(...), AnagramOrders::draw($tiles, $accept, 2, $random)))->toBe(['AB'])
        ->and(array_map(anagramReads(...), AnagramOrders::draw($tiles, $accept, 3, $random)))->toBe(['A'])
        ->and(AnagramOrders::draw($tiles, $accept, 4, $random))->toBe([[]])
        ->and(fn () => AnagramOrders::draw($tiles, $accept, 5, $random))->toThrow(InvalidArgumentException::class);
});

it('draws every order that reads no word, and never the word, for the shortest words', function (): void {
    // «baba» has six orders; across seeds its list shows each of the five that are no word, «BABA» never.
    $seen = [];

    foreach (range(1, 300) as $seed) {
        foreach (AnagramOrders::draw(anagramTiles('baba'), [['B', 'A', 'B', 'A']], 0, new Randomizer(new Mt19937($seed))) as $order) {
            $seen[anagramReads($order)] = true;
        }
    }

    ksort($seen);

    expect(array_keys($seen))->toBe(['AABB', 'ABAB', 'ABBA', 'BAAB', 'BBAA']);
});

it('takes the one order left when every other reads a word', function (): void {
    // Three letters that spell five accepted words leave one order: the list is that one.
    $accept = array_map(mb_str_split(...), ['ABC', 'ACB', 'BAC', 'BCA', 'CAB']);

    foreach (range(1, 20) as $seed) {
        expect(array_map(anagramReads(...), AnagramOrders::draw(anagramTiles('abc'), $accept, 0, new Randomizer(new Mt19937($seed)))))->toBe(['CBA']);
    }

    // Letters that read an accepted spelling in every order have none.
    expect(fn () => AnagramOrders::draw(anagramTiles('aba'), array_map(mb_str_split(...), ['ABA', 'AAB', 'BAA']), 0, new Randomizer(new Mt19937(1))))
        ->toThrow(InvalidArgumentException::class);
});

it('draws the same orders from the same seed', function (): void {
    $words = anagramWords();
    $draw = static fn (int $seed, int $bought): array => array_map(
        anagramReads(...),
        AnagramOrders::draw(anagramTiles('qarpız'), anagramAccept($words, 'qarpız'), $bought, new Randomizer(new Mt19937($seed))),
    );

    expect($draw(42, 0))->toBe($draw(42, 0))
        ->and($draw(42, 0))->not->toBe($draw(43, 0))
        ->and($draw(42, 1))->toBe($draw(42, 1));

    // And a game's rounds carry the same orders from the same seed.
    $level = app(ContentRepository::class)->roomLevel(RoomMechanic::Anagram, 2);
    $orders = static fn (int $seed): array => array_map(
        static fn (BuiltRoomRoundData $round): array => $round->ask->orders,
        (new AnagramBuilder($words))->build($level, 5, new Randomizer(new Mt19937($seed))),
    );

    expect($orders(7))->toBe($orders(7))
        ->and($orders(7))->not->toBe($orders(8));
});
