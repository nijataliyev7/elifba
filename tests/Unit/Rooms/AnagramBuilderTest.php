<?php

use App\Domain\Content\Data\RangeData;
use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\RoomWords;
use App\Domain\Language\Support\Az;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomLayout;
use App\Domain\Rooms\Support\Mechanics\AnagramBuilder;
use App\Domain\Rooms\Support\Mechanics\AnagramOrders;
use App\Domain\Rooms\Support\RoomJudge;
use App\Domain\Rooms\Support\RoomPlayable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * «Anaqram» (docs/architecture.md §12 "Room games", ADR 023): the letters
 * of a word of the list, shuffled, to tap into a word. The tiles never
 * read like any word they spell, the solution accepts every one of them,
 * and the letters are cased by Azerbaijani rules on the server. From ADR
 * 037 the question carries the word's clue and the orders «Qarışdır»
 * cycles through, the first the tiles' own (`AnagramOrdersTest`).
 */

/**
 * @return list<BuiltRoomRoundData>
 */
function anagramRounds(RoomLevelData $level, int $seed, int $count = 20, ?RoomWords $words = null): array
{
    $content = app(ContentRepository::class);

    return (new AnagramBuilder($words ?? RoomWords::of($content->roomWords(), $content->names()->names), $words === null ? $content->roomClues()->clues : []))
        ->build($level, $count, new Randomizer(new Mt19937($seed)));
}

/**
 * @param  list<RoomItemData>  $items
 */
function anagramReading(array $items): string
{
    return implode('', array_map(static fn (RoomItemData $item): string => $item->says(), $items));
}

function anagramLevel(int $min, int $max): RoomLevelData
{
    return new RoomLevelData(RoomMechanic::Anagram, 1, 0, 20_000, wordLength: new RangeData($min, $max));
}

/**
 * The four levels of content/rooms/levels.json, bound: the content is read
 * once the application has booted.
 *
 * @return array<string, array{Closure(): RoomLevelData}>
 */
function anagramLevels(): array
{
    $levels = [];

    foreach (range(1, 4) as $difficulty) {
        $levels["difficulty {$difficulty}"] = [fn (): RoomLevelData => app(ContentRepository::class)->roomLevel(RoomMechanic::Anagram, $difficulty)];
    }

    return $levels;
}

it('never shows the letters in the word\'s own order', function (RoomLevelData $level): void {
    $content = app(ContentRepository::class);
    $words = RoomWords::of($content->roomWords(), $content->names()->names);

    foreach (range(1, 10) as $seed) {
        foreach (anagramRounds($level, $seed) as $round) {
            $word = Az::lower((string) $round->solution->word);
            $shown = anagramReading($round->ask->items);
            $accepted = array_map(static fn (array $reading): string => implode('', $reading), $round->solution->accept);

            // A word of the level, its letters as tiles, never in the order of a word they spell.
            expect($words->forAnagram($level->wordLength ?? new RangeData(0, 0)))->toContain($word)
                ->and($accepted)->toContain(Az::upper($word))
                ->and($accepted)->not->toContain($shown)
                ->and(mb_str_split($shown))->toEqualCanonicalizing(mb_str_split(Az::upper($word)))
                ->and($round->study)->toBeNull()
                ->and($round->studyMs)->toBe(0)
                ->and($round->answerMs)->toBe($level->answerMs)
                ->and($round->ask->shape)->toBe(RoomAnswerShape::Sequence)
                ->and($round->ask->pick)->toBe(mb_strlen($word))
                ->and($round->ask->layout)->toBe(RoomLayout::Row)
                ->and($round->ask->grid)->toBeNull();

            // Ids of 8 characters, none twice; the solution is every tile once, and right.
            $ids = $round->ask->ids();

            foreach ($ids as $id) {
                expect($id)->toMatch('/^[a-z0-9]{8}$/');
            }

            // ADR 037: the word's clue, and orders of these tiles, the first theirs, none reading a word they spell.
            $says = array_column(array_map(static fn (RoomItemData $item): array => $item->toArray(), $round->ask->items), 'text', 'id');
            $read = array_map(static fn (array $order): string => implode('', array_map(static fn (string $id): string => $says[$id], $order)), $round->ask->orders);

            expect($round->ask->clue)->toBe($content->roomClues()->of($word))
                ->and($round->ask->clue)->not->toBeNull()
                ->and($round->ask->orders[0])->toBe($ids)
                ->and(count($round->ask->orders))->toBe(AnagramOrders::count(RoomWords::arrangements($word), count($accepted)))
                ->and(array_intersect($read, $accepted))->toBe([])
                ->and(array_unique($read))->toBe($read);

            foreach ($round->ask->orders as $order) {
                expect($order)->toEqualCanonicalizing($ids);
            }

            expect(array_unique($ids))->toBe($ids)
                ->and($round->solution->ids)->toEqualCanonicalizing($ids)
                ->and(RoomJudge::correct($round->solution, $round->ask->items, $round->solution->ids))->toBeTrue()
                ->and(RoomJudge::correct($round->solution, $round->ask->items, $ids))->toBeFalse();
        }
    }
})->with(anagramLevels());

