<?php

use App\Domain\Rooms\Actions\CloseRoom;
use App\Domain\Rooms\Actions\RemoveRoomPlayer;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomCode;
use App\Domain\Rooms\Support\RoomJoinLink;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\RoomInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GuestBoards;
use Tests\Support\Rooms;

/*
 * Joining a room with its code (docs/architecture.md §12 "Rooms and
 * joining", "Games first", ADR 020 decisions 3–4, ADR 026 decision 6):
 * `/oyun`, `/oyun/{code}` and «Qoşul» — `/otaq` and `/otaq/{code}` lead
 * there —
 * admit a signed-in, confirmed account with the player role into that one
 * room. An unknown code, a closed room and an account the host removed get
 * the same refusal, a sentence on the code field and never an error page; a
 * locked or full room says so and nothing else. A room code and a pairing
 * code know nothing of each other.
 */

beforeEach(function (): void {
    Rooms::on();
    $this->host = User::factory()->player()->create(['name' => 'Rəşad Quliyev']);
    $this->room = Rooms::open($this->host);
    $this->player = User::factory()->player()->create(['name' => 'Aygün Məmmədova']);
});

/**
 * Everything a join could change.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function joinSnapshot(): array
{
    $rows = static fn (string $table): array => DB::table($table)->orderBy('id')->get()
        ->map(static fn (object $row): array => (array) $row)->all();

    return ['rooms' => $rows('rooms'), 'room_players' => $rows('room_players')];
}

it('joins with the code and an account', function (): void {
    $code = (string) $this->room->code;
    $spaced = substr($code, 0, 3).' '.substr($code, 3);

    $this->actingAs($this->player);

    // The code field, then the join page: the code and the account's own name.
    $this->get(route('rooms.entrance'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('rooms/join')->where('code', null));
    $this->get(route('rooms.entrance', ['code' => $spaced]))->assertRedirect(route('rooms.join', $code));
    $this->get(route('rooms.join', $code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('rooms/join')
            ->where('code', $code)
            ->where('name', 'Aygün Məmmədova')
            ->missing('room')
            ->missing('players'));

    $this->post(route('rooms.join.store', $code))->assertRedirect(route('rooms.show', $this->room));

    $row = RoomPlayer::query()->where('user_id', $this->player->id)->sole();
    expect($row->room_id)->toBe($this->room->id)
        ->and($row->isPresent())->toBeTrue()
        ->and($this->room->refresh()->revision)->toBe(1)
        ->and($this->reverb->sent)->toBe([[
            'channels' => ['private-room.'.$this->room->id],
            'event' => 'room.changed',
            'data' => ['v' => 1, 'revision' => 1],
        ]]);

    // Both sit in the lobby and see each other's name.
    $this->getJson(route('rooms.state', $this->room))
        ->assertOk()
        ->assertJsonPath('me.player_id', $row->id)
        ->assertJsonPath('players.0.name', 'Rəşad Quliyev')
        ->assertJsonPath('players.1.name', 'Aygün Məmmədova');
    // Joined before the first game: ready for it by default (ADR 035 decision 21).
    $this->actingAs($this->host)->getJson(route('rooms.state', $this->room))
        ->assertJsonPath('players.1', ['id' => $row->id, 'name' => 'Aygün Məmmədova', 'present' => true, 'host' => false, 'voice' => null, 'ready' => true]);

    // A present player is sent on to the room; joining again changes nothing.
    $before = joinSnapshot();
    $this->actingAs($this->player)->get(route('rooms.join', $code))->assertRedirect(route('rooms.show', $this->room));
    $this->post(route('rooms.join.store', $code))->assertRedirect(route('rooms.show', $this->room));
    expect(joinSnapshot())->toBe($before);
});

it('joins with one tap from the code field and the camera, the host and a present player as they are', function (): void {
    $code = (string) $this->room->code;
    $spaced = substr($code, 0, 3).'-'.substr($code, 3);

    // The code field's «Qoşul»: `POST /oyun`, the code read as typed, and the room at once (ADR 033 decision 20).
    expect(route('rooms.entrance.store', [], false))->toBe('/oyun');
    $this->actingAs($this->player)
        ->from(route('rooms.index'))
        ->post(route('rooms.entrance.store'), ['code' => $spaced])
        ->assertRedirect(route('rooms.show', $this->room));

    $row = $this->room->rowOf($this->player);
    expect($row?->isPresent())->toBeTrue()
        ->and($row?->ready_for)->toBe(0)
        ->and($this->room->refresh()->revision)->toBe(1);

    // Again, or by the camera's post: a present player goes to the room and nothing changes.
    $before = joinSnapshot();
    $this->post(route('rooms.entrance.store'), ['code' => $code])->assertRedirect(route('rooms.show', $this->room));
    $this->post(route('rooms.join.store', $code))->assertRedirect(route('rooms.show', $this->room));
    expect(joinSnapshot())->toBe($before);

    // The host — playing or not — goes to the room as they are, by the field or the camera.
    $room = Rooms::open($this->host, plays: false);
    $revision = $room->revision;
    $this->actingAs($this->host)->post(route('rooms.entrance.store'), ['code' => (string) $room->code])->assertRedirect(route('rooms.show', $room));
    $this->post(route('rooms.join.store', (string) $room->code))->assertRedirect(route('rooms.show', $room));
    expect($room->rowOf($this->host)?->isPresent() ?? false)->toBeFalse()
        ->and($room->refresh()->revision)->toBe($revision);
});

it('refuses under the field what the one tap cannot join', function (): void {
    $code = (string) $this->room->code;
    $unknown = $code === '000000' ? '000001' : '000000';
    $before = joinSnapshot();
    $this->actingAs($this->player);

    // Malformed, unknown: the field's own refusals, back where it was typed.
    $this->from(route('rooms.index'))->post(route('rooms.entrance.store'), ['code' => '12'])
        ->assertRedirect(route('rooms.index'))->assertSessionHasErrors('code');
    $this->from(route('rooms.index'))->post(route('rooms.entrance.store'), ['code' => $unknown])
        ->assertRedirect(route('rooms.index'))->assertSessionHasErrors(['code' => __('rooms.errors.unknown')]);

    // Locked, then full: the reason under the field and nothing else.
    $this->actingAs($this->host)->patch(route('rooms.update', $this->room), ['locked' => true])->assertRedirect();
    $this->actingAs($this->player)->from(route('rooms.entrance'))->post(route('rooms.entrance.store'), ['code' => $code])
        ->assertRedirect(route('rooms.entrance'))->assertSessionHasErrors(['code' => __('rooms.errors.locked')]);
    $this->actingAs($this->host)->patch(route('rooms.update', $this->room), ['locked' => false]);
    Config::set('elifba.rooms.max_players', 1);
    $this->actingAs($this->player)->from(route('rooms.entrance'))->post(route('rooms.entrance.store'), ['code' => $code])
        ->assertRedirect(route('rooms.entrance'))->assertSessionHasErrors(['code' => __('rooms.errors.full')]);

    expect($this->room->rowOf($this->player))->toBeNull()
        ->and(joinSnapshot()['room_players'])->toBe($before['room_players']);
});

it('sends a visitor to the login and back to the code', function (): void {
    $code = (string) $this->room->code;

    $this->get(route('rooms.join', $code))->assertRedirect(route('login'));
    $this->post(route('login.store'), ['email' => $this->player->email, 'password' => 'password'])
        ->assertRedirect(route('rooms.join', $code));

    $this->get(route('rooms.join', $code))->assertOk()->assertInertia(fn (Assert $page) => $page->where('code', $code));
    $this->post(route('rooms.join.store', $code))->assertRedirect(route('rooms.show', $this->room));
});

it('refuses an unknown code, a closed room and a removed account alike, on the code field', function (): void {
    $closed = Rooms::open();
    $closedCode = (string) $closed->code;
    app(CloseRoom::class)->handle($closed);

    $removed = User::factory()->player()->create();
    app(RemoveRoomPlayer::class)->handle($this->room, Rooms::join($this->room, $removed));

    // Idle: closed by the rule before the scheduler clears its code.
    $idle = Room::factory()->create(['last_activity_at' => now()->subHours(config('elifba.rooms.idle_hours'))]);

    $unknown = $this->room->code === '000000' ? '000001' : '000000';
    $before = joinSnapshot();

    $refusals = [
        'an unknown code' => [$this->player, $unknown],
        'a closed room' => [$this->player, $closedCode],
        'an idle room' => [$this->player, (string) $idle->code],
        'a removed account' => [$removed, (string) $this->room->code],
    ];

    // A link or a QR, «Qoşul», and the code typed at the entrance: each ends
    // at the code field with the one sentence, whatever the reason.
    $refused = [200, ['code' => __('rooms.errors.unknown')]];
    $answers = [];

    foreach ($refusals as $case => [$account, $code]) {
        $this->actingAs($account)->from(route('rooms.entrance'));

        foreach ([['GET', route('rooms.join', $code)], ['POST', route('rooms.join.store', $code)], ['GET', route('rooms.entrance', ['code' => $code])]] as [$method, $url]) {
            $response = $this->followingRedirects()->call($method, $url);
            $response->assertInertia(fn (Assert $page) => $page
                ->component('rooms/join')
                ->where('code', null)
                ->missing('room')
                ->missing('players'));
            $answers[$case][] = [$response->status(), $response->inertiaProps('errors')];

            expect($response->getContent())->not->toContain('Rəşad');
        }

        // A minute apart, so the limit on guessing (below) stays out of it.
        $this->travel(61)->seconds();
    }

    expect($answers)->toBe([
        'an unknown code' => [$refused, $refused, $refused],
        'a closed room' => [$refused, $refused, $refused],
        'an idle room' => [$refused, $refused, $refused],
        'a removed account' => [$refused, $refused, $refused],
    ])->and(joinSnapshot())->toBe($before);

    // Where each is refused: a link, a QR and «Qoşul» at the entrance; a
    // typed code where it was typed, «Otaqlar» too.
    foreach ($refusals as [$account, $code]) {
        $this->actingAs($account)->from(route('rooms.index'));

        Rooms::assertRefused($this->get(route('rooms.join', $code)));
        Rooms::assertRefused($this->post(route('rooms.join.store', $code)));
        $this->get(route('rooms.entrance', ['code' => $code]))
            ->assertRedirect(route('rooms.index'))
            ->assertSessionHasErrors(['code' => __('rooms.errors.unknown')]);

        $this->travel(61)->seconds();
    }

    // To a JSON client too, one body for every reason.
    $bodies = [];

    foreach ($refusals as $case => [$account, $code]) {
        $bodies[$case] = $this->actingAs($account)->postJson(route('rooms.join.store', $code))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => __('rooms.errors.unknown')])
            ->getContent();
        $this->travel(61)->seconds();
    }

    expect(array_unique($bodies))->toHaveCount(1)
        ->and(joinSnapshot())->toBe($before);

    // A malformed code is no code at all: no route, and its own sentence when typed.
    $this->actingAs($this->player)->get('/otaq/48291')->assertNotFound();
    $this->get('/otaq/abcdef')->assertNotFound();
    $this->from(route('rooms.entrance'))->get(route('rooms.entrance', ['code' => '48-29']))
        ->assertRedirect(route('rooms.entrance'))
        ->assertSessionHasErrors(['code' => __('rooms.errors.code')]);
});

it('says that a room is locked or full and nothing else about it', function (): void {
    $code = (string) $this->room->code;
    $this->actingAs($this->host)->patch(route('rooms.update', $this->room), ['locked' => true])->assertRedirect();
    $before = joinSnapshot();

    $this->actingAs($this->player)->get(route('rooms.join', $code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('code', $code)->missing('locked')->missing('players'));

    $locked = $this->from(route('rooms.join', $code))->post(route('rooms.join.store', $code));
    $locked->assertRedirect(route('rooms.join', $code))->assertSessionHasErrors(['code' => __('rooms.errors.locked')]);
    expect(session('errors')->all())->toBe([__('rooms.errors.locked')])
        ->and(joinSnapshot())->toBe($before);

    // Full: as many present players as the room may hold.
    $this->actingAs($this->host)->patch(route('rooms.update', $this->room), ['locked' => false]);
    Config::set('elifba.rooms.max_players', 2);
    Rooms::join($this->room);
    $before = joinSnapshot();

    $this->actingAs($this->player)->from(route('rooms.join', $code))->post(route('rooms.join.store', $code))
        ->assertRedirect(route('rooms.join', $code))
        ->assertSessionHasErrors(['code' => __('rooms.errors.full')]);
    expect(session('errors')->all())->toBe([__('rooms.errors.full')])
        ->and(joinSnapshot())->toBe($before);

    $this->get(route('rooms.join', $code))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('code', $code)
        ->where('name', 'Aygün Məmmədova')
        ->missing('players'));
});

it('brings a player who left back to the same row', function (): void {
    $this->travelTo('2026-11-24 19:00:00');
    $this->room = Rooms::open();
    $row = Rooms::join($this->room, $this->player);

    $this->travelTo('2026-11-24 19:10:00');
    $this->actingAs($this->player)->post(route('rooms.leave', $this->room))->assertRedirect(route('rooms.index'));
    expect($row->refresh()->left_at?->toDateTimeString())->toBe('2026-11-24 19:10:00');

    // Out of the live room: the page without the state, no state, no channel.
    $this->get(route('rooms.show', $this->room))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('state', null)
        ->where('can.leave', false));
    $this->getJson(route('rooms.state', $this->room))->assertForbidden();

    $this->travelTo('2026-11-24 19:20:00');
    $this->get(route('rooms.join', (string) $this->room->code))->assertOk();
    $this->post(route('rooms.join.store', (string) $this->room->code))->assertRedirect(route('rooms.show', $this->room));

    $back = RoomPlayer::query()->where('user_id', $this->player->id)->sole();
    expect($back->id)->toBe($row->id)
        ->and($back->left_at)->toBeNull()
        ->and($back->joined_at->toDateTimeString())->toBe('2026-11-24 19:00:00')
        ->and($back->isPresent())->toBeTrue()
        ->and($this->room->refresh()->revision)->toBe(3);
});

it('refuses a twenty-first player', function (): void {
    expect(Config::integer('elifba.rooms.max_players'))->toBe(20);

    foreach (range(2, 20) as $_) {
        Rooms::join($this->room);
    }

    expect($this->room->players()->present()->count())->toBe(20);

    $this->actingAs($this->player)->from(route('rooms.join', (string) $this->room->code))
        ->post(route('rooms.join.store', (string) $this->room->code))
        ->assertSessionHasErrors(['code' => __('rooms.errors.full')]);

    expect($this->room->players()->count())->toBe(20);

    // One leaves, and there is a place again.
    $leaving = RoomPlayer::query()->where('room_id', $this->room->id)->where('user_id', '!=', $this->host->id)->firstOrFail();
    $this->actingAs(User::query()->findOrFail($leaving->user_id))->post(route('rooms.leave', $this->room));
    $this->actingAs($this->player)->post(route('rooms.join.store', (string) $this->room->code))
        ->assertRedirect(route('rooms.show', $this->room));
});

it('does not know a pairing code, and the pult does not know a room code', function (): void {
    $teacher = User::factory()->teacherAndPlayer()->create();
    $board = GuestBoards::open()['board'];
    $boardCode = (string) $board->code;

    // A board's code at the room's entrance; the room's code at the pult.
    Rooms::assertRefused($this->actingAs($teacher)->get(route('rooms.join', $boardCode)));
    Rooms::assertRefused($this->post(route('rooms.join.store', $boardCode)));
    $this->get(route('pult.code', (string) $this->room->code))
        ->assertRedirect(route('pult', ['list' => 1]))
        ->assertSessionHasErrors(['code' => __('pult.errors.unknown')]);

    // The same six digits as a room and a board at once: each entrance finds its own.
    app()->instance(RoomCode::class, new RoomCode(static fn (): string => $boardCode));
    $twin = Rooms::open();
    expect($twin->code)->toBe($boardCode);

    $this->get(route('rooms.join', $boardCode))->assertOk()->assertInertia(fn (Assert $page) => $page->component('rooms/join'));
    $this->get(route('pult.code', $boardCode))->assertOk()->assertInertia(fn (Assert $page) => $page->component('pult/guest-board'));

    expect(Room::query()->whereKey($twin->id)->value('code'))->toBe($boardCode)
        ->and($board->refresh()->claimed_at)->toBeNull();
});

it('limits guessing per account, and says so on the code field', function (): void {
    $code = (string) $this->room->code;
    $unknown = ['code' => __('rooms.errors.unknown', [], 'az')];
    $tooMany = ['code' => __('rooms.errors.too_many', [], 'az')];

    $this->actingAs($this->player)->from(route('rooms.index'));

    // Ten tries of a code a minute, the three ways together.
    foreach (range(1, 4) as $i) {
        Rooms::assertRefused($this->get(route('rooms.join', str_pad((string) $i, 6, '9', STR_PAD_LEFT))));
        Rooms::assertRefused($this->post(route('rooms.join.store', str_pad((string) $i, 6, '8', STR_PAD_LEFT))));
    }

    $this->get(route('rooms.entrance', ['code' => '777 777']))->assertRedirect(route('rooms.index'))->assertSessionHasErrors($unknown);
    Rooms::assertRefused($this->get(route('rooms.join', '666666')));

    // Then even the right code waits: a link and «Qoşul» are answered at the
    // entrance, a typed code where it was typed — a sentence, not an error page.
    $this->get(route('rooms.join', $code))->assertRedirect(route('rooms.entrance'))->assertSessionHasErrors($tooMany);
    $this->post(route('rooms.join.store', $code))->assertRedirect(route('rooms.entrance'))->assertSessionHasErrors($tooMany);
    $this->get(route('rooms.entrance', ['code' => $code]))->assertRedirect(route('rooms.index'))->assertSessionHasErrors($tooMany);
    $this->postJson(route('rooms.join.store', $code))->assertTooManyRequests()->assertExactJson(['message' => $tooMany['code']]);
    expect(RoomPlayer::query()->where('user_id', $this->player->id)->exists())->toBeFalse();

    // The code field is no try: it opens however often, and shows the sentence.
    foreach (range(1, 12) as $_) {
        $this->get(route('rooms.entrance'))->assertOk();
    }

    $this->followingRedirects()->get(route('rooms.join', $code))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('rooms/join')
            ->where('code', null)
            ->where('errors', $tooMany));

    // Another account is not held back by this one, and reads the sentence
    // in its own language: the limit answers before the language is set.
    $english = User::factory()->player()->create(['locale' => 'en']);
    $this->actingAs($english)->get(route('rooms.join', $code))->assertOk();

    foreach (range(2, 10) as $_) {
        $this->get(route('rooms.join', $code))->assertOk();
    }

    app()->setLocale('az');
    $this->get(route('rooms.join', $code))
        ->assertRedirect(route('rooms.entrance'))
        ->assertSessionHasErrors(['code' => 'Too many tries. Try again in a minute.']);

    // A minute later the account may try again.
    $this->travel(61)->seconds();
    $this->actingAs($this->player)->post(route('rooms.join.store', $code))->assertRedirect(route('rooms.show', $this->room));
});

it('joins at /oyun', function (): void {
    $code = (string) $this->room->code;

    expect(route('rooms.entrance', [], false))->toBe('/oyun')
        ->and(route('rooms.join', $code, false))->toBe('/oyun/'.$code)
        ->and(route('rooms.join.store', $code, false))->toBe('/oyun/'.$code);

    $this->actingAs($this->player);
    // The code field's page knows its own address, whose origin its camera accepts (ADR 026 decision 15).
    $this->get('/oyun')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('rooms/join')
        ->where('code', null)
        ->where('entrance', rtrim((string) config('app.url'), '/').'/oyun'));
    $this->get('/oyun?code='.$code)->assertRedirect('/oyun/'.$code);
    $this->get('/oyun/'.$code)->assertOk()->assertInertia(fn (Assert $page) => $page->where('code', $code)->where('entrance', null));
    $this->post('/oyun/'.$code)->assertRedirect(route('rooms.show', $this->room));

    expect($this->room->rowOf($this->player)?->isPresent())->toBeTrue();

    // The QR and the line under it name the new address, on `APP_URL`; the link carries the room's tag (I2).
    $root = rtrim((string) config('app.url'), '/');
    $this->actingAs($this->host)->get(route('rooms.show', $this->room))
        ->assertInertia(fn (Assert $page) => $page->where('join', ['url' => $root.'/oyun/'.$code.'?t='.RoomJoinLink::tag($this->room), 'entry_url' => $root.'/oyun']));
    $this->get(route('rooms.index'))->assertInertia(fn (Assert $page) => $page->where('entrance', $root.'/oyun'));
});

it('sends the old /otaq addresses to the new ones', function (): void {
    $code = (string) $this->room->code;
    $this->actingAs($this->player);

    // Permanent, with the query kept; a code the old field sent is tried at the new one.
    $this->get('/otaq')->assertStatus(301)->assertRedirect(route('rooms.entrance'));
    $this->get('/otaq?code='.$code)->assertStatus(301)->assertRedirect(route('rooms.entrance', ['code' => $code]));
    $this->get('/otaq/'.$code)->assertStatus(301)->assertRedirect(route('rooms.join', $code));
    $this->get('/otaq/'.$code.'?from=qr')->assertStatus(301)->assertRedirect(route('rooms.join', ['code' => $code, 'from' => 'qr']));

    // The redirect tries no code and joins nobody; nothing posts to the old address.
    $this->post('/otaq/'.$code)->assertStatus(405);
    expect($this->room->rowOf($this->player))->toBeNull();

    // A visitor is sent to the login first, as at the new address.
    auth()->logout();
    $this->get('/otaq/'.$code)->assertRedirect(route('login'));
});

/*
 * The invitation link (docs/architecture.md §12 "The invitation link (I2)",
 * ADR 033 decisions 5–8): a link opened by hand joins at once, anything
 * else meets the join page; the link's tag keeps it to its own room; the
 * session's invitation carries the open to the end of a way in, once.
 */

