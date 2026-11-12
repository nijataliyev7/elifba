<?php

namespace App\Http\Controllers\Family;

use App\Domain\Content\Support\Avatars;
use App\Domain\Family\Actions\AddChild;
use App\Domain\Family\Actions\DeleteChild;
use App\Domain\Family\Actions\UpdateChild;
use App\Domain\Family\Data\ChildData;
use App\Domain\Family\Models\Child;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Support\PlayerVoice;
use App\Domain\Rankings\Support\ChildStars;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Uşaqlarım» and a child's page, in the parent area (docs/architecture.md
 * §1, §5 "A child's stars", §7 "Parents and their children", ADR 011). The
 * list is in the parent's own order with each child's stars of today only,
 * never sorted by stars and never with a total beside another child's. A
 * child's page has today, the week, in all and the last seven days. A
 * child's name is never flashed back or put in a URL. Both say whether
 * the board says the child's name aloud (`has_voice`, ADR 016 decision 10:
 * derived from the name and the built-in list, not a field about the
 * child; null while the account says no name), and the child's page how
 * the list spells a name typed otherwise (`voice_spelling`, «Lale» →
 * «Lalə»), for a one-tap fix.
 */
final class ChildController extends Controller
{
    public function index(#[CurrentUser] User $parent, PlayerVoice $voice): Response
    {
        $today = ChildStars::today($parent);

        return Inertia::render('children/index', [
            'children' => array_values(Child::query()->ownedBy($parent)->ordered()->get()
                ->map(static fn (Child $child): array => [
                    ...self::row($child),
                    'stars_today' => $today[$child->id] ?? 0,
                    'has_voice' => $voice->says($parent, $child->display_name),
                ])
                ->all()),
            'avatars' => Avatars::all(),
            'limits' => ['max' => Config::integer('elifba.children.max')],
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $parent, AddChild $addChild): RedirectResponse
    {
        $child = $addChild->handle($parent, ChildData::validate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('children.flash.added')]);

        return to_route('children.show', $child);
    }

    public function show(Child $child, #[CurrentUser] User $parent, PlayerVoice $voice): Response
    {
        return Inertia::render('children/show', [
            'child' => [
                ...self::row($child),
                'learned_letters' => $child->learned_letters ?? Alphabet::letters(),
                'has_voice' => $voice->says($parent, $child->display_name),
                'voice_spelling' => $voice->spelling($parent, $child->display_name),
            ],
            'stars' => ChildStars::of($child)->toArray(),
            'alphabet' => array_map(
                static fn (string $letter): array => ['lower' => $letter, 'upper' => Az::upper($letter)],
                Alphabet::letters(),
            ),
            'avatars' => Avatars::all(),
            'retentionDays' => Config::integer('elifba.retention_days'),
        ]);
    }

    public function update(Request $request, Child $child, UpdateChild $updateChild): RedirectResponse
    {
        $updateChild->handle($child, ChildData::validate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('children.flash.updated')]);

        return to_route('children.show', $child);
    }

    public function destroy(Child $child, DeleteChild $deleteChild): RedirectResponse
    {
        $deleteChild->handle($child);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('children.flash.deleted')]);

        return to_route('children.index');
    }

    /**
     * What a page receives about a child (ADR 013): the id, the first name
     * and the avatar key.
     *
     * @return array{id: string, display_name: string, avatar: string}
     */
    private static function row(Child $child): array
    {
        return [
            'id' => $child->id,
            'display_name' => $child->display_name,
            'avatar' => $child->avatar,
        ];
    }
}
