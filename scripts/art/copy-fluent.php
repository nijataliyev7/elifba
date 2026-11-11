<?php

/**
 * Copies the Fluent Emoji pictures of `scripts/art/inventory.csv` from the
 * download into `content/assets/fluent/` (ADR 014 decision 2):
 *
 *     php scripts/art/copy-fluent.php <fluentui-emoji checkout> --session=N
 *
 * For every `fluent:` row of session N, the row's `fluent_file` is copied
 * unmodified to `content/assets/{new_file}`, after checking that the
 * emoji's `metadata.json` names the row's code point (`FE0F` left out), and
 * the download's `LICENSE` is copied beside the files as `LICENSE.txt`.
 * A file already there with other bytes is an error, never overwritten.
 * The checkout is made as `scripts/art/README.md` "The download" says.
 *
 * Plain PHP with no dependencies, so it runs without booting the app.
 */
$root = dirname(__DIR__, 2);
$download = null;
$session = null;

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--session=(\d)$/', $argument, $match) === 1) {
        $session = (int) $match[1];
    } elseif ($download === null && ! str_starts_with($argument, '--')) {
        $download = rtrim($argument, '/');
    } else {
        $download = null;
        break;
    }
}

if ($download === null || $session === null || ! is_file("{$download}/LICENSE")) {
    fwrite(STDERR, "Usage: php scripts/art/copy-fluent.php <fluentui-emoji checkout> --session=N\n");
    exit(2);
}

$license = (string) file_get_contents("{$download}/LICENSE");

if (! str_contains($license, 'MIT License') || ! str_contains($license, 'Copyright (c) Microsoft Corporation')) {
    fwrite(STDERR, "{$download}/LICENSE is not Microsoft's MIT licence: read it before copying anything\n");
    exit(1);
}

$handle = fopen(__DIR__.'/inventory.csv', 'r');

if ($handle === false) {
    fwrite(STDERR, "Cannot read scripts/art/inventory.csv\n");
    exit(2);
}

$columns = fgetcsv($handle, null, ',', '"', '');

/** @var array<string, string> $copies new_file => fluent_file */
$copies = [];

/** @var list<string> $problems */
$problems = [];

while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
    $row = array_combine((array) $columns, $values);

    if ((int) $row['session'] !== $session || preg_match('/^fluent:([0-9A-F-]+)$/', $row['new_source'], $match) !== 1) {
        continue;
    }

    $source = "{$download}/{$row['fluent_file']}";
    $metadata = dirname($source, str_contains($row['fluent_file'], '/Default/') ? 3 : 2).'/metadata.json';
    $unicode = is_file($metadata) ? json_decode((string) file_get_contents($metadata), true)['unicode'] ?? '' : '';
    $codePoint = strtoupper(implode('-', array_diff(explode(' ', $unicode), ['fe0f'])));

    if (! is_file($source)) {
        $problems[] = "{$row['pack']}, {$row['word_or_figure']}: {$row['fluent_file']} is not in the download";
    } elseif ($codePoint !== $match[1]) {
        $problems[] = "{$row['pack']}, {$row['word_or_figure']}: {$row['fluent_file']} is {$codePoint}, not {$match[1]}";
    } elseif (isset($copies[$row['new_file']]) && $copies[$row['new_file']] !== $row['fluent_file']) {
        $problems[] = "{$row['new_file']} comes from two files";
    } else {
        $copies[$row['new_file']] = $row['fluent_file'];
    }
}

fclose($handle);

$copies['fluent/LICENSE.txt'] = 'LICENSE';

foreach ($copies as $target => $source) {
    $path = "{$root}/content/assets/{$target}";

    if (is_file($path) && file_get_contents($path) !== file_get_contents("{$download}/{$source}")) {
        $problems[] = "content/assets/{$target} exists with other bytes";
    }
}

if ($problems !== []) {
    fwrite(STDERR, implode("\n", $problems)."\n");
    exit(1);
}

if (! is_dir("{$root}/content/assets/fluent")) {
    mkdir("{$root}/content/assets/fluent");
}

foreach ($copies as $target => $source) {
    copy("{$download}/{$source}", "{$root}/content/assets/{$target}");
}

echo 'Copied '.(count($copies) - 1)." Fluent pictures of session {$session} and LICENSE.txt into content/assets/fluent/\n";