/** A top-level navigation's fetch metadata, as a browser sends it. */
const JOIN_BY_HAND = ['Sec-Fetch-Site' => 'none', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'];

/** A tap on a link in another site's page (WhatsApp Web, a mail read in a browser). */
const JOIN_TAPPED = ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document', 'Sec-Fetch-User' => '?1'];

/**
 * An Inertia visit's headers, with the page's own version, as the app's
 * script sends them when it follows a redirect.
 *
 * @return array<string, string>
 */
function joinInertiaVisit(): array
{
    return ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
}

/** The room's link, with its tag. */
function invitationLink(Room $room): string
{
    return route('rooms.join', ['code' => (string) $room->code, 't' => RoomJoinLink::tag($room)]);
}

it('joins at once when a player opens the link by hand', function (array $headers): void {
    $this->actingAs($this->player)
        ->get(invitationLink($this->room), $headers)
        ->assertRedirect(route('rooms.show', $this->room));

    expect($this->room->rowOf($this->player)?->isPresent())->toBeTrue()
        ->and($this->room->refresh()->revision)->toBe(1)
        // Used at once: nothing is left to join later.
        ->and(session(RoomInvitation::KEY))->toBeNull();
})->with([
    'from another app, the camera or the address bar' => [JOIN_BY_HAND],
    'a tap on a link in a web page' => [JOIN_TAPPED],
]);

it('shows the join page to what is not opened by hand', function (string $method, array $headers): void {
    $this->actingAs($this->player);
    $before = joinSnapshot();

    if (array_key_exists('X-Inertia', $headers)) {
        $headers = [...$headers, ...joinInertiaVisit()];
    }

    $response = $this->call($method, invitationLink($this->room), [], [], [], $this->transformHeadersToServerVars($headers));

    expect($response->status())->toBe(200, 'never a redirect into the room')
        ->and(joinSnapshot())->toBe($before)
        ->and(session(RoomInvitation::KEY))->toBeNull();

    if ($method === 'GET' && ! array_key_exists('Accept', $headers) && ! array_key_exists('X-Inertia', $headers)) {
        $response->assertInertia(fn (Assert $page) => $page->component('rooms/join')->where('code', (string) $this->room->code));
    }
})->with([
    'a prefetch' => ['GET', [...JOIN_BY_HAND, 'Sec-Purpose' => 'prefetch']],
    'a prerender' => ['GET', [...JOIN_BY_HAND, 'Sec-Purpose' => 'prefetch;prerender']],
    'an older prefetch, or Inertia\'s' => ['GET', [...JOIN_BY_HAND, 'Purpose' => 'prefetch']],
    'Safari\'s preload' => ['GET', [...JOIN_BY_HAND, 'X-Purpose' => 'preview']],
    'Firefox\'s prefetch' => ['GET', [...JOIN_BY_HAND, 'X-Moz' => 'prefetch']],
    'HEAD' => ['HEAD', JOIN_BY_HAND],
    'an Inertia visit' => ['GET', [...JOIN_BY_HAND, 'X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest']],
    'a JSON client' => ['GET', [...JOIN_BY_HAND, 'Accept' => 'application/json']],
    'an <img>' => ['GET', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'no-cors', 'Sec-Fetch-Dest' => 'image']],
    'an iframe' => ['GET', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'iframe', 'Sec-Fetch-User' => '?1']],
    'a fetch' => ['GET', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty']],
    'a cross-site navigation without a tap' => ['GET', ['Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document']],
    'a same-site navigation without a tap' => ['GET', ['Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document']],
    'a browser without fetch metadata' => ['GET', []],
]);

