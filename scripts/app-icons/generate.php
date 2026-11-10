<?php

/**
 * Draws Elifba's app icons from the logo's master file (docs/architecture.md
 * §8 "Elifba's mark", §10 "Installing Elifba"):
 *
 *     php scripts/app-icons/generate.php
 *
 * - `public/icons/elifba-192.png`, `elifba-512.png`: `tile.svg` as it is, the
 *   rounded green tile with transparent corners (the manifest's `any` icons:
 *   the desktop's dock or taskbar, the install dialog).
 * - `public/icons/elifba-maskable-512.png`: the manifest's `maskable` icon for
 *   Android, full-bleed green with the mark at 80 % of the tile's size, so a
 *   launcher's circle or squircle (at least the centre circle of 40 % radius)
 *   never cuts it.
 * - `public/apple-touch-icon.png` (180×180): full-bleed green with the mark as
 *   on the tile; iOS rounds the corners itself and would fill transparent ones
 *   with black. iOS also asks for this path when a page names none.
 *
 * Chrome draws the SVG (headless `--screenshot`), the engine that draws the
 * favicon. Set CHROME to its binary when it is not in the usual place. Plain
 * PHP with no dependencies, so it runs without booting the app.
 */
$root = dirname(__DIR__, 2);
$master = "{$root}/resources/images/brand/tile.svg";

$tile = file_get_contents($master);

if ($tile === false) {
    fwrite(STDERR, "Cannot read {$master}\n");
    exit(1);
}

$tile = trim((string) preg_replace('/<!--.*?-->/s', '', $tile));

if (preg_match('/<rect [^>]*fill="(#[0-9A-Fa-f]{6})"/', $tile, $green) !== 1
    || preg_match('/<g fill="#fff">.*?<\/g>/s', $tile, $mark) !== 1) {
    fwrite(STDERR, "{$master} is not the expected tile: a <rect> with the green and a <g fill=\"#fff\"> with the mark\n");
    exit(1);
}

/** The green square without rounded corners, the mark scaled about the centre. */
$fullBleed = static fn (float $scale): string => sprintf(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="%s"/><g transform="translate(32 32) scale(%s) translate(-32 -32)">%s</g></svg>',
    $green[1],
    $scale,
    $mark[0],
);

$icons = [
    'public/icons/elifba-192.png' => [$tile, 192],
    'public/icons/elifba-512.png' => [$tile, 512],
    'public/icons/elifba-maskable-512.png' => [$fullBleed(0.8), 512],
    'public/apple-touch-icon.png' => [$fullBleed(1.0), 180],
];

$chrome = getenv('CHROME') ?: chromeBinary();

if ($chrome === null) {
    fwrite(STDERR, "Chrome not found; set CHROME to its binary\n");
    exit(1);
}

foreach ($icons as $path => [$svg, $size]) {
    render($chrome, $svg, $size, "{$root}/{$path}");
    echo "{$path} ({$size}×{$size})\n";
}

function chromeBinary(): ?string
{
    $candidates = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        '/usr/bin/google-chrome',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
    ];

    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/** Draws `$svg` at `$size`×`$size` pixels into a PNG with a transparent background. */
function render(string $chrome, string $svg, int $size, string $out): void
{
    $work = sys_get_temp_dir().'/elifba-icons-'.bin2hex(random_bytes(6));
    mkdir($work, 0700, true);

    $page = "{$work}/icon.html";
    $sized = preg_replace('/<svg /', sprintf('<svg width="%1$d" height="%1$d" ', $size), $svg, 1);
    file_put_contents($page, '<!doctype html><html><head><style>html,body{margin:0;background:transparent}svg{display:block}</style></head><body>'.$sized.'</body></html>');

    if (! is_dir(dirname($out))) {
        mkdir(dirname($out), 0755, true);
    }

    $command = implode(' ', [
        escapeshellarg($chrome),
        '--headless',
        '--disable-gpu',
        '--hide-scrollbars',
        '--force-device-scale-factor=1',
        '--default-background-color=00000000',
        "--window-size={$size},{$size}",
        '--user-data-dir='.escapeshellarg("{$work}/profile"),
        '--screenshot='.escapeshellarg($out),
        escapeshellarg("file://{$page}"),
        '2>/dev/null',
    ]);

    exec($command, $output, $status);
    exec('rm -rf '.escapeshellarg($work));

    $drawn = is_file($out) ? getimagesize($out) : false;

    if ($status !== 0 || $drawn === false || $drawn[0] !== $size || $drawn[1] !== $size) {
        fwrite(STDERR, "Chrome did not draw {$out} at {$size}×{$size}\n");
        exit(1);
    }
}
