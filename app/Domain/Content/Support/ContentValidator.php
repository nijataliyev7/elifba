<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\CreditData;
use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\PromptTemplateData;
use App\Domain\Content\Enums\Effect;
use App\Domain\Content\Enums\ItemStyle;
use App\Domain\Content\Enums\License;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\SlotStyle;
use App\Domain\Content\Enums\Sound;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Enums\WordProblem;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Language\Support\Syllabifier;
use BackedEnum;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Checks a content root (docs/architecture.md §3, §8): `alphabet.json`,
 * `avatars.json`, `levels.json`, `prompts.json`, `credits.json`,
 * `packs/*.json`, `tests/syllables.json` and every file under `assets/`
 * and `resources/fonts/` (credits; SVG files are also checked for anything
 * that could run or load). `validate()` lists every problem, one line each;
 * an empty list means the content is valid.
 *
 * The root is a `content` directory: credits name files from the folder
 * above it (`content/assets/fruit/alma.svg`, `resources/fonts/inter/*`), so
 * a fixture is laid out as `{tmp}/content/…`. Letters are checked against
 * `Language\Support\Alphabet`.
 *
 * Checks that need another file (prompts against levels, packs against
 * levels, files against credits) run only when that file is sound, so one
 * mistake is reported once.
 */
final class ContentValidator
{
    /**
     * "Every word has a picture" (§3), on since A5 drew own art for the
     * words OpenMoji lacks.
     */
    public const bool WORD_PICTURES_REQUIRED = true;

    /**
     * Every pack's minimum vocabulary (A4's bar).
     */
    public const int MIN_WORDS = 20;

    public const int MIN_SENTENCES = 10;

    /**
     * Distinct items a pack must yield for every syllable, word and sentence
     * level with all 32 letters learned: `find` shows 4 items and never
     * repeats a target in a row.
     */
    public const int MIN_LEVEL_ITEMS = 6;

    /**
     * Words per sentence that `build_sentence` lays out (§2).
     */
    public const int MIN_SENTENCE_WORDS = 2;

    public const int MAX_SENTENCE_WORDS = 4;

    /**
     * Folders outside the content root whose every file needs a credits
     * entry, from the repository root.
     */
    public const array CREDITED_FOLDERS = ['resources/fonts'];

    /**
     * Where share-alike files must stay, inside the content root (§8).
     */
    public const string SHARE_ALIKE_FOLDER = 'assets/openmoji/';

    private const string KEY = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * Lowercase folders; a file name may hold capitals, for OpenMoji's code
     * points (`openmoji/1F9D1-200D-1F3EB.svg`).
     */
    private const string ASSET_PATH = '/^(?:[a-z0-9_-]+\/)+[A-Za-z0-9_-]+\.[a-z0-9]+$/';

    private const array IMAGE_EXTENSIONS = ['svg', 'png', 'webp'];

    private const array AUDIO_EXTENSIONS = ['ogg', 'mp3', 'wav'];

    private const string SHAPE = '/^C*VC*$/';

    private const string COLOUR = '/^#[0-9A-F]{6}$/';

    private const int MAX_COLOURS = 8;

    /**
     * Signs allowed besides letters in titles and prompts (§3).
     */
    private const array SIGNS = [' ', '-', '.', ',', '!', '?', '«', '»'];

    private const array FINAL_MARKS = ['.', '!', '?'];

    /**
     * @var list<string>
     */
    private array $problems = [];

    public function __construct(
        private readonly string $root,
        private readonly bool $wordPicturesRequired = self::WORD_PICTURES_REQUIRED,
    ) {}

    /**
     * @return list<string>
     */
    public function validate(): array
    {
        $this->problems = [];

        $this->alphabet();
        $this->avatars();
        $levels = $this->levels();
        $this->prompts($levels);
        $credits = $this->credits();

        foreach (array_keys(ContentRepository::packFiles($this->root)) as $key) {
            $this->pack((string) $key, $levels);
        }

        $this->syllableTests();

        if ($credits !== null) {
            $this->coverage($credits);
        }

        $this->svgs();

        return $this->problems;
    }

    // ── alphabet.json ───────────────────────────────────────────────────

