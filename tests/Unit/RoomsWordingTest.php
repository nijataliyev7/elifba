<?php

use App\Domain\Language\Support\Az;

/*
 * No room text promises a benefit (docs/architecture.md §12 "No promise",
 * ADR 023 decision 12, ADR 024 decision 8): no string of the player area,
 * of the welcome page's section for adults' games, of the privacy notice's
 * «Böyüklərin oyunları» or of the READMEs' «Böyüklər üçün oyunlar» / "Games
 * for adults" (ADR 026 decision 5) says that a game improves memory, the
 * mind, attention or health. A game is named by what one does.
 */

/**
 * Stems of a promise, matched inside words: «inkişaf» catches «inkişaf
 * etdirir», "improve" catches "improves". Each is a stem that no room text
 * needs for anything else.
 */
const ROOM_PROMISES = [
    // Azerbaijani: develop, strengthen, healthy, improve, sharpen, exercise,
    // intelligence, benefit, cure, dementia, rejuvenate, brain.
    'inkişaf', 'gücləndir', 'güclənir', 'sağlam', 'yaxşılaşdır', 'yaxşılaşır', 'itiləşdir', 'məşq',
    'zəka', 'intellekt', 'fayda', 'müalicə', 'demensiya', 'alsheymer', 'cavanlaş', 'beyin', 'beyn',
    // English.
    'improve', 'boost', 'train your', 'brain', 'sharpen', 'enhance', 'cognitive', 'smarter',
    'intelligence', 'health', 'benefit', 'dementia', 'alzheimer', 'workout', 'keep your mind', 'mental',
];

/**
 * The stems of a promise a text holds, whatever its case: an Azerbaijani
 * text is lowered with Azerbaijani casing («İnkişaf» → «inkişaf»), an
 * English one with the plain rules ("IMPROVE" → "improve"), and a stem
 * found by either counts.
 *
 * @return list<string>
 */
function promisesInRoomText(string $text): array
{
    $lowered = [Az::lower($text), mb_strtolower($text)];

    return array_values(array_filter(
        ROOM_PROMISES,
        static fn (string $stem): bool => array_any($lowered, static fn (string $lower): bool => str_contains($lower, $stem)),
    ));
}

/**
 * A README's section for rooms: from its heading to the next one. Its other
 * sections are not room texts — README.az.md's «İnkişaf» is "Develop".
 */
function roomSectionOf(string $readme, string $heading): string
{
    $text = (string) file_get_contents(base_path($readme));
    $start = strpos($text, "\n## {$heading}\n");

    expect($start)->not->toBeFalse("{$readme} has no section «{$heading}»");

    $end = strpos($text, "\n## ", (int) $start + 1);

    return substr($text, (int) $start, $end === false ? null : $end - (int) $start);
}

it('promises no benefit in any room text', function (): void {
    $texts = [];

    foreach (['az', 'en'] as $locale) {
        /** @var array<string, string> $translations */
        $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);

        foreach ($translations as $key => $text) {
            $room = str_starts_with($key, 'rooms.')
                || str_starts_with($key, 'welcome.rooms.')
                || str_starts_with($key, 'privacy.rooms.')
                || str_starts_with($key, 'roles.player.')
                || in_array($key, ['roles.agreement.player', 'roles.settings.add.player', 'nav.rooms', 'nav.rooms_past'], true);

            if ($room) {
                $texts["{$locale}: {$key}"] = $text;
            }
        }
    }

    $texts['README.md: Games for adults'] = roomSectionOf('README.md', 'Games for adults');
    $texts['README.az.md: Böyüklər üçün oyunlar'] = roomSectionOf('README.az.md', 'Böyüklər üçün oyunlar');

    expect(count($texts))->toBeGreaterThan(300);

    $found = array_filter(array_map(promisesInRoomText(...), $texts));

    expect($found)->toBe([], 'docs/architecture.md §12 "No promise": a room text names what one does, never a benefit.');
});

