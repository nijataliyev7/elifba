<?php

namespace App\Http\Controllers;

use App\Actions\Settings\UpdateLocale;
use App\Models\User;
use App\Support\Translations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LocaleController extends Controller
{
    public function __invoke(Request $request, UpdateLocale $updateLocale): RedirectResponse
    {
        $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(Translations::locales()))],
        ]);

        $user = $request->user();

        $updateLocale->handle($request->string('locale')->value(), $request->session(), $user instanceof User ? $user : null);

        return back();
    }
}