    private function alphabet(): void
    {
        $file = 'alphabet.json';
        $data = $this->object($this->read($file), $file, '', ['letters', 'lookalikes']);

        if ($data === null) {
            return;
        }

        $letters = $this->list($data['letters'], $file, 'letters') ?? [];

        // every lowercase letter the file lists, even in an entry with other problems
        $lowers = array_values(array_filter(array_map(
            static fn (mixed $entry): mixed => is_array($entry) ? ($entry['lower'] ?? null) : null,
            $letters,
        ), is_string(...)));

        /** @var array<string, array{where: string, name: string|null, confusables: list<string>}> $entries */
        $entries = [];
        $uppers = [];

        foreach ($letters as $index => $entry) {
            $where = "letters[{$index}]";
            $entry = $this->object($entry, $file, $where, ['upper', 'lower', 'vowel', 'name', 'confusables']);

            if ($entry === null) {
                continue;
            }

            $lower = $entry['lower'];
            $upper = $entry['upper'];

            if (! is_string($lower) || ! is_string($upper) || mb_strlen($lower) !== 1 || mb_strlen($upper) !== 1) {
                $this->problem($file, "{$where} needs a one-character `upper` and `lower`");

                continue;
            }

            $where .= " \"{$lower}\"";

            if (isset($entries[$lower]) || in_array($upper, $uppers, true)) {
                $this->problem($file, "{$where} is listed twice");
            }

            if (! is_bool($entry['vowel'])) {
                $this->problem($file, "{$where}.vowel must be true or false");
            }

            foreach (['upper', 'lower', 'name', 'confusables'] as $field) {
                $this->normalized($entry[$field], $file, "{$where}.{$field}");
            }

            $confusables = $this->strings($entry['confusables'], $file, "{$where}.confusables", allowEmpty: true) ?? [];

            if (count(array_unique($confusables)) !== count($confusables)) {
                $this->problem($file, "{$where}.confusables lists a letter twice");
            }

            $entries[$lower] = [
                'where' => $where,
                'name' => $this->string($entry['name'], $file, "{$where}.name"),
                'confusables' => $confusables,
            ];
            $uppers[] = $upper;
        }

        foreach ($entries as $lower => $entry) {
            $lower = (string) $lower;
            $where = $entry['where'];

            if ($entry['name'] !== null && array_diff(mb_str_split($entry['name']), $lowers) !== []) {
                $this->problem($file, "{$where}.name \"{$entry['name']}\" must be lowercase letters of the alphabet");
            }

            foreach ($entry['confusables'] as $other) {
                if ($other === $lower) {
                    $this->problem($file, "{$where} lists itself as a confusable");
                } elseif (! in_array($other, $lowers, true)) {
                    $this->problem($file, "{$where}: confusable \"{$other}\" is not a lowercase letter of the alphabet");
                } elseif (isset($entries[$other]) && ! in_array($lower, $entries[$other]['confusables'], true)) {
                    $this->problem($file, "{$where}: confusable \"{$other}\" does not list \"{$lower}\" back");
                }
            }
        }

        $lookalikes = $data['lookalikes'];

        if (! is_array($lookalikes) || ($lookalikes !== [] && array_is_list($lookalikes))) {
            $this->problem($file, '`lookalikes` must map look-alike characters to letters');

            return;
        }

        foreach ($lookalikes as $from => $to) {
            if (! is_string($to) || ! in_array($to, [...$lowers, ...$uppers], true)) {
                $this->problem($file, "lookalikes: \"{$from}\" must map to a letter of the alphabet");
            }
        }
    }

    // ── avatars.json ────────────────────────────────────────────────────

    private function avatars(): void
    {
        $file = 'avatars.json';
        $keys = [];
        $pictures = [];

        foreach ($this->listIn($file, 'avatars') ?? [] as $index => $avatar) {
            $where = "avatars[{$index}]";
            $avatar = $this->object($avatar, $file, $where, ['key', 'image']);
            $key = $avatar === null ? null : $this->string($avatar['key'], $file, "{$where}.key");

            if ($avatar === null || $key === null) {
                continue;
            }

            if (preg_match(Avatars::KEY_PATTERN, $key) !== 1 || in_array($key, $keys, true)) {
                $this->problem($file, "{$where} \"{$key}\" needs a unique key matching ".Avatars::KEY_PATTERN);
            }

            $keys[] = $key;
            $this->picture($avatar['image'], $file, "{$where} \"{$key}\"", $pictures);
        }
    }

    // ── levels.json ─────────────────────────────────────────────────────

