<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\SoundChoiceData;
use App\Domain\Content\Enums\Sound;
use JsonException;
use RuntimeException;

/**
 * The sounds from `content/sounds.json` (docs/architecture.md §3 "Sounds"):
 * for each choosable moment (`Sound::choosable()`) the sounds an account may
 * choose in Settings → Oyun instead of the pack's own. Every pack's own
 * sound for such a moment is one of them.
 * `ContentValidator` checks the file; this class trusts it enough to read
 * it.
 */
final class SoundChoices
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9-]{0,31}$/';

    /**
     * @var array{version: int, sounds: array<value-of<Sound>, array<string, SoundChoiceData>>}|null
     */
    private static ?array $catalog = null;

    /**
     * The file's version, added to every URL (`?v=`) like a pack's.
     */
    public static function version(): int
    {
        return self::catalog()['version'];
    }

    /**
     * The sounds of one moment by key, in the file's order; none for a
     * moment that is not `Sound::choosable()`.
     *
     * @return array<string, SoundChoiceData>
     */
    public static function of(Sound $sound): array
    {
        return self::catalog()['sounds'][$sound->value] ?? [];
    }

    /**
     * @return list<string>
     */
    public static function keys(Sound $sound): array
    {
        return array_keys(self::of($sound));
    }

    public static function find(Sound $sound, string $key): ?SoundChoiceData
    {
        return self::of($sound)[$key] ?? null;
    }

    public static function url(SoundChoiceData $choice): string
    {
        return ContentAssets::url($choice->file, self::version());
    }

    /**
     * @return array{version: int, sounds: array<value-of<Sound>, array<string, SoundChoiceData>>}
     */
    private static function catalog(): array
    {
        return self::$catalog ??= self::load(base_path('content/sounds.json'));
    }

    /**
     * @return array{version: int, sounds: array<value-of<Sound>, array<string, SoundChoiceData>>}
     */
    private static function load(string $path): array
    {
        $json = @file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("{$path} is not valid JSON.", previous: $exception);
        }

        if (! is_array($decoded) || ! is_int($decoded['version'] ?? null) || ! is_array($decoded['sounds'] ?? null)) {
            throw new RuntimeException("{$path} needs a `version` and `sounds`.");
        }

        $sounds = [];

        foreach (Sound::choosable() as $sound) {
            $sounds[$sound->value] = [];

            foreach ($decoded['sounds'][$sound->value] ?? [] as $entry) {
                /** @var array{key: string, file: string, title: array{az: string, en: string}, effects?: list<string>} $entry */
                $choice = SoundChoiceData::fromArray($entry);
                $sounds[$sound->value][$choice->key] = $choice;
            }
        }

        return ['version' => $decoded['version'], 'sounds' => $sounds];
    }
}
