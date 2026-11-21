<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Rooms;

/*
 * The privacy notice (docs/architecture.md §7): public, az and en, with the
 * retention period and the operator from `.env`; the section «Otaqlar» only
 * while the rooms switch is on (§12 "Adults' privacy", ADR 024).
 */

it('is public and shows the retention period and the operator', function (): void {
    config([
        'elifba.retention_days' => 400,
        'elifba.operator.name' => ' Məktəb №5 ',
        'elifba.operator.email' => 'elifba@example.az',
    ]);

    $this->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('retentionDays', 400)
            ->where('operator', ['name' => 'Məktəb №5', 'email' => 'elifba@example.az']));
});

it('sends no operator when none is configured', function (?string $name, ?string $email): void {
    config(['elifba.operator.name' => $name, 'elifba.operator.email' => $email]);

    $this->get(route('privacy'))
        ->assertInertia(fn (Assert $page) => $page->where('operator', ['name' => null, 'email' => null]));
})->with([
    'unset' => [null, null],
    'blank' => ['', '  '],
]);

it('is in Azerbaijani by default and in English when chosen', function (?string $locale, string $title, string $parents): void {
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', $locale ?? 'az')
            ->where('translations', fn ($translations): bool => $translations['privacy.title'] === $title
                && str_contains($translations['privacy.parents.note'], $parents)
                && str_contains($translations['privacy.how_long.body'], ':days')));
})->with([
    'az' => [null, 'Məxfilik qeydi', 'Hörmətli valideynlər'],
    'en' => ['en', 'Privacy note', 'Dear parents'],
]);

