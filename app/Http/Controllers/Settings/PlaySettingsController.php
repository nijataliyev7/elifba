<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Content\Data\SoundChoiceData;
use App\Domain\Content\Enums\Effect;
use App\Domain\Content\Enums\Sound;
use App\Domain\Content\Support\SoundChoices;
use App\Domain\Play\Actions\UpdateBoardSounds;
use App\Domain\Play\Actions\UpdateBoardVoice;
use App\Domain\Play\Actions\UpdatePlaySettings;
use App\Domain\Play\Data\BoardSoundsData;
use App\Domain\Play\Data\BoardVoiceData;
use App\Domain\Play\Data\PlaySettingsData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Oyun: whether the teacher board offers the phone remote
 * (docs/architecture.md §4 "Pairing"), the sounds the account's boards
 * play (§3 "Sounds") and what they say (§3 "Voice").
 */
final class PlaySettingsController extends Controller
{
    public function edit(#[CurrentUser] User $user): Response
    {
        $locale = app()->getLocale();
        $chosen = [];
        $options = [];

        foreach (Sound::choosable() as $sound) {
            $key = $user->board_sounds[$sound->value] ?? null;
            $chosen[$sound->value] = is_string($key) && SoundChoices::find($sound, $key) !== null ? $key : null;
            $options[$sound->value] = array_values(array_map(static fn (SoundChoiceData $choice): array => [
                'key' => $choice->key,
                'title' => $choice->title->in($locale),
                'url' => SoundChoices::url($choice),
                // A pop plays only where items are burst (§8); elsewhere the pack's own.
                'every_pack' => array_all(Effect::cases(), $choice->suits(...)),
            ], SoundChoices::of($sound)));
        }

        return Inertia::render('settings/play', [
            'phoneRemote' => $user->phone_remote,
            'sounds' => ['chosen' => $chosen, 'options' => $options],
            'voice' => BoardVoiceData::fromStored($user->board_voice)->toArray(),
        ]);
    }

    public function update(Request $request, #[CurrentUser] User $user, UpdatePlaySettings $updatePlaySettings): RedirectResponse
    {
        $updatePlaySettings->handle($user, PlaySettingsData::validate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('settings.play.updated')]);

        return to_route('play-settings.edit');
    }

    public function updateSounds(Request $request, #[CurrentUser] User $user, UpdateBoardSounds $updateBoardSounds): RedirectResponse
    {
        $updateBoardSounds->handle($user, BoardSoundsData::validate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('settings.play.updated')]);

        return to_route('play-settings.edit');
    }

    public function updateVoice(Request $request, #[CurrentUser] User $user, UpdateBoardVoice $updateBoardVoice): RedirectResponse
    {
        $updateBoardVoice->handle($user, BoardVoiceData::validate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('settings.play.updated')]);

        return to_route('play-settings.edit');
    }
}
