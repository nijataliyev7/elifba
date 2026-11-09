<?php

namespace Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Contracts\Foundation\Application;
use Psr\Http\Message\RequestInterface;
use Pusher\Pusher;
use RuntimeException;

/**
 * Tests never call a real Reverb server (docs/architecture.md §11). Tests
 * boot with the `reverb` broadcaster (phpunit.xml), so `/broadcasting/auth`
 * signs with real keys; this swaps the broadcaster's HTTP client for one
 * that records what would have been published, or fails like a Reverb that
 * is down.
 */
final class FakeReverb
{
    /**
     * @var list<array{channels: list<string>, event: string, data: array<string, mixed>}>
     */
    public array $sent = [];

    private bool $down = false;

    public static function install(Application $app): self
    {
        $fake = new self;
        $broadcaster = $app->make('Illuminate\Broadcasting\BroadcastManager')->connection('reverb');

        if (! $broadcaster instanceof PusherBroadcaster) {
            throw new RuntimeException('Tests run with BROADCAST_CONNECTION=reverb (phpunit.xml).');
        }

        /** @var array{key: string, secret: string, app_id: string, options: array<string, mixed>} $config */
        $config = $app['config']['broadcasting.connections.reverb'];
        $client = new Client(['handler' => HandlerStack::create(
            static fn (RequestInterface $request): PromiseInterface => $fake->handle($request),
        )]);

        $broadcaster->setPusher(new Pusher($config['key'], $config['secret'], $config['app_id'], $config['options'], $client));

        return $fake;
    }

    /**
     * From now on every publish fails as if Reverb were not running.
     */
    public function down(): void
    {
        $this->down = true;
    }

    private function handle(RequestInterface $request): PromiseInterface
    {
        if ($this->down) {
            return Create::rejectionFor(new ConnectException('Connection refused', $request));
        }

        /** @var array{name: string, data: string, channels?: list<string>, channel?: string} $body */
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->sent[] = [
            'channels' => $body['channels'] ?? [$body['channel'] ?? ''],
            'event' => $body['name'],
            'data' => json_decode($body['data'], true, 512, JSON_THROW_ON_ERROR),
        ];

        return Create::promiseFor(new Response(200, [], '{}'));
    }
}
