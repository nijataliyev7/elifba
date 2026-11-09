<?php

/*
 * `composer dev:lan` (docs/architecture.md §10): the dev stack on the
 * laptop's LAN address, so a real phone on the same Wi-Fi can open the
 * remote — phones cannot resolve `elifba.test`.
 *
 * It finds the LAN IP (or takes it as the first argument, or from
 * ELIFBA_LAN_IP), and runs the app server, Reverb, Vite and the log tail
 * with APP_URL, REVERB_HOST and VITE_REVERB_HOST pointing at it. The
 * overrides live only in these processes' environment: `.env` is never
 * edited, and Laravel's .env loader does not overwrite variables that are
 * already set. `artisan serve` runs with --no-reload, because its reload
 * mode drops every variable not read from `.env`.
 *
 *   composer dev:lan                  # detect the IP
 *   composer dev:lan -- 192.168.1.20  # or name it
 */

$root = dirname(__DIR__);
$ip = $argv[1] ?? (getenv('ELIFBA_LAN_IP') ?: null) ?? lanIp();

if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
    fwrite(STDERR, "Could not find the laptop's LAN IPv4 address. Pass it: composer dev:lan -- 192.168.1.20\n");
    exit(1);
}

require $root.'/vendor/autoload.php';

// .env as written, without loading it into this process: Reverb keeps the
// port it has there (another app may hold 8080), but on the LAN it is plain
// ws on that port, with no Herd certificate (REVERB_HOST is the IP).
$dotenv = Dotenv\Dotenv::createArrayBacked($root)->safeLoad();
$appPort = getenv('SERVER_PORT') ?: '8000';
$reverbPort = getenv('REVERB_SERVER_PORT') ?: ($dotenv['REVERB_SERVER_PORT'] ?? null) ?: ($dotenv['REVERB_PORT'] ?? null) ?: '8080';
$appUrl = "http://{$ip}:{$appPort}";

$env = [
    ...getenv(),
    'APP_URL' => $appUrl,
    'SERVER_HOST' => '0.0.0.0',
    'SERVER_PORT' => $appPort,
    // The app broadcasts to Reverb on the same address the phone uses.
    'REVERB_HOST' => $ip,
    'REVERB_PORT' => $reverbPort,
    'REVERB_SCHEME' => 'http',
    'REVERB_SERVER_HOST' => '0.0.0.0',
    'REVERB_SERVER_PORT' => $reverbPort,
    'VITE_REVERB_HOST' => $ip,
    'VITE_REVERB_PORT' => $reverbPort,
    'VITE_REVERB_SCHEME' => 'http',
    // vite.config.ts listens on every interface and serves HMR from this host.
    'ELIFBA_LAN_HOST' => $ip,
];

$commands = [
    'server' => 'php artisan serve --no-reload',
    'reverb' => 'php artisan reverb:start',
    'vite' => 'npm run dev',
];

if (function_exists('pcntl_fork')) {
    $commands['logs'] = 'php artisan pail --timeout=0';
}

fwrite(STDOUT, "\n  Elifba on the LAN: {$appUrl}\n  Phones on the same Wi-Fi open {$appUrl}/pult\n\n");

$process = proc_open(
    [
        $root.'/node_modules/.bin/concurrently',
        '--kill-others-on-fail',
        '--names='.implode(',', array_keys($commands)),
        '--prefix-colors=#93c5fd,#c4b5fd,#fdba74,#86efac',
        ...array_values($commands),
    ],
    [STDIN, STDOUT, STDERR],
    $pipes,
    $root,
    $env,
);

exit(is_resource($process) ? proc_close($process) : 1);

/**
 * The address this machine would use to reach the internet. Connecting a
 * UDP socket sends no packet; it only picks the outgoing interface.
 */
function lanIp(): ?string
{
    $socket = @stream_socket_client('udp://192.0.2.1:9', $errno, $error, 1);

    if ($socket === false) {
        return null;
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    if ($name === false) {
        return null;
    }

    $host = substr($name, 0, (int) strrpos($name, ':'));

    return $host === '' || str_starts_with($host, '127.') ? null : $host;
}
