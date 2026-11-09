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
 * "Teacher words", ADR 007): the built-in words in file order without the
 * ones the teacher hid, then the teacher's own words in the order they were
 * added. Every Play reader that builds or checks rounds reads packs here,
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
     * @throws InvalidArgumentException when there is no such pack
     */
    public function pack(User $teacher, string $key): PackData
    {
        $pack = $this->content->pack($key);

        return self::merge($pack, $this->customWords($teacher, $key)[$key] ?? [], $this->hiddenKeys($teacher, $key)[$key] ?? []);
    }

    /**
     * Every pack by key, in key order, as the teacher plays it.
     *
     * @return array<string, PackData>
     */
    public function packs(User $teacher): array
    {
        $custom = $this->customWords($teacher);
        $hidden = $this->hiddenKeys($teacher);

        return array_map(
            static fn (PackData $pack): PackData => self::merge($pack, $custom[$pack->key] ?? [], $hidden[$pack->key] ?? []),
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
     * whose lowercase equals a built-in word — hidden or not — is dropped,
     * so the built-in one, with its picture, wins when a later pack version
     * adds it. Sentences, look, title and version stay the pack's.
     *
     * @param  list<WordData>  $custom  the teacher's words in this pack, in order
     * @param  list<string>  $hidden  content keys of the built-in words to leave out
     */
    public static function merge(PackData $pack, array $custom, array $hidden): PackData
    {
        $hidden = array_flip($hidden);
        $seen = [];
        $words = [];

        foreach ($pack->words as $word) {
            $seen[$word->key()] = true;

            if (! isset($hidden[$word->key()])) {
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
     * The teacher's words as pack words, by pack, in the order they were
     * added; only the one pack when given.
     *
     * @return array<string, list<WordData>>
     */
    private function customWords(User $teacher, ?string $pack = null): array
    {
        $words = [];
        $rows = CustomWord::query()
            ->where('user_id', $teacher->id)
            ->when($pack !== null, fn ($query) => $query->where('pack', $pack))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $words[$row->pack][] = $row->toWordData();
        }

        return $words;
    }
}