it('follows a teacher\'s own language', function (): void {
    $this->actingAs(User::factory()->create(['locale' => 'en']))
        ->get(route('privacy'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('has the section for parents in both languages', function (?string $locale, string $title, string $stored, string $classNote, string $intro): void {
    config(['elifba.children.max' => 10, 'elifba.retention_days' => 400]);
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);
    $parts = ['title', 'stored', 'why', 'who', 'not_shared', 'delete', 'how_long'];

    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('childrenMax', 10)
            ->where('retentionDays', 400)
            ->where('translations', function ($translations) use ($locale, $parts, $title, $stored, $classNote, $intro): bool {
                foreach ($parts as $part) {
                    if (trim((string) ($translations["privacy.home.{$part}"] ?? '')) === '') {
                        return false;
                    }
                }

                return $translations['privacy.home.title'] === $title
                    && str_contains($translations['privacy.home.stored'], $stored)
                    && str_contains($translations['privacy.home.stored'], ':max')
                    && str_contains($translations['privacy.home.how_long'], ':days')
                    // The note a teacher copies stays, under a title that says it is for a class.
                    && $translations['privacy.parents.title'] === $classNote
                    // The introduction names both uses; the Google paragraph speaks of the account.
                    && str_contains($translations['privacy.intro'], $intro)
                    && ! str_contains($translations['privacy.google.body'], $locale === 'en' ? 'teacher' : 'müəllim');
            }));
})->with([
    'az' => [null, 'Evdə: valideynlər və uşaqları', 'yalnız ad (soyadsız)', 'Sinif valideynləri üçün qeyd', 'valideynlərin'],
    'en' => ['en', 'At home: parents and their children', 'a first name only (no surname)', 'A note for a class\'s parents', 'parents'],
]);

it('says what the board says aloud, a name only from the built-in list and sent nowhere, in both languages', function (?string $locale, string $title, string $list, string $note): void {
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('translations', fn ($translations): bool => $translations['privacy.voice.title'] === $title
                && str_contains($translations['privacy.voice.body'], $list)
                // The note a teacher copies for a class's parents says it too.
                && str_contains($translations['privacy.parents.note'], $note)));
})->with([
    'az' => [null, 'Lövhənin səsi', 'hazır ad lüğətindədirsə', 'ad heç yerə göndərilmir'],
    'en' => ['en', "The board's voice", 'built-in name list', 'the name is sent nowhere'],
]);

it('has the section for rooms in both languages when the switch is on', function (?string $locale, string $title, string $intro, string $stored, string $who, string $age, string $contact, string $profile): void {
    Rooms::on();
    config(['elifba.retention_days' => 400, 'elifba.rooms.idle_hours' => 3]);
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    // The parts the page draws, in its order (ADR 024 decision 10).
    preg_match('/const ROOMS = \[(.*?)\];/s', (string) file_get_contents(resource_path('js/pages/privacy.tsx')), $list);
    preg_match_all("/'([a-z_]+)'/", $list[1] ?? '', $parts);

    expect($parts[1])->toBe(['what', 'camera', 'share', 'stored', 'not_stored', 'why', 'who', 'voice', 'contact', 'how_long', 'delete', 'where']);

    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('rooms', ['idleHours' => 3])
            ->where('retentionDays', 400)
            ->where('translations', function ($translations) use ($parts, $title, $intro, $stored, $who, $age, $contact, $profile): bool {
                foreach (['intro', 'title', ...$parts[1]] as $part) {
                    if (trim((string) ($translations["privacy.rooms.{$part}"] ?? '')) === '') {
                        return false;
                    }
                }

                return $translations['privacy.rooms.title'] === $title
                    // The introduction names the third use.
                    && str_contains($translations['privacy.rooms.intro'], $intro)
                    // What is stored for an account that plays and for a room.
                    && str_contains($translations['privacy.rooms.stored'], $stored)
                    && str_contains($translations['privacy.rooms.stored'], $translations['roles.player.title'])
                    // Who sees a name, and for how long.
                    && str_contains($translations['privacy.rooms.who'], $who)
                    && str_contains($translations['privacy.rooms.how_long'], ':hours')
                    && str_contains($translations['privacy.rooms.how_long'], ':days')
                    // No age, and no way to write to another player.
                    && str_contains($translations['privacy.rooms.contact'], $age)
                    && str_contains($translations['privacy.rooms.contact'], $contact)
                    // The four ways out, by the names the room pages give them (ADR 022 decision 7).
                    && str_contains($translations['privacy.rooms.delete'], $translations['rooms.show.forget'])
                    && str_contains($translations['privacy.rooms.delete'], $translations['rooms.show.delete'])
                    && str_contains($translations['privacy.rooms.delete'], $profile)
                    && str_contains($translations['privacy.rooms.delete'], ':days');
            }));

    // The operator closes the section, as it closes the notice.
    expect((string) file_get_contents(resource_path('js/pages/privacy.tsx')))
        ->toMatch('/data-test="rooms".*?<Operator \{\.\.\.operator\} \/>.*?<\/section>/s');
})->with([
    'az' => [null, 'Böyüklərin oyunları', '«Böyüklərin oyunları» bölməsindədir', 'razılığının verildiyi vaxt', 'otaqların aparıcıları və oyunçuları görür', 'yaşını soruşmur', 'bir-birinə yazması', 'Ayarlar → Profil'],
    'en' => ['en', 'Games for adults', 'the section “Games for adults” below', 'when its agreement was given', 'seen only by the hosts and the players', "asks no one's age", 'write to each other', 'Settings → Profile'],
]);

