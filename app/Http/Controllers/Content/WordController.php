<?php

namespace App\Http\Controllers\Content;

use App\Domain\Content\Actions\AddCustomWord;
use App\Domain\Content\Actions\DeleteCustomWord;
use App\Domain\Content\Actions\UpdateCustomWord;
use App\Domain\Content\Data\CustomWordData;
use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\WordData;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Models\CustomWord;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TeacherPacks;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The teacher's words page (docs/architecture.md §3 "Teacher words", ADR
 * 007, ADR 009): all of the teacher's own words, which play in every theme
 * pack, each with what it gives the word and syllable levels
 * (`LevelData::itemsFrom()`) and the packs that have it built in; how many
 * items all of them give each level; and the built-in words of one pack at a
 * time (`?pack=`, a pack key, never a word) with whether the teacher hid
 * them. Syllables reach React already joined (`al-ma`). Flash messages never
 * carry a word: it may be a child's name.
 */
final class WordController extends Controller
{
    public function index(Request $request, #[CurrentUser] User $user, ContentRepository $content, TeacherPacks $teacherPacks): Response
    {
        $packs = $content->packs();
        $key = $request->query('pack');
        $pack = is_string($key) && isset($packs[$key]) ? $packs[$key] : $packs[array_key_first($packs)];
        $hidden = array_flip($teacherPacks->hiddenKeys($user, $pack->key)[$pack->key] ?? []);
        $locale = app()->getLocale();
        $levels = array_values(array_filter(
            $content->levels(),
            static fn (LevelData $level): bool => in_array($level->unit, [Unit::Word, Unit::Syllable], true),
        ));

        $words = CustomWord::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        /** @var array<string, array<string, true>> $totals level key → distinct items */
        $totals = array_fill_keys(array_map(static fn (LevelData $level): string => $level->key, $levels), []);

        $customWords = $words->map(static function (CustomWord $word) use ($levels, $packs, $locale, &$totals): array {
            $data = $word->toWordData();
            $gives = [];

            foreach ($levels as $level) {
                $items = $level->itemsFrom($data);

                foreach ($items as $item) {
                    $totals[$level->key][$item] = true;
                }

                if ($items !== []) {
                    $gives[] = ['title' => $level->title->in($locale), 'unit' => $level->unit->value, 'items' => $items];
                }
            }

            return [
                'id' => $word->id,
                'text' => $word->text,
                'syllables' => $data->hyphenated(),
                'override' => $word->syllables === null ? null : implode('-', $word->syllables),
                'levels' => $gives,
                // A pack that has the word built in plays its own, with the picture.
                'built_in_in' => array_values(array_map(
                    static fn (PackData $pack): string => $pack->title->in($locale),
                    array_filter($packs, static fn (PackData $pack): bool => $pack->word($word->text) !== null),
                )),
            ];
        })->values()->all();

        return Inertia::render('words/index', [
            'customWords' => $customWords,
            'levelTotals' => array_map(static fn (LevelData $level): array => [
                'title' => $level->title->in($locale),
                'count' => count($totals[$level->key]),
            ], $levels),
            'packs' => array_values(array_map(
                static fn (PackData $pack): array => ['key' => $pack->key, 'title' => $pack->title->in($locale)],
                $packs,
            )),
            'pack' => $pack->key,
            'builtInWords' => array_map(
                static fn (WordData $word): array => [
                    'text' => $word->text,
                    'syllables' => $word->hyphenated(),
                    'hidden' => isset($hidden[$word->key()]),
                ],
                $pack->words,
            ),
            'limits' => [
                'max_length' => Config::integer('elifba.custom_words.max_length'),
                'max_per_teacher' => Config::integer('elifba.custom_words.max_per_teacher'),
                'count' => $words->count(),
            ],
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user, AddCustomWord $addCustomWord): RedirectResponse
    {
        $addCustomWord->handle($user, CustomWordData::validate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('words.flash.added')]);

        return back(fallback: route('words.index'));
    }

    public function update(Request $request, CustomWord $word, UpdateCustomWord $updateCustomWord): RedirectResponse
    {
        $updateCustomWord->handle($word, CustomWordData::validate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('words.flash.updated')]);

        return back(fallback: route('words.index'));
    }

    public function destroy(CustomWord $word, DeleteCustomWord $deleteCustomWord): RedirectResponse
    {
        $deleteCustomWord->handle($word);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('words.flash.deleted')]);

        return back(fallback: route('words.index'));
    }
}