    /**
     * @return list<LevelData>|null the levels, or null when the file has a problem
     */
    private function levels(): ?array
    {
        $file = 'levels.json';
        $before = count($this->problems);
        $levels = $this->listIn($file, 'levels');

        if ($levels === null) {
            return null;
        }

        $this->normalized($levels, $file, 'levels');
        $keys = [];

        foreach ($levels as $index => $level) {
            $where = "levels[{$index}]";
            $level = $this->object($level, $file, $where, ['key', 'title', 'unit', 'mechanics'], ['items', 'decoys', 'shapes', 'syllables', 'words']);

            if ($level === null) {
                continue;
            }

            $key = $this->string($level['key'], $file, "{$where}.key");

            if ($key !== null) {
                $where .= " \"{$key}\"";

                if (preg_match(self::KEY, $key) !== 1 || in_array($key, $keys, true)) {
                    $this->problem($file, "{$where} needs a unique key of a-z, 0-9 and single hyphens");
                }

                $keys[] = $key;
            }

            $this->title($level['title'], $file, "{$where}.title");
            $unit = $this->enum(Unit::class, $level['unit'], $file, "{$where}.unit");
            $mechanics = $this->enums(Mechanic::class, $level['mechanics'], $file, "{$where}.mechanics") ?? [];

            foreach ($mechanics as $mechanic) {
                if ($unit !== null && ! $mechanic->supports($unit)) {
                    $this->problem($file, "{$where}: {$mechanic->value} cannot be played with {$unit->value}s");
                }
            }

            $hits = array_filter($mechanics, static fn (Mechanic $mechanic): bool => ! $mechanic->builds()) !== [];
            $builds = array_filter($mechanics, static fn (Mechanic $mechanic): bool => $mechanic->builds()) !== [];
            $this->wholeNumber($level, 'items', $hits, 2, 8, $file, $where, 'levels with find or pop');
            $this->wholeNumber($level, 'decoys', $builds, 0, 4, $file, $where, 'levels with a build mechanic');

            foreach (['shapes' => Unit::Syllable, 'syllables' => Unit::Word, 'words' => Unit::Sentence] as $filter => $filterUnit) {
                if ($unit === $filterUnit && ! array_key_exists($filter, $level)) {
                    $this->problem($file, "{$where} needs `{$filter}`");
                } elseif ($unit !== null && $unit !== $filterUnit && array_key_exists($filter, $level)) {
                    $this->problem($file, "{$where}: `{$filter}` belongs to {$filterUnit->value} levels only");
                }
            }

            if (array_key_exists('shapes', $level)) {
                $shapes = $this->strings($level['shapes'], $file, "{$where}.shapes") ?? [];

                foreach ($shapes as $shape) {
                    if (preg_match(self::SHAPE, $shape) !== 1) {
                        $this->problem($file, "{$where}.shapes: \"{$shape}\" is not a syllable shape like V, CV or CVC");
                    }
                }

                if (count(array_unique($shapes)) !== count($shapes)) {
                    $this->problem($file, "{$where}.shapes lists a shape twice");
                }
            }

            if (array_key_exists('syllables', $level)) {
                $this->range($level['syllables'], 1, null, $file, "{$where}.syllables");
            }

            if (array_key_exists('words', $level)) {
                $this->range($level['words'], self::MIN_SENTENCE_WORDS, self::MAX_SENTENCE_WORDS, $file, "{$where}.words");
            }
        }

        if (count($this->problems) !== $before) {
            return null;
        }

        /** @var list<array{key: string, title: array{az: string, en: string}, unit: string, mechanics: list<string>, items?: int, decoys?: int, shapes?: list<string>, syllables?: array{min: int, max: int}, words?: array{min: int, max: int}}> $levels */
        return array_map(LevelData::fromArray(...), $levels);
    }

    // ── prompts.json ────────────────────────────────────────────────────

    /**
     * @param  list<LevelData>|null  $levels
     */
    private function prompts(?array $levels): void
    {
        $file = 'prompts.json';
        $prompts = $this->listIn($file, 'prompts');

        if ($prompts === null) {
            return;
        }

        $this->normalized($prompts, $file, 'prompts');

        /** @var array<string, string> $pairs mechanic × unit → where */
        $pairs = [];

        foreach ($prompts as $index => $prompt) {
            $where = "prompts[{$index}]";
            $prompt = $this->object($prompt, $file, $where, ['mechanic', 'unit', 'text']);

            if ($prompt === null) {
                continue;
            }

            $mechanic = $this->enum(Mechanic::class, $prompt['mechanic'], $file, "{$where}.mechanic");
            $unit = $this->enum(Unit::class, $prompt['unit'], $file, "{$where}.unit");
            $text = $this->string($prompt['text'], $file, "{$where}.text");

            if ($text !== null) {
                $this->promptText($text, $file, "{$where}.text");
            }

            if ($mechanic !== null && $unit !== null) {
                $pair = "{$mechanic->value} × {$unit->value}";

                if (isset($pairs[$pair])) {
                    $this->problem($file, "{$where}: {$pair} has a prompt already");
                }

                $pairs[$pair] = $where;
            }
        }

        if ($levels === null) {
            return;
        }

        $allowed = [];

        foreach ($levels as $level) {
            foreach ($level->mechanics as $mechanic) {
                $allowed["{$mechanic->value} × {$level->unit->value}"] = true;
            }
        }

        foreach (array_keys(array_diff_key($allowed, $pairs)) as $pair) {
            $this->problem($file, "no prompt for {$pair}, which levels.json allows");
        }

        foreach (array_diff_key($pairs, $allowed) as $pair => $where) {
            $this->problem($file, "{$where}: no level allows {$pair}");
        }
    }

    /**
     * The inserted text stands alone in «» with no suffix (§3), so vowel
     * harmony never has to be computed.
     */
    private function promptText(string $text, string $file, string $where): void
    {
        $placeholder = PromptTemplateData::PLACEHOLDER;
        $wrapped = "«{$placeholder}»";

        if (substr_count($text, $placeholder) > 1) {
            $this->problem($file, "{$where} inserts the text more than once");
        }

        if (str_contains($text, $placeholder) && preg_match('/(?:^| )'.preg_quote($wrapped, '/').'(?:$|[ .,!?])/u', $text) !== 1) {
            $this->problem($file, "{$where} \"{$text}\" must wrap {$placeholder} in «» with nothing attached, like \"«{$placeholder}» hecasını tap!\"");
        }

        $rest = str_replace($wrapped, '', $text);

        if (preg_match('/[{}«»]/u', $rest) === 1 || ! $this->lettersAndSigns($rest)) {
            $this->problem($file, "{$where} \"{$text}\" may hold only alphabet letters, spaces, «{$placeholder}» and . , ! ? -");
        }

        if (! in_array(mb_substr($text, -1), self::FINAL_MARKS, true)) {
            $this->problem($file, "{$where} \"{$text}\" must end with . ! or ?");
        }
    }