it('says what a taunt is, what it costs, who sees it and how it is hidden', function (?string $locale, array $contact, string $stored): void {
    Rooms::on();
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('translations', function ($translations) use ($contact, $stored): bool {
                foreach ($contact as $words) {
                    if (! str_contains($translations['privacy.rooms.contact'], $words)) {
                        return false;
                    }
                }

                // Kept: who threw what at whom and when; the target forgotten after a day, the cost kept.
                return str_contains($translations['privacy.rooms.stored'], $stored)
                    && substr_count($translations['privacy.rooms.stored'], "\n") === 3
                    // The player role's sentence does not change for it (ADR 029 decision 30).
                    && ! str_contains(mb_strtolower($translations['roles.agreement.player']), 'atmaca')
                    && ! str_contains(mb_strtolower($translations['roles.agreement.player']), 'taunt');
            }));
})->with([
    'az' => [null, ['mesaj, çat və ya dəvət göndərilmir', 'yalnız hazır siyahıdan atmaca', 'hər atmaca atana 1 xal başa gəlir', 'öz cihazında gizlədə', 'otaqda bağlaya bilər'], 'kimə atıldığı bir gündən sonra silinir'],
    'en' => ['en', ['no message, chat or invitation is sent', 'only a taunt from a ready-made list', 'each costs the thrower 1 point', 'hide taunts on their own device', 'switch them off for the room'], 'whom it was aimed at is erased after a day'],
]);

it('says a taunt is seen and heard only by its target and the big screen', function (?string $locale, string $clause): void {
    Rooms::on();
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    // ADR 029 decision 52: one clause of the notice changes, in both languages; nothing new is stored.
    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('translations', static fn ($translations): bool => str_contains($translations['privacy.rooms.contact'], $clause)
                && ! str_contains($translations['privacy.rooms.contact'], '4 sec')
                && ! str_contains($translations['privacy.rooms.contact'], '4 san')
                && substr_count($translations['privacy.rooms.stored'], "\n") === 3));
})->with([
    'az' => [null, 'hər atmaca atana 1 xal başa gəlir; onu bir neçə saniyə yalnız hədəf olan oyunçu və aparıcının böyük ekranı görür və əvvəlcədən yazılmış səslə eşidir.'],
    'en' => ['en', "each costs the thrower 1 point, and only the player it is aimed at and the host's big screen see it, and hear it in a prerecorded voice, for a few seconds."],
]);

it('says that the screen, or the phones without one, may say the winner\'s name from the list, and sends it nowhere', function (?string $locale, string $winner, string $list, string $nowhere, string $phones): void {
    Rooms::on();
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    // ADR 030 decision 8: one paragraph, after who sees a name.
    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('translations', fn ($translations): bool => str_contains($translations['privacy.rooms.voice'], $winner)
                && str_contains($translations['privacy.rooms.voice'], $list)
                && str_contains($translations['privacy.rooms.voice'], $nowhere)
                && str_contains($translations['privacy.rooms.voice'], $phones)
                // The screen's toggle, by the name it has there.
                && str_contains($translations['privacy.rooms.voice'], $translations['rooms.sound.label'])));
})->with([
    'az' => [null, 'qalibin adını', 'hazır ad lüğətindədirsə', 'heç bir xidmətə göndərilmir və bunun üçün heç nə saxlanılmır', 'oyunçuların telefonları'],
    'en' => ['en', "the winner's first name", 'built-in name list', 'sent to no service and nothing is stored for it', "the players' phones"],
]);

it('says what the camera does with the picture', function (?string $locale, string $optional, string $asks, string $nowhere): void {
    Rooms::on();
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('translations', fn ($translations): bool => str_contains($translations['privacy.rooms.camera'], $optional)
                && str_contains($translations['privacy.rooms.camera'], $asks)
                && str_contains($translations['privacy.rooms.camera'], $nowhere)
                // The scanner says the same where the camera opens.
                && str_contains($translations['rooms.scan.privacy'], $locale === null ? 'saxlanılmır' : 'not kept')));
})->with([
    'az' => [null, 'istəyə bağlıdır', 'brauzer əvvəlcə icazə soruşur', 'heç yerə göndərilmir və saxlanılmır'],
    'en' => ['en', 'is optional', 'the browser asks first', 'it is sent nowhere and is not kept'],
]);

