<?php

namespace App\Domain\Play\Data;

use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Support\Playable;
use App\Domain\Play\Support\StartableGame;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A game as the teacher or the parent sets it up (docs/architecture.md §2):
 * mechanic × pack × level, the number of rounds, the letter case, for a
 * letter level, the letters to ask for and, for a syllable or word level,
 * whether to play only the owner's own words (`own_words`, §3 "Teacher
 * words"). Every entry point (the setup page of a class or a child, A7's
 * phone and guest board) validates through `rules()` for the game's
 * `GamePlayers`, which also refuses a game the board cannot draw
 * (`Playable`) or that the class's or child's learned letters, or the words
 * the owner hid, leave too little content for, with a translated reason.
 */
final readonly class GameSetupData
{
    /**
     * @param  list<string>|null  $letters  lowercase, alphabet order; null = every learned letter
     * @param  bool  $own_words  only the teacher's own words in the pack (`TeacherPacks::pack(…, own: true)`)
     */
    public function __construct(
        public Mechanic $mechanic,
        public string $pack,
        public string $level,
        public int $rounds,
        public LetterCase $letter_case,
        public ?array $letters = null,
        public bool $own_words = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(GamePlayers $players): array
    {
        $content = app(ContentRepository::class);
        $learned = $players->learned();

        return [
            'mechanic' => ['required', 'string', Rule::enum(Mechanic::class)],
            'pack' => ['required', 'string', Rule::in(array_keys($content->packs()))],
            'level' => ['required', 'string', Rule::in(array_keys($content->levels())), new StartableGame($players)],
            'rounds' => ['sometimes', 'integer', 'between:'.Config::integer('elifba.rounds.min').','.Config::integer('elifba.rounds.max')],
            'letter_case' => ['sometimes', 'nullable', 'string', Rule::enum(LetterCase::class)],
            'letters' => ['sometimes', 'nullable', 'array', 'list', 'max:'.Alphabet::count()],
            'letters.*' => ['required', 'string', 'distinct', Rule::in($learned)],
            'own_words' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'letters.*.in' => Playable::message('letter_not_learned'),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input, GamePlayers $players): self
    {
        return self::fromArray(Validator::make($input, self::rules($players), self::messages())->validate());
    }

    /**
     * Fills the defaults: `elifba.rounds.default` rounds; capitals for a
     * letter level, the content as written otherwise — always at a picture
     * or number level (R5), which reads nothing.
     *
     * @param  array<array-key, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $level = app(ContentRepository::class)->level(self::text($validated['level'] ?? null));
        $rounds = $validated['rounds'] ?? null;
        $case = is_string($validated['letter_case'] ?? null) ? LetterCase::from($validated['letter_case']) : null;
        $letters = is_array($validated['letters'] ?? null) ? array_values(array_filter($validated['letters'], is_string(...))) : [];

        return new self(
            mechanic: Mechanic::from(self::text($validated['mechanic'] ?? null)),
            pack: self::text($validated['pack'] ?? null),
            level: $level->key,
            rounds: is_numeric($rounds) ? (int) $rounds : Config::integer('elifba.rounds.default'),
            // Nothing is read at a picture or number level: it is not offered there and stored as the content is written.
            letter_case: ! $level->unit->reads() ? LetterCase::Lower : ($case ?? ($level->unit === Unit::Letter ? LetterCase::Upper : LetterCase::Lower)),
            letters: $letters === [] ? null : Alphabet::sort($letters),
            own_words: filter_var($validated['own_words'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );
    }

    /**
     * The class's or the child's learned letters, lowercase in alphabet
     * order (all 32 when they have not been narrowed).
     *
     * @return list<string>
     */
    public static function learned(GamePlayers $players): array
    {
        return $players->learned();
    }

    /**
     * @return array{mechanic: string, pack: string, level: string, rounds: int, letter_case: string, letters: list<string>|null, own_words: bool}
     */
    public function toArray(): array
    {
        return [
            'mechanic' => $this->mechanic->value,
            'pack' => $this->pack,
            'level' => $this->level,
            'rounds' => $this->rounds,
            'letter_case' => $this->letter_case->value,
            'letters' => $this->letters,
            'own_words' => $this->own_words,
        ];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
