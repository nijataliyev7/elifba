<?php

use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Models\CustomWord;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\ContentValidator;
use App\Domain\Content\Support\VoiceClips;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\Support\ContentFixture;

/*
 * php artisan content:voice (docs/architecture.md §3 "Voice", ADR 015) on a
 * fixture content root, never on the real content/: ElevenLabs is faked
 * (Http::fake) and so is ffmpeg (Process::fake), so no test makes a real
 * call or pays for a character.
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

/**
 * @return array<string, string>
 */
function listedClips(string $root): array
{
    return json_decode((string) file_get_contents($root.'/voice-clips.json'), true, 4, JSON_THROW_ON_ERROR)['clips'];
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
    File::put($root.'/voice-clips.json', "{\n    \"clips\": {}\n}\n");
    $credits = json_decode((string) file_get_contents($root.'/credits.json'), true, 16, JSON_THROW_ON_ERROR);
    $credits['credits'] = array_values(array_filter($credits['credits'], static fn (array $credit): bool => $credit['files'] !== ['content/assets/voice/*']));
    File::put($root.'/credits.json', json_encode($credits, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
}

it('says in a dry run what it would make and what it sends, and calls nothing', function (): void {
    Http::fake();
    $needed = voiceClips()->needed(app(ContentRepository::class));
    $missing = array_filter($needed, fn (VoiceLineData $line): bool => ! is_file($this->root.'/assets/'.VoiceClips::path($line->hash)));
    $characters = array_sum(array_map(static fn (VoiceLineData $line): int => mb_strlen($line->text), $missing));

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
    expect(listedClips($this->root))->toHaveCount(3);
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

    $this->artisan('content:voice', ['--force' => true, '--only' => 'praise,retry'])
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
        ->and(listedClips($this->root))->toHaveCount(4);

    Http::assertSentCount(2);
});

it('deletes only with --prune the clips nothing needs any more', function (): void {
    // A word that has left its pack; and a clip of the voice before a change of its settings.
    $orphan = voiceClips()->hash('[playful] Köhnə sözünü tap!');
    $stale = '0123456789abcdef';

    foreach ([$orphan, $stale] as $hash) {
        File::put($this->root.'/assets/'.VoiceClips::path($hash), 'OLD');
    }

    File::put($this->root.'/voice-clips.json', json_encode(['clips' => [...listedClips($this->root), $orphan => '[playful] Köhnə sözünü tap!', $stale => '[calm] Alma hansıdır?']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    expect((new ContentValidator($this->root))->validate())->toBe(["voice-clips.json: clips.{$stale} is not the clip of \"[calm] Alma hansıdır?\" in the voice of voice.json; make it again (php artisan content:voice) and remove the old one (--prune)"]);
    fakeElevenLabs();

    $this->artisan('content:voice', ['--force' => true, '--only' => 'find.number'])->assertSuccessful();

    expect(listedClips($this->root))->toHaveKeys([$orphan, $stale]);

    $this->artisan('content:voice', ['--prune' => true])
        ->expectsOutputToContain('Deleted 2 clip(s)')
        ->assertSuccessful();

    expect(listedClips($this->root))->not->toHaveKey($orphan)->not->toHaveKey($stale)->toHaveCount(8)
        ->and(is_file($this->root.'/assets/'.VoiceClips::path($orphan)))->toBeFalse()
        ->and((new ContentValidator($this->root))->validate())->toBe([]);
});

it('voices built-in content only, never a teacher word or a name from the database', function (): void {
    fakeElevenLabs();
    CustomWord::factory()->create(['user_id' => User::factory()->create()->id, 'text' => 'nigar']);

    $this->artisan('content:voice', ['--force' => true])->assertSuccessful();

    Http::assertNotSent(static fn (Request $request): bool => str_contains(mb_strtolower((string) $request['text']), 'nigar'));
    expect(collect(Http::recorded())->count())->toBe(count(voiceClips()->needed(app(ContentRepository::class))) - 3);
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
