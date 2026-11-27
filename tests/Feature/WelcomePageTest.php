<?php

use App\Domain\Content\Data\PackData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\RoomFamily;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\Avatars;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Play\Support\Playable;
use App\Domain\Rooms\Support\RoomPlayable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Rooms;

/*
 * The welcome page (docs/frontend.md rule 10): public, the same page for a
 * guest and a teacher, every fact a prop from the content and the code, the
 * adults' rooms only while their switch is on, and the tags a shared link
 * shows rendered by the server.
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

it('has the section for parents in both languages', function (): void {
    $picturePacks = array_keys(array_filter(
        app(ContentRepository::class)->packs(),
        static fn (PackData $pack): bool => $pack->lists(Unit::Picture) || $pack->lists(Unit::Number),
    ));

    expect($picturePacks)->toContain('animals', 'dinosaurs', 'fruit', 'colors', 'numbers')
        ->not->toContain('balloons', 'train');

    $this->get(route('home'))
        ->assertInertia(fn (Assert $page) => $page->where('picturePacks', $picturePacks));

    $keys = [
        'welcome.hero.parents', 'welcome.parents.title', 'welcome.parents.text',
        'welcome.parents.alt', 'welcome.parents.remote_alt',
        'welcome.parents.role.title', 'welcome.parents.role.text',
        'welcome.parents.child.title', 'welcome.parents.child.text',
        'welcome.parents.play.title', 'welcome.parents.play.text',
        'welcome.parents.pictures', 'welcome.parents.pictures_text',
        'welcome.parents.reading', 'welcome.parents.reading_text',
        'welcome.parents.stars', 'welcome.parents.stars_text',
    ];

    foreach (['az', 'en'] as $locale) {
        $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);

        foreach ($keys as $key) {
            expect($translations)->toHaveKey($key)
                ->and($translations[$key])->not->toBe('');
        }
    }

    // The page renders the section with its two pictures of a home game.
    $page = (string) file_get_contents(resource_path('js/pages/welcome.tsx'));

    expect($page)->toContain('id="welcome-parents"', 'href="#welcome-parents"', 'picturePacks')
        ->and(resource_path('images/welcome/home.webp'))->toBeFile()
        ->and(resource_path('images/welcome/home-remote.webp'))->toBeFile();

    // No ranking, place or podium is promised to a parent (ADR 011).
    expect(__('welcome.parents.stars_text', locale: 'en'))->toContain('no ranking');
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

it('shows a signed-in parent the same page, with the parent area', function (): void {
    $parent = User::factory()->parent()->create();

    $this->actingAs($parent)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('auth.area', 'parent')
            ->has('picturePacks')
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

it('shows rooms only when the switch is on', function (): void {
    $player = User::factory()->teacherAndPlayer()->create();

    // Off, as `Tests\TestCase` and an instance have it: no prop about rooms,
    // for a guest or an account that holds the player role.

    foreach ([null, $player] as $account) {
        ($account === null ? $this : $this->actingAs($account))
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('welcome')->missing('rooms')->has('sample'));
    }

    // On: the families with the games a room offers, the difficulties and the limits.
    Rooms::on();
    $families = [];

    foreach (RoomFamily::cases() as $family) {
        $families[] = [
            'key' => $family->value,
            'mechanics' => array_values(array_map(
                static fn (RoomMechanic $mechanic): string => $mechanic->value,
                array_filter(RoomPlayable::mechanics(), static fn (RoomMechanic $mechanic): bool => $mechanic->family() === $family),
            )),
        ];
    }

    expect(array_merge(...array_column($families, 'mechanics')))->toHaveCount(count(RoomMechanic::cases()));

    $this->withSession(['locale' => 'en'])
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('welcome')
            ->where('rooms', [
                'families' => $families,
                'difficulties' => [1, 2, 3, 4],
                'maxPlayers' => Config::integer('elifba.rooms.max_players'),
                'rounds' => ['min' => Config::integer('elifba.rooms.rounds.min'), 'max' => Config::integer('elifba.rooms.rounds.max')],
            ]));

    // Every name the section draws is in both languages.
    $keys = [
        'welcome.rooms.title', 'welcome.rooms.text', 'welcome.rooms.players', 'welcome.rooms.rounds',
        'welcome.rooms.difficulties', 'welcome.rooms.screen_alt', 'welcome.rooms.phone_alt',
        'welcome.rooms.join_title', 'welcome.rooms.join_text', 'welcome.rooms.open', 'welcome.rooms.enter',
        'welcome.rooms.names', 'welcome.rooms.privacy', 'welcome.rooms.hero',
        ...array_map(static fn (int $difficulty): string => "rooms.difficulty.{$difficulty}", [1, 2, 3, 4]),
    ];

    foreach ($families as $family) {
        $keys[] = "rooms.setup.families.{$family['key']}";

        foreach ($family['mechanics'] as $mechanic) {
            array_push($keys, "rooms.mechanics.{$mechanic}.name", "rooms.mechanics.{$mechanic}.line");
        }
    }

    foreach (['az', 'en'] as $locale) {
        $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);

        foreach ($keys as $key) {
            expect($translations)->toHaveKey($key)
                ->and(trim($translations[$key]))->not->toBe('');
        }
    }

    // The page draws the section only from the prop, after the children's
    // data and before the price, with its two screenshots of a room.
    $page = (string) file_get_contents(resource_path('js/pages/welcome.tsx'));

    expect($page)->toContain('{rooms && <RoomsSection rooms={rooms} />}')
        ->and(strpos($page, 'id="welcome-privacy"'))->toBeLessThan(strpos($page, '<RoomsSection'))
        ->and(strpos($page, '<RoomsSection'))->toBeLessThan(strpos($page, 'id="welcome-free"'))
        ->and(substr_count($page, 'RoomsSection'))->toBe(2)
        ->and(resource_path('images/welcome/rooms-screen.webp'))->toBeFile()
        ->and(resource_path('images/welcome/rooms-phone.webp'))->toBeFile();
});

it('names the voice on the welcome page only while its switch is on', function (): void {
    // The rooms switch alone: the section as before, no word of the voice.
    Rooms::on();
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('welcome')->has('rooms')->missing('rooms.voice'));

    // The voice switch too (ADR 031 decision 15): `voice` in the prop, for a guest and a player.
    Rooms::voiceOn();

    foreach ([null, User::factory()->player()->create()] as $account) {
        ($account === null ? $this : $this->actingAs($account))
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('welcome')->where('rooms.voice', true));
    }

    // The line keeps what it said and adds the voice: on until the host turns it off, never recorded (ADR 031 as amended 2026-11-19).
    foreach (['az' => ['aparıcı isə onu söndürə bilər', 'mikrofonu hər kəs özü açır', 'səs yazılmır'], 'en' => ['the host can turn it off', 'everyone turns their own microphone on', 'the voice is not recorded']] as $locale => $words) {
        $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);

        expect($translations['welcome.rooms.names_voice'])->toStartWith($translations['welcome.rooms.names'])
            ->toContain(...$words)
            ->and($translations['welcome.rooms.names'])->not->toContain($words[2]);
    }

    // The section draws the voice's line only from its prop.
    $section = (string) file_get_contents(resource_path('js/components/welcome/rooms-section.tsx'));

    expect($section)->toMatch("/facts\\.voice\\s*\\?\\s*'welcome\\.rooms\\.names_voice'\\s*:\\s*'welcome\\.rooms\\.names'/")
        ->and(substr_count($section, 'names_voice'))->toBe(1);
});
