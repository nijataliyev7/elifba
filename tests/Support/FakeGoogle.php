<?php

namespace Tests\Support;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * Tests never reach Google (ADR 008). This configures a Google OAuth client
 * whose HTTP client answers Google's token and userinfo endpoints itself, so
 * Socialite's own state and PKCE checks still run. It records every request.
 */
final class FakeGoogle
{
    public const string CLIENT_ID = 'elifba-test.apps.googleusercontent.com';

    /**
     * The OpenID claims Google's userinfo answers with.
     *
     * @var array<string, mixed>
     */
    public array $claims = [
        'sub' => '109876543210987654321',
        'email' => 'aygun@gmail.com',
        'email_verified' => true,
        'name' => 'Aygün Məmmədova',
        'given_name' => 'Aygün',
        'picture' => 'https://lh3.googleusercontent.com/a/photo',
    ];

    /** @var list<RequestInterface> */
    public array $requests = [];

    /** Google refuses the code (an expired or replayed one). */
    public bool $rejectsCode = false;

    /** Google cannot be reached. */
    public bool $down = false;

    public static function install(): self
    {
        $fake = new self;

        config([
            'services.google.client_id' => self::CLIENT_ID,
            'services.google.client_secret' => 'elifba-test-secret',
            'services.google.guzzle' => [
                'handler' => HandlerStack::create(fn (RequestInterface $request): PromiseInterface => $fake->answer($request)),
            ],
        ]);

        return $fake;
    }

    /** @param  array<string, mixed>  $claims */
    public function answers(array $claims): self
    {
        $this->claims = [...$this->claims, ...$claims];

        return $this;
    }

    /**
     * The form fields of the token request (code, PKCE verifier, redirect URI).
     *
     * @return array<mixed>
     */
    public function tokenRequest(): array
    {
        foreach ($this->requests as $request) {
            if (str_starts_with((string) $request->getUri(), 'https://www.googleapis.com/oauth2/v4/token')) {
                parse_str((string) $request->getBody(), $fields);

                return $fields;
            }
        }

        throw new RuntimeException('No token request was made.');
    }

    private function answer(RequestInterface $request): PromiseInterface
    {
        $this->requests[] = $request;
        $url = (string) $request->getUri();

        if ($this->down) {
            return Create::rejectionFor(new ConnectException('Google is down', $request));
        }

        if (str_starts_with($url, 'https://www.googleapis.com/oauth2/v4/token')) {
            return Create::promiseFor($this->rejectsCode
                ? $this->json(400, ['error' => 'invalid_grant'])
                : $this->json(200, [
                    'access_token' => 'ya29.test-access-token',
                    'token_type' => 'Bearer',
                    'expires_in' => 3599,
                    'scope' => 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile',
                    'id_token' => 'header.payload.signature',
                ]));
        }

        if (str_starts_with($url, 'https://www.googleapis.com/oauth2/v3/userinfo')) {
            return Create::promiseFor($this->json(200, $this->claims));
        }

        throw new RuntimeException("Unexpected request to {$url}");
    }

    /** @param  array<string, mixed>  $body */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }
}