it('reads only the headers a browser sets for the open by hand', function (array $headers, bool $byHand): void {
    $request = Request::create('/oyun/123456', 'GET', server: $this->transformHeadersToServerVars($headers));

    expect(RoomInvitation::openedByHand($request))->toBe($byHand);
})->with([
    'none' => [JOIN_BY_HAND, true],
    'a tap' => [JOIN_TAPPED, true],
    'a same-origin tap' => [[...JOIN_TAPPED, 'Sec-Fetch-Site' => 'same-origin'], true],
    'a speculative header, whatever its value' => [[...JOIN_BY_HAND, 'Sec-Purpose' => ''], false],
    'not a document' => [[...JOIN_BY_HAND, 'Sec-Fetch-Dest' => 'embed'], false],
    'not a navigation' => [[...JOIN_BY_HAND, 'Sec-Fetch-Mode' => 'no-cors'], false],
    'a user header that is not ?1' => [[...JOIN_TAPPED, 'Sec-Fetch-User' => '?0'], false],
    'nothing' => [[], false],
]);

it('joins at the end of a path an open by hand began, once', function (): void {
    $link = invitationLink($this->room);
    $newcomer = User::factory()->withoutRole()->create();

    // Opened by hand by an account without a role: kept, and off to take the role.
    $this->actingAs($newcomer)->get($link, JOIN_BY_HAND)->assertRedirect(route('role', ['role' => 'player']));
    expect(session(RoomInvitation::KEY))->toMatchArray(['code' => (string) $this->room->code, 'tag' => RoomJoinLink::tag($this->room)]);

    // The role taken, the intended address is the link; Inertia's follow of that redirect joins.
    $this->post(route('role.store'), ['role' => 'player', 'agreement' => '1'])->assertRedirect($link);
    $this->get($link, joinInertiaVisit())
        ->assertRedirect(route('rooms.show', $this->room));

    expect($this->room->rowOf($newcomer)?->isPresent())->toBeTrue()
        ->and(session(RoomInvitation::KEY))->toBeNull();

    // Used once: after leaving, the same link without a tap meets the join page.
    $this->post(route('rooms.leave', $this->room))->assertRedirect();
    $this->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page->component('rooms/join'));
    expect($this->room->rowOf($newcomer)?->isPresent())->toBeFalse();
});