it('never shows the order of another word the letters spell, nor of the word, whatever the shuffle', function (): void {
    // «ayaq» and «qaya»: every turn of «QAYA» but one reads like one of them.
    $words = new RoomWords(['ayaq', 'qaya']);

    foreach (range(1, 200) as $seed) {
        [$round] = anagramRounds(anagramLevel(4, 4), $seed, 1, $words);

        expect(anagramReading($round->ask->items))->not->toBeIn(['AYAQ', 'QAYA']);
    }

    // Three letters that spell five accepted words leave one order: it is the one shown.
    $words = new RoomWords(['abc', 'acb', 'bac', 'bca', 'cab']);

    foreach (range(1, 20) as $seed) {
        [$round] = anagramRounds(anagramLevel(3, 3), $seed, 1, $words);

        expect(anagramReading($round->ask->items))->toBe('CBA');
    }

    // Letters that spell an accepted word in every order are never drawn.
    expect((new RoomWords(['aba', 'aab', 'baa']))->forAnagram(new RangeData(3, 3)))->toBe([])
        ->and(fn () => anagramRounds(anagramLevel(3, 3), 1, 1, new RoomWords(['aba', 'aab', 'baa'])))->toThrow(InvalidArgumentException::class);
});

it('accepts every listed word the letters spell', function (): void {
    // «alma» and «amal» are both words of the list; «aeroport» and «operator» too.
    $content = app(ContentRepository::class);
    $words = RoomWords::of($content->roomWords(), $content->names()->names);
    $level = anagramLevel(4, 4);
    $seen = false;

    foreach (range(1, 400) as $seed) {
        [$round] = anagramRounds($level, $seed, 1, new RoomWords(['alma', 'amal', 'ağac', 'qala']));

        if (! in_array($round->solution->word, ['ALMA', 'AMAL'], true)) {
            continue;
        }

        $seen = true;
        $tiles = $round->ask->items;
        $spell = static function (string $word) use ($tiles): array {
            $left = $tiles;
            $picked = [];

            foreach (mb_str_split($word) as $letter) {
                $at = array_key_first(array_filter($left, static fn (RoomItemData $tile): bool => $tile->text === $letter));
                $picked[] = $left[$at]->id;
                unset($left[$at]);
            }

            return $picked;
        };

        expect($round->solution->accept)->toEqualCanonicalizing([['A', 'L', 'M', 'A'], ['A', 'M', 'A', 'L']])
            ->and(RoomJudge::correct($round->solution, $tiles, $spell('ALMA')))->toBeTrue()
            ->and(RoomJudge::correct($round->solution, $tiles, $spell('AMAL')))->toBeTrue()
            ->and(RoomJudge::correct($round->solution, $tiles, $spell('LAMA')))->toBeFalse()
            ->and(RoomJudge::correct($round->solution, $tiles, $spell('MALA')))->toBeFalse();
    }

    expect($seen)->toBeTrue()
        ->and($words->accepted('alma'))->toBe(['alma', 'amal'])
        ->and($words->accepted('amal'))->toBe(['amal', 'alma'])
        ->and($words->accepted('aeroport'))->toBe(['aeroport', 'operator'])
        // An also-correct spelling with the same letters is accepted too.
        ->and((new RoomWords(['badam'], ['dabam']))->accepted('badam'))->toBe(['badam', 'dabam']);
});

it('cases the letters with Azerbaijani rules', function (): void {
    // i → İ and ı → I, never the I of mbstring for i; the solution's word in the same capitals.
    $words = new RoomWords(['işıqlı']);
    [$round] = anagramRounds(anagramLevel(6, 6), 3, 1, $words);
    $letters = array_map(static fn (RoomItemData $item): ?string => $item->text, $round->ask->items);

    expect($letters)->toEqualCanonicalizing(['İ', 'Ş', 'I', 'Q', 'L', 'I'])
        ->and(array_unique(array_map(static fn (RoomItemData $item): RoomItemKind => $item->kind, $round->ask->items), SORT_REGULAR))->toBe([RoomItemKind::Letter])
        ->and($round->solution->word)->toBe('İŞIQLI')
        ->and($round->solution->accept)->toBe([['İ', 'Ş', 'I', 'Q', 'L', 'I']]);
});

it('draws no word twice in a game while the level has words left', function (): void {
    $level = app(ContentRepository::class)->roomLevel(RoomMechanic::Anagram, 2);

    foreach (range(1, 5) as $seed) {
        $drawn = array_map(static fn (BuiltRoomRoundData $round): ?string => $round->solution->word, anagramRounds($level, $seed, 20));

        expect(array_unique($drawn))->toBe($drawn);
    }

    // A level with fewer words than rounds draws them all again.
    $two = anagramRounds(anagramLevel(4, 4), 9, 5, new RoomWords(['alma', 'qala']));

    expect(array_count_values(array_map(static fn (BuiltRoomRoundData $round): ?string => $round->solution->word, $two)))->toHaveCount(2);
});

it('builds the same rounds from the same seed', function (): void {
    $level = app(ContentRepository::class)->roomLevel(RoomMechanic::Anagram, 3);
    $rounds = static fn (int $seed): array => array_map(
        static fn (BuiltRoomRoundData $round): array => [$round->payload(), $round->solution->toArray()],
        anagramRounds($level, $seed, 10),
    );

    expect($rounds(42))->toBe($rounds(42))
        ->and($rounds(42))->not->toBe($rounds(43))
        ->and(RoomPlayable::builder(RoomMechanic::Anagram))->toBeInstanceOf(AnagramBuilder::class);
});

it('builds only a level of its own', function (RoomLevelData $level): void {
    expect(fn () => anagramRounds($level, 1))->toThrow(InvalidArgumentException::class);
})->with([
    'no word length' => [new RoomLevelData(RoomMechanic::Anagram, 1, 0, 20_000)],
    'something to study' => [new RoomLevelData(RoomMechanic::Anagram, 1, 2_000, 20_000, wordLength: new RangeData(4, 5))],
    'no word of that length' => [new RoomLevelData(RoomMechanic::Anagram, 1, 0, 20_000, wordLength: new RangeData(12, 12))],
]);
