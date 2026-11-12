<?php

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Actions\GenerateVoiceClips;
use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Models\CustomWord;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\ContentValidator;
use App\Domain\Content\Support\NameVoices;
use App\Domain\Content\Support\VoiceClips;
use App\Domain\Family\Models\Child;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\ContentFixture;

/*
 * php artisan content:voice (docs/architecture.md §3 "Voice", ADR 015,
 * ADR 016) on a fixture content root, never on the real content/:
 * ElevenLabs is faked (Http::fake) and so is ffmpeg (Process::fake), so no
 * test makes a real call or pays for a character. The fixture's name list
 * has seven names, Nihat's clip made.
 */

const VOICE_KEY = 'sk_test_never_printed_0123456789';

beforeEach(function (): void {
    $this->root = ContentFixture::make();
    $this->app->instance(ContentRepository::class, new ContentRepository($this->root));
    config()->set('elifba.voice.api_key', VOICE_KEY);
    config()->set('elifba.voice.api_url', 'https://api.elevenlabs.test');

    Process::fake([
        '*print_format=json*' => Process::result(errorOutput: "[Parsed_loudnorm_0]\n{\n\t\"input_i\" : \"-21.40\",\n\t\"input_tp\" : \"-3.10\"\n}\n"),
        '*' => Process::result(output: 'LEVELLED-MP3'),
    ]);
});

function voiceClips(): VoiceClips
{
    return app(VoiceClips::class);
}

function nameVoices(): NameVoices
{
    return app(NameVoices::class);
}

/**
 * @return array<string, string>
 */
function listedClips(string $root, string $key = 'clips'): array
{
    return json_decode((string) file_get_contents($root.'/voice-clips.json'), true, 4, JSON_THROW_ON_ERROR)[$key];
}

/**
 * @param  iterable<VoiceLineData>  $lines
 */
function charactersOf(iterable $lines): int
{
    $characters = 0;

    foreach ($lines as $line) {
        $characters += mb_strlen($line->text);
    }

    return $characters;
}

function fakeElevenLabs(): void
{
    Http::fake([
        'api.elevenlabs.test/v1/text-to-speech/*' => Http::response('RAW-MP3', 200, ['Content-Type' => 'audio/mpeg']),
    ]);
}

/**
 * The fixture root with no clip made yet and no credits entry for them.
 */