it('keeps the invitation for another code and never pulls it with a speculative request', function (): void {
    $other = Rooms::open();
    $this->actingAs($this->player)->withSession([RoomInvitation::KEY => ['code' => (string) $other->code, 'tag' => null, 'until' => now()->addHour()->unix()]]);

    // Another room's code: not this invitation, the join page.
    $this->get(invitationLink($this->room))->assertOk()->assertInertia(fn (Assert $page) => $page->component('rooms/join'));
    expect(session(RoomInvitation::KEY))->not->toBeNull();

    // A prefetch of the invited code: no join, the invitation stays.
    $this->get(route('rooms.join', (string) $other->code), [...JOIN_BY_HAND, 'Sec-Purpose' => 'prefetch'])->assertOk();
    expect(session(RoomInvitation::KEY))->not->toBeNull()
        ->and($other->rowOf($this->player))->toBeNull();

    // A tag that is not the invitation's is not it either.
    $this->get(route('rooms.join', ['code' => (string) $other->code, 't' => RoomJoinLink::tag($this->room)]));
    expect(session(RoomInvitation::KEY))->not->toBeNull()
        ->and($other->rowOf($this->player))->toBeNull();
});

it('forgets the invitation after its minutes', function (): void {
    $link = invitationLink($this->room);
    $unconfirmed = User::factory()->player()->unverified()->create();

    // Opened by hand before the address is confirmed: kept, and off to the notice.
    $this->actingAs($unconfirmed)->get($link, JOIN_BY_HAND)->assertRedirect(route('verification.notice'));
    expect(session(RoomInvitation::KEY))->not->toBeNull();

    $unconfirmed->markEmailAsVerified();
    $this->travel(config('elifba.rooms.invitation_minutes') + 1)->minutes();

    // Confirmed an evening later: the join page and «Qoşul», nothing joined.
    $this->get($link, joinInertiaVisit())->assertOk();
    expect($this->room->rowOf($unconfirmed))->toBeNull()
        ->and(session(RoomInvitation::KEY))->toBeNull();
});