it('finds a promise however it is cased, and nothing in a plain name', function (): void {
    expect(promisesInRoomText('YADDAŞINIZI İNKİŞAF ETDİRİN'))->toBe(['inkişaf'])
        ->and(promisesInRoomText('Beyninizi gücləndirin'))->toBe(['gücləndir', 'beyn'])
        ->and(promisesInRoomText('Sağlam düşüncə'))->toBe(['sağlam'])
        ->and(promisesInRoomText('IMPROVES your Memory'))->toBe(['improve'])
        ->and(promisesInRoomText('Train your BRAIN'))->toBe(['train your', 'brain'])
        ->and(promisesInRoomText('Artıq olanı tap · Ardıcıllığı yadda saxla · İki sıra'))->toBe([])
        ->and(promisesInRoomText('Remember the order · Find the odd one out'))->toBe([]);
});

it('says «otaq» only where the thing itself is named', function (): void {
    // ADR 026 decision 2: «oyun» is what a person does; «otaq» only where a
    // text must name the thing that holds the players and several games —
    // the host's tools, the standing across games, a closed room, the
    // player role's sentence and `/privacy`.
    $allowed = [
        'roles.agreement.player', 'roles.player.description',
        'rooms.flash.deleted', 'rooms.game.standing',
        'rooms.show.close', 'rooms.show.close_title', 'rooms.show.close_description', 'rooms.show.closed',
        'rooms.show.delete', 'rooms.show.delete_title', 'rooms.show.delete_line', 'rooms.show.forget_line',
        'rooms.show.locked', 'rooms.show.no_games', 'rooms.show.remove_description', 'rooms.show.tools',
    ];

    /** @var array<string, string> $az */
    $az = json_decode((string) file_get_contents(lang_path('az.json')), true);
    $found = [];

    foreach ($az as $key => $text) {
        $area = str_starts_with($key, 'rooms.') || str_starts_with($key, 'welcome.rooms.') || str_starts_with($key, 'nav.rooms')
            || str_starts_with($key, 'roles.player.') || $key === 'roles.agreement.player';

        if ($area && ! in_array($key, $allowed, true) && preg_match('/\botaq|\botağ/u', Az::lower($text)) === 1) {
            $found[] = $key;
        }
    }

    expect($found)->toBe([])
        ->and(Az::lower(roomSectionOf('README.az.md', 'Böyüklər üçün oyunlar')))->not->toContain('«otaq aç»')
        ->and($az)->not->toHaveKey('rooms.show.title');
});

it('words the rivalry strip in exactly the strings ADR 027 and ADR 034 list, in both languages', function (): void {
    // ADR 027 decision 19: the strip's and the lanes' words, nothing more; ADR 034 decision 23: a match's gap in pieces.
    $keys = ['ahead', 'away', 'behind', 'label', 'leads', 'level', 'level_others', 'more', 'more_short', 'no_points', 'pieces_ahead', 'pieces_behind', 'pieces_leads', 'took_lead', 'took_lead_you', 'you'];

    foreach (['az', 'en'] as $locale) {
        /** @var array<string, string> $translations */
        $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);
        $rivalry = array_filter($translations, static fn (string $key): bool => str_starts_with($key, 'rooms.rivalry.'), ARRAY_FILTER_USE_KEY);

        expect(array_keys($rivalry))->toEqualCanonicalizing(array_map(static fn (string $key): string => "rooms.rivalry.{$key}", $keys), $locale)
            ->and(array_filter(array_map(promisesInRoomText(...), $rivalry)))->toBe([], $locale);
    }
});

/**
 * The live voice's words as ADR 031 writes them, read from the record
 * itself so that a sentence changes in the decision first: the table
 * "Words" (a row may name several keys, `.state.away`, `.muted`, …, whose
 * texts it parts with « · »; a short key takes the first one's parent),
 * decision 14's sentence and its three notes, decision 15's welcome line,
 * decision 16's three voice forms, decision 17's part «Canlı səs» (its five
 * paragraphs a line each) and decision 25's two words of the cue toggle.
 *
 * @return array{az: array<string, string>, en: array<string, string>}
 */
