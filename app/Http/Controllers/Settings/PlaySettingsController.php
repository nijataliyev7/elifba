<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Play\Actions\UpdatePlaySettings;
use App\Domain\Play\Data\PlaySettingsData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Oyun (docs/architecture.md §4 "Pairing"): whether the teacher
 * board offers the phone remote.
 */
final class PlaySettingsController extends Controller
{
    public function edit(#[CurrentUser] User $user): Response
    {
        return Inertia::render('settings/play', [
            'phoneRemote' => $user->phone_remote,
        ]);
    }

    public function update(Request $request, #[CurrentUser] User $user, UpdatePlaySettings $updatePlaySettings): RedirectResponse
    {
        $updatePlaySettings->handle($user, PlaySettingsData::validate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('settings.play.updated')]);

        return to_route('play-settings.edit');
    }
}
