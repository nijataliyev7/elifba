<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeReverb;
use Tests\Support\GuestBoards;

abstract class TestCase extends BaseTestCase
{
    /**
     * What the app would have published to Reverb in this test.
     */
    protected FakeReverb $reverb;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reverb = FakeReverb::install($this->app);

        // spatie/laravel-permission caches what it loaded; its testing guide
        // forgets it before every test (ADR 010).
        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * One application serves every request of a test, but in production each
     * request starts fresh. The `board` guard (a request guard) remembers the
     * guest board it found, and `auth:board,web` switches the default guard;
     * both are reset so a request without a bearer never inherits one. A
     * teacher set with `actingAs()` stays logged in, as with a session.
     *
     * @param  string  $method
     * @param  string  $uri
     * @param  array<array-key, mixed>  $parameters
     * @param  array<array-key, mixed>  $cookies
     * @param  array<array-key, mixed>  $files
     * @param  array<array-key, mixed>  $server
     * @param  string|null  $content
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        GuestBoards::forgetBearer($this->app);

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
