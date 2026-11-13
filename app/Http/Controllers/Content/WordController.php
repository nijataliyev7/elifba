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
use App\Domain\Content\Support\CustomWordList;
use App\Domain\Content\Support\TeacherPacks;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The teacher's words page (docs/architecture.md §3 "Teacher words", ADR
 * 007, ADR 009): all of the teacher's own words, which play in every theme
 * pack, each with what it gives the word and syllable levels and the packs
 * that have it built in, how many items all of them give each level and the
 * add form's limits (`CustomWordList`, which «Sözlərim» on the game catalog
 * reads too); and the built-in words of one pack at a time (`?pack=`, a pack
 * key, never a word) with whether the teacher hid them. Syllables reach
 * React already joined (`al-ma`). A change redirects back, so «Sözlərim»
 * stays on the catalog. Flash messages never carry a word: it may be a
 * child's name.
 */
final class WordController extends Controller
{
    public function index(Request $request, #[CurrentUser] User $user, ContentRepository $content, TeacherPacks $teacherPacks, CustomWordList $customWords): Response
    {
        $packs = $content->packs();
        $key = $request->query('pack');
        $pack = is_string($key) && isset($packs[$key]) ? $packs[$key] : $packs[array_key_first($packs)];
        $hidden = array_flip($teacherPacks->hiddenKeys($user, $pack->key)[$pack->key] ?? []);
        $locale = app()->getLocale();
        $list = $customWords->for($user);

        return Inertia::render('words/index', [
            'customWords' => $list['words'],
            'levelTotals' => $list['levelTotals'],
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
            'limits' => $list['limits'],
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
