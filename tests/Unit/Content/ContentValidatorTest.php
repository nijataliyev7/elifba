<?php

use App\Domain\Content\Enums\Effect;
use App\Domain\Content\Enums\ItemStyle;
use App\Domain\Content\Enums\RoomSound;
use App\Domain\Content\Enums\RoomSpeaker;
use App\Domain\Content\Enums\Sound;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\ContentValidator;
use Tests\Support\ContentFixture;

/*
 * ContentValidator (docs/architecture.md §3, §8). The real content/ must be
 * valid; so must the small fixture root in tests/Fixtures/content/base; and
 * every case in tests/Fixtures/content/broken.json breaks that root in one
 * way and must be reported, in the file it broke.
 */

it('accepts the real content', function (): void {
    expect((new ContentValidator(base_path('content')))->validate())->toBe([]);
});

it('accepts the fixture content root', function (): void {
    expect((new ContentValidator(ContentFixture::make()))->validate())->toBe([]);
});

it('rejects each broken fixture with its problem', function (array $patch, string $problem): void {
    $problems = (new ContentValidator(ContentFixture::make($patch)))->validate();

    expect($problems)->not->toBeEmpty()
        ->and(implode("\n", $problems))->toContain($problem)
        ->and($problems)->each->toStartWith($patch['file'].': ');
})->with(ContentFixture::broken());

it('requires a picture for every word since A5 switched it on', function (): void {
    $root = ContentFixture::make(['file' => 'packs/sample.json', 'unset' => ['words.1.image']]);

    expect(ContentValidator::WORD_PICTURES_REQUIRED)->toBeTrue()
        ->and((new ContentValidator($root))->validate())
        ->toBe(['packs/sample.json: words[1] "ata" needs an `image`'])
        ->and((new ContentValidator($root, wordPicturesRequired: false))->validate())
        ->toBe([]);
});

it('keeps every room sound small enough to fetch once sound is unlocked', function (): void {
    $limit = ContentValidator::MAX_ROOM_SOUND_BYTES;
    $root = ContentFixture::make(['write' => ['assets/rooms/sounds/roll.mp3' => str_repeat('x', $limit + 1)]]);

    expect((new ContentValidator($root))->validate())
        ->toBe(['rooms/sounds.json: sounds.roll "rooms/sounds/roll.mp3" holds '.($limit + 1).' bytes; a room sound holds at most '.$limit]);

    foreach (RoomSound::cases() as $sound) {
        expect(filesize(base_path('content/assets/rooms/sounds/'.$sound->value.'.mp3')))->toBeLessThanOrEqual($limit);
    }
});

it('keeps each speaker\'s whole set of room sounds light: a phone that joins fetches only a few short files', function (): void {
    $limit = ContentValidator::MAX_ROOM_SPEAKER_BYTES['phone'];
    $half = intdiv($limit, 2) + 1;
    $root = ContentFixture::make(['write' => [
        'assets/rooms/sounds/tap.mp3' => str_repeat('x', $half),
        'assets/rooms/sounds/undo.mp3' => str_repeat('x', $half),
    ]]);
    $phone = 2 * $half + array_sum(array_map(
        static fn (RoomSound $sound): int => (int) filesize($root.'/assets/rooms/sounds/'.$sound->value.'.mp3'),
        array_filter(RoomSound::of(RoomSpeaker::Phone), static fn (RoomSound $sound): bool => ! in_array($sound, [RoomSound::Tap, RoomSound::Undo], true)),
    ));

    expect((new ContentValidator($root))->validate())
        ->toBe(["rooms/sounds.json: the phone's sounds hold {$phone} bytes; a phone's sounds hold at most {$limit}"]);

    foreach (RoomSpeaker::cases() as $speaker) {
        $bytes = array_sum(array_map(
            static fn (RoomSound $sound): int => (int) filesize(base_path('content/assets/rooms/sounds/'.$sound->value.'.mp3')),
            RoomSound::of($speaker),
        ));

        expect($bytes)->toBeLessThanOrEqual(ContentValidator::MAX_ROOM_SPEAKER_BYTES[$speaker->value]);
    }
});

it('sends a page only its own speaker\'s room sounds, as content URLs with the file\'s version', function (): void {
    $sounds = (new ContentRepository(base_path('content')))->roomSounds();
    $screen = $sounds->urls(RoomSpeaker::Screen);
    $phone = $sounds->urls(RoomSpeaker::Phone);

    expect(array_keys($screen))->toBe(['countdown', 'go', 'study', 'question', 'clock', 'close', 'ranking', 'place', 'roll', 'fanfare', 'join', 'move', 'capture', 'crown'])
        ->and(array_keys($phone))->toBe(['tap', 'undo', 'sent', 'right', 'wrong', 'offer'])
        ->and($phone['tap'])->toBe(asset('content/rooms/sounds/tap.mp3').'?v='.$sounds->version);
});