    // ── credits.json ────────────────────────────────────────────────────

    /**
     * @return list<CreditData>|null the credits, or null when the file has a problem
     */
    private function credits(): ?array
    {
        $file = 'credits.json';
        $before = count($this->problems);
        $credits = $this->listIn($file, 'credits');

        if ($credits === null) {
            return null;
        }

        $this->normalized($credits, $file, 'credits');
        $files = $this->creditedFiles();
        $folders = array_map(static fn (string $folder): string => "{$folder}/", $this->creditedFolders());
        $shareAlikeFolder = $this->contentFolder().'/'.self::SHARE_ALIKE_FOLDER;
        $licenses = implode(', ', array_map(static fn (License $license): string => $license->value, License::cases()));

        foreach ($credits as $index => $credit) {
            $where = "credits[{$index}]";
            $credit = $this->object($credit, $file, $where, ['files', 'title', 'author', 'source', 'license'], ['changes']);

            if ($credit === null) {
                continue;
            }

            $this->string($credit['title'], $file, "{$where}.title");
            $this->string($credit['author'], $file, "{$where}.author");

            if (array_key_exists('changes', $credit)) {
                $this->string($credit['changes'], $file, "{$where}.changes");
            }
            $source = $this->string($credit['source'], $file, "{$where}.source");

            if ($source !== null && ! str_starts_with($source, 'https://')) {
                $this->problem($file, "{$where}.source must be an https:// URL");
            }

            $license = is_string($credit['license']) ? License::tryFrom($credit['license']) : null;

            if ($license === null) {
                $this->problem($file, "{$where}.license must be one of {$licenses}");
            }

            foreach ($this->strings($credit['files'], $file, "{$where}.files") ?? [] as $pattern) {
                $inside = array_filter($folders, static fn (string $folder): bool => str_starts_with($pattern, $folder));

                if ($inside === [] || str_contains($pattern, '..')) {
                    $this->problem($file, "{$where}.files \"{$pattern}\" must be under ".implode(' or ', $folders));
                } elseif (array_filter($files, static fn (string $path): bool => CreditData::matches($pattern, $path)) === []) {
                    $this->problem($file, "{$where}.files \"{$pattern}\" matches no file");
                }

                if ($license?->shareAlike() === true && ! str_starts_with($pattern, $shareAlikeFolder)) {
                    $this->problem($file, "{$where}.files \"{$pattern}\": {$license->value} files must stay in {$shareAlikeFolder}");
                }
            }
        }

        if (count($this->problems) !== $before) {
            return null;
        }

        /** @var list<array{files: list<string>, title: string, author: string, source: string, license: string, changes?: string}> $credits */
        return array_map(CreditData::fromArray(...), $credits);
    }

    /**
     * Every asset and font file needs a credits entry (§8).
     *
     * @param  list<CreditData>  $credits
     */
    private function coverage(array $credits): void
    {
        foreach ($this->creditedFiles() as $path) {
            if (array_filter($credits, static fn (CreditData $credit): bool => $credit->covers($path)) === []) {
                $this->problem('credits.json', "{$path} has no entry");
            }
        }
    }

    /**
     * @return list<string> from the repository root
     */
    private function creditedFolders(): array
    {
        return [$this->contentFolder().'/assets', ...self::CREDITED_FOLDERS];
    }

