<?php

/**
 * Checks `scripts/art/inventory.csv` against the packs (ADR 014):
 *
 *     php scripts/art/check-inventory.php [--done=N]
 *
 * Every picture, figure and backdrop a pack in `content/packs/` references
 * must be the `current_file` or the `new_file` of a row of that pack, and
 * every row must be well formed. With `--done=N`, the rows of sessions up to
 * N must be migrated: their pack references `new_file` and the file exists.
 *
 * Plain PHP with no dependencies, so it runs without booting the app.
 */
const COLUMNS = ['pack', 'kind', 'word_or_figure', 'current_file', 'new_source', 'new_file', 'fluent_file', 'session', 'notes'];

const KINDS = ['word', 'word+figure', 'figure', 'backdrop'];

const SESSIONS = [2, 3, 4, 5];

$root = dirname(__DIR__, 2);
$done = 0;

foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--done=(\d)$/', $argument, $match) === 1) {
        $done = (int) $match[1];
    } else {
        fwrite(STDERR, "Usage: php scripts/art/check-inventory.php [--done=N]\n");
        exit(2);
    }
}

/** @var list<string> $problems */
$problems = [];

/** @var array<string, list<string>> $references pack key => the asset paths it references */
$references = [];

foreach (glob("{$root}/content/packs/*.json") ?: [] as $file) {
    $pack = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $paths = [];

    foreach ($pack['words'] ?? [] as $word) {
        if (isset($word['image'])) {
            $paths[] = $word['image'];
        }
    }

    foreach ($pack['style']['figures'] ?? [] as $figure) {
        $paths[] = $figure;
    }

    if (isset($pack['style']['backdrop'])) {
        $paths[] = $pack['style']['backdrop'];
    }

    $references[$pack['key']] = array_values(array_unique($paths));
}

$handle = fopen(__DIR__.'/inventory.csv', 'r');

if ($handle === false) {
    fwrite(STDERR, "Cannot read scripts/art/inventory.csv\n");
    exit(2);
}

if (fgetcsv($handle, null, ',', '"', '') !== COLUMNS) {
    fwrite(STDERR, 'The header must be: '.implode(',', COLUMNS)."\n");
    exit(1);
}

/** @var array<string, list<array<string, string>>> $rows pack key => its rows */
$rows = [];
$line = 1;

while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
    $line++;

    if (count($values) !== count(COLUMNS)) {
        $problems[] = "line {$line}: ".count($values).' columns, not '.count(COLUMNS);

        continue;
    }

    $row = array_combine(COLUMNS, array_map('strval', $values));
    $where = "line {$line} ({$row['pack']}, {$row['word_or_figure']})";

    if (! isset($references[$row['pack']])) {
        $problems[] = "{$where}: no pack \"{$row['pack']}\"";
    }

    if (! in_array($row['kind'], KINDS, true)) {
        $problems[] = "{$where}: kind must be one of ".implode(', ', KINDS);
    }

    if (! in_array((int) $row['session'], SESSIONS, true)) {
        $problems[] = "{$where}: session must be one of ".implode(', ', SESSIONS);
    }

    if ($row['new_source'] === 'own') {
        if ($row['fluent_file'] !== '') {
            $problems[] = "{$where}: an own picture has no fluent_file";
        }
    } elseif (preg_match('/^fluent:([0-9A-F]+(?:-[0-9A-F]+)*)$/', $row['new_source'], $match) === 1) {
        if ($row['new_file'] !== "fluent/{$match[1]}.svg") {
            $problems[] = "{$where}: new_file must be fluent/{$match[1]}.svg";
        }

        if (preg_match('#^assets/[^/]+/(?:Default/)?Color/[a-z0-9_-]+\.svg$#', $row['fluent_file']) !== 1) {
            $problems[] = "{$where}: fluent_file must be a Color SVG under assets/ of fluentui-emoji";
        }
    } else {
        $problems[] = "{$where}: new_source must be \"own\" or \"fluent:{code point}\"";
    }

    foreach ($rows[$row['pack']] ?? [] as $other) {
        if ($other['current_file'] === $row['current_file']) {
            $problems[] = "{$where}: {$row['current_file']} has a row already";
        }
    }

    $rows[$row['pack']][] = $row;
}

fclose($handle);

$count = 0;

foreach ($references as $pack => $paths) {
    foreach ($paths as $path) {
        $count++;
        $covered = false;

        foreach ($rows[$pack] ?? [] as $row) {
            $covered = $covered || $row['current_file'] === $path || $row['new_file'] === $path;
        }

        if (! $covered) {
            $problems[] = "{$pack}: {$path} has no row";
        }
    }

    foreach ($rows[$pack] ?? [] as $row) {
        $migrated = in_array($row['new_file'], $paths, true);

        if (! $migrated && ! in_array($row['current_file'], $paths, true)) {
            $problems[] = "{$pack}, {$row['word_or_figure']}: the pack references neither {$row['current_file']} nor {$row['new_file']}";
        }

        if ((int) $row['session'] <= $done) {
            if (! $migrated) {
                $problems[] = "{$pack}, {$row['word_or_figure']}: session {$row['session']} is done, but the pack does not reference {$row['new_file']}";
            }

            if (! is_file("{$root}/content/assets/{$row['new_file']}")) {
                $problems[] = "{$pack}, {$row['word_or_figure']}: content/assets/{$row['new_file']} is missing";
            }
        }
    }
}

if ($problems !== []) {
    fwrite(STDERR, implode("\n", $problems)."\n");
    exit(1);
}

$total = array_sum(array_map('count', $rows));
echo "inventory.csv: {$total} rows cover the {$count} pictures, figures and backdrops of ".count($references)." packs\n";
