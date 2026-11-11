<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\VoiceSettingsData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * ElevenLabs' text-to-speech API, as `php artisan content:voice` calls it
 * (docs/architecture.md §3 "Voice", ADR 015) — never a page, a request or
 * a queue. The key comes from `config('elifba.voice.api_key')` and is sent
 * only in the `xi-api-key` header; no message of this class holds it.
 */
final readonly class ElevenLabs
{
    /**
     * What the API returns; `VoiceLeveler` makes the file the board plays.
     */
    public const string FORMAT = 'mp3_44100_128';

    /**
     * Waits before each retry of a request the API refused for now (429,
     * 5xx) or that did not connect, in milliseconds.
     */
    private const array BACKOFF = [2000, 5000, 15000];

    public function __construct(
        private string $url,
        private string $key,
    ) {}

    /**
     * The audio of one text in this voice: `[playful] Dovşan hansıdır?`.
     *
     * @return string MP3 bytes
     *
     * @throws RuntimeException when the API refuses or answers with no audio
     */
    public function speak(VoiceSettingsData $voice, string $text): string
    {
        try {
            $response = $this->request()
                ->accept('audio/mpeg')
                ->timeout(120)
                ->post('/v1/text-to-speech/'.rawurlencode($voice->voiceId).'?output_format='.self::FORMAT, [
                    'text' => $text,
                    'model_id' => $voice->model,
                    'language_code' => $voice->language,
                    'voice_settings' => [
                        'stability' => $voice->stability,
                        'similarity_boost' => $voice->similarity,
                    ],
                    // Context the voice never says: a short phrase alone drifted towards Turkish.
                    ...array_filter(['previous_text' => $voice->previousText, 'next_text' => $voice->nextText], static fn (string $text): bool => $text !== ''),
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('ElevenLabs could not be reached: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException(self::refusal($response));
        }

        if (! str_starts_with($response->header('Content-Type'), 'audio/') || $response->body() === '') {
            throw new RuntimeException('ElevenLabs answered without audio ('.($response->header('Content-Type') ?: 'no content type').').');
        }

        return $response->body();
    }

    /**
     * The voices the account can use, by id: to find the id of the voice
     * `voice.json` names.
     *
     * @return array<string, string> id → name
     *
     * @throws RuntimeException when the API refuses
     */
    public function voices(): array
    {
        try {
            $response = $this->request()->acceptJson()->timeout(30)->get('/v1/voices');
        } catch (ConnectionException $exception) {
            throw new RuntimeException('ElevenLabs could not be reached: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            throw new RuntimeException(self::refusal($response));
        }

        $voices = [];

        foreach ((array) $response->json('voices', []) as $voice) {
            if (is_array($voice) && is_string($voice['voice_id'] ?? null) && is_string($voice['name'] ?? null)) {
                $voices[$voice['voice_id']] = $voice['name'];
            }
        }

        return $voices;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->url)
            ->withHeaders(['xi-api-key' => $this->key])
            ->retry(self::BACKOFF, when: static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                || ($exception instanceof RequestException && in_array($exception->response->status(), [429, 500, 502, 503, 504], true)), throw: false);
    }

    /**
     * What the API said, without the request (which holds the key).
     */
    private static function refusal(Response $response): string
    {
        $detail = $response->json('detail');
        $message = match (true) {
            is_array($detail) && is_string($detail['message'] ?? null) => $detail['message'],
            is_string($detail) => $detail,
            default => mb_substr($response->body(), 0, 300),
        };

        return "ElevenLabs answered {$response->status()}: {$message}";
    }
}