function liveVoiceWordsOfAdr031(): array
{
    $adr = (string) file_get_contents(base_path('docs/decisions/031-live-voice-in-rooms.md'));
    $between = static function (string $from, string $to) use ($adr): string {
        $start = strpos($adr, $from);
        expect($start)->not->toBeFalse("ADR 031 has no «{$from}»");
        $end = strpos($adr, $to, (int) $start + strlen($from));

        return substr($adr, (int) $start, $end === false ? null : $end - (int) $start);
    };
    $cells = static fn (string $line): array => array_map(trim(...), explode('|', trim(trim($line), '|')));
    $words = ['az' => [], 'en' => []];

    foreach (explode("\n", $between("\n## Words\n", "\n## Routes\n")) as $line) {
        if (! str_starts_with($line, '| `.')) {
            continue;
        }

        [$keys, $az, $en] = $cells($line);
        $keys = array_map(static fn (string $key): string => trim($key, ' `'), explode(',', $keys));
        $parent = substr($keys[0], 0, (int) strrpos($keys[0], '.'));
        $keys = array_map(static fn (string $key, int $at): string => 'rooms.voice'.($at > 0 && substr_count($key, '.') === 1 ? $parent : '').$key, $keys, array_keys($keys));
        [$az, $en] = [explode(' · ', $az), explode(' · ', $en)];

        expect($az)->toHaveCount(count($keys), $line)->and($en)->toHaveCount(count($keys), $line);

        foreach ($keys as $at => $key) {
            [$words['az'][$key], $words['en'][$key]] = [$az[$at], $en[$at]];
        }
    }

    $table = static function (string $part, string $key) use ($cells, &$words): void {
        foreach (explode("\n", $part) as $line) {
            if (preg_match('/^\s*\| (az|en) +\|/', $line) === 1) {
                [$locale, $text] = $cells($line);
                $words[$locale][$key] = $text;
            }
        }
    };

    $decision14 = $between("\n14. ", "\n15. ");
    $table($decision14, 'rooms.voice.agreement');
    preg_match_all('/`rooms\.voice\.note\.(\w+)` «(.+?)» \/ "(.+?)"/u', $decision14, $notes, PREG_SET_ORDER);

    foreach ($notes as [, $key, $az, $en]) {
        [$words['az']["rooms.voice.note.{$key}"], $words['en']["rooms.voice.note.{$key}"]] = [$az, $en];
    }

    $table($between("\n15. ", "\n16. "), 'welcome.rooms.names_voice');

    foreach (explode("\n", $between("\n16. ", "\n17. ")) as $line) {
        if (preg_match('/^\s*\| `(privacy\.rooms\.\w+_voice)`/', $line, $key) === 1) {
            [, $words['az'][$key[1]], $words['en'][$key[1]]] = $cells($line);
        }
    }

    $decision17 = $between("\n17. ", "\n### ");
    preg_match('/`privacy\.rooms\.live_voice_title` «(.+?)» \/ "(.+?)"/u', $decision17, $title);
    [, $words['az']['privacy.rooms.live_voice_title'], $words['en']['privacy.rooms.live_voice_title']] = $title;

    foreach (['az' => ['**az**', '**en**'], 'en' => ['**en**', '`privacy.rooms.contact_voice`']] as $locale => [$from, $to]) {
        $part = substr($decision17, (int) strpos($decision17, $from), (int) strpos($decision17, $to) - (int) strpos($decision17, $from));
        preg_match_all('/^\s*> (\S.*)$/mu', $part, $paragraphs);

        expect($paragraphs[1])->toHaveCount(5, "ADR 031 decision 17 ({$locale})");
        $words[$locale]['privacy.rooms.live_voice'] = implode("\n", $paragraphs[1]);
    }

    preg_match('/`rooms\.sound\.label` «(.+?)» \/ "(.+?)", `rooms\.sound\.unlock` «(.+?)» \/ "(.+?)"/u', $between('**Two words apart.**', "\n26. "), $sound);
    [, $words['az']['rooms.sound.label'], $words['en']['rooms.sound.label'], $words['az']['rooms.sound.unlock'], $words['en']['rooms.sound.unlock']] = $sound;

    return $words;
}

