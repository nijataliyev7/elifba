<?php

namespace App\Domain\Play\Data;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * A pairing code as a board shows it (docs/architecture.md §4): the 6
 * digits, when they expire, the QR's URL `{APP_URL}/pult/{code}` and the
 * address a teacher types instead (`{APP_URL}/pult`). The URLs are built on
 * `APP_URL`, not on the request's host, so `composer dev:lan` and a proxy
 * give phones an address they can open.
 *
 * @phpstan-type PairingArray array{code: string, expires_at: string, url: string, entry_url: string}
 */
final readonly class PairingData
{
    public function __construct(
        public string $code,
        public CarbonImmutable $expires_at,
    ) {}

    /**
     * @return PairingArray
     */
    public function toArray(): array
    {
        $root = Config::string('app.url');

        return [
            'code' => $this->code,
            'expires_at' => $this->expires_at->utc()->toIso8601ZuluString(),
            'url' => URL::format($root, route('pult.code', ['code' => $this->code], false)),
            'entry_url' => URL::format($root, route('pult', [], false)),
        ];
    }
}