it('says what WhatsApp gets when a game\'s link is sent, in both languages', function (?string $locale, array $share, array $what): void {
    Rooms::on();
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('translations', function ($translations) use ($share, $what): bool {
                // The button, the code and no name, WhatsApp's own rules, no request, the preview, an hour, the close (ADR 033 decision 4).
                foreach ($share as $words) {
                    if (! str_contains($translations['privacy.rooms.share'], $words)) {
                        return false;
                    }
                }

                // The link and the separate sentence of a newcomer (ADR 033 decision 18).
                foreach ($what as $words) {
                    if (! str_contains($translations['privacy.rooms.what'], $words)) {
                        return false;
                    }
                }

                return str_contains($translations['privacy.rooms.share'], $translations['rooms.share.whatsapp'])
                    && str_contains($translations['privacy.rooms.share'], $translations['rooms.share.share']);
            }));
})->with([
    'az' => [null, ['istəyə bağlıdır', 'oyunun kodu var, heç kimin adı yoxdur', 'öz qaydaları ilə', 'Elifba özü WhatsApp-a heç nə göndərmir', 'önizləməsini', 'ən çox bir saat', 'Otaq bağlananda link işləməz'], ['və ya linki ilə qoşulur', 'linki toxunaraq açan hesab dərhal qoşulur', 'məxfilik qeydindən ayrıca təsdiqləyir']],
    'en' => ['en', ['is optional', "the game's code and nobody's name", 'under its own rules', 'Elifba itself sends nothing to WhatsApp', 'preview', 'for an hour at most', 'stops working when the room closes'], ['or its link', 'opens the link with a tap joins at once', 'separately from the privacy note']],
]);

it('says nothing of rooms when it is off', function (): void {
    // `Tests\TestCase` leaves the switch off, as an instance has it.
    foreach ([null, User::factory()->teacherAndPlayer()->create()] as $account) {
        $request = $account === null ? $this : $this->actingAs($account);

        $request->get(route('privacy'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('privacy')
                ->missing('rooms')
                // The sections for teachers and parents and the class note stay.
                ->has('retentionDays')
                ->has('childrenMax')
                ->has('operator'));
    }

    // Every part of the section and the introduction's sentence are drawn
    // only under `rooms`, which the page gets only while the switch is on.
    $page = (string) file_get_contents(resource_path('js/pages/privacy.tsx'));
    preg_match_all('/privacy\.rooms\./', $page, $uses);

    expect($uses[0])->toHaveCount(5)
        ->and($page)->toContain("{rooms && ` \${t('privacy.rooms.intro')}`}")
        ->toMatch('/\{rooms && \(\s*<section[^>]*data-test="rooms"/s');
});

it('says what the live voice is, that it is not recorded and what the operator could hear, only while the voice switch is on', function (?string $locale, array $part, string $stored, string $where, string $contact): void {
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);
    config(['elifba.rooms.idle_hours' => 3]);

    // The rooms switch alone: the section as before, without a word of the voice.
    Rooms::on();
    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('privacy')->where('rooms', ['idleHours' => 3]));

    // The voice switch too (ADR 031 decisions 16–17): `voice` in the prop.
    Rooms::voiceOn();
    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('rooms', ['idleHours' => 3, 'voice' => true])
            ->where('translations', function ($translations) use ($part, $stored, $where, $contact): bool {
                $voice = $translations['privacy.rooms.live_voice'];

                foreach ($part as $words) {
                    if (! str_contains($voice, $words)) {
                        return false;
                    }
                }

                // Five paragraphs, a line each; the three sentences in their voice forms.
                return substr_count($voice, "\n") === 4
                    && str_contains($translations['privacy.rooms.not_stored_voice'], $translations['privacy.rooms.not_stored'])
                    && str_contains($translations['privacy.rooms.not_stored_voice'], $stored)
                    && str_contains($translations['privacy.rooms.where_voice'], $where)
                    && str_contains($translations['privacy.rooms.contact_voice'], $contact)
                    // Without the voice they stay as they were: nothing of it there.
                    && ! str_contains($translations['privacy.rooms.not_stored'], $stored)
                    && ! str_contains($translations['privacy.rooms.where'], $where)
                    && ! str_contains($translations['privacy.rooms.contact'], $contact);
            }));

    // The page draws the part and the three voice forms only from `rooms.voice`,
    // inside the section and before the operator, who closes it.
    $page = (string) file_get_contents(resource_path('js/pages/privacy.tsx'));

    expect($page)->toMatch("/const VOICE_FORMS = \\['contact', 'not_stored', 'where'\\];/")
        ->toContain('rooms.voice && VOICE_FORMS.includes(part)')
        ->toMatch('/data-test="rooms".*?\{rooms\.voice && \(\s*<div[^>]*data-test="live-voice".*?privacy\.rooms\.live_voice_title.*?privacy\.rooms\.live_voice\'.*?<Operator \{\.\.\.operator\} \/>/s')
        ->and(substr_count($page, 'rooms.voice'))->toBe(2);
})->with([
    'az' => [null, [
        'Canlı səs hər otaqda açıq başlayır',
        'Mikrofon sönülü başlayır və onu yalnız oyunçu özü açır',
        'səhifəni yeniləyəndə əvvəl açıq idisə, açıq qalır',
        'Səs yazılmır, mətnə çevrilmir və saxlanılmır',
        'Böyük ekran səsi nə səsləndirir, nə də dinləyir',
        'Uçdan-uca şifrələmə yoxdur: serveri idarə edən, hər bir zəng serverində olduğu kimi, texniki cəhətdən canlı səsə çıxış əldə edə bilər',
        'hesabda razılığın verildiyi vaxt',
        'ad və səs yox',
    ], 'Canlı səs də saxlanılmır', 'öz səs serverindən keçir', 'canlı səsdən dərhal kəsilir'],
    'en' => ['en', [
        'The live voice starts on in every room',
        'The microphone starts off and only the player turns it on',
        'if it was on when you reload the page, it stays on',
        'The voice is not recorded, not turned into text and not kept',
        'The big screen neither plays the voice nor listens',
        'There is no end-to-end encryption: whoever runs the server could technically get access to the live voice, as with any call server',
        'with the account, the moment it agreed',
        'no name and no voice',
    ], 'The live voice is not stored either', "this instance's own voice server", 'cut from the live voice at once'],
]);

