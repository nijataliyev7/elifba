<?php

namespace App\Domain\Rankings\Data;

use App\Domain\Content\Support\Avatars;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Language\Support\Ordinal;
use App\Domain\Language\Support\Plural;
use App\Domain\Rankings\Enums\Period;

/**
 * The share card of a podium (docs/architecture.md §6): the 1080×1920 PNG
 * the teacher posts to WhatsApp status, made in the browser from this shape
 * and nothing else. It carries exactly §6's list — the title, the date or
 * week, places 1–3 with their label, points and each student's display name
 * and avatar picture, the wordmark and the domain — plus the file name.
 *
 * Never in it (§7): ids, the class name, the teacher's name, a link, a time,
 * a student off the podium. The avatar is a path on the page's own origin,
 * so the capture never needs another host.
 */
final readonly class ShareCardData
{
    /**
     * @param  non-empty-list<PodiumPlaceData>  $places
     */
    private function __construct(
        public PeriodData $period,
        public array $places,
    ) {}

    /**
     * The card of a podium; null when the podium is empty, since an empty
     * podium has nothing to share.
     *
     * @param  list<PodiumPlaceData>  $places
     */
    public static function of(PeriodData $period, array $places): ?self
    {
        return $places === [] ? null : new self($period, $places);
    }

    /**
     * `elifba-gunun-qalibleri-2026-11-11.png` for a day,
     * `elifba-heftenin-qalibleri-2026-11-09.png` for the week that starts on
     * that Monday. Never a name in it.
     */
    public function fileName(): string
    {
        $slug = match ($this->period->period) {
            Period::Day => 'gunun',
            Period::Week => 'heftenin',
        };

        return "elifba-{$slug}-qalibleri-{$this->period->first()->toDateString()}.png";
    }

    /**
     * @return array{
     *     title: string,
     *     label: string,
     *     places: list<array{place: int, place_label: string, points_label: string, students: list<array{display_name: string, avatar_url: string|null}>}>,
     *     wordmark: string,
     *     domain: string,
     *     file_name: string,
     * }
     */
    public function toArray(string $locale): array
    {
        return [
            'title' => self::translate("podium.card.title.{$this->period->period->value}", $locale),
            'label' => $this->period->label($locale),
            'places' => array_map(
                static fn (PodiumPlaceData $place): array => [
                    'place' => $place->place,
                    'place_label' => Ordinal::label($place->place, $locale),
                    'points_label' => str_replace(
                        ':count',
                        (string) $place->points,
                        Plural::choose(self::translate('rankings.points', $locale), $place->points, $locale),
                    ),
                    'students' => array_map(
                        static function (RankedStudentData $row): array {
                            $image = Avatars::image($row->student->avatar);

                            return [
                                'display_name' => $row->student->display_name,
                                'avatar_url' => $image === null ? null : ContentAssets::path($image),
                            ];
                        },
                        $place->students,
                    ),
                ],
                $this->places,
            ),
            'wordmark' => self::string(config('app.name'), 'Elifba'),
            'domain' => self::string(parse_url(self::string(config('app.url'), ''), PHP_URL_HOST), ''),
            'file_name' => $this->fileName(),
        ];
    }

    private static function translate(string $key, string $locale): string
    {
        return self::string(__($key, [], $locale), $key);
    }

    private static function string(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