it('keeps a board game\'s four sounds light: a move, a capture and a crown under 24 KB together, an offer under 8 KB', function (): void {
    $bytes = static fn (RoomSound ...$sounds): int => array_sum(array_map(
        static fn (RoomSound $sound): int => (int) filesize(base_path('content/assets/rooms/sounds/'.$sound->value.'.mp3')),
        $sounds,
    ));

    expect($bytes(RoomSound::Move, RoomSound::Capture, RoomSound::Crown))->toBeLessThan(24 * 1024)
        ->and($bytes(RoomSound::Offer))->toBeLessThan(8 * 1024)
        ->and([RoomSound::Move->speaker(), RoomSound::Capture->speaker(), RoomSound::Crown->speaker(), RoomSound::Offer->speaker()])
        ->toBe([RoomSpeaker::Screen, RoomSpeaker::Screen, RoomSpeaker::Screen, RoomSpeaker::Phone]);
});

it('names the same room sounds on the page as the content does, each for one speaker', function (): void {
    $source = (string) file_get_contents(resource_path('js/rooms/sound.ts'));
    preg_match('/export const ROOM_SOUNDS = \{(.*?)\} as const;/s', $source, $block);
    $page = [];

    foreach (RoomSpeaker::cases() as $speaker) {
        preg_match('/'.$speaker->value.': \[(.*?)\]/s', $block[1] ?? '', $list);
        preg_match_all("/'([a-z]+)'/", $list[1] ?? '', $names);
        $page[$speaker->value] = $names[1];
    }

    expect($page)->toBe(array_map(
        static fn (RoomSpeaker $speaker): array => array_map(static fn (RoomSound $sound): string => $sound->value, RoomSound::of($speaker)),
        ['screen' => RoomSpeaker::Screen, 'phone' => RoomSpeaker::Phone],
    ));
});

it('gives every pack a sound for every moment an account may choose, a chime instead of a pop where the effect is sparkle', function (): void {
    foreach ((new ContentRepository(base_path('content')))->packs() as $pack) {
        foreach (Sound::choosable() as $sound) {
            expect($pack->style->sound($sound))->not->toBeNull();
        }

        expect($pack->style->sound(Sound::Hit))->toBe($pack->style->effect === Effect::Sparkle ? 'sounds/chime.mp3' : 'sounds/pop.mp3');
    }
});

it('gives the train its own sounds for a round arriving and leaving, and no other pack any', function (): void {
    foreach ((new ContentRepository(base_path('content')))->packs() as $pack) {
        $train = $pack->key === 'train';

        expect($pack->style->sound(Sound::Arrive))->toBe($train ? 'sounds/train-arrive.mp3' : null, $pack->key)
            ->and($pack->style->sound(Sound::Leave))->toBe($train ? 'sounds/train-leave.mp3' : null, $pack->key);
    }
});

it('accepts a pack\'s own arrive and leave sounds, which sounds.json does not list', function (): void {
    $root = ContentFixture::make([
        'file' => 'packs/sample.json',
        'set' => ['style.sounds.arrive' => 'sounds/arrive.ogg', 'style.sounds.leave' => 'sounds/pop.ogg'],
        'write' => ['assets/sounds/arrive.ogg' => ''],
    ]);

    expect((new ContentValidator($root))->validate())->toBe([]);
});

it('needs a credits entry for an arrive or leave sound, as for every asset', function (): void {
    $root = ContentFixture::make([
        'file' => 'packs/sample.json',
        'set' => ['style.sounds.leave' => 'sample/leave.ogg'],
        'write' => ['assets/sample/leave.ogg' => ''],
    ]);

    expect((new ContentValidator($root))->validate())->toBe(['credits.json: content/assets/sample/leave.ogg has no entry']);
});

it('credits every font and asset file the repository ships', function (): void {
    $problems = (new ContentValidator(base_path('content')))->validate();

    expect(array_filter($problems, static fn (string $problem): bool => str_starts_with($problem, 'credits.json')))->toBe([]);
});

/** Ten figures of the fixture pack: its word pictures and the backdrop, a picture of no word. */
const SAMPLE_FIGURES = [
    'sample/alma.svg', 'sample/words/ata.svg', 'sample/words/ana.svg', 'sample/words/nene.svg', 'sample/words/baba.svg',
    'sample/words/dede.svg', 'sample/words/baci.svg', 'sample/words/kitab.svg', 'sample/words/qelem.svg', 'sample/backdrop.svg',
];