it('words the live voice in exactly the strings ADR 031 lists, in both languages', function (): void {
    // ADR 031 "Words" and decisions 14–17 and 25; docs/frontend.md rule 15 "Live voice" → "Words".
    $adr = liveVoiceWordsOfAdr031();

    expect($adr['az'])->toHaveCount(61)->and(array_keys($adr['en']))->toBe(array_keys($adr['az']));

    foreach (['az', 'en'] as $locale) {
        /** @var array<string, string> $translations */
        $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);
        $voice = array_filter($translations, static fn (string $key): bool => str_starts_with($key, 'rooms.voice.'), ARRAY_FILTER_USE_KEY);
        $listed = array_filter($adr[$locale], static fn (string $key): bool => str_starts_with($key, 'rooms.voice.'), ARRAY_FILTER_USE_KEY);

        // No key the record does not list, and every text as it writes it.
        expect(array_keys($voice))->toEqualCanonicalizing(array_keys($listed), $locale);

        foreach ($adr[$locale] as $key => $text) {
            expect($translations[$key] ?? null)->toBe($text, "{$locale}: {$key}");
        }

        expect(array_filter(array_map(promisesInRoomText(...), $voice)))->toBe([], $locale);
    }

    // «otaq» is in none of the voice's words, nor in the welcome page's line
    // (ADR 026 decision 2); `/privacy` names the room, as it does everywhere.
    $named = array_filter(
        $adr['az'],
        static fn (string $text, string $key): bool => ! str_starts_with($key, 'privacy.') && preg_match('/\botaq|\botağ/u', Az::lower($text)) === 1,
        ARRAY_FILTER_USE_BOTH,
    );

    expect($named)->toBe([]);
});

it('says that the voice is not recorded wherever it is offered', function (): void {
    // ADR 031 decisions 14, 15, 17 and "Words": the sentence one agrees to,
    // the host's switch, the welcome page's line and `/privacy`'s part.
    $said = ['az' => 'yazılmır', 'en' => 'not recorded'];

    foreach ($said as $locale => $words) {
        /** @var array<string, string> $translations */
        $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);

        foreach (['rooms.voice.agreement', 'rooms.voice.host_off', 'welcome.rooms.names_voice', 'privacy.rooms.live_voice'] as $key) {
            expect(str_contains(mb_strtolower($translations[$key]), $words))->toBeTrue("{$locale}: {$key}");
        }
    }
});

it('leaves the player role\'s sentence word for word', function (): void {
    // ADR 031 decision 14: the voice has a sentence of its own; the player
    // role's stays as architecture §7 "Agreements" quotes it (ADR 019).
    $architecture = (string) file_get_contents(base_path('docs/architecture.md'));

    preg_match('/a fourth moment, offered only while `elifba\.rooms\.enabled` is on: taking the player role, on the same pages — «(.+?)» \/ "(.+?)"/u', $architecture, $quoted);

    expect($quoted)->toHaveCount(3);

    foreach (['az' => $quoted[1], 'en' => $quoted[2]] as $locale => $sentence) {
        /** @var array<string, string> $translations */
        $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);

        expect($translations['roles.agreement.player'])->toBe($sentence)
            ->and($translations['rooms.voice.agreement'])->not->toBe($sentence)
            ->and($translations['rooms.voice.agreement'])->not->toContain($sentence)
            ->and($sentence)->not->toContain($translations['rooms.voice.agreement']);
    }
});

