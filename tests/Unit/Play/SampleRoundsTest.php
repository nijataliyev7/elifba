<?php

use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Support\SampleRounds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

/*
 * The welcome page's wall (docs/frontend.md rule 10, architecture §2 "The
 * find builder"): real `find` rounds of the first letter level, one built-in
 * pack, the same all Baku day.
 */

function sampleOn(string $bakuMoment, string $locale = 'az'): ?array
{
    return app(SampleRounds::class)->on(CarbonImmutable::parse($bakuMoment, 'Asia/Baku'), $locale);
}

it('builds find rounds of letters that a child solves by one item', function (): void {
    $sample = sampleOn('2026-11-10 10:00:00');
    $letters = array_map(Az::upper(...), Alphabet::letters());

    expect($sample)->not->toBeNull()
        ->and($sample['rounds'])->toHaveCount(Config::integer('elifba.rounds.min'));

    foreach ($sample['rounds'] as $round) {
        $ids = array_column($round['items'], 'id');
        $target = array_values(array_filter($round['items'], fn (array $item): bool => $item['id'] === $round['solution'][0]))[0];

        expect($round['items'])->toHaveCount(4)
            ->and($round['solution'])->toHaveCount(1)
            ->and($ids)->toContain($round['solution'][0])
            ->and($round['show'])->toBe($target['text'])
            ->and($round['prompt'])->toBe("«{$target['text']}» hərfini tap!")
            ->and(array_diff(array_column($round['items'], 'text'), $letters))->toBe([]);
    }
});

it('takes a built-in pack with its look and its title in the interface language', function (): void {
    $packs = app(ContentRepository::class)->packs();
    $az = sampleOn('2026-11-10 10:00:00');
    $en = sampleOn('2026-11-10 10:00:00', 'en');
    $pack = $packs[$az['pack']['key']];

    expect($az['pack'])->toBe([
        'key' => $pack->key,
        'title' => $pack->title->az,
        'item' => $pack->style->item->value,
        'palette' => $pack->style->palette,
    ])
        ->and($en['pack']['title'])->toBe($pack->title->en)
        ->and($en['rounds'][0]['prompt'])->toBe($az['rounds'][0]['prompt']);
});

it('stays the same all Baku day and changes with the date', function (): void {
    $morning = sampleOn('2026-11-10 00:05:00');
    $evening = sampleOn('2026-11-10 23:55:00');

    $strip = static fn (array $sample): array => array_map(
        static fn (array $round): array => [$round['prompt'], $round['items']],
        $sample['rounds'],
    );

    expect($strip($evening))->toBe($strip($morning));

    $days = collect(range(11, 20))
        ->map(fn (int $day): array => $strip(sampleOn("2026-11-{$day} 10:00:00")))
        ->push($strip($morning))
        ->unique(fn (array $rounds): string => json_encode($rounds, JSON_THROW_ON_ERROR));

    expect($days->count())->toBeGreaterThan(1);
});
