<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\CreditData;
use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\PromptTemplateData;
use App\Domain\Content\Data\RangeData;
use App\Domain\Content\Data\RoomRivalryData;
use App\Domain\Content\Data\RoomTauntData;
use App\Domain\Content\Data\SoundChoiceData;
use App\Domain\Content\Data\VoiceData;
use App\Domain\Content\Enums\Effect;
use App\Domain\Content\Enums\ItemStyle;
use App\Domain\Content\Enums\License;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\RoomGameKind;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Enums\RoomSequenceRule;
use App\Domain\Content\Enums\RoomSound;
use App\Domain\Content\Enums\RoomSource;
use App\Domain\Content\Enums\RoomSpeaker;
use App\Domain\Content\Enums\SlotStyle;
use App\Domain\Content\Enums\Sound;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Enums\WordProblem;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Language\Support\AzCollator;
use App\Domain\Language\Support\Syllabifier;
use BackedEnum;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Checks a content root (docs/architecture.md §3, §8): `alphabet.json`,
 * `avatars.json`, `sounds.json`, `levels.json`, `prompts.json`, `names.json`,
 * `voice.json` with `voice-clips.json`, `credits.json`, `packs/*.json`,
 * `rooms/levels.json`, `rooms/shapes.json`, `rooms/categories.json`,
 * `rooms/words.json`, `rooms/sounds.json`, `rooms/rivalry.json` and
 * `rooms/taunts.json` (§12 "Room content", with the taunts' clips of
 * `assets/rooms/taunts/`), `tests/syllables.json` and every file under
 * `assets/`, `voice-names/` and `resources/fonts/` (credits; SVG files are
 * also checked for anything that could run or load). `validate()` lists every problem, one line each;
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
     * words its emoji set lacked.
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
     * Words with a picture a pack that lists `picture` needs (R5, ADR 012).
     */
    public const int MIN_PICTURES = 6;

    /**
     * Words with a picture a pack that lists `number` needs: the things a
     * `count` round counts.
     */
    public const int MIN_COUNTED = 4;

    /**
     * The numbers a number level may use (R5): 1–10.
     */
    public const int MIN_NUMBER = 1;

    public const int MAX_NUMBER = 10;

    /**
     * Figures a pack of an `animal` or `fruit` look needs per item of the
     * fullest round any level shows (§2 "Figures"): the round's own words
     * and picture are left out, and the rest must still give every item a
     * figure of its own.
     */
    public const int FIGURES_PER_ITEM = 2;

    /**
     * Avatars mark students (§7); no item ever stands in front of one.
     */
    public const string AVATAR_FOLDER = 'avatars/';

    /**
     * Folders outside the content root whose every file needs a credits
     * entry, from the repository root.
     */
    public const array CREDITED_FOLDERS = ['resources/fonts'];

    /**
     * Where share-alike files must stay, inside the content root (§8). None
     * is left since ADR 014 removed OpenMoji; the folder name stays, so a
     * share-alike file added later still has one place.
     */
    public const string SHARE_ALIKE_FOLDER = 'assets/openmoji/';

    /**
     * The one speech provider `php artisan content:voice` calls (ADR 015).
     */
    public const string VOICE_PROVIDER = 'elevenlabs';

    /**
     * The greeting and the two end phrases of V2 (ADR 016 decision 7) need
     * a clip, like praise and retry: switched on with the clips V2's paid
     * month made (2026-11-12), as `WORD_PICTURES_REQUIRED` was when A5 drew
     * the pictures.
     */
    public const bool NAME_PHRASE_CLIPS_REQUIRED = true;

    /**
     * Letters a first name of `names.json` may have (ADR 016 decision 1).
     */
    public const int MIN_NAME_LETTERS = 2;

    public const int MAX_NAME_LETTERS = 24;

    /**
     * A room mechanic has a level for each difficulty 1–4 («Asan», «Orta»,
     * «Çətin», «Çox çətin»), or none (§12 "Room content").
     */
    public const int ROOM_DIFFICULTIES = 4;

    /**
     * A room round's time to answer, and the most it may be studied.
     */
    public const int MIN_ROOM_ANSWER_MS = 5_000;

    public const int MAX_ROOM_ANSWER_MS = 60_000;

    public const int MAX_ROOM_STUDY_MS = 60_000;

    /** A board level's clock is whole minutes, from one to this many (ADR 034 decision 6). */
    public const int MAX_BOARD_CLOCK_MINUTES = 30;

    /** A board level's increment is whole seconds, from 0 to this many. */
    public const int MAX_BOARD_INCREMENT_SECONDS = 30;

    private const int MINUTE_MS = 60_000;

    /**
     * Items in a `recall_order` row: at least three, since the question
     * never shows the row's order and two items in another order can only
     * be the row reversed — the question would tell the answer; at most
     * twelve.
     */
    public const int MIN_ROOM_ROW = 3;

    public const int MAX_ROOM_ROW = 12;

    /**
     * A `recall_cells` grid: 2–6 cells across and down, so at most 6 × 6
     * (§12 "Room games"); a grid of one row or column would be a row.
     */
    public const int MIN_ROOM_GRID = 2;

    public const int MAX_ROOM_GRID = 6;

    /**
     * Where the shapes of memory rounds are, under `assets/` (§12 "Room
     * content").
     */
    public const string ROOM_SHAPES_FOLDER = 'rooms/';

    /**
     * Where the sounds of a room game are, under `assets/` (§12 "Room
     * content", ADR 025 "Sound").
     */
    public const string ROOM_SOUNDS_FOLDER = 'rooms/sounds/';

    /**
     * A room sound is one event, so a page can fetch its speaker's set as
     * soon as sound is unlocked: no file above this many bytes — the 5 s
     * clock is the longest (ADR 028 decision 12).
     */
    public const int MAX_ROOM_SOUND_BYTES = 64 * 1024;

    /**
     * A speaker's whole set, fetched at once when sound is unlocked: the
     * screen's on the computer by the wall, a phone's a few short files, so
     * a phone that joins stays light (ADR 028 decision 12).
     *
     * @var array<value-of<RoomSpeaker>, int>
     */
    public const array MAX_ROOM_SPEAKER_BYTES = ['screen' => 320 * 1024, 'phone' => 64 * 1024];

    /**
     * Where the rivalry strip's emoji are, under `assets/`: Fluent Emoji
     * (Color) files of the app's own set (§12 "Room content", ADR 027).
     */
    public const string ROOM_RIVALRY_FOLDER = 'fluent/';

    /**
     * Where the taunts' emoji are, under `assets/`: Fluent Emoji (Color)
     * files of the app's own set (§12 "Room content", ADR 029 decision 28).
     */
    public const string ROOM_TAUNTS_FOLDER = 'fluent/';

    /** How many taunts the list holds (ADR 029 decision 28). */
    public const int MIN_ROOM_TAUNTS = 8;

    public const int MAX_ROOM_TAUNTS = 12;

    /**
     * A taunt's clip holds at most this (ADR 029 decision 50): a phrase of
     * two or three seconds at the voice's bitrate is about 20 KB, and a
     * device fetches the twelve once its sound is on.
     */
    public const int MAX_TAUNT_VOICE_BYTES = 48 * 1024;

    /**
     * A `next_in_sequence` row shows at most seven terms, and every number
     * of a round is 1–999 — three digits, short enough for seven terms and
     * the «?» to fit one row of a 375px phone (§12 "Room games", B5).
     */
    public const int MAX_ROOM_TERMS = 7;

    public const int MAX_ROOM_NUMBER = 999;

    /**
     * An `odd_one_out` round offers 4 or 5 options (§12 "Room games").
     */
    public const int MIN_ROOM_OPTIONS = 4;

    public const int MAX_ROOM_OPTIONS = 5;

    /**
     * `categories.json` (B5): at least four groups of at least eight words,
     * so a round has a group to take all but one from and others to take
     * the odd one from, and rounds do not repeat at once.
     */
    public const int MIN_ROOM_CATEGORIES = 4;

    public const int MIN_ROOM_CATEGORY_WORDS = 8;

    /**
     * The words a word level draws from (§12 "Room content", B6): at least
     * this many of every length it asks for, and a spelling level as many
     * words that make three wrong spellings with its `swaps` — so a game
     * of 20 rounds draws no word twice and the next game other words.
     */
    public const int MIN_ROOM_WORDS = 60;

    /**
     * Content written as lists rather than drawn or recorded that carries a
     * licence of its own and so a credits entry (§8): the adults' word list
     * (ADR 023 decision 10), relative to the content root.
     */
    public const array CREDITED_LISTS = ['rooms/words.json'];

    private const string KEY = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * Lowercase folders; a file name may hold capitals, for Fluent Emoji's
     * code points (`fluent/1F9D1-200D-1F3EB.svg`).
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
     * What a title may hold besides letters and SIGNS: digits and a colon,
     * for «1-dən 5-ə» and «Şəkillər: asan» (R5). Prompts never hold them.
     */
    private const array TITLE_SIGNS = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', ':'];

    /**
     * @var list<string>
     */
    private array $problems = [];

    /**
     * The sounds of `sounds.json` by moment and key, or null when the file
     * has a problem (then no pack's sound is checked against it).
     *
     * @var array<value-of<Sound>, array<string, SoundChoiceData>>|null
     */
    private ?array $soundChoices = null;

    /**
     * The templates of `prompts.json` as `voice.json` keys them
     * (`find.picture`), or null when the file has a problem.
     *
     * @var list<string>|null
     */
    private ?array $templates = null;

    /**
     * The first names of `names.json`, or null when the file has a problem
     * (then no name clip is checked against it).
     *
     * @var list<string>|null
     */
    private ?array $names = null;

    /**
     * The rivalry strip's icons by path (`fluent/26A1.svg` → `icons.clash`),
     * as `rooms/rivalry.json` names them: no taunt may be one of them.
     *
     * @var array<string, string>
     */
    private array $rivalryIcons = [];

    /**
     * The taunts' context and each taunt's audio tag by key, as
     * `rooms/taunts.json` gives them, or null when that file has a problem
     * (then no taunt's clip is checked against it).
     *
     * @var array{context: string, tags: array<string, string>}|null
     */
    private ?array $tauntVoices = null;

    /**
     * @param  bool  $voiceClipsRequired  false for `content:voice`, which makes what this would require (the praise and retry clips, a deleted clip), lists what is made and prunes the clips of another voice or of a name that left the list
     * @param  bool  $namePhraseClipsRequired  whether the greeting and the end phrases need a clip (`NAME_PHRASE_CLIPS_REQUIRED`)
     */
    public function __construct(
        private readonly string $root,
        private readonly bool $wordPicturesRequired = self::WORD_PICTURES_REQUIRED,
        private readonly bool $voiceClipsRequired = true,
        private readonly bool $namePhraseClipsRequired = self::NAME_PHRASE_CLIPS_REQUIRED,
    ) {}

    /**
     * @return list<string>
     */
    public function validate(): array
    {
        $this->problems = [];

        $this->alphabet();
        $this->avatars();
        $this->sounds();
        $levels = $this->levels();
        $this->prompts($levels);
        $this->names();
        $this->roomCategories();
        $this->roomLevels($this->roomShapes(), $this->roomWords());
        $this->roomSounds();
        $this->roomRivalry();
        $this->roomTaunts();
        $this->voice();
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

    // ── sounds.json ─────────────────────────────────────────────────────

    /**
     * The sounds an account may choose (§3 "Sounds"): a `version` and, for
     * every choosable moment (`Sound::choosable()`, and no other), a
     * non-empty list of sounds, each with a unique key, an audio file listed
     * once, a title and, optionally, the effects it suits.
     */
    private function sounds(): void
    {
        $file = 'sounds.json';
        $this->soundChoices = null;
        $before = count($this->problems);
        $data = $this->object($this->read($file), $file, '', ['version', 'sounds']);

        if ($data === null) {
            return;
        }

        if (! is_int($data['version']) || $data['version'] < 1) {
            $this->problem($file, 'version must be a whole number from 1');
        }

        $moments = array_map(static fn (Sound $sound): string => $sound->value, Sound::choosable());
        $lists = $this->object($data['sounds'], $file, 'sounds', $moments);

        if ($lists === null) {
            return;
        }

        $choices = [];

        foreach (Sound::choosable() as $sound) {
            $where = "sounds.{$sound->value}";
            $list = $this->list($lists[$sound->value], $file, $where);
            $keys = [];
            $files = [];

            if ($list === []) {
                $this->problem($file, "{$where} needs at least one sound");
            }

            foreach ($list ?? [] as $index => $entry) {
                $at = "{$where}[{$index}]";
                $entry = $this->object($entry, $file, $at, ['key', 'file', 'title'], ['effects']);

                if ($entry === null) {
                    continue;
                }

                $key = $this->string($entry['key'], $file, "{$at}.key");

                if ($key !== null && (preg_match(SoundChoices::KEY_PATTERN, $key) !== 1 || in_array($key, $keys, true))) {
                    $this->problem($file, "{$at} \"{$key}\" needs a key unique in {$where} matching ".SoundChoices::KEY_PATTERN);
                }

                $path = $this->asset($entry['file'], self::AUDIO_EXTENSIONS, $file, "{$at}.file");

                if ($path !== null && in_array($path, $files, true)) {
                    $this->problem($file, "{$at}.file \"{$path}\" is already a sound of {$where}");
                }

                $this->title($entry['title'], $file, "{$at}.title");

                if (array_key_exists('effects', $entry)) {
                    $this->enums(Effect::class, $entry['effects'], $file, "{$at}.effects");
                }

                $keys[] = $key;
                $files[] = $path;
                /** @var array{key: string, file: string, title: array{az: string, en: string}, effects?: list<string>} $entry */
                $choices[$sound->value][] = $entry;
            }
        }

        if (count($this->problems) !== $before) {
            return;
        }

        $this->soundChoices = [];

        foreach ($choices as $moment => $entries) {
            foreach ($entries as $entry) {
                $this->soundChoices[$moment][$entry['key']] = SoundChoiceData::fromArray($entry);
            }
        }
    }

    /**
     * A pack's own sound for a choosable moment is one of the sounds of
     * `sounds.json` for the same moment, so the account's choice (Settings →
     * Oyun) can always fall back to it, and it suits the pack's effect (no
     * pop where items sparkle, §8). `arrive` and `leave` are the pack's
     * alone: any audio file that exists, credited like every asset.
     */
    private function packSound(Sound $sound, ?string $path, ?Effect $effect, string $file): void
    {
        if (! $sound->isChoosable() || $this->soundChoices === null || $path === null) {
            return;
        }

        foreach ($this->soundChoices[$sound->value] ?? [] as $choice) {
            if ($choice->file === $path) {
                if ($effect !== null && ! $choice->suits($effect)) {
                    $this->problem($file, "style.sounds.{$sound->value} \"{$path}\" does not suit the effect `{$effect->value}`");
                }

                return;
            }
        }

        $this->problem($file, "style.sounds.{$sound->value} \"{$path}\" must be a sound of sounds.json under sounds.{$sound->value}");
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
            $level = $this->object($level, $file, $where, ['key', 'title', 'unit', 'mechanics'], ['items', 'decoys', 'shapes', 'syllables', 'words', 'numbers']);

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

            foreach (['shapes' => Unit::Syllable, 'syllables' => Unit::Word, 'words' => Unit::Sentence, 'numbers' => Unit::Number] as $filter => $filterUnit) {
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

            if (array_key_exists('numbers', $level)) {
                $this->numbers($level['numbers'], $level['items'] ?? null, $file, "{$where}.numbers");
            }
        }

        if (count($this->problems) !== $before) {
            return null;
        }

        /** @var list<array{key: string, title: array{az: string, en: string}, unit: string, mechanics: list<string>, items?: int, decoys?: int, shapes?: list<string>, syllables?: array{min: int, max: int}, words?: array{min: int, max: int}, numbers?: array{min: int, max: int}}> $levels */
        return array_map(LevelData::fromArray(...), $levels);
    }

    // ── prompts.json ────────────────────────────────────────────────────

    /**
     * @param  list<LevelData>|null  $levels
     */
    private function prompts(?array $levels): void
    {
        $file = 'prompts.json';
        $this->templates = null;
        $before = count($this->problems);
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

        $templates = array_map(static fn (string $pair): string => str_replace(' × ', '.', $pair), array_keys($pairs));

        if ($levels === null) {
            $this->templates = count($this->problems) === $before ? $templates : null;

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

        $this->templates = count($this->problems) === $before ? $templates : null;
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

    // ── rooms/levels.json ───────────────────────────────────────────────

    /**
     * The room levels (§12 "Room content", ADR 023): `version` and `levels`,
     * each with a known mechanic, a difficulty 1–4 and no second level of
     * the same pair; a mechanic with any level has all four, so whatever
     * `Rooms\Support\RoomPlayable` lists can be played at every difficulty
     * (`RoomPlayableTest` checks that each mechanic it lists has levels).
     * `answer_ms` is 5 000–60 000; `study_ms` is above 0 (at most 60 000)
     * for a memory mechanic and 0 for any other. A level holds the keys of
     * its own mechanic and no other, with the numbers its builder can
     * build:
     *
     * - `recall_order`: `kinds`, distinct kinds of `RoomMechanic::kinds()`
     *   (digits, letters, short words and shapes); `length`, 3–12 items,
     *   `min` ≤ `max`, and no longer than each kind has different items
     *   (`RoomItemKind::distinct()`: 10 digits; the shapes of `shapes.json`
     *   and the short words of `words.json` (`RoomWords::forRow()`),
     *   counted when that file is sound), since no item comes twice in a
     *   row.
     * - `recall_cells`: `grid`, `cols` and `rows` 2–6; `lit`, from 1 and
     *   `min` ≤ `max`, fewer than the grid's cells, so a dark cell is always
     *   left to tell from a lit one.
     * - `next_in_sequence` (B5): `rules`, distinct rules of
     *   `RoomSequenceRule`; `terms`, at least what every rule named needs
     *   to be told from the others (`RoomSequenceRule::minTerms()`) and at
     *   most seven; `range`, 1–999 with `min` below `max`. Whether a rule
     *   fits its range is the builder's arithmetic, which the validator may
     *   not reach: `RoomPlayableTest` builds every level.
     * - `odd_one_out` (B5): `source`, numbers or words; `options`, 4 or 5.
     * - `anagram` (B6): `word_length`, 3–12 letters, `min` ≤ `max`, and for
     *   every length in it at least `MIN_ROOM_WORDS` words of `words.json`
     *   an anagram can be drawn from (`RoomWords::forAnagram()`).
     * - `spelling` (B6): `word_length` as an anagram's, with at least
     *   `MIN_ROOM_WORDS` words of every length in it; `swaps`, 1 or 2; and
     *   at least `MIN_ROOM_WORDS` words in the range that make three wrong
     *   spellings with those swaps (`RoomWords::forSpelling()`) — counted
     *   per level, not merely words with a confusable letter.
     *
     * A board mechanic (D3, ADR 034 decision 6) has other rules: its level is
     * its clock, `{mechanic, difficulty, clock_ms, increment_ms}` and no key
     * of a race level; `clock_ms` is whole minutes from 1 to 30 and
     * `increment_ms` whole seconds from 0 to 30; it has two to four levels
     * numbered from 1 without a gap, and a shorter clock at each higher
     * number. A race mechanic keeps "all four or none".
     *
     * The words are counted only when `words.json` is sound.
     *
     * @param  int|null  $shapes  how many shapes `shapes.json` holds; null when it has a problem
     * @param  RoomWords|null  $words  the word list; null when `words.json` has a problem
     */
    private function roomLevels(?int $shapes, ?RoomWords $words): void
    {
        $file = ContentRepository::ROOM_LEVELS;
        $data = $this->object($this->read($file), $file, '', ['version', 'levels']);

        if ($data === null) {
            return;
        }

        if (! is_int($data['version']) || $data['version'] < 1) {
            $this->problem($file, 'version must be a whole number from 1');
        }

        $levels = $this->list($data['levels'], $file, 'levels');

        if ($levels === null) {
            return;
        }

        /** @var array<string, list<int>> $difficulties */
        $difficulties = [];
        /** @var array<string, array<int, int|null>> $clocks a board mechanic's clocks, by difficulty */
        $clocks = [];

        foreach ($levels as $index => $level) {
            $where = "levels[{$index}]";
            $mechanic = is_array($level) && is_string($level['mechanic'] ?? null) ? RoomMechanic::tryFrom($level['mechanic']) : null;
            $board = $mechanic?->kind() === RoomGameKind::Board;
            $every = $board ? ['mechanic', 'difficulty', 'clock_ms', 'increment_ms'] : ['mechanic', 'difficulty', 'study_ms', 'answer_ms'];

            // An unknown mechanic is reported once, not with each of its keys.
            $level = $mechanic === null
                ? $this->object($level, $file, $where, $every, is_array($level) ? array_map(strval(...), array_keys($level)) : [])
                : $this->object($level, $file, $where, [...$every, ...self::roomLevelKeys($mechanic)]);

            if ($level === null) {
                continue;
            }

            $this->enum(RoomMechanic::class, $level['mechanic'], $file, "{$where}.mechanic");
            $difficulty = $level['difficulty'];

            if (! is_int($difficulty) || $difficulty < 1 || $difficulty > self::ROOM_DIFFICULTIES) {
                $this->problem($file, "{$where}.difficulty must be a whole number from 1 to ".self::ROOM_DIFFICULTIES);
            } elseif ($mechanic !== null) {
                if (in_array($difficulty, $difficulties[$mechanic->value] ?? [], true)) {
                    $this->problem($file, "{$where}: {$mechanic->value} has a second level of difficulty {$difficulty}");
                }

                $difficulties[$mechanic->value][] = $difficulty;
            }

            if ($board) {
                $clock = $this->boardLevel($level, $file, $where);

                if (is_int($difficulty)) {
                    $clocks[$mechanic->value][$difficulty] = $clock;
                }

                continue;
            }

            $answer = $level['answer_ms'];

            if (! is_int($answer) || $answer < self::MIN_ROOM_ANSWER_MS || $answer > self::MAX_ROOM_ANSWER_MS) {
                $this->problem($file, "{$where}.answer_ms must be a whole number from ".self::MIN_ROOM_ANSWER_MS.' to '.self::MAX_ROOM_ANSWER_MS);
            }

            $study = $level['study_ms'];

            if ($mechanic?->studies() === true && (! is_int($study) || $study < 1 || $study > self::MAX_ROOM_STUDY_MS)) {
                $this->problem($file, "{$where}.study_ms must be a whole number from 1 to ".self::MAX_ROOM_STUDY_MS.' for a memory game');
            } elseif ($mechanic?->studies() === false && $study !== 0) {
                $this->problem($file, "{$where}.study_ms must be 0: only a memory game is studied");
            }

            match ($mechanic) {
                RoomMechanic::RecallOrder => $this->recallOrderLevel($mechanic, $level, $file, $where, $shapes, $words === null ? null : count($words->forRow())),
                RoomMechanic::RecallCells => $this->recallCellsLevel($level, $file, $where),
                RoomMechanic::NextInSequence => $this->nextInSequenceLevel($level, $file, $where),
                RoomMechanic::OddOneOut => $this->oddOneOutLevel($level, $file, $where),
                RoomMechanic::Anagram, RoomMechanic::Spelling => $this->wordLevel($mechanic, $level, $file, $where, $words),
                RoomMechanic::Draughts, null => null,
            };
        }

        foreach ($difficulties as $mechanic => $given) {
            if (array_key_exists($mechanic, $clocks)) {
                $this->boardLevels($mechanic, $given, $clocks[$mechanic], $file);

                continue;
            }

            foreach (range(1, self::ROOM_DIFFICULTIES) as $difficulty) {
                if (! in_array($difficulty, $given, true)) {
                    $this->problem($file, "{$mechanic} has no level of difficulty {$difficulty}: a room game has all four or none");
                }
            }
        }
    }

    /**
     * A board game's level: its clock (ADR 034 decision 6).
     *
     * @param  array<string, mixed>  $level
     * @return int|null its `clock_ms` when it is sound
     */
    private function boardLevel(array $level, string $file, string $where): ?int
    {
        $clock = $level['clock_ms'];
        $increment = $level['increment_ms'];
        $sound = true;

        if (! is_int($clock) || $clock < self::MINUTE_MS || $clock > self::MAX_BOARD_CLOCK_MINUTES * self::MINUTE_MS || $clock % self::MINUTE_MS !== 0) {
            $this->problem($file, "{$where}.clock_ms must be whole minutes from 1 to ".self::MAX_BOARD_CLOCK_MINUTES);
            $sound = false;
        }

        if (! is_int($increment) || $increment < 0 || $increment > self::MAX_BOARD_INCREMENT_SECONDS * 1_000 || $increment % 1_000 !== 0) {
            $this->problem($file, "{$where}.increment_ms must be whole seconds from 0 to ".self::MAX_BOARD_INCREMENT_SECONDS);
        }

        return $sound ? $clock : null;
    }

    /**
     * A board game's levels together: two to four, numbered from 1 without a
     * gap, a shorter clock at each higher number.
     *
     * @param  list<int>  $given  the difficulties of its levels
     * @param  array<int, int|null>  $clocks  each sound level's `clock_ms`, by difficulty
     */
    private function boardLevels(string $mechanic, array $given, array $clocks, string $file): void
    {
        $count = count($given);

        if ($count < 2 || max($given) !== $count || count(array_unique($given)) !== $count) {
            $this->problem($file, "{$mechanic} must have two to four levels numbered from 1 without a gap");

            return;
        }

        ksort($clocks);
        $previous = null;

        foreach ($clocks as $difficulty => $clock) {
            if ($clock !== null && $previous !== null && $clock >= $previous) {
                $this->problem($file, "the level of difficulty {$difficulty} of {$mechanic} must have a shorter clock than the level before it");
            }

            $previous = $clock ?? $previous;
        }
    }

    /**
     * The keys of a room level besides the four every level of its kind has.
     *
     * @return list<string>
     */
    private static function roomLevelKeys(RoomMechanic $mechanic): array
    {
        return match ($mechanic) {
            RoomMechanic::RecallOrder => ['kinds', 'length'],
            RoomMechanic::RecallCells => ['grid', 'lit'],
            RoomMechanic::NextInSequence => ['rules', 'terms', 'range'],
            RoomMechanic::OddOneOut => ['source', 'options'],
            RoomMechanic::Anagram => ['word_length'],
            RoomMechanic::Spelling => ['word_length', 'swaps'],
            RoomMechanic::Draughts => [],
        };
    }

    /**
     * @param  array<string, mixed>  $level
     */
    private function nextInSequenceLevel(array $level, string $file, string $where): void
    {
        $rules = $this->enums(RoomSequenceRule::class, $level['rules'], $file, "{$where}.rules") ?? [];
        $terms = $level['terms'];

        if (! is_int($terms) || $terms < 1 || $terms > self::MAX_ROOM_TERMS) {
            $this->problem($file, "{$where}.terms must be a whole number from 1 to ".self::MAX_ROOM_TERMS);
        } else {
            foreach ($rules as $rule) {
                if ($terms < $rule->minTerms()) {
                    $this->problem($file, "{$where}.terms: a row of {$rule->value} shows at least {$rule->minTerms()} terms, so no other rule continues it otherwise");
                }
            }
        }

        $this->range($level['range'], 1, self::MAX_ROOM_NUMBER, $file, "{$where}.range");
        $range = $level['range'];

        if (is_array($range) && isset($range['min'], $range['max']) && is_int($range['min']) && $range['min'] === $range['max']) {
            $this->problem($file, "{$where}.range: a row needs more than one number");
        }
    }

    /**
     * @param  array<string, mixed>  $level
     */
    private function oddOneOutLevel(array $level, string $file, string $where): void
    {
        $this->enum(RoomSource::class, $level['source'], $file, "{$where}.source");
        $options = $level['options'];

        if (! is_int($options) || $options < self::MIN_ROOM_OPTIONS || $options > self::MAX_ROOM_OPTIONS) {
            $this->problem($file, "{$where}.options must be a whole number from ".self::MIN_ROOM_OPTIONS.' to '.self::MAX_ROOM_OPTIONS);
        }
    }

    /**
     * @param  array<string, mixed>  $level
     * @param  int|null  $shapes  how many shapes there are; null when `shapes.json` has a problem
     * @param  int|null  $rowWords  how many words a row may show; null when `words.json` has a problem
     */
    private function recallOrderLevel(RoomMechanic $mechanic, array $level, string $file, string $where, ?int $shapes, ?int $rowWords): void
    {
        $kinds = $this->enums(RoomItemKind::class, $level['kinds'], $file, "{$where}.kinds") ?? [];
        $shown = array_map(static fn (RoomItemKind $kind): string => $kind->value, $mechanic->kinds());

        foreach ($kinds as $kind) {
            if (! in_array($kind, $mechanic->kinds(), true)) {
                $this->problem($file, "{$where}.kinds: {$mechanic->value} can show ".implode(', ', $shown).", not {$kind->value}");
            }
        }

        $this->range($level['length'], self::MIN_ROOM_ROW, self::MAX_ROOM_ROW, $file, "{$where}.length");
        $max = is_array($level['length']) ? ($level['length']['max'] ?? null) : null;

        foreach ($kinds as $kind) {
            $distinct = match ($kind) {
                RoomItemKind::Shape => $shapes,
                RoomItemKind::Word => $rowWords,
                default => $kind->distinct(),
            };

            if (is_int($max) && $distinct !== null && $max > $distinct) {
                $this->problem($file, "{$where}.length: a row of {$kind->value}s holds at most {$distinct}, none twice");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $level
     */
    private function recallCellsLevel(array $level, string $file, string $where): void
    {
        $grid = $this->object($level['grid'], $file, "{$where}.grid", ['cols', 'rows']);
        $cells = null;

        if ($grid !== null) {
            $sides = array_filter([$grid['cols'], $grid['rows']], static fn (mixed $side): bool => is_int($side) && $side >= self::MIN_ROOM_GRID && $side <= self::MAX_ROOM_GRID);

            if (count($sides) !== 2) {
                $this->problem($file, "{$where}.grid needs cols and rows as whole numbers from ".self::MIN_ROOM_GRID.' to '.self::MAX_ROOM_GRID);
            } else {
                /** @var int $cols */
                $cols = $grid['cols'];
                /** @var int $rows */
                $rows = $grid['rows'];
                $cells = $cols * $rows;
            }
        }

        $this->range($level['lit'], 1, null, $file, "{$where}.lit");
        $max = is_array($level['lit']) ? ($level['lit']['max'] ?? null) : null;

        if ($cells !== null && is_int($max) && $max >= $cells) {
            $this->problem($file, "{$where}.lit: a grid of {$cells} cells lights at most ".($cells - 1).', so a dark cell is left');
        }
    }

    /**
     * A word game's level: its `word_length`, a spelling's `swaps`, and
     * enough words for both (see `roomLevels()`).
     *
     * @param  array<string, mixed>  $level
     */
    private function wordLevel(RoomMechanic $mechanic, array $level, string $file, string $where, ?RoomWords $words): void
    {
        $length = $this->letterRange($level['word_length'], $file, "{$where}.word_length");
        $swaps = $level['swaps'] ?? null;

        if ($mechanic === RoomMechanic::Spelling && ! in_array($swaps, RoomWords::SWAPS, true)) {
            $this->problem($file, "{$where}.swaps must be ".implode(' or ', RoomWords::SWAPS));
            $swaps = null;
        }

        if ($length === null || $words === null) {
            return;
        }

        for ($letters = $length->min; $letters <= $length->max; $letters++) {
            $exactly = new RangeData($letters, $letters);
            $count = count($mechanic === RoomMechanic::Anagram ? $words->forAnagram($exactly) : $words->ofLength($exactly));

            if ($count < self::MIN_ROOM_WORDS) {
                $this->problem($file, "{$where}.word_length: {$mechanic->value} draws words of {$letters} letters, and ".ContentRepository::ROOM_WORDS." has {$count} of them; it needs ".self::MIN_ROOM_WORDS);
            }
        }

        if ($mechanic === RoomMechanic::Spelling && is_int($swaps)) {
            $count = count($words->forSpelling($length, $swaps));

            if ($count < self::MIN_ROOM_WORDS) {
                $this->problem($file, "{$where}: spelling with {$swaps} swap".($swaps === 1 ? '' : 's')." draws from {$count} words of {$length->min}–{$length->max} letters that make three wrong spellings; it needs ".self::MIN_ROOM_WORDS);
            }
        }
    }

    /**
     * A `{min, max}` of word letters within what the list holds
     * (`RoomWords::MIN_LETTERS`–`MAX_LETTERS`), or null after reporting it.
     */
    private function letterRange(mixed $range, string $file, string $where): ?RangeData
    {
        $before = count($this->problems);
        $this->range($range, RoomWords::MIN_LETTERS, RoomWords::MAX_LETTERS, $file, $where);

        /** @var array{min: int, max: int} $range checked just now */
        return count($this->problems) === $before ? RangeData::fromArray($range) : null;
    }

    // ── rooms/words.json ────────────────────────────────────────────────

    /**
     * The adults' word list (§12 "Room content", ADR 023 decision 10):
     * `version` and two lists, each in Azerbaijani order (`AzCollator`), so
     * a duplicate sits beside its twin in a diff:
     *
     * - `words`, what the games draw: non-empty; each one lowercase word of
     *   the alphabet's letters with a vowel (`WordRules`), of 3–12 letters,
     *   none twice by `Az::lower()` and none a first name of `names.json`.
     * - `also_correct`, spellings that are correct too and never drawn
     *   (forms of a word, words of another kind): each lowercase letters of
     *   the alphabet, no word of `words` and none twice, and each a
     *   spelling some word of `words` makes by swapping one or two letters
     *   for their confusables — what it is there to keep from being
     *   offered as wrong.
     *
     * The file needs a `credits.json` entry (`CREDITED_LISTS`).
     *
     * @return RoomWords|null what the games make of it, or null when the file has a problem
     */
    private function roomWords(): ?RoomWords
    {
        $file = ContentRepository::ROOM_WORDS;
        $before = count($this->problems);
        $data = $this->object($this->read($file), $file, '', ['version', 'words', 'also_correct']);

        if ($data === null) {
            return null;
        }

        $this->normalized($data, $file);

        if (! is_int($data['version']) || $data['version'] < 1) {
            $this->problem($file, 'version must be a whole number from 1');
        }

        $words = $this->strings($data['words'], $file, 'words');
        $also = $this->strings($data['also_correct'], $file, 'also_correct', allowEmpty: true);
        $names = array_flip(array_map(Az::lower(...), $this->names ?? []));
        /** @var array<string, int> $seen lowercase → its place in `words` */
        $seen = [];

        foreach ($words ?? [] as $index => $word) {
            $where = "words[{$index}] \"{$word}\"";
            $letters = mb_strlen($word);

            if (WordRules::problems($word) !== []) {
                $this->problem($file, "{$where} must be one word of the alphabet's letters, with a vowel");
            } elseif (Az::lower($word) !== $word) {
                $this->problem($file, "{$where} must be lowercase");
            } elseif ($letters < RoomWords::MIN_LETTERS || $letters > RoomWords::MAX_LETTERS) {
                $this->problem($file, "{$where} must have ".RoomWords::MIN_LETTERS.'–'.RoomWords::MAX_LETTERS.' letters');
            }

            $key = Az::lower($word);

            if (isset($seen[$key])) {
                $this->problem($file, "{$where} is already words[{$seen[$key]}]");
            }

            if (isset($names[$key])) {
                $this->problem($file, "{$where} is a first name of ".ContentRepository::NAMES.'; the list holds no names');
            }

            $seen[$key] ??= $index;
        }

        $this->collated($words ?? [], $file, 'words');
        $list = new RoomWords(array_values(array_filter($words ?? [], static fn (string $word): bool => Az::lower($word) === $word)));
        $variants = [];

        foreach ($list->words() as $word) {
            foreach ([1, 2] as $swaps) {
                foreach (self::swapped($word, $swaps) as $variant) {
                    $variants[$variant] = true;
                }
            }
        }

        $alsoSeen = [];

        foreach ($also ?? [] as $index => $spelling) {
            $where = "also_correct[{$index}] \"{$spelling}\"";

            if (! WordRules::isWord($spelling) || Az::lower($spelling) !== $spelling) {
                $this->problem($file, "{$where} must be one lowercase word of the alphabet's letters");
            } elseif (isset($seen[$spelling])) {
                $this->problem($file, "{$where} is words[{$seen[$spelling]}] already");
            } elseif (! isset($variants[$spelling])) {
                $this->problem($file, "{$where} is no spelling a word of the list makes by swapping one or two letters for their confusables; it would keep nothing from being offered as wrong");
            }

            if (isset($alsoSeen[$spelling])) {
                $this->problem($file, "{$where} is already also_correct[{$alsoSeen[$spelling]}]");
            }

            $alsoSeen[$spelling] ??= $index;
        }

        $this->collated($also ?? [], $file, 'also_correct');

        if (count($this->problems) !== $before || $words === null || $also === null) {
            return null;
        }

        return new RoomWords($words, $also, $this->names ?? []);
    }

    /**
     * Every spelling the word makes by swapping exactly `$swaps` of its
     * letters for one of their confusables each.
     *
     * @return list<string>
     */
    private static function swapped(string $word, int $swaps): array
    {
        $spellings = [$word => mb_str_split($word)];

        for ($round = 0; $round < $swaps; $round++) {
            $next = [];

            foreach ($spellings as $letters) {
                foreach ($letters as $place => $letter) {
                    // Only a letter still as the word has it: a place is swapped once.
                    if ($letter !== mb_substr($word, $place, 1) || ! Alphabet::isLetter($letter)) {
                        continue;
                    }

                    foreach (Alphabet::confusables($letter) as $confusable) {
                        $other = $letters;
                        $other[$place] = $confusable;
                        $next[implode('', $other)] = $other;
                    }
                }
            }

            $spellings = $next;
        }

        return array_map(strval(...), array_keys($spellings));
    }

    /**
     * A list in Azerbaijani alphabetical order (`AzCollator`), or one
     * problem where it first is not.
     *
     * @param  list<string>  $list
     */
    private function collated(array $list, string $file, string $key): void
    {
        foreach (AzCollator::sort($list) as $at => $item) {
            if ($list[$at] !== $item) {
                $this->problem($file, "{$key} must be in Azerbaijani alphabetical order: {$key}[{$at}] \"{$list[$at]}\" belongs elsewhere (\"{$item}\" comes there)");

                return;
            }
        }
    }

    // ── rooms/shapes.json ───────────────────────────────────────────────

    /**
     * The shapes of memory rounds (§12 "Room content", B4): `version` and a
     * non-empty list of `shapes`, each a `key` (lowercase, unique) and an
     * `image`, an SVG under `assets/rooms/` that exists and no other shape
     * uses. A shape is told apart from the others by its form, not by its
     * colour alone: two files that are the same drawing but for their
     * colours (and the ids their names prefix) are refused. A shape holds
     * no digit, letter or text, so no `<text>` element; and every SVG under
     * `assets/rooms/` is a shape of the list. The files also pass the SVG
     * check every asset passes and need a credits entry like every asset.
     *
     * @return int|null how many shapes there are, or null when the file has a problem
     */
    private function roomShapes(): ?int
    {
        $file = ContentRepository::ROOM_SHAPES;
        $before = count($this->problems);
        $data = $this->object($this->read($file), $file, '', ['version', 'shapes']);

        if ($data === null) {
            return null;
        }

        if (! is_int($data['version']) || $data['version'] < 1) {
            $this->problem($file, 'version must be a whole number from 1');
        }

        $shapes = $this->list($data['shapes'], $file, 'shapes');

        if ($shapes === null) {
            return null;
        }

        if ($shapes === []) {
            $this->problem($file, '`shapes` must not be empty');
        }

        $keys = [];
        /** @var array<string, string> $used picture path → the entry that has it */
        $used = [];
        /** @var array<string, string> $forms a drawing without its colours → the entry that has it */
        $forms = [];

        foreach ($shapes as $index => $shape) {
            $where = "shapes[{$index}]";
            $shape = $this->object($shape, $file, $where, ['key', 'image']);

            if ($shape === null) {
                continue;
            }

            $key = $shape['key'];

            if (! is_string($key) || preg_match(self::KEY, $key) !== 1) {
                $this->problem($file, "{$where}.key must be lowercase letters and digits joined by single hyphens");
            } elseif (in_array($key, $keys, true)) {
                $this->problem($file, "{$where}.key \"{$key}\" is already a shape's");
            } else {
                $keys[] = $key;
            }

            $image = $shape['image'];

            if (is_string($image) && ! str_starts_with($image, self::ROOM_SHAPES_FOLDER)) {
                $this->problem($file, "{$where}.image \"{$image}\" must be under {$this->contentFolder()}/assets/".self::ROOM_SHAPES_FOLDER);

                continue;
            }

            $image = $this->asset($image, ['svg'], $file, "{$where}.image");

            if ($image === null || pathinfo($image, PATHINFO_EXTENSION) !== 'svg' || ! self::exists("{$this->root}/assets/{$image}")) {
                continue;
            }

            if (isset($used[$image])) {
                $this->problem($file, "{$where}.image \"{$image}\" is already the picture of {$used[$image]}");

                continue;
            }

            $used[$image] = $where;
            $svg = (string) file_get_contents("{$this->root}/assets/{$image}");

            if (preg_match('/<(?:text|tspan|textPath)\b/i', $svg) === 1) {
                $this->problem($file, "{$where}.image \"{$image}\" holds text; a shape has no digit, letter or text");
            }

            $form = self::form($svg, pathinfo($image, PATHINFO_FILENAME));

            if (isset($forms[$form])) {
                $this->problem($file, "{$where}.image \"{$image}\" is the drawing of {$forms[$form]} in other colours; shapes differ by form");
            } else {
                $forms[$form] = $where;
            }
        }

        foreach ($this->roomShapeFiles() as $path) {
            if (! isset($used[$path])) {
                $this->problem($file, "assets/{$path} is no shape of the list");
            }
        }

        return count($this->problems) === $before ? count($shapes) : null;
    }

    /**
     * A drawing without its colours and without its own name where its ids
     * are prefixed with it (`ulduz_fill1`): what is left is its form.
     */
    private static function form(string $svg, string $name): string
    {
        $svg = str_replace("{$name}_", '', $svg);
        $svg = (string) preg_replace('/<!--.*?-->/s', '', $svg);
        $svg = (string) preg_replace('/#[0-9A-Fa-f]{3,8}\b|rgba?\([^)]*\)/', '', $svg);

        return (string) preg_replace('/\s+/', ' ', trim($svg));
    }

    /**
     * Every SVG under `assets/rooms/`, relative to `assets/`.
     *
     * @return list<string>
     */
    private function roomShapeFiles(): array
    {
        $folder = "{$this->root}/assets/".rtrim(self::ROOM_SHAPES_FOLDER, '/');
        $files = [];

        foreach (glob("{$folder}/*.svg") ?: [] as $path) {
            $files[] = self::ROOM_SHAPES_FOLDER.basename($path);
        }

        sort($files, SORT_STRING);

        return $files;
    }

    // ── rooms/sounds.json ───────────────────────────────────────────────

    /**
     * The sounds of a room game (§12 "Room content", ADR 025 "Sound", ADR
     * 028): `version` and `sounds`, an object with one file for every
     * `RoomSound` and no other key. Each is an MP3 under
     * `assets/rooms/sounds/` that exists, no other sound uses and holds at
     * most `MAX_ROOM_SOUND_BYTES`; each speaker's files together hold at
     * most its `MAX_ROOM_SPEAKER_BYTES`; and every file in that folder is a
     * sound of the list. The files need a credits entry like every asset.
     */
    private function roomSounds(): void
    {
        $file = ContentRepository::ROOM_SOUNDS;
        $data = $this->object($this->read($file), $file, '', ['version', 'sounds']);

        if ($data === null) {
            return;
        }

        if (! is_int($data['version']) || $data['version'] < 1) {
            $this->problem($file, 'version must be a whole number from 1');
        }

        $sounds = $this->object($data['sounds'], $file, 'sounds', array_map(static fn (RoomSound $sound): string => $sound->value, RoomSound::cases()));

        if ($sounds === null) {
            return;
        }

        /** @var array<string, string> $used sound path → the entry that has it */
        $used = [];
        /** @var array<value-of<RoomSpeaker>, int> $speakers bytes of each speaker's files */
        $speakers = array_fill_keys(array_keys(self::MAX_ROOM_SPEAKER_BYTES), 0);

        foreach ($sounds as $name => $path) {
            $where = "sounds.{$name}";

            if (is_string($path) && ! str_starts_with($path, self::ROOM_SOUNDS_FOLDER)) {
                $this->problem($file, "{$where} \"{$path}\" must be under {$this->contentFolder()}/assets/".self::ROOM_SOUNDS_FOLDER);

                continue;
            }

            $path = $this->asset($path, ['mp3'], $file, $where);

            if ($path === null || pathinfo($path, PATHINFO_EXTENSION) !== 'mp3' || ! self::exists("{$this->root}/assets/{$path}")) {
                continue;
            }

            if (isset($used[$path])) {
                $this->problem($file, "{$where} \"{$path}\" is already the file of {$used[$path]}");

                continue;
            }

            $used[$path] = $where;
            $bytes = (int) filesize("{$this->root}/assets/{$path}");

            if ($bytes > self::MAX_ROOM_SOUND_BYTES) {
                $this->problem($file, "{$where} \"{$path}\" holds {$bytes} bytes; a room sound holds at most ".self::MAX_ROOM_SOUND_BYTES);
            }

            $speaker = RoomSound::tryFrom((string) $name)?->speaker();

            if ($speaker !== null) {
                $speakers[$speaker->value] += $bytes;
            }
        }

        foreach ($speakers as $speaker => $bytes) {
            if ($bytes > self::MAX_ROOM_SPEAKER_BYTES[$speaker]) {
                $this->problem($file, "the {$speaker}'s sounds hold {$bytes} bytes; a {$speaker}'s sounds hold at most ".self::MAX_ROOM_SPEAKER_BYTES[$speaker]);
            }
        }

        $folder = "{$this->root}/assets/".rtrim(self::ROOM_SOUNDS_FOLDER, '/');
        $files = [];

        foreach (glob("{$folder}/*") ?: [] as $path) {
            if (is_file($path) && ! str_starts_with(basename($path), '.')) {
                $files[] = self::ROOM_SOUNDS_FOLDER.basename($path);
            }
        }

        sort($files, SORT_STRING);

        foreach ($files as $path) {
            if (! isset($used[$path])) {
                $this->problem($file, "assets/{$path} is no sound of the list");
            }
        }
    }

    // ── rooms/rivalry.json ──────────────────────────────────────────────

    /**
     * The rivalry strip's emoji (§12 "Room content", ADR 027 decision 10):
     * `version` and `icons`, an object with exactly `clash`, `leader` and
     * `overtake`. Each is an SVG under `assets/fluent/` — a Fluent Emoji
     * file of the app's own set — that exists and no other icon uses. Like
     * every SVG under `assets/` it must be a plain drawing (`svgs()`), and
     * like every asset it needs a credits entry.
     */
    private function roomRivalry(): void
    {
        $this->rivalryIcons = [];
        $file = ContentRepository::ROOM_RIVALRY;
        $data = $this->object($this->read($file), $file, '', ['version', 'icons']);

        if ($data === null) {
            return;
        }

        if (! is_int($data['version']) || $data['version'] < 1) {
            $this->problem($file, 'version must be a whole number from 1');
        }

        $icons = $this->object($data['icons'], $file, 'icons', RoomRivalryData::ICONS);

        if ($icons === null) {
            return;
        }

        /** @var array<string, string> $used icon path → the entry that has it */
        $used = [];

        foreach ($icons as $name => $path) {
            $where = "icons.{$name}";

            if (is_string($path) && ! str_starts_with($path, self::ROOM_RIVALRY_FOLDER)) {
                $this->problem($file, "{$where} \"{$path}\" must be under {$this->contentFolder()}/assets/".self::ROOM_RIVALRY_FOLDER);

                continue;
            }

            $path = $this->asset($path, ['svg'], $file, $where);

            if ($path === null || pathinfo($path, PATHINFO_EXTENSION) !== 'svg' || ! self::exists("{$this->root}/assets/{$path}")) {
                continue;
            }

            if (isset($used[$path])) {
                $this->problem($file, "{$where} \"{$path}\" is already the icon of {$used[$path]}");

                continue;
            }

            $used[$path] = $where;
        }

        $this->rivalryIcons = $used;
    }

    // ── rooms/taunts.json ───────────────────────────────────────────────

    /**
     * The taunts a player may throw (§12 "Room content", "Taunts (T2)",
     * "Taunts in the middle, said aloud (T3)", ADR 029 decisions 28 and 50):
     * `version`, `voice` and `taunts`. `voice` is `{context, credit}`: the
     * Azerbaijani context the clips are made with and the credits entry they
     * get, its five fields as `voice.json`'s. `taunts` is a list of
     * `MIN_ROOM_TAUNTS`–`MAX_ROOM_TAUNTS` entries `{key, image, voice}`. Each
     * key is unique and matches `RoomTauntData::KEY_PATTERN`; each `voice`
     * is an audio tag matching `RoomTauntData::VOICE_PATTERN`; each image is
     * an SVG under `assets/fluent/` — a Fluent Emoji file of the app's own
     * set — that exists, no other taunt uses and is none of the rivalry
     * strip's three (`rooms/rivalry.json`). Like every SVG under `assets/` it
     * must be a plain drawing (`svgs()`), and like every asset it needs a
     * credits entry. The phrases are the translations'
     * (`rooms.taunts.say.{key}`), checked by `RoomTauntsTest`; the clips are
     * `voice-clips.json`'s `taunts` (`voice()`).
     */
    private function roomTaunts(): void
    {
        $this->tauntVoices = null;
        $file = ContentRepository::ROOM_TAUNTS;
        $before = count($this->problems);
        $data = $this->object($this->read($file), $file, '', ['version', 'voice', 'taunts']);

        if ($data === null) {
            return;
        }

        if (! is_int($data['version']) || $data['version'] < 1) {
            $this->problem($file, 'version must be a whole number from 1');
        }

        $voice = $this->object($data['voice'], $file, 'voice', ['context', 'credit']);
        $context = null;

        if ($voice !== null) {
            $context = $voice['context'];

            if (! is_string($context) || trim($context) === '' || ! $this->lettersAndSigns($context) || preg_match('/[«»]/u', $context) === 1) {
                $this->problem($file, 'voice.context must be Azerbaijani text of alphabet letters, spaces and . , ! ? -');
                $context = null;
            }

            $this->voiceCredit($voice['credit'], $file, 'voice.credit');
        }

        $taunts = $this->list($data['taunts'], $file, 'taunts');

        if ($taunts === null) {
            return;
        }

        if (count($taunts) < self::MIN_ROOM_TAUNTS || count($taunts) > self::MAX_ROOM_TAUNTS) {
            $this->problem($file, 'taunts must hold '.self::MIN_ROOM_TAUNTS.'–'.self::MAX_ROOM_TAUNTS.' taunts, not '.count($taunts));
        }

        $keys = [];
        /** @var array<string, string> $tags key → audio tag */
        $tags = [];

        /** @var array<string, string> $used image path → the entry that has it */
        $used = [];

        foreach ($taunts as $index => $taunt) {
            $where = "taunts[{$index}]";
            $taunt = $this->object($taunt, $file, $where, ['key', 'image', 'voice']);
            $key = $taunt === null ? null : $this->string($taunt['key'], $file, "{$where}.key");

            if ($taunt === null || $key === null) {
                continue;
            }

            if (preg_match(RoomTauntData::KEY_PATTERN, $key) !== 1 || in_array($key, $keys, true)) {
                $this->problem($file, "{$where} \"{$key}\" needs a unique key matching ".RoomTauntData::KEY_PATTERN);
            }

            $keys[] = $key;

            if (! is_string($taunt['voice']) || preg_match(RoomTauntData::VOICE_PATTERN, $taunt['voice']) !== 1) {
                $this->problem($file, "{$where} \"{$key}\".voice must be an audio tag matching ".RoomTauntData::VOICE_PATTERN.', like "mischievously"');
            } else {
                $tags[$key] = $taunt['voice'];
            }

            $where = "{$where} \"{$key}\".image";
            $path = $taunt['image'];

            if (is_string($path) && ! str_starts_with($path, self::ROOM_TAUNTS_FOLDER)) {
                $this->problem($file, "{$where} \"{$path}\" must be under {$this->contentFolder()}/assets/".self::ROOM_TAUNTS_FOLDER);

                continue;
            }

            $path = $this->asset($path, ['svg'], $file, $where);

            if ($path === null || pathinfo($path, PATHINFO_EXTENSION) !== 'svg' || ! self::exists("{$this->root}/assets/{$path}")) {
                continue;
            }

            if (isset($this->rivalryIcons[$path])) {
                $this->problem($file, "{$where} \"{$path}\" is the rivalry strip's {$this->rivalryIcons[$path]}");

                continue;
            }

            if (isset($used[$path])) {
                $this->problem($file, "{$where} \"{$path}\" is already the emoji of {$used[$path]}");

                continue;
            }

            $used[$path] = "taunts[{$index}] \"{$key}\"";
        }

        if ($context !== null && count($this->problems) === $before) {
            $this->tauntVoices = ['context' => $context, 'tags' => $tags];
        }
    }

    // ── rooms/categories.json ───────────────────────────────────────────

    /**
     * The groups of words of `odd_one_out` (§12 "Room content", B5):
     * `version` and at least four `groups`, each a `key` (lowercase,
     * unique) and at least eight `words`. A word is one lowercase word of
     * the alphabet's letters with a vowel (`WordRules`), normalized, in
     * exactly one group — compared by `Az::lower`, so the word that does
     * not belong is never also a word of the group — and never a first
     * name of `names.json`, since a round may show it to a room.
     */
    private function roomCategories(): void
    {
        $file = ContentRepository::ROOM_CATEGORIES;
        $data = $this->object($this->read($file), $file, '', ['version', 'groups']);

        if ($data === null) {
            return;
        }

        $this->normalized($data, $file);

        if (! is_int($data['version']) || $data['version'] < 1) {
            $this->problem($file, 'version must be a whole number from 1');
        }

        $groups = $this->list($data['groups'], $file, 'groups');

        if ($groups === null) {
            return;
        }

        if (count($groups) < self::MIN_ROOM_CATEGORIES) {
            $this->problem($file, '`groups` must hold at least '.self::MIN_ROOM_CATEGORIES.' groups');
        }

        $names = array_flip(array_map(Az::lower(...), $this->names ?? []));
        $keys = [];
        /** @var array<string, string> $seen a word by `Az::lower` → where it is */
        $seen = [];

        foreach ($groups as $index => $group) {
            $where = "groups[{$index}]";
            $group = $this->object($group, $file, $where, ['key', 'words']);

            if ($group === null) {
                continue;
            }

            $key = $group['key'];

            if (! is_string($key) || preg_match(self::KEY, $key) !== 1) {
                $this->problem($file, "{$where}.key must be lowercase letters and digits joined by single hyphens");
            } elseif (in_array($key, $keys, true)) {
                $this->problem($file, "{$where}.key \"{$key}\" is already a group's");
            } else {
                $keys[] = $key;
            }

            $words = $this->strings($group['words'], $file, "{$where}.words") ?? [];

            if ($words !== [] && count($words) < self::MIN_ROOM_CATEGORY_WORDS) {
                $this->problem($file, "{$where}.words must hold at least ".self::MIN_ROOM_CATEGORY_WORDS.' words');
            }

            foreach ($words as $at => $word) {
                $here = "{$where}.words[{$at}] \"{$word}\"";
                $lower = Az::lower($word);

                if (WordRules::problems($word) !== [] || $lower !== $word) {
                    $this->problem($file, "{$here} must be one lowercase word of the alphabet's letters with a vowel");
                }

                if (isset($seen[$lower])) {
                    $this->problem($file, "{$here} is already {$seen[$lower]}: a word is in one group only");
                } else {
                    $seen[$lower] = "{$where}.words[{$at}]";
                }

                if (isset($names[$lower])) {
                    $this->problem($file, "{$here} is a first name of ".ContentRepository::NAMES);
                }
            }
        }
    }

    // ── names.json ──────────────────────────────────────────────────────

    /**
     * The built-in name list (§3 "Voice", ADR 016 decision 1): `version`,
     * `sources`, a non-empty list of https URLs, and `names`, a non-empty
     * list of first names of 2–24 letters of the alphabet only, each as
     * `Az::title()` writes it, none twice by `Az::lower()`, in Azerbaijani
     * order (`AzCollator`), so a duplicate sits beside its twin in a diff.
     */
    private function names(): void
    {
        $file = ContentRepository::NAMES;
        $before = count($this->problems);
        $data = $this->object($this->read($file), $file, '', ['version', 'sources', 'names']);

        if ($data === null) {
            return;
        }

        $this->normalized($data, $file);

        if (! is_int($data['version']) || $data['version'] < 1) {
            $this->problem($file, 'version must be a whole number from 1');
        }

        foreach ($this->strings($data['sources'], $file, 'sources') ?? [] as $index => $source) {
            if (! str_starts_with($source, 'https://')) {
                $this->problem($file, "sources[{$index}] must be an https:// URL");
            }
        }

        $names = $this->strings($data['names'], $file, 'names');

        if ($names === null) {
            return;
        }

        $seen = [];

        foreach ($names as $index => $name) {
            $letters = mb_str_split($name);

            if (count($letters) < self::MIN_NAME_LETTERS || count($letters) > self::MAX_NAME_LETTERS || array_filter($letters, static fn (string $letter): bool => ! Alphabet::isLetter($letter)) !== []) {
                $this->problem($file, "names[{$index}] \"{$name}\" must be one word of ".self::MIN_NAME_LETTERS.'–'.self::MAX_NAME_LETTERS.' letters of the alphabet');
            } elseif (Az::title($name) !== $name) {
                $this->problem($file, "names[{$index}] \"{$name}\" must be written \"".Az::title($name).'", a capital first letter and the rest lowercase');
            }

            $key = Az::lower($name);

            if (isset($seen[$key])) {
                $this->problem($file, "names[{$index}] \"{$name}\" is already names[{$seen[$key]}]");
            }

            $seen[$key] ??= $index;
        }

        foreach (AzCollator::sort($names) as $at => $name) {
            if ($names[$at] !== $name) {
                $this->problem($file, "names must be in Azerbaijani alphabetical order: names[{$at}] \"{$names[$at]}\" belongs elsewhere (\"{$name}\" comes there)");

                break;
            }
        }

        if (count($this->problems) === $before) {
            $this->names = $names;
        }
    }

    // ── voice.json, voice-clips.json ────────────────────────────────────

    /**
     * The board's voice (§3 "Voice", ADR 015). `voice.json`: the voice and
     * its settings, an audio tag for every template of `prompts.json` and no
     * other, how every letter is said, a name for every number a level may use, respellings, the
     * context the provider gets around each phrase, the praise and retry
     * phrases and the credits entry the clips get.
     * `voice-clips.json`: every clip is a file of `assets/voice` named by the
     * hash of its text in this voice, and every file there is a listed clip;
     * every praise and retry phrase has one. A prompt without a clip is no
     * problem: its round is silent (`content:validate` counts them).
     */
    private function voice(): void
    {
        $file = 'voice.json';
        $before = count($this->problems);
        $data = $this->object($this->read($file), $file, '', ['version', 'voice', 'styles', 'spoken', 'context', 'praise', 'retry', 'greeting', 'finish', 'finish_class', 'names', 'credit']);

        if ($data === null) {
            return;
        }

        $this->normalized($data, $file);

        if (! is_int($data['version']) || $data['version'] < 1) {
            $this->problem($file, 'version must be a whole number from 1');
        }

        $voice = $this->object($data['voice'], $file, 'voice', ['provider', 'model', 'voice_id', 'voice_name', 'language', 'stability', 'similarity']);

        if ($voice !== null) {
            if ($voice['provider'] !== self::VOICE_PROVIDER) {
                $this->problem($file, 'voice.provider must be "'.self::VOICE_PROVIDER.'", the one provider `content:voice` calls');
            }

            $this->string($voice['model'], $file, 'voice.model');
            $this->string($voice['voice_name'], $file, 'voice.voice_name');

            if (! is_string($voice['voice_id']) || preg_match('/^[A-Za-z0-9]{8,64}$/', $voice['voice_id']) !== 1) {
                $this->problem($file, 'voice.voice_id must be the provider\'s voice id, 8–64 letters and digits');
            }

            if (! is_string($voice['language']) || preg_match('/^[a-z]{2}$/', $voice['language']) !== 1) {
                $this->problem($file, 'voice.language must be an ISO 639-1 code like "az"');
            }

            foreach (['stability', 'similarity'] as $setting) {
                if (! (is_int($voice[$setting]) || is_float($voice[$setting])) || $voice[$setting] < 0 || $voice[$setting] > 1) {
                    $this->problem($file, "voice.{$setting} must be a number from 0 to 1");
                }
            }
        }

        $styles = $this->object($data['styles'], $file, 'styles', $this->templates ?? [], $this->templates === null ? array_keys(is_array($data['styles']) ? $data['styles'] : []) : []);

        foreach ($styles ?? [] as $group => $style) {
            $this->voiceStyle($style, $file, "styles.{$group}");
        }

        $spoken = $this->object($data['spoken'], $file, 'spoken', ['letters', 'numbers', 'respell']);

        if ($spoken !== null) {
            $letters = $this->object($spoken['letters'], $file, 'spoken.letters', Alphabet::letters());

            foreach ($letters ?? [] as $letter => $said) {
                if (! is_string($said) || preg_match('/^\p{Ll}+$/u', $said) !== 1 || ! $this->lettersAndSigns($said)) {
                    $this->problem($file, "spoken.letters.{$letter} must say the letter in lowercase letters, like \"rı\"");
                }
            }

            // JSON keys "1"…"10" come back as whole numbers.
            $names = is_array($spoken['numbers']) ? $spoken['numbers'] : [];
            $numbers = range(self::MIN_NUMBER, self::MAX_NUMBER);

            if (! is_array($spoken['numbers']) || ($names !== [] && array_is_list($names) && ! isset($names[self::MIN_NUMBER]))) {
                $this->problem($file, 'spoken.numbers must be an object');
            }

            foreach (array_diff($numbers, array_keys($names)) as $number) {
                $this->problem($file, "spoken.numbers needs `{$number}`, a number a level may use");
            }

            foreach ($names as $number => $name) {
                if (! in_array($number, $numbers, true)) {
                    $this->problem($file, "spoken.numbers has an unknown key `{$number}`");
                } elseif (! is_string($name) || preg_match('/^[\p{Ll}]+(?: [\p{Ll}]+)*$/u', $name) !== 1 || ! $this->lettersAndSigns($name)) {
                    $this->problem($file, "spoken.numbers.{$number} must be the number's name in lowercase letters");
                }
            }

            $respell = $this->object($spoken['respell'], $file, 'spoken.respell', [], array_map(strval(...), array_keys(is_array($spoken['respell']) ? $spoken['respell'] : [])));

            foreach ($respell ?? [] as $phrase => $text) {
                $this->string($text, $file, "spoken.respell \"{$phrase}\"");
            }
        }

        $context = $this->object($data['context'], $file, 'context', ['previous', 'next']);

        foreach ($context ?? [] as $side => $text) {
            if (! is_string($text) || ($text !== '' && (! $this->lettersAndSigns($text) || preg_match('/[«»]/u', $text) === 1))) {
                $this->problem($file, "context.{$side} must be Azerbaijani text of alphabet letters, spaces and . , ! ? -, or empty");
            }
        }

        $phrases = [];
        $praise = $this->list($data['praise'], $file, 'praise');

        if ($praise === []) {
            $this->problem($file, 'praise needs at least one phrase');
        }

        foreach ($praise ?? [] as $index => $phrase) {
            $phrases[] = $this->voicePhrase($phrase, $file, "praise[{$index}]");
        }

        $retry = $this->list($data['retry'], $file, 'retry');

        if ($retry === []) {
            $this->problem($file, 'retry needs at least one phrase');
        }

        foreach ($retry ?? [] as $index => $phrase) {
            $phrases[] = $this->voicePhrase($phrase, $file, "retry[{$index}]");
        }

        // The greeting and the end phrases of V2 (ADR 016 decision 7).
        foreach (['greeting', 'finish', 'finish_class'] as $key) {
            $phrases[] = $this->voicePhrase($data[$key], $file, $key);
        }

        $texts = array_filter($phrases, is_string(...));

        if (count($texts) !== count(array_unique($texts))) {
            $this->problem($file, 'a phrase is listed twice among praise, retry, greeting, finish and finish_class');
        }

        $names = $this->object($data['names'], $file, 'names', ['style', 'credit']);

        if ($names !== null) {
            $this->voiceStyle($names['style'], $file, 'names.style');
            $this->voiceCredit($names['credit'], $file, 'names.credit');
        }

        $this->voiceCredit($data['credit'], $file, 'credit');

        $clips = $this->voiceClips();

        if ($clips === null || count($this->problems) !== $before) {
            return;
        }

        /** @var array{version: int, voice: array{provider: string, model: string, voice_id: string, voice_name: string, language: string, stability: float|int, similarity: float|int}, styles: array<string, string>, spoken: array{letters: array<string, string>, numbers: array<string, string>, respell: array<string, string>}, context: array{previous: string, next: string}, praise: list<array{text: string, style: string}>, retry: list<array{text: string, style: string}>, greeting: array{text: string, style: string}, finish: array{text: string, style: string}, finish_class: array{text: string, style: string}, names: array{style: string, credit: array{title: string, author: string, source: string, license: string, changes: string}}, credit: array{title: string, author: string, source: string, license: string, changes: string}} $data */
        $voice = new VoiceClips(VoiceData::fromArray([...$data, 'clips' => $clips['clips'], 'name_clips' => $clips['names'], 'taunt_clips' => $clips['taunts']]));
        $file = ContentRepository::VOICE_CLIPS;

        foreach ($this->voiceClipsRequired ? $clips['clips'] : [] as $hash => $text) {
            if ($voice->hash($text) !== (string) $hash) {
                $this->problem($file, "clips.{$hash} is not the clip of \"{$text}\" in the voice of voice.json; make it again (php artisan content:voice) and remove the old one (--prune)");
            }
        }

        $phrases = [
            ...$voice->praise(),
            ...$voice->retry(),
            ...($this->namePhraseClipsRequired ? [$voice->greeting(), $voice->finish(), $voice->finishClass()] : []),
        ];

        foreach ($this->voiceClipsRequired ? $phrases : [] as $line) {
            if ($voice->url($line) === null) {
                $this->problem($file, "the {$line->group} phrase \"{$line->phrase}\" has no clip (php artisan content:voice)");
            }
        }

        $this->tauntClips($voice, $clips['taunts']);

        // A name clip is the clip of a listed name in this voice; one whose
        // name left the list stays until `content:voice --prune`.
        if (! $this->voiceClipsRequired || $this->names === null) {
            return;
        }

        $listed = [];

        foreach ($this->names as $name) {
            $line = NameVoices::lineIn($voice, $name);
            $listed[$line->hash] = $line->text;
        }

        foreach ($clips['names'] as $hash => $text) {
            if (($listed[(string) $hash] ?? null) !== $text) {
                $this->problem($file, "names.{$hash} is not the clip of a name of ".ContentRepository::NAMES." in the voice of voice.json (\"{$text}\"); make it again or remove it (php artisan content:voice --prune)");
            }
        }
    }

    /**
     * A credits entry the clips get (`credit`, `names.credit`): an https
     * `source`, a license of the allow list and `{month}` in `changes`.
     */
    private function voiceCredit(mixed $value, string $file, string $where): void
    {
        $credit = $this->object($value, $file, $where, ['title', 'author', 'source', 'license', 'changes']);

        if ($credit === null) {
            return;
        }

        $this->string($credit['title'], $file, "{$where}.title");
        $this->string($credit['author'], $file, "{$where}.author");

        if (! is_string($credit['source']) || ! str_starts_with($credit['source'], 'https://')) {
            $this->problem($file, "{$where}.source must be an https:// URL");
        }

        if (! is_string($credit['license']) || License::tryFrom($credit['license']) === null) {
            $this->problem($file, "{$where}.license must be one of ".implode(', ', array_map(static fn (License $license): string => $license->value, License::cases())));
        }

        if (! is_string($credit['changes']) || ! str_contains($credit['changes'], '{month}')) {
            $this->problem($file, "{$where}.changes must say when the clips were made with {month}");
        }
    }

    /**
     * The clips of the room's taunts (`voice-clips.json`'s `taunts`, ADR 029
     * decision 50): each an MP3 of at most `MAX_TAUNT_VOICE_BYTES`, and each
     * the clip of a taunt of `rooms/taunts.json` — its Azerbaijani phrase
     * after its tag, with the taunts' context, in this voice. One that no
     * taunt needs any more stays until `content:voice --prune`. That each
     * listed clip has its file and each file there is listed is
     * `clipList()`'s; that the folder is credited, `coverage()`'s.
     *
     * @param  array<string, string>  $clips
     */
    private function tauntClips(VoiceClips $voice, array $clips): void
    {
        $file = ContentRepository::VOICE_CLIPS;

        foreach ($clips as $hash => $text) {
            $path = "{$this->root}/assets/".TauntVoices::path((string) $hash);

            if (! is_file($path)) {
                continue;
            }

            $head = (string) file_get_contents($path, length: 3);
            $bytes = (int) filesize($path);

            if (! str_starts_with($head, 'ID3') && (strlen($head) < 2 || ord($head[0]) !== 0xFF || (ord($head[1]) & 0xE0) !== 0xE0)) {
                $this->problem($file, "taunts.{$hash}: {$this->contentFolder()}/assets/".TauntVoices::path((string) $hash).' is not an MP3');
            } elseif ($bytes > self::MAX_TAUNT_VOICE_BYTES) {
                $this->problem($file, "taunts.{$hash}: {$this->contentFolder()}/assets/".TauntVoices::path((string) $hash)." holds {$bytes} bytes; a taunt's clip holds at most ".self::MAX_TAUNT_VOICE_BYTES);
            }
        }

        if (! $this->voiceClipsRequired || $this->tauntVoices === null) {
            return;
        }

        $listed = [];

        foreach ($this->tauntVoices['tags'] as $key => $tag) {
            $line = TauntVoices::lineIn($voice, $this->tauntVoices['context'], $key, $tag);

            if ($line !== null) {
                $listed[$line->hash] = $line->text;
            }
        }

        foreach ($clips as $hash => $text) {
            if (($listed[(string) $hash] ?? null) !== $text) {
                $this->problem($file, "taunts.{$hash} is not the clip of a taunt of ".ContentRepository::ROOM_TAUNTS." in the voice of voice.json (\"{$text}\"); make it again or remove it (php artisan content:voice --prune)");
            }
        }
    }

    /**
     * `voice-clips.json`: `clips`, hash → text, each hash a file of
     * `assets/voice`, each file there a listed hash; `names` the same for
     * the name clips of `voice-names` (ADR 016); `taunts` the same for the
     * taunts' clips of `assets/rooms/taunts` (ADR 029 decision 50), an empty
     * list when the file has none yet.
     *
     * @return array{clips: array<string, string>, names: array<string, string>, taunts: array<string, string>}|null the clips, or null when the file has a problem
     */
    private function voiceClips(): ?array
    {
        $file = ContentRepository::VOICE_CLIPS;
        $before = count($this->problems);
        $data = $this->object($this->read($file), $file, '', ['clips', 'names'], ['taunts']);

        if ($data === null) {
            return null;
        }

        $clips = $this->clipList($data['clips'], 'clips', 'assets/'.VoiceClips::FOLDER);
        $names = $this->clipList($data['names'], 'names', NameVoices::FOLDER);
        $taunts = $this->clipList($data['taunts'] ?? [], 'taunts', 'assets/'.TauntVoices::FOLDER);

        return $clips === null || $names === null || $taunts === null || count($this->problems) !== $before ? null : ['clips' => $clips, 'names' => $names, 'taunts' => $taunts];
    }

    /**
     * One list of `voice-clips.json`: hash → text, each hash a file of
     * `$folder` (under the content root), each file there a listed hash.
     *
     * @return array<string, string>|null
     */
    private function clipList(mixed $clips, string $key, string $folder): ?array
    {
        $file = ContentRepository::VOICE_CLIPS;

        // `{}` is an empty list once decoded; a hash of digits only comes back as a whole number.
        if (! is_array($clips) || ($clips !== [] && array_is_list($clips))) {
            $this->problem($file, "{$key} must be an object");

            return null;
        }

        $listed = [];

        foreach ($clips as $hash => $text) {
            $hash = (string) $hash;
            $listed[] = $hash;
            $path = "{$folder}/{$hash}.".VoiceClips::EXTENSION;

            if (preg_match('/^[0-9a-f]{'.VoiceClips::HASH_LENGTH.'}$/', $hash) !== 1) {
                $this->problem($file, "{$key}.{$hash} must be a hash of ".VoiceClips::HASH_LENGTH.' lowercase hex digits');
            } elseif ($this->voiceClipsRequired && ! self::exists("{$this->root}/{$path}")) {
                $this->problem($file, "{$key}.{$hash}: {$this->contentFolder()}/{$path} does not exist");
            }

            if (! is_string($text) || $text === '') {
                $this->problem($file, "{$key}.{$hash} must be the text the clip was made from");
            }
        }

        // `content:voice` lists what is made and makes what was deleted (a
        // run stopped half way, a bad clip deleted to be made again).
        foreach (is_dir("{$this->root}/{$folder}") && $this->voiceClipsRequired ? (scandir("{$this->root}/{$folder}") ?: []) : [] as $name) {
            if (str_starts_with($name, '.')) {
                continue;
            }

            if (! in_array(pathinfo($name, PATHINFO_FILENAME), $listed, true) || pathinfo($name, PATHINFO_EXTENSION) !== VoiceClips::EXTENSION) {
                $this->problem($file, "{$this->contentFolder()}/{$folder}/{$name} is no clip of {$key}");
            }
        }

        /** @var array<string, string> $clips */
        return $clips;
    }

    /**
     * A praise or retry phrase: `{text, style}`, the text a short sentence.
     *
     * @return string|null the text
     */
    private function voicePhrase(mixed $phrase, string $file, string $where): ?string
    {
        $phrase = $this->object($phrase, $file, $where, ['text', 'style']);

        if ($phrase === null) {
            return null;
        }

        $this->voiceStyle($phrase['style'], $file, "{$where}.style");
        $text = $this->string($phrase['text'], $file, "{$where}.text");

        if ($text !== null && (! $this->lettersAndSigns($text) || preg_match('/[«»]/u', $text) === 1 || ! in_array(mb_substr($text, -1), self::FINAL_MARKS, true))) {
            $this->problem($file, "{$where}.text \"{$text}\" may hold only alphabet letters, spaces and . , ! ? - and must end with . ! or ?");
        }

        return $text;
    }

    /**
     * An audio tag without its brackets: `playful`, `soft chuckle`.
     */
    private function voiceStyle(mixed $style, string $file, string $where): void
    {
        if (! is_string($style) || preg_match('/^[a-z]+(?: [a-z]+)*$/', $style) !== 1) {
            $this->problem($file, "{$where} must be an audio tag in lowercase English words, like \"playful\"");
        }
    }

    // ── credits.json ────────────────────────────────────────────────────

    /**
     * Every entry names files under a credited folder or a credited list;
     * each pattern matches a file, but a missing list's, which its own
     * check reports as missing.
     *
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
        $lists = $this->creditedLists();
        $shareAlikeFolder = $this->contentFolder().'/'.self::SHARE_ALIKE_FOLDER;
        // `content:voice` may run with every clip pruned: it makes them again.
        $voiceFolders = [$this->contentFolder().'/assets/'.VoiceClips::FOLDER.'/*', $this->contentFolder().'/'.NameVoices::FOLDER.'/*'];
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

                if (($inside === [] && ! in_array($pattern, $lists, true)) || str_contains($pattern, '..')) {
                    $this->problem($file, "{$where}.files \"{$pattern}\" must be under ".implode(' or ', $folders).' or one of '.implode(', ', $lists));
                } elseif (array_filter($files, static fn (string $path): bool => CreditData::matches($pattern, $path)) === [] && ($this->voiceClipsRequired || ! in_array($pattern, $voiceFolders, true)) && ! in_array($pattern, $lists, true)) {
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
     * Every asset, name clip, font file and credited list needs a credits
     * entry (§8).
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
        return [$this->contentFolder().'/assets', $this->contentFolder().'/'.NameVoices::FOLDER, ...self::CREDITED_FOLDERS];
    }

    /**
     * `CREDITED_LISTS` from the repository root.
     *
     * @return list<string>
     */
    private function creditedLists(): array
    {
        return array_map(fn (string $list): string => "{$this->contentFolder()}/{$list}", self::CREDITED_LISTS);
    }

    /**
     * Every file that needs a credits entry, as a path from the repository
     * root: those under the credited folders, but hidden files (`.gitkeep`),
     * and the credited lists that exist.
     *
     * @return list<string>
     */
    private function creditedFiles(): array
    {
        $project = dirname($this->root);
        $files = array_values(array_filter($this->creditedLists(), static fn (string $list): bool => is_file("{$project}/{$list}")));

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
        $pack = $this->object($this->read($file), $file, '', ['key', 'version', 'title', 'with', 'units', 'style', 'words'], ['sentences']);

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
        $this->with($pack['with'], $pack['title'], $file);
        $units = $this->units($pack['units'], $file);
        $reads = $units !== null && array_filter($units, static fn (Unit $unit): bool => $unit->reads()) !== [];
        $this->style($pack['style'], $file, $levels);
        $words = $this->words($pack['words'], $file);
        $this->cover($pack['style'], $pack['words'], $file);

        if ($reads && ! array_key_exists('sentences', $pack)) {
            $this->problem($file, 'needs `sentences`: it lists the reading units');
        } elseif ($units !== null && ! $reads && array_key_exists('sentences', $pack)) {
            $this->problem($file, '`sentences` belong to a pack that lists the reading units only');
        }

        $sentences = array_key_exists('sentences', $pack) ? $this->sentences($pack['sentences'], $file) : null;

        if ($reads && $words !== null && $words < self::MIN_WORDS) {
            $this->problem($file, "has {$words} words; a pack of the reading units needs at least ".self::MIN_WORDS);
        }

        if ($reads && $sentences !== null && $sentences < self::MIN_SENTENCES) {
            $this->problem($file, "has {$sentences} sentences; a pack of the reading units needs at least ".self::MIN_SENTENCES);
        }

        if ($levels === null || count($this->problems) !== $before) {
            return;
        }

        /** @var array{key: string, version: int, title: array{az: string, en: string}, with: array{az: string, en: string}, units: list<string>, style: array{item: string, slot: string, effect: string, cover: string, backdrop?: string, palette: list<string>, sounds?: array<'hit'|'miss'|'solved', string>, figures?: list<string>}, words: list<array{text: string, image?: string, syllables?: list<string>}>, sentences?: list<array{id: string, text: string}>} $pack */
        $this->fitsLevels(PackData::fromArray($pack), $levels, $file);
    }

    /**
     * `units` (R5, ADR 012): a non-empty list of `Unit` values, each once,
     * in enum order, with the four reading units together or none of them.
     *
     * @return list<Unit>|null
     */
    private function units(mixed $value, string $file): ?array
    {
        $before = count($this->problems);
        $units = $this->enums(Unit::class, $value, $file, 'units');

        if ($units === null || count($this->problems) !== $before) {
            return null;
        }

        $order = array_values(array_filter(Unit::cases(), static fn (Unit $unit): bool => in_array($unit, $units, true)));

        if ($order !== $units) {
            $this->problem($file, 'units must follow the order '.implode(', ', array_map(static fn (Unit $unit): string => $unit->value, Unit::cases())));
        }

        $reading = array_filter($units, static fn (Unit $unit): bool => $unit->reads());

        if ($reading !== [] && count($reading) !== count(Unit::reading())) {
            $this->problem($file, 'units lists '.implode(', ', array_map(static fn (Unit $unit): string => $unit->value, $reading)).'; the reading units '.implode(', ', array_map(static fn (Unit $unit): string => $unit->value, Unit::reading())).' come together or not at all');
        }

        return count($this->problems) === $before ? $units : null;
    }

    /**
     * @param  list<LevelData>|null  $levels
     */
    private function style(mixed $style, string $file, ?array $levels): void
    {
        $style = $this->object($style, $file, 'style', ['item', 'slot', 'effect', 'cover', 'palette'], ['backdrop', 'sounds', 'figures']);

        if ($style === null) {
            return;
        }

        $item = $this->enum(ItemStyle::class, $style['item'], $file, 'style.item');
        $this->enum(SlotStyle::class, $style['slot'], $file, 'style.slot');
        $effect = $this->enum(Effect::class, $style['effect'], $file, 'style.effect');

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
                $path = $this->asset($path, self::AUDIO_EXTENSIONS, $file, "style.sounds.{$name}");
                $sound = Sound::tryFrom($name);

                if ($sound !== null) {
                    $this->packSound($sound, $path, $effect, $file);
                }
            }
        }

        if ($item instanceof ItemStyle) {
            $this->figures($style, $item, $file, $levels);
        }
    }

    /**
     * `style.cover` (§3 "Cover"): the picture of the pack on the setup page,
     * the `image` of one of its words, so it is already credited and never a
     * backdrop or an avatar. A missing key is `style()`'s problem.
     */
    private function cover(mixed $style, mixed $words, string $file): void
    {
        if (! is_array($style) || ! array_key_exists('cover', $style)) {
            return;
        }

        $cover = $this->string($style['cover'], $file, 'style.cover');
        $images = is_array($words)
            ? array_filter(array_map(static fn (mixed $word): mixed => is_array($word) ? ($word['image'] ?? null) : null, $words), is_string(...))
            : [];

        if ($cover !== null && ! in_array($cover, $images, true)) {
            $this->problem($file, "style.cover \"{$cover}\" must be the `image` of one of the pack's words");
        }
    }

    /**
     * `style.figures` (§2 "Figures", §3): required for an item style that
     * draws figures and refused for any other; image paths under `assets/`,
     * each once, never an avatar, and at least FIGURES_PER_ITEM × the most
     * items a round of any level shows. Their credits are checked with every
     * other asset file.
     *
     * @param  array<string, mixed>  $style
     * @param  list<LevelData>|null  $levels
     */
    private function figures(array $style, ItemStyle $item, string $file, ?array $levels): void
    {
        if (! $item->hasFigures()) {
            if (array_key_exists('figures', $style)) {
                $this->problem($file, "style.figures is only for item styles that draw figures; \"{$item->value}\" draws none");
            }

            return;
        }

        if (! array_key_exists('figures', $style)) {
            $this->problem($file, "style.figures is needed: \"{$item->value}\" items stand in front of figures");

            return;
        }

        $figures = $this->list($style['figures'], $file, 'style.figures');

        if ($figures === null) {
            return;
        }

        $used = [];

        foreach ($figures as $index => $figure) {
            $where = "style.figures[{$index}]";
            $path = $this->asset($figure, self::IMAGE_EXTENSIONS, $file, $where);

            if ($path === null) {
                continue;
            }

            if (str_starts_with($path, self::AVATAR_FOLDER)) {
                $this->problem($file, "{$where} \"{$path}\" is an avatar; avatars mark students and are never figures");
            }

            if (isset($used[$path])) {
                $this->problem($file, "{$where} \"{$path}\" repeats {$used[$path]}");

                continue;
            }

            $used[$path] = $where;
        }

        $most = max([0, ...array_map(static fn (LevelData $level): int => $level->mostItems(), $levels ?? [])]);
        $needed = self::FIGURES_PER_ITEM * $most;

        if (count($figures) < $needed) {
            $this->problem($file, 'style.figures has '.self::counted(count($figures), 'figure')."; it needs at least {$needed}, ".self::FIGURES_PER_ITEM." for each of the {$most} items a round can show");
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
     * The levels the pack lists the unit of: with all 32 letters learned,
     * every syllable, word and sentence level gets at least MIN_LEVEL_ITEMS
     * distinct items from the pack, and every word fits a word level and
     * every sentence a sentence level. A pack that lists `picture` has at
     * least MIN_PICTURES words with a picture, and every picture level gets
     * its `items` + 2 from them; one that lists `number` has at least
     * MIN_COUNTED, the things counted (R5).
     *
     * @param  list<LevelData>  $levels
     */
    private function fitsLevels(PackData $pack, array $levels, string $file): void
    {
        $pictured = count($pack->pictured());

        if ($pack->lists(Unit::Picture) && $pictured < self::MIN_PICTURES) {
            $this->problem($file, 'has '.self::counted($pictured, 'word').' with a picture; a pack that lists picture needs at least '.self::MIN_PICTURES);
        }

        if ($pack->lists(Unit::Number) && $pictured < self::MIN_COUNTED) {
            $this->problem($file, 'has '.self::counted($pictured, 'word').' with a picture to count; a pack that lists number needs at least '.self::MIN_COUNTED);
        }

        foreach ($levels as $level) {
            if (! $pack->lists($level->unit)) {
                continue;
            }

            $count = count($level->keysFrom($pack));
            $needed = match ($level->unit) {
                Unit::Letter, Unit::Number => 0,
                Unit::Picture => ($level->items ?? 0) + 2,
                default => self::MIN_LEVEL_ITEMS,
            };

            if ($count < $needed) {
                $this->problem($file, "level \"{$level->key}\" gets ".self::counted($count, "distinct {$level->unit->value}").' from this pack; it needs at least '.$needed);
            }
        }

        if (! $pack->lists(Unit::Word)) {
            return;
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
     * A pack's `with` (§3 "With"): the title as a game's card names it, in
     * both interface languages, neither empty, the Azerbaijani one a title
     * (letters and the title's signs) that starts with the pack's own
     * Azerbaijani title — the title in the instrumental case, written out.
     */
    private function with(mixed $with, mixed $title, string $file): void
    {
        $with = $this->object($with, $file, 'with', ['az', 'en']);

        if ($with === null) {
            return;
        }

        $az = $this->string($with['az'], $file, 'with.az');
        $this->string($with['en'], $file, 'with.en');
        $own = is_array($title) && is_string($title['az'] ?? null) ? $title['az'] : null;

        if ($az === null) {
            return;
        }

        if (! $this->lettersAndSigns($az, self::TITLE_SIGNS)) {
            $this->problem($file, "with.az \"{$az}\" may hold only alphabet letters, digits, spaces and . , : ! ? « » -");
        } elseif ($own !== null && ! str_starts_with($az, $own)) {
            $this->problem($file, "with.az \"{$az}\" must start with the title \"{$own}\"");
        }
    }

    /**
     * A title in both interface languages (§9); the Azerbaijani one uses
     * only alphabet letters, the signs of a sentence, digits and a colon.
     */
    private function title(mixed $title, string $file, string $where): void
    {
        $title = $this->object($title, $file, $where, ['az', 'en']);

        if ($title === null) {
            return;
        }

        $az = $this->string($title['az'], $file, "{$where}.az");
        $this->string($title['en'], $file, "{$where}.en");

        if ($az !== null && ! $this->lettersAndSigns($az, self::TITLE_SIGNS)) {
            $this->problem($file, "{$where}.az \"{$az}\" may hold only alphabet letters, digits, spaces and . , : ! ? « » -");
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
     * A number level's range (R5): whole numbers within 1–10, `min` < `max`,
     * with at least one number more than a round shows, so the target can
     * change from round to round.
     */
    private function numbers(mixed $range, mixed $items, string $file, string $where): void
    {
        $range = $this->object($range, $file, $where, ['min', 'max']);

        if ($range === null) {
            return;
        }

        $min = $range['min'];
        $max = $range['max'];

        if (! is_int($min) || ! is_int($max) || $min < self::MIN_NUMBER || $max > self::MAX_NUMBER || $min >= $max) {
            $this->problem($file, "{$where} needs whole numbers from ".self::MIN_NUMBER.' to '.self::MAX_NUMBER.', min < max');

            return;
        }

        if (is_int($items) && $max - $min + 1 < $items + 1) {
            $this->problem($file, "{$where} holds ".($max - $min + 1).' numbers; a level of '.self::counted($items, 'item').' needs at least '.($items + 1));
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

    /**
     * @param  list<string>  $more  characters allowed besides letters and SIGNS
     */
    private function lettersAndSigns(string $text, array $more = []): bool
    {
        foreach (mb_str_split($text) as $character) {
            if (! Alphabet::isLetter($character) && ! in_array($character, [...self::SIGNS, ...$more], true)) {
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