it('words the invitation link in exactly the strings ADR 033 lists, in both languages', function (): void {
    // ADR 033 decision 18: each new or changed key, az and en, as its table writes it.
    $adr = (string) file_get_contents(base_path('docs/decisions/033-invitation-link.md'));
    preg_match_all('/^\s*\| [+~] `([a-z_.]+)`\s*\|(.*)\|(.*)\|\s*$/mu', $adr, $rows, PREG_SET_ORDER);
    $listed = ['az' => [], 'en' => []];

    foreach ($rows as [, $key, $az, $en]) {
        [$listed['az'][$key], $listed['en'][$key]] = [trim($az), trim($en)];
    }

    expect(array_keys($listed['az']))->toContain('rooms.share.text', 'rooms.invited.title', 'privacy.rooms.share', 'privacy.rooms.what');

    foreach (['az', 'en'] as $locale) {
        /** @var array<string, string> $translations */
        $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);
        $own = array_filter($translations, static fn (string $key): bool => str_starts_with($key, 'rooms.share.') || str_starts_with($key, 'rooms.invited.'), ARRAY_FILTER_USE_KEY);

        // The share row's and the door's keys are the table's, nothing more.
        expect(array_keys($own))->toEqualCanonicalizing(array_values(array_filter(array_keys($listed[$locale]), static fn (string $key): bool => ! str_starts_with($key, 'privacy.'))), $locale);

        foreach ($listed[$locale] as $key => $text) {
            expect($translations[$key] ?? null)->toBe($text, "{$locale}: {$key}")
                ->and(promisesInRoomText($text))->toBe([], "{$locale}: {$key}");
        }

        // The message names nobody: the link alone fills it.
        expect($translations['rooms.share.text'])->toContain(':url')
            ->and(preg_match_all('/:[a-z_]+/', $translations['rooms.share.text']))->toBe(1);
    }

    // «oyun» for what a person does; «otaq» only on `/privacy` (ADR 026 decision 2).
    foreach ($listed['az'] as $key => $text) {
        if (! str_starts_with($key, 'privacy.')) {
            expect(preg_match('/\botaq|\botağ/u', Az::lower($text)))->toBe(0, $key);
        }
    }
});

it('words the ready mark in exactly the strings ADR 035 lists, in both languages', function (): void {
    // ADR 035 decision 12: each new or changed key, az and en, as its table writes it (a line break as `\n`).
    $adr = (string) file_get_contents(base_path('docs/decisions/035-ready-for-the-next-game.md'));
    preg_match_all('/^\s*\| [+~] `([a-z_.]+)`\s*\|(.*)\|(.*)\|\s*$/mu', $adr, $rows, PREG_SET_ORDER);
    $listed = ['az' => [], 'en' => []];

    foreach ($rows as [, $key, $az, $en]) {
        [$listed['az'][$key], $listed['en'][$key]] = [str_replace('\n', "\n", trim($az)), str_replace('\n', "\n", trim($en))];
    }

    expect(array_keys($listed['az']))->toContain('rooms.ready.button', 'rooms.ready.count', 'privacy.rooms.stored');

    foreach (['az', 'en'] as $locale) {
        /** @var array<string, string> $translations */
        $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);
        $own = array_filter($translations, static fn (string $key): bool => str_starts_with($key, 'rooms.ready.'), ARRAY_FILTER_USE_KEY);

        // The mark's keys are the table's, nothing more.
        expect(array_keys($own))->toEqualCanonicalizing(array_values(array_filter(array_keys($listed[$locale]), static fn (string $key): bool => ! str_starts_with($key, 'privacy.'))), $locale);

        foreach ($listed[$locale] as $key => $text) {
            expect($translations[$key] ?? null)->toBe($text, "{$locale}: {$key}")
                ->and(promisesInRoomText($text))->toBe([], "{$locale}: {$key}");
        }
    }

    // «oyun» for what a person does; «otaq» only on `/privacy` (ADR 026 decision 2).
    foreach ($listed['az'] as $key => $text) {
        if (! str_starts_with($key, 'privacy.')) {
            expect(preg_match('/\botaq|\botağ/u', Az::lower($text)))->toBe(0, $key);
        }
    }
});
