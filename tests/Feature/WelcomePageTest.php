<?php

use App\Domain\Content\Data\PackData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Support\Avatars;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Play\Support\Playable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The welcome page (docs/frontend.md rule 10): public, the same page for a
 * guest and a teacher, every fact a prop from the content and the code, and
 * the tags a shared link shows rendered by the server.
 */

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00:00', 'Asia/Baku'));
});

it('shows a guest the welcome page with its facts as props', function (): void {
    $packs = app(ContentRepository::class)->packs();
    $first = array_values($packs)[0];

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('auth.user', null)
            ->where('sourceUrl', 'https://github.com/nijataliyev7/elifba')
            ->where('mechanics', array_map(static fn (Mechanic $mechanic): string => $mechanic->value, Playable::mechanics()))
            ->has('packs', count($packs))
            ->where('packs.0', [
                'key' => $first->key,
                'title' => $first->title->az,
                'palette' => $first->style->palette,
            ])
            ->has('letters', Alphabet::count())
            ->where('letters.6', ['lower' => 'ə', 'upper' => 'Ə'])
            ->has('avatars', min(6, count(Avatars::keys())))
            ->where('avatars.0', Avatars::all()[0])
            ->has('sample.rounds', Config::integer('elifba.rounds.min'))
            ->has('sample.rounds.0.items', 4)
            ->where('sample.pack.key', fn (string $key): bool => isset($packs[$key])));
});

it('names every mechanic it lists in both languages', function (): void {
    foreach (Playable::mechanics() as $mechanic) {
        foreach (['az', 'en'] as $locale) {
            $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);

            expect($translations)->toHaveKey("games.mechanics.{$mechanic->value}")
                ->toHaveKey("welcome.get.mechanic.{$mechanic->value}");
        }
    }
});

it('shows a signed-in teacher the same page', function (): void {
    $teacher = User::factory()->create();

    $this->actingAs($teacher)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('auth.user.id', $teacher->id)
            ->has('packs')
            ->has('sample.rounds'));
});

it('links to the repository this instance names', function (): void {
    Config::set('elifba.source_url', 'https://example.org/school/elifba');

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('sourceUrl', 'https://example.org/school/elifba'));
});

it('gives the pack titles in the interface language, the content in Azerbaijani', function (): void {
    $packs = array_values(array_map(
        static fn (PackData $pack): string => $pack->title->en,
        app(ContentRepository::class)->packs(),
    ));

    $this->withSession(['locale' => 'en'])
        ->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            ->where('packs', fn ($given): bool => collect($given)->pluck('title')->all() === $packs)
            ->where('sample.rounds.0.prompt', fn (string $prompt): bool => str_contains($prompt, 'hərfini tap')));
});

it('renders the description, Open Graph and Twitter tags on the server', function (): void {
    $html = $this->get(route('home'))->assertOk()->getContent();
    $image = url('images/og.png');

    expect($html)
        ->toContain('<meta name="description" content="'.e(__('welcome.meta.description')).'">')
        ->toContain('<meta property="og:title" content="Elifba: sinif lövhəsində oxu oyunları">')
        ->toContain('<meta property="og:description" content="'.e(__('welcome.meta.description')).'">')
        ->toContain('<meta property="og:site_name" content="'.e((string) config('app.name')).'">')
        ->toContain('<meta property="og:locale" content="az_AZ">')
        ->toContain('<meta property="og:image" content="'.$image.'">')
        ->toContain('<meta name="twitter:card" content="summary_large_image">')
        ->toContain('<meta name="twitter:image" content="'.$image.'">')
        ->and($image)->toStartWith(config('app.url'))
        ->and(public_path('images/og.png'))->toBeFile();

    [$width, $height] = getimagesize(public_path('images/og.png')) ?: [0, 0];
    expect([$width, $height])->toBe([1200, 630]);
});

it('renders the tags in English when English is chosen', function (): void {
    $html = $this->withSession(['locale' => 'en'])->get(route('home'))->getContent();

    expect($html)
        ->toContain('<meta property="og:title" content="Elifba: reading games on the classroom wall">')
        ->toContain('<meta property="og:locale" content="en_US">')
        ->toContain('<html lang="en"');
});
