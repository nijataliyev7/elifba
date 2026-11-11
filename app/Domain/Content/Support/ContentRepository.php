<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\CreditData;
use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\PromptTemplateData;
use App\Domain\Content\Data\VoiceData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * The one reader of built-in content (docs/architecture.md §3, ADR 004):
 * levels, prompts, credits, theme packs and the voice (`voice.json` with
 * the clip list of `voice-clips.json`, §3 "Voice") from a content root.
 *
 * With a compiled file (`php artisan content:cache` writes
 * `bootstrap/cache/content.php`, which OPcache keeps in memory) it reads
 * only that file. Without one it reads the JSON files, once per request
 * (the container holds one instance), so an edited pack shows on the next
 * reload. The files are trusted to be valid: `ContentValidator` checks them
 * in the test suite and before every `content:cache`.
 *
 * `content/alphabet.json` and `content/avatars.json` are read by
 * `Language\Support\Alphabet` and `Content\Support\Avatars`.
 *
 * @phpstan-import-type VoiceArray from VoiceData
 */
final class ContentRepository
{
    /**
     * The clip list `php artisan content:voice` writes, beside `voice.json`.
     */
    public const string VOICE_CLIPS = 'voice-clips.json';

    /**
     * @var array{levels: list<mixed>, prompts: list<mixed>, credits: list<mixed>, packs: array<string, mixed>, voice: array<string, mixed>}|null
     */
    private ?array $data = null;

    /**
     * @var array<string, PackData>|null
     */
    private ?array $packs = null;

    /**
     * @var array<string, LevelData>|null
     */
    private ?array $levels = null;

    /**
     * @var list<PromptTemplateData>|null
     */
    private ?array $prompts = null;

    private ?VoiceData $voice = null;

    /**
     * @param  string  $root  the content directory (`content/`, or a fixture copy)
     * @param  string|null  $compiled  where `content:cache` writes; null = never read a compiled file
     */
    public function __construct(
        private readonly string $root,
        private readonly ?string $compiled = null,
    ) {}

    public function root(): string
    {
        return $this->root;
    }

    public function compiledPath(): ?string
    {
        return $this->compiled;
    }

    /**
     * Whether this instance reads the compiled file instead of the JSON files.
     */
    public function readsCompiled(): bool
    {
        return $this->compiled !== null && is_file($this->compiled);
    }

    /**
     * Every pack by key, in key order.
     *
     * @return array<string, PackData>
     */
    public function packs(): array
    {
        return $this->packs ??= array_map(PackData::fromArray(...), $this->data()['packs']);
    }

    public function hasPack(string $key): bool
    {
        return isset($this->packs()[$key]);
    }

    /**
     * @throws InvalidArgumentException when there is no such pack
     */
    public function pack(string $key): PackData
    {
        return $this->packs()[$key] ?? throw new InvalidArgumentException("There is no pack \"{$key}\".");
    }

    /**
     * Every level by key, in file order.
     *
     * @return array<string, LevelData>
     */
    public function levels(): array
    {
        if ($this->levels === null) {
            $this->levels = [];

            foreach ($this->data()['levels'] as $level) {
                $level = LevelData::fromArray($level);
                $this->levels[$level->key] = $level;
            }
        }

        return $this->levels;
    }

    /**
     * @throws InvalidArgumentException when there is no such level
     */
    public function level(string $key): LevelData
    {
        return $this->levels()[$key] ?? throw new InvalidArgumentException("There is no level \"{$key}\".");
    }

    /**
     * @return list<PromptTemplateData>
     */
    public function prompts(): array
    {
        return $this->prompts ??= array_map(PromptTemplateData::fromArray(...), $this->data()['prompts']);
    }

    /**
     * @throws InvalidArgumentException when no template exists for the pair
     */
    public function prompt(Mechanic $mechanic, Unit $unit): PromptTemplateData
    {
        foreach ($this->prompts() as $prompt) {
            if ($prompt->mechanic === $mechanic && $prompt->unit === $unit) {
                return $prompt;
            }
        }

        throw new InvalidArgumentException("There is no prompt for {$mechanic->value} × {$unit->value}.");
    }

    /**
     * @return list<CreditData>
     */
    public function credits(): array
    {
        return array_map(CreditData::fromArray(...), $this->data()['credits']);
    }

    /**
     * The voice and its clips (ADR 015). Read through `VoiceClips`.
     */
    public function voice(): VoiceData
    {
        if ($this->voice === null) {
            /** @var VoiceArray $data checked by `ContentValidator` */
            $data = $this->data()['voice'];
            $this->voice = VoiceData::fromArray($data);
        }

        return $this->voice;
    }

    /**
     * The content as the JSON files hold it now, ignoring any compiled file:
     * what `content:cache` compiles.
     *
     * @return array{levels: list<mixed>, prompts: list<mixed>, credits: list<mixed>, packs: array<string, mixed>, voice: array<string, mixed>}
     *
     * @throws RuntimeException when a file is missing or not valid JSON
     */
    public function sources(): array
    {
        $packs = [];

        foreach (self::packFiles($this->root) as $key => $path) {
            $packs[$key] = self::decode($path);
        }

        return [
            'levels' => self::listIn($this->root.'/levels.json', 'levels'),
            'prompts' => self::listIn($this->root.'/prompts.json', 'prompts'),
            'credits' => self::listIn($this->root.'/credits.json', 'credits'),
            'packs' => $packs,
            'voice' => self::voiceIn($this->root),
        ];
    }

    /**
     * The pack files of a content root, by key (the file name), in key order.
     *
     * @return array<string, string>
     */
    public static function packFiles(string $root): array
    {
        $files = [];

        foreach (glob($root.'/packs/*.json') ?: [] as $path) {
            $files[basename($path, '.json')] = $path;
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @throws RuntimeException when the file is missing or not valid JSON
     */
    public static function decode(string $path): mixed
    {
        $json = is_file($path) ? file_get_contents($path) : false;

        if ($json === false) {
            throw new RuntimeException('Cannot read '.basename($path).'.');
        }

        try {
            return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(basename($path).' is not valid JSON: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return array{levels: list<mixed>, prompts: list<mixed>, credits: list<mixed>, packs: array<string, mixed>, voice: array<string, mixed>}
     */
    private function data(): array
    {
        if ($this->data === null) {
            /** @var array{levels: list<mixed>, prompts: list<mixed>, credits: list<mixed>, packs: array<string, mixed>, voice: array<string, mixed>} $compiled */
            $compiled = $this->readsCompiled() ? require $this->compiled : $this->sources();
            $this->data = $compiled;
        }

        return $this->data;
    }

    /**
     * `voice.json` with `clips`, the clip list `content:voice` writes to
     * `voice-clips.json`.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException when a file is missing, not valid JSON or not an object
     */
    public static function voiceIn(string $root): array
    {
        $voice = self::decode($root.'/voice.json');
        $clips = self::decode($root.'/'.self::VOICE_CLIPS);

        if (! is_array($voice) || ! is_array($clips) || ! is_array($clips['clips'] ?? null)) {
            throw new RuntimeException('voice.json and '.self::VOICE_CLIPS.' must be objects, the second with `clips`.');
        }

        return [...$voice, 'clips' => $clips['clips']];
    }

    /**
     * @return list<mixed>
     */
    private static function listIn(string $path, string $key): array
    {
        $decoded = self::decode($path);
        $list = is_array($decoded) ? ($decoded[$key] ?? null) : null;

        if (! is_array($list) || ! array_is_list($list)) {
            throw new RuntimeException(basename($path)." has no list of {$key}.");
        }

        return $list;
    }
}
