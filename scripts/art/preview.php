<?php

/**
 * Writes a self-contained HTML page that shows a pack's pictures and figures
 * in copies of the board's picture tile at 384 and 96 px, on the board's
 * background (ADR 014: a drawing is checked between its pack's pictures).
 *
 *     php scripts/art/preview.php <pack> [output.html] [extra.svg …]
 *
 * The tile copies `resources/js/game/styles/picture.ts` (the pack colour,
 * face shade 0.86, radius 0.16 of the side, picture 0.8 of the side) and the
 * background `palette.ts` (the first palette colour at 8 % over white).
 * Extra SVG files, such as a drawing not yet in the pack, are shown first.
 * Every picture is embedded, so the page makes no request. The output
 * defaults to `{pack}-preview.html` in the system's temporary folder.
 *
 * Plain PHP with no dependencies, so it runs without booting the app.
 */
$root = dirname(__DIR__, 2);
$key = $argv[1] ?? '';
$file = "{$root}/content/packs/{$key}.json";

if (preg_match('/^[a-z0-9-]+$/', $key) !== 1 || ! is_file($file)) {
    fwrite(STDERR, "Usage: php scripts/art/preview.php <pack> [output.html] [extra.svg …]\n");
    exit(2);
}

$pack = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
$output = $argv[2] ?? sys_get_temp_dir()."/{$key}-preview.html";
$palette = $pack['style']['palette'];

/** @var array<string, string> $pictures label => SVG path */
$pictures = [];

foreach (array_slice($argv, 3) as $extra) {
    $pictures['+ '.basename($extra, '.svg')] = $extra;
}

foreach ($pack['words'] ?? [] as $word) {
    if (isset($word['image'])) {
        $pictures[$word['text']] = "{$root}/content/assets/{$word['image']}";
    }
}

foreach ($pack['style']['figures'] ?? [] as $figure) {
    if (! in_array("{$root}/content/assets/{$figure}", $pictures, true)) {
        $pictures['fig. '.basename($figure, '.svg')] = "{$root}/content/assets/{$figure}";
    }
}

/** Mixes a #RRGGBB colour towards white (amount > 0) as `shade()` in item.ts does. */
function mix(string $colour, float $amount): string
{
    $channels = array_map(
        fn (string $hex): int => (int) round(hexdec($hex) + (255 - hexdec($hex)) * $amount),
        str_split(substr($colour, 1), 2),
    );

    return vsprintf('#%02X%02X%02X', $channels);
}

$tiles = ['big' => '', 'small' => ''];
$index = 0;

foreach ($pictures as $label => $path) {
    if (! is_file($path)) {
        fwrite(STDERR, "Missing: {$path}\n");
        exit(1);
    }

    $colour = $palette[$index++ % count($palette)];
    $uri = 'data:image/svg+xml;base64,'.base64_encode((string) file_get_contents($path));
    $face = mix($colour, 0.86);
    $name = htmlspecialchars((string) $label);

    foreach (array_keys($tiles) as $size) {
        $tiles[$size] .= "<figure class=\"{$size}\"><div style=\"--c:{$colour};--f:{$face};background-image:url('{$uri}')\"></div><figcaption>{$name}</figcaption></figure>";
    }
}

$background = mix($palette[0], 0.92);
$title = htmlspecialchars("{$key}: pictures in the board's tile");

file_put_contents($output, <<<HTML
<!doctype html>
<html lang="az">
<head>
<meta charset="utf-8">
<title>{$title}</title>
<style>
body { margin: 0; padding: 16px; background: {$background}; color: #1F2937; font: 14px system-ui, sans-serif; }
section { display: flex; flex-wrap: wrap; gap: 18px; margin-bottom: 32px; }
figure { margin: 0; text-align: center; }
figure div { background-color: var(--f); border: solid var(--c); background-repeat: no-repeat; background-position: center; background-size: 80% 80%; box-sizing: border-box; }
.big div { width: 384px; height: 384px; border-radius: 61px; border-width: 11.5px; box-shadow: 8px 12px 0 rgba(31, 41, 55, .14); }
.small div { width: 96px; height: 96px; border-radius: 15px; border-width: 2.9px; box-shadow: 2px 3px 0 rgba(31, 41, 55, .14); }
figcaption { margin-top: 6px; color: #6B7280; }
</style>
</head>
<body>
<h1>{$title}</h1>
<h2>96 px</h2>
<section>{$tiles['small']}</section>
<h2>384 px</h2>
<section>{$tiles['big']}</section>
</body>
</html>

HTML);

echo "{$output}\n";
