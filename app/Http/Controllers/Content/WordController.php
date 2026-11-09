<?php

namespace App\Http\Controllers\Content;

use App\Domain\Content\Actions\AddCustomWord;
use App\Domain\Content\Actions\DeleteCustomWord;
use App\Domain\Content\Actions\UpdateCustomWord;
use App\Domain\Content\Data\CustomWordData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\WordData;
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
 * 007): one theme pack at a time (`?pack=`, a pack key, never a word), its
 * built-in words with whether the teacher hid them, and the teacher's own
 * words in it. Syllables reach React already joined (`al-ma`). Flash
 * messages never carry a word: it may be a child's name.
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

        $words = CustomWord::query()
            ->where('user_id', $user->id)
            ->where('pack', $pack->key)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return Inertia::render('words/index', [
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
            'customWords' => $words->map(static fn (CustomWord $word): array => [
                'id' => $word->id,
                'text' => $word->text,
                'syllables' => $word->toWordData()->hyphenated(),
                'override' => $word->syllables === null ? null : implode('-', $word->syllables),
                // A later pack version may add the word itself: the built-in one plays.
                'played' => $pack->word($word->text) === null,
            ])->values()->all(),
            'limits' => [
                'max_length' => Config::integer('elifba.custom_words.max_length'),
                'max_per_teacher' => Config::integer('elifba.custom_words.max_per_teacher'),
                'count' => CustomWord::query()->where('user_id', $user->id)->count(),
            ],
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user, AddCustomWord $addCustomWord): RedirectResponse
    {
        $word = $addCustomWord->handle($user, CustomWordData::validate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('words.flash.added')]);

        return to_route('words.index', ['pack' => $word->pack]);
    }

    public function update(Request $request, CustomWord $word, UpdateCustomWord $updateCustomWord): RedirectResponse
    {
        $word = $updateCustomWord->handle($word, CustomWordData::validate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('words.flash.updated')]);

        return to_route('words.index', ['pack' => $word->pack]);
    }

    public function destroy(CustomWord $word, DeleteCustomWord $deleteCustomWord): RedirectResponse
    {
        $pack = $word->pack;
        $deleteCustomWord->handle($word);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('words.flash.deleted')]);

        return to_route('words.index', ['pack' => $pack]);
    }
}
