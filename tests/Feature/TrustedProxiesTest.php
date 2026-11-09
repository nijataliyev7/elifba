<?php

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/*
 * Behind Caddy (docs/architecture.md §10): the app sees plain http from the
 * proxy (`http://app:8080`) and learns the browser's scheme and host from
 * X-Forwarded-*, but only from the proxies in `trustedproxy.proxies`
 * (TRUSTED_PROXIES; phpunit.xml empties it, as in development).
 */

/** @return TestResponse<Response> */
function getDashboardThroughProxy(string $remoteAddress): TestResponse
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->withHeaders([
            'X-Forwarded-For' => '198.51.100.7',
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'elifba.example.az',
        ])
        ->get('http://app:8080/dashboard');
}

/** @param  TestResponse<Response>  $response */
function cookiesAreSecure(TestResponse $response): bool
{
    $cookies = $response->headers->getCookies();

    expect($cookies)->not->toBeEmpty();

    return collect($cookies)->every(fn ($cookie): bool => $cookie->isSecure());
}

it('gives https URLs and secure cookies behind a trusted proxy', function (): void {
    config()->set('trustedproxy.proxies', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16');

    $response = getDashboardThroughProxy('172.18.0.5')
        ->assertRedirect('https://elifba.example.az/login');

    expect(cookiesAreSecure($response))->toBeTrue();
});

it('ignores forwarded headers from an address it does not trust', function (): void {
    config()->set('trustedproxy.proxies', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16');

    $response = getDashboardThroughProxy('203.0.113.9')
        ->assertRedirect('http://app:8080/login');

    expect(cookiesAreSecure($response))->toBeFalse();
});

it('trusts no proxy by default, as in development', function (): void {
    expect(config('trustedproxy.proxies'))->toBeNull();

    getDashboardThroughProxy('127.0.0.1')
        ->assertRedirect('http://app:8080/login');
});