it('accepts an animal, fruit or dino look with enough figures, word pictures or others', function (string $item): void {
    $root = ContentFixture::make(['file' => 'packs/sample.json', 'set' => ['style.item' => $item, 'style.figures' => SAMPLE_FIGURES]]);

    expect((new ContentValidator($root))->validate())->toBe([]);
})->with(['animal', 'fruit', 'dino']);

it('needs a credits entry for a figure, as for every asset', function (): void {
    $root = ContentFixture::make([
        'file' => 'packs/sample.json',
        'set' => ['style.item' => 'animal', 'style.figures' => [...array_slice(SAMPLE_FIGURES, 0, 9), 'sample/dinozavr.svg']],
        'write' => ['assets/sample/dinozavr.svg' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 72 72"/>'],
    ]);

    expect((new ContentValidator($root))->validate())->toBe(['credits.json: content/assets/sample/dinozavr.svg has no entry']);
});

it('asks for twice as many figures as the fullest round of any level shows', function (): void {
    $content = new ContentRepository(base_path('content'));
    $most = [];

    foreach ($content->levels() as $level) {
        $most[$level->key] = $level->mostItems();
    }

    // find and pop show 4; a build round its longest answer and one decoy.
    expect($most)->toBe([
        'letters' => 4,
        'syllables-open' => 4,
        'syllables-closed' => 5,
        'words-short' => 4,
        'words-long' => 5,
        'sentences' => 5,
        'pictures-easy' => 2,
        'pictures' => 4,
        'numbers-5' => 3,
        'numbers-10' => 4,
    ])->and(ContentValidator::FIGURES_PER_ITEM * max($most))->toBe(10);

    expect(array_map(static fn (ItemStyle $style): bool => $style->hasFigures(), ItemStyle::cases()))
        ->toBe([false, false, false, false, true, true, false, true]);

    foreach ($content->packs() as $pack) {
        $figures = count($pack->style->figures);

        expect($pack->style->item->hasFigures() ? $figures >= 10 : $figures === 0)->toBeTrue($pack->key);
    }
});

it('needs a credits entry for a name clip, as for every asset', function (): void {
    $root = ContentFixture::make(['file' => 'credits.json', 'set' => ['credits.4.files' => ['content/assets/sample/alma.svg']]]);

    expect((new ContentValidator($root))->validate())->toBe(['credits.json: content/voice-names/f66bf3c65feb9853.mp3 has no entry']);
});

it('lets content:voice run with every name clip pruned or deleted, which it makes again', function (): void {
    $root = ContentFixture::make(['delete' => ['voice-names/f66bf3c65feb9853.mp3']]);

    expect((new ContentValidator($root, voiceClipsRequired: false))->validate())->toBe([])
        ->and((new ContentValidator($root))->validate())->not->toBe([]);
});

it('needs a clip for the greeting and the end phrases since V2\'s paid month made them', function (): void {
    $phrases = ['d6d4213f48206758', 'e8b1494c24e9dff0', 'd8133988f1d26eea'];
    $root = ContentFixture::make([
        'file' => 'voice-clips.json',
        'unset' => array_map(static fn (string $hash): string => "clips.{$hash}", $phrases),
        'delete' => array_map(static fn (string $hash): string => "assets/voice/{$hash}.mp3", $phrases),
    ]);

    expect(ContentValidator::NAME_PHRASE_CLIPS_REQUIRED)->toBeTrue()
        ->and((new ContentValidator(ContentFixture::make()))->validate())->toBe([])
        ->and((new ContentValidator($root))->validate())->toBe([
            'voice-clips.json: the greeting phrase "Salam!" has no clip (php artisan content:voice)',
            'voice-clips.json: the finish phrase "Əla oynadın!" has no clip (php artisan content:voice)',
            'voice-clips.json: the finish_class phrase "Əla oynadınız!" has no clip (php artisan content:voice)',
        ])
        ->and((new ContentValidator($root, namePhraseClipsRequired: false))->validate())->toBe([]);
});

it('keeps the clip of a name that left the list until --prune, for content:voice', function (): void {
    $root = ContentFixture::make(['file' => 'names.json', 'set' => ['names' => ['Aysel', 'Əli']]]);

    expect((new ContentValidator($root, voiceClipsRequired: false))->validate())->toBe([])
        ->and((new ContentValidator($root))->validate())->toBe([
            'voice-clips.json: names.f66bf3c65feb9853 is not the clip of a name of names.json in the voice of voice.json ("[excited] Nihat!"); make it again or remove it (php artisan content:voice --prune)',
        ]);
});

it('keeps a taunt\'s clip small enough to fetch with the eleven others once sound is on', function (): void {
    $limit = ContentValidator::MAX_TAUNT_VOICE_BYTES;
    $root = ContentFixture::make(['write' => ['assets/rooms/taunts/61f456c92efd186b.mp3' => "\xFF\xFB".str_repeat('x', $limit - 1)]]);

    expect($limit)->toBe(48 * 1024)
        ->and((new ContentValidator($root))->validate())
        ->toBe(['voice-clips.json: taunts.61f456c92efd186b: content/assets/rooms/taunts/61f456c92efd186b.mp3 holds '.($limit + 1).' bytes; a taunt\'s clip holds at most '.$limit]);

    foreach (glob(base_path('content/assets/rooms/taunts/*.mp3')) ?: [] as $clip) {
        expect(filesize($clip))->toBeLessThanOrEqual($limit, basename($clip));
    }
});

it('keeps the clip of a taunt whose phrase changed until --prune, for content:voice', function (): void {
    // The clip says the old phrase: a lang change makes a new hash, and the old file stays until --prune.
    $root = ContentFixture::make(['file' => 'rooms/taunts.json', 'set' => ['taunts.0.voice' => 'sarcastic']]);

    expect((new ContentValidator($root, voiceClipsRequired: false))->validate())->toBe([])
        ->and((new ContentValidator($root))->validate())->toBe([
            'voice-clips.json: taunts.61f456c92efd186b is not the clip of a taunt of rooms/taunts.json in the voice of voice.json ("[mischievously] Tısbağa da səndən tezdir!"); make it again or remove it (php artisan content:voice --prune)',
        ]);
});

it('lets a taunt whose clip is not made yet play silent, and its folder go uncredited until the first', function (): void {
    $root = ContentFixture::make([
        'file' => 'voice-clips.json',
        'unset' => ['taunts.61f456c92efd186b'],
        'delete' => ['assets/rooms/taunts/61f456c92efd186b.mp3'],
    ]);
    $credits = json_decode((string) file_get_contents("{$root}/credits.json"), true, 16, JSON_THROW_ON_ERROR);
    $credits['credits'] = array_values(array_filter($credits['credits'], static fn (array $credit): bool => $credit['files'] !== ['content/assets/rooms/taunts/*']));
    file_put_contents("{$root}/credits.json", json_encode($credits, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    expect((new ContentValidator($root))->validate())->toBe([]);
});

it('keeps the clues in the word list\'s order, so a word and its clue change in one place', function (): void {
    $root = ContentFixture::make();
    $path = "{$root}/rooms/clues.json";
    $file = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    $file['clues'] = array_reverse($file['clues'], preserve_keys: true);
    file_put_contents($path, json_encode($file, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    expect((new ContentValidator($root))->validate())->toBe([
        'rooms/clues.json: clues must follow the order of rooms/words.json: "zirvə" stands where "axşam" does',
    ]);
});

it('rejects a clue that names a listed word its word is built on, and leaves a shorter part to the hand', function (): void {
    // «balıqçı» is built on «balıq», a word of the list: its clue may not say it.
    $root = ContentFixture::make();
    $write = static function (string $path, array $data): void {
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    };
    $words = json_decode((string) file_get_contents("{$root}/rooms/words.json"), true, 16, JSON_THROW_ON_ERROR);
    $clues = json_decode((string) file_get_contents("{$root}/rooms/clues.json"), true, 16, JSON_THROW_ON_ERROR);
    $at = array_search('balıq', $words['words'], true) + 1;
    array_splice($words['words'], $at, 0, ['balıqçı']);
    $write("{$root}/rooms/words.json", $words);
    $withClue = static function (string $clue) use ($words, $clues, $root, $write): void {
        $write("{$root}/rooms/clues.json", [...$clues, 'clues' => array_combine($words['words'], array_map(static fn (string $word): string => $word === 'balıqçı' ? $clue : $clues['clues'][$word], $words['words']))]);
    };

    $withClue('Peşə, göldən balıq tutur');

    expect((new ContentValidator($root))->validate())->toBe(['rooms/clues.json: clues "balıqçı" holds "balıq": a clue never names its word']);

    $withClue('Peşə, tilovla göl kənarında oturur');

    expect((new ContentValidator($root))->validate())->toBe([]);
});

it('gives every word of the real list a clue that fits two lines of a phone', function (): void {
    $content = app(ContentRepository::class);
    $clues = $content->roomClues();

    expect(array_keys($clues->clues))->toBe($content->roomWords()->words)
        ->and(max(array_map(mb_strlen(...), $clues->clues)))->toBeLessThanOrEqual(ContentValidator::MAX_ROOM_CLUE_LENGTH)
        ->and($clues->of('qarpız'))->toBe('Yay meyvəsi, bostanda yetişir')
        ->and($clues->of('babam'))->toBeNull();
});