it('refuses a link whose tag is not the open room\'s, as an unknown code', function (): void {
    $code = (string) $this->room->code;
    $this->actingAs($this->player);

    foreach (['000000', 'abcdef', strtoupper(RoomJoinLink::tag($this->room)), RoomJoinLink::tag($this->room).'0'] as $tag) {
        Rooms::assertRefused($this->get(route('rooms.join', ['code' => $code, 't' => $tag]), JOIN_BY_HAND));
    }

    // A tag that is not text is no tag of the room either.
    Rooms::assertRefused($this->get('/oyun/'.$code.'?t[]=x', JOIN_BY_HAND));

    // An old link: the room closed, another drew the same code — its link names the old room.
    $old = invitationLink($this->room);
    app(CloseRoom::class)->handle($this->room);
    $stranger = Rooms::open();
    $stranger->forceFill(['code' => $code])->save();
    Rooms::assertRefused($this->get($old, JOIN_BY_HAND));

    expect(RoomPlayer::query()->where('user_id', $this->player->id)->exists())->toBeFalse();

    // The stranger's room's own link opens it.
    $this->get(invitationLink($stranger), JOIN_BY_HAND)->assertRedirect(route('rooms.show', $stranger));
});

it('joins without a tag', function (): void {
    // A typed address, an `/otaq/{code}` redirect, a QR of before.
    $this->actingAs($this->player)
        ->get(route('rooms.join', (string) $this->room->code), JOIN_BY_HAND)
        ->assertRedirect(route('rooms.show', $this->room));

    expect($this->room->rowOf($this->player)?->isPresent())->toBeTrue();
});