function withoutClips(string $root): void
{
    File::deleteDirectory($root.'/assets/voice');
    File::put($root.'/voice-clips.json', json_encode(['clips' => (object) [], 'names' => listedClips($root, 'names')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
    $credits = json_decode((string) file_get_contents($root.'/credits.json'), true, 16, JSON_THROW_ON_ERROR);
    $credits['credits'] = array_values(array_filter($credits['credits'], static fn (array $credit): bool => $credit['files'] !== ['content/assets/voice/*']));
    File::put($root.'/credits.json', json_encode($credits, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
}

it('says in a dry run what it would make and what it sends, and calls nothing', function (): void {
    Http::fake();
    $needed = app(GenerateVoiceClips::class)->needed();
    $missing = app(GenerateVoiceClips::class)->missing();
    $characters = charactersOf($missing);

    expect(Artisan::call('content:voice', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())
        ->toContain('find.picture')
        ->toContain('count.number')
        ->toContain((string) count($needed))
        ->toContain('This run: '.count($missing).' clip(s), '.$characters.' characters sent')
        ->toContain('credits.json')
        ->toContain('Dry run: nothing was sent.');

    Http::assertNothingSent();
    Process::assertNothingRan();
    expect(listedClips($this->root))->toHaveCount(6);
});

it('makes the clips that have no file, levelled, lists them and keeps the ones made', function (): void {
    fakeElevenLabs();
    $before = listedClips($this->root);

    $this->artisan('content:voice', ['--force' => true, '--only' => 'find.number', '--review' => $this->root.'/../review.html'])
        ->expectsOutputToContain('Made 5 clip(s)')
        ->assertSuccessful();

    $numbers = array_filter(voiceClips()->needed(app(ContentRepository::class)), static fn (VoiceLineData $line): bool => $line->group === 'find.number');

    expect($numbers)->toHaveCount(5);

    foreach ($numbers as $line) {
        // A faked process ends its output with a line break.
        expect(trim((string) file_get_contents($this->root.'/assets/'.VoiceClips::path($line->hash))))->toBe('LEVELLED-MP3', $line->text);
    }

    expect(listedClips($this->root))->toBe([
        ...array_map(static fn (VoiceLineData $line): string => $line->text, array_filter(
            voiceClips()->needed(app(ContentRepository::class)),
            fn (VoiceLineData $line): bool => is_file($this->root.'/assets/'.VoiceClips::path($line->hash)),
        )),
    ])->and(array_intersect_key(listedClips($this->root), $before))->toBe($before)
        ->and(file_get_contents($this->root.'/../review.html'))->toContain('Bir rəqəmini tap!')
        ->and((new ContentValidator($this->root))->validate())->toBe([]);

    Http::assertSentCount(5);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.elevenlabs.test/v1/text-to-speech/fixturevoice01?output_format=mp3_44100_128'
        && $request->header('xi-api-key') === [VOICE_KEY]
        && $request['model_id'] === 'eleven_v4'
        && $request['language_code'] === 'az'
        && $request['voice_settings'] === ['stability' => 0.13, 'similarity_boost' => 0.75]
        // Azerbaijani context the voice never says.
        && $request['previous_text'] === 'Salam, uşaqlar! Bu gün birlikdə oynayırıq. Şəkillərə yaxşı baxın və düzgün cavabı tapın.'
        && $request['next_text'] === 'Afərin, düz tapdın!'
        && $request['text'] === '[playful] Beş rəqəmini tap!');
    Process::assertRan(static fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'alimiter'));

    // A second run makes nothing again.
    $this->artisan('content:voice', ['--force' => true, '--only' => 'find.number'])
        ->expectsOutputToContain('There is no clip to make.')
        ->assertSuccessful();

    Http::assertSentCount(5);
});

it('gives the folder its credits entry with the first clip and writes CREDITS.md again', function (): void {
    fakeElevenLabs();
    withoutClips($this->root);
    $this->travelTo(now()->setDate(2026, 11, 20));

    $this->artisan('content:voice', ['--force' => true, '--only' => 'praise,retry,greeting,finish,finish_class'])
        ->expectsOutputToContain('credits.json got the entry for content/assets/voice/*')
        ->assertSuccessful();

    $credits = (new ContentRepository($this->root))->credits();
    $voice = array_values(array_filter($credits, static fn ($credit): bool => $credit->files === ['content/assets/voice/*']));

    expect($voice)->toHaveCount(1)
        ->and($voice[0]->license->value)->toBe('MIT')
        ->and($voice[0]->changes)->toContain('November 2026')->toContain('Nigar')->not->toContain('{month}')
        ->and(file_get_contents(dirname($this->root).'/CREDITS.md'))->toContain('content/assets/voice/*')
        ->and((new ContentValidator($this->root))->validate())->toBe([]);

    // Laid out as the formatter writes it.
    expect(file_get_contents($this->root.'/credits.json'))->toContain('"files": ["content/assets/voice/*"],');
});

it('asks before it calls the paid API', function (): void {
    Http::fake();

    $numbers = array_filter(voiceClips()->needed(app(ContentRepository::class)), static fn (VoiceLineData $line): bool => $line->group === 'find.number');
    $characters = array_sum(array_map(static fn (VoiceLineData $line): int => mb_strlen($line->text), $numbers));

    $this->artisan('content:voice', ['--only' => 'find.number'])
        ->expectsConfirmation("Make 5 clip(s), {$characters} characters, with ElevenLabs (paid plan)?", 'no')
        ->expectsOutputToContain('Nothing was sent.')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('needs the key and never prints it', function (): void {
    Http::fake();
    config()->set('elifba.voice.api_key', null);

    $this->artisan('content:voice', ['--force' => true])
        ->expectsOutputToContain('Set ELEVENLABS_API_KEY')
        ->assertFailed();

    Http::assertNothingSent();
});

it('stops when ElevenLabs refuses, keeps and lists what it made, and prints no key', function (): void {
    Http::fakeSequence('api.elevenlabs.test/*')
        ->push('RAW-MP3', 200, ['Content-Type' => 'audio/mpeg'])
        ->push(['detail' => ['status' => 'quota_exceeded', 'message' => 'This request exceeds your quota.']], 401);

    expect(Artisan::call('content:voice', ['--force' => true, '--only' => 'find.number']))->toBe(1)
        ->and(Artisan::output())
        ->toContain('ElevenLabs answered 401: This request exceeds your quota.')
        ->toContain('Made 1 clip(s)')
        ->not->toContain(VOICE_KEY)
        ->and(listedClips($this->root))->toHaveCount(7);

    Http::assertSentCount(2);
});

it('deletes only with --prune the clips nothing needs any more', function (): void {
    // A word that has left its pack; and a clip of the voice before a change of its settings.
    $orphan = voiceClips()->hash('[playful] Köhnə sözünü tap!');
    $stale = '0123456789abcdef';

    foreach ([$orphan, $stale] as $hash) {
        File::put($this->root.'/assets/'.VoiceClips::path($hash), 'OLD');
    }

    File::put($this->root.'/voice-clips.json', json_encode(['clips' => [...listedClips($this->root), $orphan => '[playful] Köhnə sözünü tap!', $stale => '[calm] Alma hansıdır?'], 'names' => listedClips($this->root, 'names')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    expect((new ContentValidator($this->root))->validate())->toBe(["voice-clips.json: clips.{$stale} is not the clip of \"[calm] Alma hansıdır?\" in the voice of voice.json; make it again (php artisan content:voice) and remove the old one (--prune)"]);
    fakeElevenLabs();

    $this->artisan('content:voice', ['--force' => true, '--only' => 'find.number'])->assertSuccessful();

    expect(listedClips($this->root))->toHaveKeys([$orphan, $stale]);

    $this->artisan('content:voice', ['--prune' => true])
        ->expectsOutputToContain('Deleted 2 clip(s)')
        ->assertSuccessful();

    expect(listedClips($this->root))->not->toHaveKey($orphan)->not->toHaveKey($stale)->toHaveCount(11)
        ->and(is_file($this->root.'/assets/'.VoiceClips::path($orphan)))->toBeFalse()
        ->and((new ContentValidator($this->root))->validate())->toBe([]);
});

it('sends the list\'s names and reads no student or child', function (): void {
    fakeElevenLabs();
    $owner = User::factory()->teacherAndParent()->create();
    CustomWord::factory()->create(['user_id' => $owner->id, 'text' => 'nigar']);
    // Names off the list, and a name on it: none of them is read from here.
    Student::factory()->for(SchoolClass::factory()->for($owner, 'teacher'))->create(['display_name' => 'Gülnarə M.']);
    Student::factory()->for(SchoolClass::factory()->for($owner, 'teacher'))->create(['display_name' => 'Aysel']);
    Child::factory()->for($owner, 'parent')->create(['display_name' => 'Firuzə']);
    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->artisan('content:voice', ['--force' => true])->assertSuccessful();

    $sent = collect(Http::recorded())->map(static fn (array $pair): string => (string) $pair[0]['text']);
    $names = $sent->filter(static fn (string $text): bool => str_starts_with($text, '[excited] ') && ! str_contains(mb_substr($text, 10), ' '))->values()->all();

    expect($queries)->toBe([])
        ->and($sent->filter(static fn (string $text): bool => preg_match('/nigar|gülnarə|firuzə/iu', $text) === 1)->all())->toBe([])
        // Every name of names.json but Nihat, made already: Aysel because she is listed, not because a student bears the name.
        ->and($names)->toBe(['[excited] Aysel!', '[excited] Əli!', '[excited] Hüseyin!', '[excited] Hüseyn!', '[excited] İlkin!', '[excited] Zəhra!'])
        // Every clip content needs but the six the fixture has made, and the six names.
        ->and($sent->count())->toBe(count(voiceClips()->needed(app(ContentRepository::class))) - 6 + 6);
});

it('makes a name clip under content/voice-names and never under content/assets', function (): void {
    fakeElevenLabs();
    $voiceFiles = File::files($this->root.'/assets/voice');

    $this->artisan('content:voice', ['--force' => true, '--only' => 'name', '--limit' => 2, '--review' => $this->root.'/../review.html'])
        ->expectsOutputToContain('Made 2 clip(s)')
        ->assertSuccessful();

    $aysel = nameVoices()->line('Aysel');
    $ali = nameVoices()->line('Əli');

    expect(trim((string) file_get_contents($this->root.'/'.NameVoices::path($aysel->hash))))->toBe('LEVELLED-MP3')
        ->and(is_file($this->root.'/'.NameVoices::path($ali->hash)))->toBeTrue()
        ->and(File::files($this->root.'/assets/voice'))->toHaveCount(count($voiceFiles))
        ->and(is_file($this->root.'/assets/'.VoiceClips::path($aysel->hash)))->toBeFalse()
        ->and(listedClips($this->root, 'names'))->toBe([
            $aysel->hash => '[excited] Aysel!',
            $ali->hash => '[excited] Əli!',
            nameVoices()->line('Nihat')->hash => '[excited] Nihat!',
        ])
        ->and(listedClips($this->root))->toHaveCount(6)
        ->and(file_get_contents($this->root.'/../review.html'))->toContain('<h2>Names (3)</h2>')->toContain('Aysel!')
        ->and((new ContentValidator($this->root))->validate())->toBe([]);

    Http::assertSent(static fn (Request $request): bool => $request['text'] === '[excited] Aysel!'
        && $request['previous_text'] === 'Salam, uşaqlar! Bu gün birlikdə oynayırıq. Şəkillərə yaxşı baxın və düzgün cavabı tapın.');
    Http::assertSentCount(2);
});

it('gives the name clips their own credits entry with the first one', function (): void {
    fakeElevenLabs();
    File::deleteDirectory($this->root.'/voice-names');
    File::put($this->root.'/voice-clips.json', json_encode(['clips' => listedClips($this->root), 'names' => (object) []], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $credits = json_decode((string) file_get_contents($this->root.'/credits.json'), true, 16, JSON_THROW_ON_ERROR);
    $credits['credits'] = array_values(array_filter($credits['credits'], static fn (array $credit): bool => $credit['files'] !== ['content/voice-names/*']));
    File::put($this->root.'/credits.json', json_encode($credits, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    $this->travelTo(now()->setDate(2026, 12, 3));

    expect(Artisan::call('content:voice', ['--dry-run' => true, '--only' => 'name']))->toBe(0)
        ->and(Artisan::output())->toContain('gets this entry with the first name clip')->toContain('"content/voice-names/*"');

    $this->artisan('content:voice', ['--force' => true, '--only' => 'name'])
        ->expectsOutputToContain('credits.json got the entry for content/voice-names/*')
        ->assertSuccessful();

    $names = array_values(array_filter((new ContentRepository($this->root))->credits(), static fn ($credit): bool => $credit->files === ['content/voice-names/*']));

    expect($names)->toHaveCount(1)
        ->and($names[0]->license->value)->toBe('MIT')
        ->and($names[0]->changes)->toContain('December 2026')->not->toContain('{month}')
        ->and(file_get_contents(dirname($this->root).'/CREDITS.md'))->toContain('content/voice-names/*')
        ->and(listedClips($this->root, 'names'))->toHaveCount(7)
        ->and((new ContentValidator($this->root))->validate())->toBe([]);
});

it('counts the names in a dry run: the clips and the characters a run would send', function (): void {
    Http::fake();
    $names = array_filter(app(GenerateVoiceClips::class)->missing(), static fn (VoiceLineData $line): bool => $line->isName());

    expect($names)->toHaveCount(6)
        ->and(Artisan::call('content:voice', ['--dry-run' => true, '--only' => 'name']))->toBe(0)
        ->and(Artisan::output())
        ->toMatch('/\| name\s+\| 7\s+\| 1\s+\| 6\s+\| '.charactersOf($names).'\s+\|/')
        ->toContain('This run: 6 clip(s), '.charactersOf($names).' characters sent');

    Http::assertNothingSent();
    Process::assertNothingRan();
});

it('prunes the clip of a name that left the list', function (): void {
    fakeElevenLabs();
    $this->artisan('content:voice', ['--force' => true, '--only' => 'name', '--limit' => 1])->assertSuccessful();
    $nihat = nameVoices()->line('Nihat');
    $aysel = nameVoices()->line('Aysel');
    File::put($this->root.'/names.json', json_encode(['version' => 1, 'sources' => ['https://query.wikidata.org/'], 'names' => ['Aysel', 'Əli']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $this->app->instance(ContentRepository::class, new ContentRepository($this->root));

    // A run that makes clips keeps it: a clip accepted by ear is deleted only by --prune.
    $this->artisan('content:voice', ['--dry-run' => true])->assertSuccessful();
    expect(is_file($this->root.'/'.NameVoices::path($nihat->hash)))->toBeTrue();

    $this->artisan('content:voice', ['--prune' => true])
        ->expectsOutputToContain('Deleted 1 clip(s)')
        ->expectsOutputToContain('voice-names/'.$nihat->hash.'.mp3')
        ->assertSuccessful();

    expect(is_file($this->root.'/'.NameVoices::path($nihat->hash)))->toBeFalse()
        ->and(listedClips($this->root, 'names'))->toBe([$aysel->hash => '[excited] Aysel!'])
        ->and(listedClips($this->root))->toHaveCount(6)
        ->and((new ContentValidator($this->root))->validate())->toBe([]);
});

it('lists the account\'s voices when voice.json has no voice id yet', function (): void {
    File::put($this->root.'/voice.json', str_replace('"fixturevoice01"', '""', (string) file_get_contents($this->root.'/voice.json')));
    File::put($this->root.'/voice.json', str_replace('"Fixture voice"', '"Nigar – Ad Read"', (string) file_get_contents($this->root.'/voice.json')));
    Http::fake([
        'api.elevenlabs.test/v1/voices' => Http::response(['voices' => [
            ['voice_id' => 'abcDEF123456', 'name' => 'Nigar – Ad Read'],
            ['voice_id' => 'xyz987654321', 'name' => 'Sarah'],
        ]]),
    ]);

    expect(Artisan::call('content:voice', ['--dry-run' => true]))->toBe(1)
        ->and(Artisan::output())
        ->toContain('voice.voice_id must be')
        ->toContain('abcDEF123456')
        ->not->toContain('xyz987654321');

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET' && $request->header('xi-api-key') === [VOICE_KEY]);
});

it('makes again a clip whose file was deleted, and lists a file a stopped run left unlisted', function (): void {
    fakeElevenLabs();
    $clips = listedClips($this->root);
    // A bad praise clip deleted to be made again.
    $redo = array_key_first($clips);
    File::delete($this->root.'/assets/'.VoiceClips::path((string) $redo));
    // A clip made by a run stopped with Ctrl-C, before it wrote the list.
    $line = array_values(array_filter(voiceClips()->needed(app(ContentRepository::class)), static fn (VoiceLineData $line): bool => $line->group === 'find.number'))[0];
    File::put($this->root.'/assets/'.VoiceClips::path($line->hash), 'MADE-BEFORE');

    $this->artisan('content:voice', ['--force' => true, '--only' => 'praise,retry,find.number'])->assertSuccessful();

    expect(listedClips($this->root))->toHaveKeys([(string) $redo, $line->hash])
        ->and(file_get_contents($this->root.'/assets/'.VoiceClips::path($line->hash)))->toBe('MADE-BEFORE')
        ->and((new ContentValidator($this->root))->validate())->toBe([]);

    // One request for the deleted clip and four for the numbers not made yet.
    Http::assertSentCount(5);
});

it('makes a name that says what a phrase says its own clip, apart from the public one', function (): void {
    // «Afərin» is a girl's name and a praise phrase: one text, one hash, two files.
    $voice = json_decode((string) file_get_contents($this->root.'/voice.json'), true, 16, JSON_THROW_ON_ERROR);
    $voice['praise'][] = ['text' => 'Afərin!', 'style' => 'excited'];
    File::put($this->root.'/voice.json', json_encode($voice, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $names = json_decode((string) file_get_contents($this->root.'/names.json'), true, 4, JSON_THROW_ON_ERROR);
    $names['names'] = ['Afərin', 'Aysel', 'Nihat'];
    File::put($this->root.'/names.json', json_encode($names, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $this->app->instance(ContentRepository::class, new ContentRepository($this->root));
    fakeElevenLabs();
    $line = nameVoices()->line('Afərin');

    expect(Artisan::call('content:voice', ['--force' => true, '--only' => 'praise,name']))->toBe(0)
        ->and(Artisan::output())->toContain('Made 3 clip(s)');

    // The next request reads the clip lists the run wrote.
    $this->app->instance(ContentRepository::class, new ContentRepository($this->root));

    expect(is_file($this->root.'/assets/'.VoiceClips::path($line->hash)))->toBeTrue()
        ->and(is_file($this->root.'/'.NameVoices::path($line->hash)))->toBeTrue()
        ->and(listedClips($this->root))->toHaveKey($line->hash)
        ->and(listedClips($this->root, 'names'))->toHaveKey($line->hash)
        ->and(nameVoices()->file('Afərin'))->toBe($this->root.'/'.NameVoices::path($line->hash))
        ->and((new ContentValidator($this->root))->validate())->toBe([]);
});
