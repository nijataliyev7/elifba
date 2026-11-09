<?php

/*
|--------------------------------------------------------------------------
| Trusted Proxies
|--------------------------------------------------------------------------
|
| The proxies whose X-Forwarded-* headers Laravel believes (docs/architecture.md
| §10). Illuminate\Http\Middleware\TrustProxies reads this key on every request;
| bootstrap/app.php picks which headers. A comma-separated list of addresses or
| CIDR ranges.
|
| Empty by default, so Herd, `composer dev`, `composer dev:lan` and `herd share`
| behave as if no proxy were there. docker-compose.yml sets it to the private
| ranges: there Caddy is the only thing that reaches `app`, and it replaces any
| X-Forwarded-* header a browser sends. Then `asset()`, redirects and the
| session cookie's Secure flag follow the browser's scheme (https).
|
*/

return [

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