    /**
     * Every file that needs a credits entry, as a path from the repository
     * root. Hidden files (`.gitkeep`) are skipped.
     *
     * @return list<string>
     */
    private function creditedFiles(): array
    {
        $project = dirname($this->root);
        $files = [];

        foreach ($this->creditedFolders() as $folder) {
            if (! is_dir("{$project}/{$folder}")) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$project}/{$folder}", RecursiveDirectoryIterator::SKIP_DOTS));

            /** @var SplFileInfo $info */
            foreach ($iterator as $info) {
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($info->getPathname(), strlen($project) + 1));

                if ($info->isFile() && ! str_contains("/{$relative}", '/.')) {
                    $files[] = $relative;
                }
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    // ── packs/*.json ────────────────────────────────────────────────────

    /**
     * @param  list<LevelData>|null  $levels
     */
    private function pack(string $key, ?array $levels): void
    {
        $file = "packs/{$key}.json";
        $before = count($this->problems);
        $pack = $this->object($this->read($file), $file, '', ['key', 'version', 'title', 'style', 'words', 'sentences']);

        if ($pack === null) {
            return;
        }

        $this->normalized($pack, $file);

        if (preg_match(self::KEY, $key) !== 1) {
            $this->problem($file, 'the file name must be a key of a-z, 0-9 and single hyphens');
        }

        if ($pack['key'] !== $key) {
            $this->problem($file, "`key` must be \"{$key}\", the file name");
        }

        if (! is_int($pack['version']) || $pack['version'] < 1) {
            $this->problem($file, '`version` must be a whole number from 1, bumped on every change');
        }

        $this->title($pack['title'], $file, 'title');
        $this->style($pack['style'], $file);
        $words = $this->words($pack['words'], $file);
        $sentences = $this->sentences($pack['sentences'], $file);

        if ($words !== null && $words < self::MIN_WORDS) {
            $this->problem($file, "has {$words} words; every pack needs at least ".self::MIN_WORDS);
        }

        if ($sentences !== null && $sentences < self::MIN_SENTENCES) {
            $this->problem($file, "has {$sentences} sentences; every pack needs at least ".self::MIN_SENTENCES);
        }

        if ($levels === null || count($this->problems) !== $before) {
            return;
        }

        /** @var array{key: string, version: int, title: array{az: string, en: string}, style: array{item: string, slot: string, effect: string, backdrop?: string, palette: list<string>, sounds?: array<'hit'|'miss'|'solved', string>}, words: list<array{text: string, image?: string, syllables?: list<string>}>, sentences: list<array{id: string, text: string}>} $pack */
        $this->fitsLevels(PackData::fromArray($pack), $levels, $file);
    }

    private function style(mixed $style, string $file): void
    {
        $style = $this->object($style, $file, 'style', ['item', 'slot', 'effect', 'palette'], ['backdrop', 'sounds']);

        if ($style === null) {
            return;
        }

        $this->enum(ItemStyle::class, $style['item'], $file, 'style.item');
        $this->enum(SlotStyle::class, $style['slot'], $file, 'style.slot');
        $this->enum(Effect::class, $style['effect'], $file, 'style.effect');

        if (array_key_exists('backdrop', $style)) {
            $this->asset($style['backdrop'], self::IMAGE_EXTENSIONS, $file, 'style.backdrop');
        }

        $palette = $this->strings($style['palette'], $file, 'style.palette') ?? [];

        foreach ($palette as $index => $colour) {
            if (preg_match(self::COLOUR, $colour) !== 1) {
                $this->problem($file, "style.palette[{$index}] \"{$colour}\" must be a colour like #F6C445");
            }
        }

        if (count($palette) > self::MAX_COLOURS) {
            $this->problem($file, 'style.palette has more than '.self::MAX_COLOURS.' colours');
        }

        if (array_key_exists('sounds', $style)) {
            $sounds = $this->object($style['sounds'], $file, 'style.sounds', [], array_map(static fn (Sound $sound): string => $sound->value, Sound::cases()));

            foreach ($sounds ?? [] as $name => $path) {
                $this->asset($path, self::AUDIO_EXTENSIONS, $file, "style.sounds.{$name}");
            }
        }
    }

    /**
     * @return int|null how many words, or null when `words` is not a list
     */
    private function words(mixed $words, string $file): ?int
    {
        $words = $this->list($words, $file, 'words');
        $seen = [];
        $pictures = [];

        foreach ($words ?? [] as $index => $word) {
            $where = "words[{$index}]";
            $word = $this->object($word, $file, $where, ['text'], ['image', 'syllables']);
            $text = $word === null ? null : $this->string($word['text'], $file, "{$where}.text");

            if ($word === null || $text === null) {
                continue;
            }

            $where .= " \"{$text}\"";
            $lower = Az::lower($text);

            if (! WordRules::isWord($text)) {
                $this->problem($file, "{$where} must be one word of alphabet letters, with no space, hyphen or sign");

                continue;
            }

            if (! WordRules::isCased($text)) {
                $this->problem($file, "{$where} must be lowercase, or capitalised as a proper noun");
            }

            if (isset($seen[$lower])) {
                $this->problem($file, "{$where} repeats {$seen[$lower]}");
            }

            $seen[$lower] = $where;

            if (array_key_exists('image', $word)) {
                $this->picture($word['image'], $file, $where, $pictures);
            } elseif ($this->wordPicturesRequired) {
                $this->problem($file, "{$where} needs an `image`");
            }

            $this->syllables($text, $word, $file, $where);
        }

        return $words === null ? null : count($words);
    }

    /**
     * A word without an override needs a vowel; an override must join back
     * to the word exactly, with one vowel in each part (§3), and must differ
     * from what the rule gives. The rules are `WordRules`, shared with
     * teachers' words.
     *
     * @param  array<array-key, mixed>  $word
     */
    private function syllables(string $text, array $word, string $file, string $where): void
    {
        $parts = null;

        if (array_key_exists('syllables', $word)) {
            $parts = $this->strings($word['syllables'], $file, "{$where}.syllables");

            if ($parts === null) {
                return;
            }
        }

        $shown = implode('-', $parts ?? []);

        foreach (WordRules::problems($text, $parts) as $problem) {
            match ($problem) {
                WordProblem::NoVowel => $this->problem($file, "{$where} has no vowel, so it has no syllables"),
                WordProblem::Split => $this->problem($file, "{$where}.syllables \"{$shown}\" must join back to the word exactly, with one vowel in each part"),
                WordProblem::SplitByRule => $this->problem($file, "{$where}.syllables \"{$shown}\" is what the rule gives already; remove the override"),
                // Reported by words() before this runs.
                WordProblem::Letters, WordProblem::Casing => null,
            };
        }
    }

    /**
     * @return int|null how many sentences, or null when `sentences` is not a list
     */
    private function sentences(mixed $sentences, string $file): ?int
    {
        $sentences = $this->list($sentences, $file, 'sentences');
        $ids = [];
        $texts = [];

        foreach ($sentences ?? [] as $index => $sentence) {
            $where = "sentences[{$index}]";
            $sentence = $this->object($sentence, $file, $where, ['id', 'text']);

            if ($sentence === null) {
                continue;
            }

            $id = $this->string($sentence['id'], $file, "{$where}.id");
            $text = $this->string($sentence['text'], $file, "{$where}.text");

            if ($id !== null) {
                if (preg_match(self::KEY, $id) !== 1 || isset($ids[$id])) {
                    $this->problem($file, "{$where}.id \"{$id}\" needs a unique id of a-z, 0-9 and single hyphens");
                }

                $ids[$id] = true;
            }

            if ($text === null) {
                continue;
            }

            if (preg_match('/^\p{Lu}\p{L}*(?: \p{L}+)*[.!?]$/u', $text) !== 1 || ! Alphabet::covers($text)) {
                $this->problem($file, "{$where} \"{$text}\" must be words of alphabet letters with single spaces, starting with a capital letter and ending with one . ! or ?");
            }

            if (isset($texts[Az::lower($text)])) {
                $this->problem($file, "{$where} \"{$text}\" repeats an earlier sentence");
            }

            $texts[Az::lower($text)] = true;
        }

        return $sentences === null ? null : count($sentences);
    }

    /**
     * With all 32 letters learned, every syllable, word and sentence level
     * gets at least MIN_LEVEL_ITEMS distinct items from the pack; every word
     * fits a word level and every sentence a sentence level.
     *
     * @param  list<LevelData>  $levels
     */
    private function fitsLevels(PackData $pack, array $levels, string $file): void
    {
        foreach ($levels as $level) {
            $count = count($level->keysFrom($pack));

            if ($level->unit !== Unit::Letter && $count < self::MIN_LEVEL_ITEMS) {
                $this->problem($file, "level \"{$level->key}\" gets ".self::counted($count, "distinct {$level->unit->value}").' from this pack; it needs at least '.self::MIN_LEVEL_ITEMS);
            }
        }

        $sentenceLevels = array_filter($levels, static fn (LevelData $level): bool => $level->unit === Unit::Sentence);

        foreach ($pack->words as $index => $word) {
            if (! WordRules::fitsALevel($word, $levels)) {
                $this->problem($file, "words[{$index}] \"{$word->text}\" has ".self::counted(count($word->syllables), 'syllable').' and fits no word level');
            }
        }

        foreach ($sentenceLevels === [] ? [] : $pack->sentences as $index => $sentence) {
            if (array_filter($sentenceLevels, static fn (LevelData $level): bool => $level->fitsSentence($sentence)) === []) {
                $this->problem($file, "sentences[{$index}] \"{$sentence->text}\" has ".self::counted(count($sentence->words()), 'word').' and fits no sentence level');
            }
        }
    }

    // ── tests/syllables.json ────────────────────────────────────────────

    private function syllableTests(): void
    {
        $file = 'tests/syllables.json';
        $data = $this->object($this->read($file), $file, '', ['description', 'groups']);

        if ($data === null) {
            return;
        }

        $this->normalized($data, $file);
        $this->string($data['description'], $file, 'description');
        $groups = $data['groups'];

        if (! is_array($groups) || $groups === [] || array_is_list($groups)) {
            $this->problem($file, '`groups` must map group names to lists of words');

            return;
        }

        $seen = [];

        foreach ($groups as $group => $words) {
            foreach ($this->strings($words, $file, "groups \"{$group}\"") ?? [] as $hyphenated) {
                $where = "groups \"{$group}\": \"{$hyphenated}\"";
                $parts = explode('-', $hyphenated);

                if (preg_match('/^\p{L}+(?:-\p{L}+)*$/u', $hyphenated) !== 1 || ! Alphabet::covers($hyphenated) || ! Syllabifier::isSplitOf(implode('', $parts), $parts)) {
                    $this->problem($file, "{$where} must be alphabet letters with a hyphen between syllables of one vowel each");
                }

                if (isset($seen[$hyphenated])) {
                    $this->problem($file, "{$where} is listed twice");
                }

                $seen[$hyphenated] = true;
            }
        }
    }

    // ── shared checks ───────────────────────────────────────────────────

    /**
     * The decoded file; null when it is missing, not JSON or not an object
     * at the top (all reported here).
     *
     * @return array<mixed>|null
     */
    private function read(string $file): ?array
    {
        $path = "{$this->root}/{$file}";
        $json = is_file($path) ? file_get_contents($path) : false;

        if ($json === false) {
            $this->problem($file, 'is missing');

            return null;
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->problem($file, 'is not valid JSON: '.$exception->getMessage());

            return null;
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            $this->problem($file, 'must hold a JSON object');

            return null;
        }

        return $decoded;
    }

    /**
     * The list under a file's one top-level key.
     *
     * @return list<mixed>|null
     */
    private function listIn(string $file, string $key): ?array
    {
        $data = $this->object($this->read($file), $file, '', [$key]);

        return $data === null ? null : $this->list($data[$key], $file, $key);
    }

    /**
     * @return list<mixed>|null
     */
    private function list(mixed $value, string $file, string $where): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->problem($file, "`{$where}` must be a list");

            return null;
        }

