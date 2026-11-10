<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\WordData;
use App\Domain\Content\Models\CustomWord;
use App\Domain\Content\Models\HiddenWord;
use App\Models\User;
use InvalidArgumentException;

/**
 * The theme packs as one teacher plays them (docs/architecture.md §3
 * "Teacher words", ADR 007, ADR 009): the built-in words in file order
 * without the ones the teacher hid, then all of the teacher's own words (the
 * same in every pack) in the order they were added — or, for a game of the
 * teacher's own words only, just those.
 * Every Play reader that builds or checks rounds reads packs here,
 * so the setup page, `GameSetupData::rules()` and `StartGame` see the same
 * words. `ContentRepository` stays the reader of the files.
 *
 * Nothing is memoized: a caller that needs several packs takes `packs()`,
 * two queries in all.
 */
final readonly class TeacherPacks
{
    public function __construct(private ContentRepository $content) {}

    /**
     * @param  bool  $own  only the teacher's own words (`GameSetupData::$own_words`)
     *
     * @throws InvalidArgumentException when there is no such pack
     */
    public function pack(User $teacher, string $key, bool $own = false): PackData
    {
        $pack = $this->content->pack($key);

        return self::merge($pack, $this->customWords($teacher), $this->hiddenKeys($teacher, $key)[$key] ?? [], $own);
    }

    /**
     * Every pack by key, in key order, as the teacher plays it (with only
     * the teacher's own words when `$own`), in two queries.
     *
     * @return array<string, PackData>
     */
    public function packs(User $teacher, bool $own = false): array
    {
        $custom = $this->customWords($teacher);
        $hidden = $this->hiddenKeys($teacher);

        return array_map(
            static fn (PackData $pack): PackData => self::merge($pack, $custom, $hidden[$pack->key] ?? [], $own),
            $this->content->packs(),
        );
    }

    /**
     * The built-in words the teacher hid, as content keys (`word:alma`), by
     * pack; only the one pack when given.
     *
     * @return array<string, list<string>>
     */
    public function hiddenKeys(User $teacher, ?string $pack = null): array
    {
        $keys = [];
        $rows = HiddenWord::query()
            ->where('user_id', $teacher->id)
            ->when($pack !== null, fn ($query) => $query->where('pack', $pack))
            ->orderBy('id')
            ->get(['pack', 'word_key']);

        foreach ($rows as $row) {
            $keys[$row->pack][] = $row->word_key;
        }

        return $keys;
    }

    /**
     * A pack with the teacher's changes: its words without the hidden ones,
     * then the teacher's words that the pack does not have. A teacher word
     * whose lowercase equals a built-in word of this pack — hidden or not —
     * is dropped here, so the built-in one plays with its picture; it still
     * plays in the other packs. Sentences, look, title and version stay the pack's. With
     * `$own` no built-in word is kept: the pack's words are the teacher's
     * words that play (the same ones as without it).
     *
     * @param  list<WordData>  $custom  the teacher's words, in order
     * @param  list<string>  $hidden  content keys of the built-in words to leave out
     */
    public static function merge(PackData $pack, array $custom, array $hidden, bool $own = false): PackData
    {
        $hidden = array_flip($hidden);
        $seen = [];
        $words = [];

        foreach ($pack->words as $word) {
            $seen[$word->key()] = true;

            if (! $own && ! isset($hidden[$word->key()])) {
                $words[] = $word;
            }
        }

        foreach ($custom as $word) {
            if (! isset($seen[$word->key()])) {
                $seen[$word->key()] = true;
                $words[] = $word;
            }
        }

        return $pack->withWords($words);
    }

    /**
     * The teacher's words as pack words, in the order they were added.
     *
     * @return list<WordData>
     */
    private function customWords(User $teacher): array
    {
        return array_values(array_map(
            static fn (CustomWord $row): WordData => $row->toWordData(),
            CustomWord::query()
                ->where('user_id', $teacher->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->all(),
        ));
    }
}