it('never joins the host by a link', function (): void {
    $room = Rooms::open($this->host, plays: false);
    $revision = $room->revision;

    // A host who stepped out goes to the room as they are: «Mən də oynayıram» alone changes it.
    $this->actingAs($this->host)->get(invitationLink($room), JOIN_BY_HAND)->assertRedirect(route('rooms.show', $room));
    $this->get(invitationLink($room))->assertRedirect(route('rooms.show', $room));

    expect($room->rowOf($this->host)?->isPresent() ?? false)->toBeFalse()
        ->and($room->refresh()->revision)->toBe($revision);
});

it('keeps the locked and full answers on the join page, and the unknown refusal, when opened', function (): void {
    $code = (string) $this->room->code;
    $this->actingAs($this->host)->patch(route('rooms.update', $this->room), ['locked' => true])->assertRedirect();

    // Locked: the join page with the reason, as after «Qoşul», and nothing else of the room.
    $this->actingAs($this->player)->get(invitationLink($this->room), JOIN_BY_HAND)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('rooms/join')
            ->where('code', $code)
            ->where('errors', ['code' => __('rooms.errors.locked')])
            ->missing('players'));

    // Full.
    $this->actingAs($this->host)->patch(route('rooms.update', $this->room), ['locked' => false]);
    Config::set('elifba.rooms.max_players', 1);
    $this->actingAs($this->player)->get(invitationLink($this->room), JOIN_BY_HAND)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('rooms/join')->where('errors', ['code' => __('rooms.errors.full')]));

    expect($this->room->rowOf($this->player))->toBeNull();

    // Removed, then closed: the one refusal.
    Config::set('elifba.rooms.max_players', 20);
    $row = Rooms::join($this->room, $this->player);
    app(RemoveRoomPlayer::class)->handle($this->room, $row);
    Rooms::assertRefused($this->actingAs($this->player)->get(invitationLink($this->room), JOIN_BY_HAND));

    $link = invitationLink($this->room);
    app(CloseRoom::class)->handle($this->room);
    Rooms::assertRefused($this->actingAs(User::factory()->player()->create())->get($link, JOIN_BY_HAND));
});

it('counts an open against room-join', function (): void {
    $this->actingAs($this->player);
    $tooMany = ['code' => __('rooms.errors.too_many', [], 'az')];

    for ($i = 0; $i < 10; $i++) {
        $this->get(route('rooms.join', '000000'), JOIN_BY_HAND);
    }

    // The eleventh, a real link opened by hand: refused by the limit, and nothing joined.
    $this->get(invitationLink($this->room), JOIN_BY_HAND)
        ->assertRedirect(route('rooms.entrance'))
        ->assertSessionHasErrors($tooMany);

    expect($this->room->rowOf($this->player))->toBeNull();
});