        return $value;
    }

    /**
     * An object with these keys and no others. Missing and unknown keys are
     * reported; null comes back when the value is not an object (reported
     * unless it is null, which means "already reported") or lacks a
     * required key, so callers can read every required key directly.
     *
     * @param  list<string>  $required
     * @param  list<string>  $optional
     * @return array<string, mixed>|null
     */
    private function object(mixed $value, string $file, string $where, array $required, array $optional = []): ?array
    {
        $label = $where === '' ? 'the file' : $where;

        if ($value === null) {
            if ($where !== '') {
                $this->problem($file, "{$label} must be an object");
            }

            return null;
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $this->problem($file, "{$label} must be an object");

            return null;
        }

        foreach (array_keys($value) as $key) {
            if (! in_array($key, [...$required, ...$optional], true)) {
                $this->problem($file, "{$label} has an unknown key `{$key}`");
            }
        }

        $missing = array_diff($required, array_keys($value));

        foreach ($missing as $key) {
            $this->problem($file, "{$label} needs `{$key}`");
        }

        /** @var array<string, mixed> $value */
        return $missing === [] ? $value : null;
    }

    private function string(mixed $value, string $file, string $where): ?string
    {
        if (! is_string($value) || $value === '') {
            $this->problem($file, "{$where} must be a non-empty string");

            return null;
        }

        return $value;
    }

    /**
     * @return list<string>|null
     */
    private function strings(mixed $value, string $file, string $where, bool $allowEmpty = false): ?array
    {
        $valid = is_array($value) && array_is_list($value) && ($allowEmpty || $value !== [])
            && array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== '') === $value;

        if (! $valid) {
            $this->problem($file, "{$where} must be a ".($allowEmpty ? '' : 'non-empty ').'list of non-empty strings');

            return null;
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private function enum(string $enum, mixed $value, string $file, string $where): ?BackedEnum
    {
        $case = is_string($value) ? $enum::tryFrom($value) : null;

        if ($case === null) {
            $allowed = implode(', ', array_map(static fn (BackedEnum $case): string => (string) $case->value, $enum::cases()));
            $this->problem($file, "{$where} must be one of {$allowed}");
        }

        return $case;
    }

    /**
     * A non-empty list of distinct enum values.
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return list<T>|null
     */
    private function enums(string $enum, mixed $value, string $file, string $where): ?array
    {
        $values = $this->strings($value, $file, $where);

        if ($values === null) {
            return null;
        }

        if (count(array_unique($values)) !== count($values)) {
            $this->problem($file, "{$where} lists a value twice");
        }

        $cases = [];

        foreach ($values as $index => $item) {
            $case = $this->enum($enum, $item, $file, "{$where}[{$index}]");

            if ($case !== null) {
                $cases[] = $case;
            }
        }

        return $cases;
    }

    /**
     * A title in both interface languages (§9); the Azerbaijani one uses
     * only alphabet letters.
     */
    private function title(mixed $title, string $file, string $where): void
    {
        $title = $this->object($title, $file, $where, ['az', 'en']);

        if ($title === null) {
            return;
        }

        $az = $this->string($title['az'], $file, "{$where}.az");
        $this->string($title['en'], $file, "{$where}.en");

        if ($az !== null && ! $this->lettersAndSigns($az)) {
            $this->problem($file, "{$where}.az \"{$az}\" may hold only alphabet letters, spaces and . , ! ? « » -");
        }
    }

    /**
     * An optional whole-number field, required exactly when `$needed`.
     *
     * @param  array<string, mixed>  $object
     */
    private function wholeNumber(array $object, string $key, bool $needed, int $min, int $max, string $file, string $where, string $who): void
    {
        if (! $needed) {
            if (array_key_exists($key, $object)) {
                $this->problem($file, "{$where}: `{$key}` belongs to {$who} only");
            }

            return;
        }

        $value = $object[$key] ?? null;

        if (! is_int($value) || $value < $min || $value > $max) {
            $this->problem($file, "{$where}.{$key} must be a whole number from {$min} to {$max} for {$who}");
        }
    }

    private function range(mixed $range, int $floor, ?int $ceiling, string $file, string $where): void
    {
        $range = $this->object($range, $file, $where, ['min', 'max']);

        if ($range === null) {
            return;
        }

        $min = $range['min'];
        $max = $range['max'];

        if (! is_int($min) || ! is_int($max) || $min < $floor || $max < $min || ($ceiling !== null && $max > $ceiling)) {
            $bounds = $ceiling === null ? "from {$floor}" : "from {$floor} to {$ceiling}";
            $this->problem($file, "{$where} needs whole numbers {$bounds}, min ≤ max");
        }
    }

    /**
     * A picture or sound path, relative to the content root's `assets/`; the
     * file must exist with exactly that spelling (and so needs a credits
     * entry).
     *
     * @param  list<string>  $extensions
     * @return string|null the path, or null when it is not a path at all
     */
    private function asset(mixed $path, array $extensions, string $file, string $where): ?string
    {
        $assets = $this->contentFolder().'/assets';

        if (! is_string($path) || preg_match(self::ASSET_PATH, $path) !== 1) {
            $this->problem($file, "{$where} must be a path under {$assets} with lowercase folders, like fruit/alma.svg");

            return null;
        }

        if (! in_array(pathinfo($path, PATHINFO_EXTENSION), $extensions, true)) {
            $this->problem($file, "{$where} \"{$path}\" must end in .".implode(', .', $extensions));
        }

        if (! self::exists("{$this->root}/assets/{$path}")) {
            $this->problem($file, "{$where}: {$assets}/{$path} does not exist");
        }

        return $path;
    }

    /**
     * An `image` that exists and that no earlier entry of the same list uses
     * (two words of a pack, or two avatars, never share a picture).
     *
     * @param  array<string, string>  $used  picture path → the entry that has it
     */
    private function picture(mixed $path, string $file, string $where, array &$used): void
    {
        $path = $this->asset($path, self::IMAGE_EXTENSIONS, $file, "{$where}.image");

        if ($path === null) {
            return;
        }

        if (isset($used[$path])) {
            $this->problem($file, "{$where}.image \"{$path}\" is already the picture of {$used[$path]}");

            return;
        }

        $used[$path] = $where;
    }

    /**
     * Whether a file exists with exactly this name: `is_file()` alone would
     * accept `1f34e.svg` for `1F34E.svg` on a case-insensitive disk (macOS),
     * which then fails on Linux.
     */
    private static function exists(string $path): bool
    {
        return is_file($path) && in_array(basename($path), scandir(dirname($path)) ?: [], true);
    }

    // ── SVG files ───────────────────────────────────────────────────────

    /**
     * Asset files are served from the app's own origin to children's
     * screens, and an SVG opened on its own runs its scripts with that
     * origin's rights (§8). Every SVG under `assets/` must be a plain
     * drawing: no script, no event attribute, no foreign HTML and no link
     * other than a reference inside the file.
     */
    private function svgs(): void
    {
        $assets = "{$this->root}/assets";

        if (! is_dir($assets)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($assets, RecursiveDirectoryIterator::SKIP_DOTS));
        $files = [];

        /** @var SplFileInfo $info */
        foreach ($iterator as $info) {
            if ($info->isFile() && strtolower($info->getExtension()) === 'svg') {
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($info->getPathname(), strlen($this->root) + 1));
            }
        }

        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $svg = (string) file_get_contents("{$this->root}/{$file}");

            if (preg_match('/<script\b/i', $svg) === 1) {
                $this->problem($file, 'has a <script> element; asset SVGs must be plain drawings');
            }

            if (preg_match('/<foreignObject\b/i', $svg) === 1) {
                $this->problem($file, 'has a <foreignObject> element; asset SVGs must be plain drawings');
            }

            if (preg_match('/<[^>]*\son[a-z]+\s*=/i', $svg) === 1) {
                $this->problem($file, 'has an event attribute (on…=); asset SVGs must be plain drawings');
            }

            preg_match_all('/\s(?:xlink:)?href\s*=\s*(["\'])(.*?)\1/is', $svg, $links);

            foreach (array_unique($links[2]) as $link) {
                if (! str_starts_with($link, '#')) {
                    $this->problem($file, "links to \"{$link}\"; an asset SVG may only refer to #… inside the file");
                }
            }
        }
    }

    /**
     * Files are stored normalized: a string `Az::normalize()` would change
     * (Cyrillic ә, Ǝ, invisible characters, double spaces) is rejected, not
     * fixed silently. The message names the odd characters' code points.
     */
    private function normalized(mixed $value, string $file, string $where = ''): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->normalized($key, $file, $where);
                $this->normalized($item, $file, match (true) {
                    $where === '' => (string) $key,
                    is_int($key) => "{$where}[{$key}]",
                    default => "{$where}.{$key}",
                });
            }

            return;
        }

        if (! is_string($value) || Az::normalize($value) === $value) {
            return;
        }

        $odd = array_unique(array_filter(
            mb_str_split($value),
            static fn (string $character): bool => (mb_ord($character) ?: 0) > 0x7E && ! Alphabet::isLetter($character) && ! in_array($character, ['«', '»', '×', '≤', '·'], true),
        ));
        $codes = implode(' ', array_map(static fn (string $character): string => sprintf('U+%04X', mb_ord($character) ?: 0), $odd));
        $label = $where === '' ? 'a string' : $where;

        $this->problem($file, "{$label} \"{$value}\" is not normalized (".($codes === '' ? 'extra spaces' : $codes).')');
    }

    private function lettersAndSigns(string $text): bool
    {
        foreach (mb_str_split($text) as $character) {
            if (! Alphabet::isLetter($character) && ! in_array($character, self::SIGNS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The content root's folder name, as credits spell it (`content`).
     */
    private function contentFolder(): string
    {
        return basename($this->root);
    }

    private static function counted(int $count, string $noun): string
    {
        return $count === 1 ? "1 {$noun}" : "{$count} {$noun}s";
    }

    private function problem(string $file, string $message): void
    {
        $this->problems[] = "{$file}: {$message}";
    }
}
