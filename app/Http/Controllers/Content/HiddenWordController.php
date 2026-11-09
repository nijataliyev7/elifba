<?php

namespace App\Http\Controllers\Content;

use App\Domain\Content\Actions\HideWord;
use App\Domain\Content\Actions\ShowWord;
use App\Domain\Content\Data\HiddenWordData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Hides a built-in word from the teacher's own games, or shows it again
 * (docs/architecture.md §3 "Teacher words"). The pack and the word travel
 * in the request body, never in the URL.
 */
final class HiddenWordController extends Controller
{
    public function store(Request $request, #[CurrentUser] User $user, HideWord $hideWord): RedirectResponse
    {
        $data = HiddenWordData::validate($request->all());
        $hideWord->handle($user, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('words.flash.hidden')]);

        return to_route('words.index', ['pack' => $data->pack]);
    }

    public function destroy(Request $request, #[CurrentUser] User $user, ShowWord $showWord): RedirectResponse
    {
        $data = HiddenWordData::validate($request->all());
        $showWord->handle($user, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('words.flash.shown')]);

        return to_route('words.index', ['pack' => $data->pack]);
    }
}