it('names what a board game keeps: its moves, its offers and a resignation', function (?string $locale, string $moves): void {
    Rooms::on();
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    // ADR 034 decision 34: the acts of a match are stored; the notice names them, on the answers' line.
    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('translations', static fn ($translations): bool => str_contains($translations['privacy.rooms.stored'], $moves)
                && substr_count($translations['privacy.rooms.stored'], "\n") === 3));
})->with([
    'az' => [null, 'Şaşkidə: hər gediş xanaları ilə, nə vaxt edildiyi, heç-heçə təklifləri və onların cavabı, təslim olma.'],
    'en' => ['en', 'In draughts: each move with its squares and when it was made, draw offers and their answers, and resigning.'],
]);

it('says a player\'s «Hazıram» is kept in the room\'s line, and until when it counts', function (?string $locale, string $clause): void {
    Rooms::on();
    $request = $locale === null ? $this : $this->withSession(['locale' => $locale]);

    // ADR 035 decision 11: the «Otaq:» line names the mark; the four lines stay four.
    $request->get(route('privacy'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('privacy')
            ->where('translations', static function ($translations) use ($clause): bool {
                $lines = explode("\n", $translations['privacy.rooms.stored']);

                return count($lines) === 4 && str_contains($lines[1], $clause);
            }));
})->with([
    'az' => [null, 'oyunlar arasında kimin «Hazıram» dediyi (növbəti oyun başlayana qədər sayılır, oyunçu çıxanda silinir)'],
    'en' => ['en', 'who said “I\'m ready” between games (it counts until the next game starts and is erased when the player leaves)'],
]);
