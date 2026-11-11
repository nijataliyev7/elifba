<?php

/**
 * Draws the `cities` pack's pictures: one outline of Azerbaijan, identical in
 * every file, with a pin on the city (docs/architecture.md §8).
 *
 *     php scripts/city-maps/generate.php <ne_10m_admin_0_countries.geojson> [output folder]
 *
 * The outline is Natural Earth's 1:10m admin-0 polygon of Azerbaijan (public
 * domain), which holds Nakhchivan and Karabakh inside the internationally
 * recognised border with no line between Karabakh and the rest. Download it
 * outside the repository (see README.md); it is never committed. The city
 * points come from `cities.csv` next to this file, with the source of every
 * row. The output folder defaults to `content/assets/cities`.
 *
 * Plain PHP with no dependencies, so it runs without booting the app.
 */
const NATURAL_EARTH = 'Natural Earth 1:10m Cultural Vectors, Admin 0 – Countries, version 5.1.2 (public domain)';

/** The 32 grid of ADR 014's own-art guide: the subject fills 1–31, as Fluent's pictures do. */
const SAFE_MIN = 1.0;

const SAFE_MAX = 31.0;

/** Half the rim's width: the rim reaches this far outside the land. */
const HALF_STROKE = 0.25;

/** The land's rim, in the shade of its colour (ADR 014 decision 9). */
const RIM = 0.5;

/** Parts of the border smaller than this (in grid units²) vanish at the picture's size: the small exclaves, Artsvashen and the Caspian islands. */
const MIN_PART_AREA = 0.4;

/** Douglas–Peucker tolerance in grid units: below what a projector shows. */
const SIMPLIFY = 0.11;

/** How far (grid units, about 2 km) a point may lie off the detailed coast. */
const COAST_SLACK = 0.11;

/** Standard parallel of the equirectangular projection (the middle of Azerbaijan). */
const STANDARD_PARALLEL = 40.0;

/** The pin, drawn with its tip at (0, 0): a needle up to a sphere. */
const NEEDLE = 3.8;

const HEAD_RADIUS = 2.2;

const PIN_HEIGHT = NEEDLE + 2 * HEAD_RADIUS;

const PIN_HALF_WIDTH = HEAD_RADIUS;

/** ADR 014's palette (light, base, shade): leaf green for the land, red for the pin's head, stone for its needle. */
const LEAF = ['#CFFF73', '#96C860', '#74A41C'];

const RED = ['#FF6180', '#F24756', '#B22945'];

const STONE = ['#DAD9DB', '#C2BDC9', '#988EA1'];

main($argv);

/**
 * @param  list<string>  $argv
 */
function main(array $argv): void
{
    $source = $argv[1] ?? null;

    if ($source === null || ! is_file($source)) {
        fail('usage: php scripts/city-maps/generate.php <ne_10m_admin_0_countries.geojson> [output folder]');
    }

    $output = rtrim($argv[2] ?? dirname(__DIR__, 2).'/content/assets/cities', '/');

    if (! is_dir($output) && ! mkdir($output, 0755, true)) {
        fail("cannot create {$output}");
    }

    $rings = azerbaijan($source);
    $cities = cities(__DIR__.'/cities.csv');

    // Project everything once, then find the largest scale at which the map
    // and every city's pin fit the safe area together.
    $projectedRings = array_map(fn (array $ring): array => array_map(project(...), $ring), $rings);
    $points = array_map(fn (array $city): array => project([$city['lon'], $city['lat']]), $cities);
    [$scale, $dx, $dy] = fit($projectedRings, $points);

    $place = fn (array $p): array => [$p[0] * $scale + $dx, $p[1] * $scale + $dy];
    $detailed = [];

    foreach ($projectedRings as $ring) {
        $ring = array_map($place, $ring);

        if (abs(area($ring)) >= MIN_PART_AREA) {
            $detailed[] = $ring;
        }
    }

    $parts = array_map(fn (array $ring): array => simplify($ring, SIMPLIFY), $detailed);

    $outline = implode(' ', array_map(path(...), $parts));

    foreach ($cities as $i => $city) {
        $tip = $place($points[$i]);

        // Checked against the detailed border, so a typo in cities.csv fails.
        // The 1:10m coast is generalised: a coastal town (Astara) may lie a
        // few hundred metres off it, and a pin there still touches the land.
        if (! inside($tip, $detailed) && distance($tip, $detailed) > COAST_SLACK) {
            fail("{$city['word']} ({$city['lat']}, {$city['lon']}) falls outside Azerbaijan's border");
        }

        file_put_contents("{$output}/{$city['file']}.svg", svg($city, $outline, $tip));
    }

    fwrite(STDOUT, count($cities)." maps written to {$output} (".count($parts)." parts of the border kept)\n");
}

/**
 * The outer rings and holes of Azerbaijan's polygons, as [lon, lat] lists.
 *
 * @return list<list<array{0: float, 1: float}>>
 */
function azerbaijan(string $path): array
{
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    foreach ($data['features'] ?? [] as $feature) {
        if (($feature['properties']['ADM0_A3'] ?? null) !== 'AZE') {
            continue;
        }

        $geometry = $feature['geometry'];
        $polygons = $geometry['type'] === 'MultiPolygon' ? $geometry['coordinates'] : [$geometry['coordinates']];
        $rings = [];

        foreach ($polygons as $polygon) {
            foreach ($polygon as $ring) {
                $rings[] = array_map(fn (array $c): array => [(float) $c[0], (float) $c[1]], $ring);
            }
        }

        return $rings;
    }

    fail("{$path} has no feature with ADM0_A3 = AZE; is it ne_10m_admin_0_countries?");
}

/**
 * @return list<array{word: string, file: string, lat: float, lon: float, source: string, reference: string}>
 */
function cities(string $path): array
{
    $handle = fopen($path, 'r');

    if ($handle === false) {
        fail("cannot read {$path}");
    }

    $header = fgetcsv($handle, escape: '');
    $cities = [];

    while (($row = fgetcsv($handle, escape: '')) !== false) {
        $row = array_combine($header, $row);

        if (preg_match('/^[a-z0-9-]+$/', $row['file']) !== 1) {
            fail("{$row['word']}: the file name \"{$row['file']}\" must be lowercase ASCII");
        }

        $cities[] = [
            'word' => $row['word'],
            'file' => $row['file'],
            'lat' => (float) $row['lat'],
            'lon' => (float) $row['lon'],
            'source' => $row['source'],
            'reference' => $row['reference'],
        ];
    }

    fclose($handle);

    return $cities;
}

/**
 * Equirectangular, longitudes shortened by cos(STANDARD_PARALLEL), y down.
 *
 * @param  array{0: float, 1: float}  $lonLat
 * @return array{0: float, 1: float}
 */
function project(array $lonLat): array
{
    return [$lonLat[0] * cos(deg2rad(STANDARD_PARALLEL)), -$lonLat[1]];
}

/**
 * The scale and offset that fit the map and every pin into the safe area,
 * centred.
 *
 * @param  list<list<array{0: float, 1: float}>>  $rings
 * @param  list<array{0: float, 1: float}>  $points
 * @return array{0: float, 1: float, 2: float}
 */
function fit(array $rings, array $points): array
{
    $all = array_merge(...$rings);
    $minX = min(array_column($all, 0));
    $maxX = max(array_column($all, 0));
    $minY = min(array_column($all, 1));
    $maxY = max(array_column($all, 1));

    // Bounds of map and pins at a given scale, relative to the map's corner.
    $bounds = function (float $s) use ($points, $minX, $maxX, $minY, $maxY): array {
        $left = 0.0;
        $right = ($maxX - $minX) * $s;
        $top = 0.0;
        $bottom = ($maxY - $minY) * $s;

        foreach ($points as [$x, $y]) {
            $left = min($left, ($x - $minX) * $s - PIN_HALF_WIDTH);
            $right = max($right, ($x - $minX) * $s + PIN_HALF_WIDTH);
            $top = min($top, ($y - $minY) * $s - PIN_HEIGHT);
        }

        return [$left - HALF_STROKE, $right + HALF_STROKE, $top - HALF_STROKE, $bottom + HALF_STROKE];
    };

    $room = SAFE_MAX - SAFE_MIN;
    $low = 0.0;
    $high = $room / min($maxX - $minX, $maxY - $minY);

    for ($i = 0; $i < 60; $i++) {
        $mid = ($low + $high) / 2;
        [$left, $right, $top, $bottom] = $bounds($mid);
        ($right - $left <= $room && $bottom - $top <= $room) ? $low = $mid : $high = $mid;
    }

    [$left, $right, $top, $bottom] = $bounds($low);
    $dx = SAFE_MIN + ($room - ($right - $left)) / 2 - $left - $minX * $low;
    $dy = SAFE_MIN + ($room - ($bottom - $top)) / 2 - $top - $minY * $low;

    return [$low, $dx, $dy];
}

/**
 * Signed area (shoelace).
 *
 * @param  list<array{0: float, 1: float}>  $ring
 */
function area(array $ring): float
{
    $sum = 0.0;
    $n = count($ring);

    for ($i = 0; $i < $n; $i++) {
        [$x1, $y1] = $ring[$i];
        [$x2, $y2] = $ring[($i + 1) % $n];
        $sum += $x1 * $y2 - $x2 * $y1;
    }

    return $sum / 2;
}

/**
 * Douglas–Peucker on a closed ring: split it at the point farthest from the
 * first, simplify both halves.
 *
 * @param  list<array{0: float, 1: float}>  $ring
 * @return list<array{0: float, 1: float}>
 */
function simplify(array $ring, float $tolerance): array
{
    if ($ring[0] === $ring[count($ring) - 1]) {
        array_pop($ring);
    }

    $far = 0;
    $farthest = -1.0;

    foreach ($ring as $i => $p) {
        $d = hypot($p[0] - $ring[0][0], $p[1] - $ring[0][1]);

        if ($d > $farthest) {
            [$far, $farthest] = [$i, $d];
        }
    }

    $first = douglasPeucker(array_slice($ring, 0, $far + 1), $tolerance);
    $second = douglasPeucker([...array_slice($ring, $far), $ring[0]], $tolerance);

    return [...$first, ...array_slice($second, 1, -1)];
}

/**
 * @param  list<array{0: float, 1: float}>  $line
 * @return list<array{0: float, 1: float}>
 */
function douglasPeucker(array $line, float $tolerance): array
{
    $n = count($line);

    if ($n < 3) {
        return $line;
    }

    [$ax, $ay] = $line[0];
    [$bx, $by] = $line[$n - 1];
    $length = hypot($bx - $ax, $by - $ay);
    $index = 0;
    $max = 0.0;

    for ($i = 1; $i < $n - 1; $i++) {
        [$px, $py] = $line[$i];
        $d = $length == 0.0
            ? hypot($px - $ax, $py - $ay)
            : abs(($bx - $ax) * ($ay - $py) - ($ax - $px) * ($by - $ay)) / $length;

        if ($d > $max) {
            [$index, $max] = [$i, $d];
        }
    }

    if ($max <= $tolerance) {
        return [$line[0], $line[$n - 1]];
    }

    $left = douglasPeucker(array_slice($line, 0, $index + 1), $tolerance);
    $right = douglasPeucker(array_slice($line, $index), $tolerance);

    return [...array_slice($left, 0, -1), ...$right];
}

/**
 * Even–odd point-in-polygon over every kept part.
 *
 * @param  array{0: float, 1: float}  $p
 * @param  list<list<array{0: float, 1: float}>>  $parts
 */
function inside(array $p, array $parts): bool
{
    $in = false;

    foreach ($parts as $ring) {
        $n = count($ring);

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];

            if (($yi > $p[1]) !== ($yj > $p[1]) && $p[0] < ($xj - $xi) * ($p[1] - $yi) / ($yj - $yi) + $xi) {
                $in = ! $in;
            }
        }
    }

    return $in;
}

/**
 * Distance from a point to the nearest edge of any part.
 *
 * @param  array{0: float, 1: float}  $p
 * @param  list<list<array{0: float, 1: float}>>  $parts
 */
function distance(array $p, array $parts): float
{
    $best = INF;

    foreach ($parts as $ring) {
        $n = count($ring);

        for ($i = 0; $i < $n; $i++) {
            [$ax, $ay] = $ring[$i];
            [$bx, $by] = $ring[($i + 1) % $n];
            $dx = $bx - $ax;
            $dy = $by - $ay;
            $length = $dx * $dx + $dy * $dy;
            $t = $length == 0.0 ? 0.0 : max(0.0, min(1.0, (($p[0] - $ax) * $dx + ($p[1] - $ay) * $dy) / $length));
            $best = min($best, hypot($p[0] - $ax - $t * $dx, $p[1] - $ay - $t * $dy));
        }
    }

    return $best;
}

/**
 * @param  list<array{0: float, 1: float}>  $ring
 */
function path(array $ring): string
{
    $points = array_map(fn (array $p): string => number($p[0]).','.number($p[1]), $ring);

    return 'M'.implode(' L', $points).' Z';
}

function number(float $value): string
{
    $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

    return $text === '-0' ? '0' : $text;
}

/**
 * One map in ADR 014's own-art manner: no black outline, light from the top
 * left. The land is one surface (a radial gradient light → base → shade from
 * its top left, a shade from its bottom right) with a thin rim of its shade
 * colour; the pin is a red sphere with a soft highlight on a stone needle.
 *
 * @param  array{word: string, file: string, lat: float, lon: float, source: string, reference: string}  $city
 * @param  array{0: float, 1: float}  $tip
 */
function svg(array $city, string $outline, array $tip): string
{
    $x = number($tip[0]);
    $y = number($tip[1]);
    $id = str_replace('-', '_', $city['file']);
    [$leafLight, $leaf, $leafShade] = LEAF;
    [$redLight, $red, $redShade] = RED;
    [, $stone, $stoneShade] = STONE;
    $rim = number(RIM);
    $needle = number(-NEEDLE);
    $head = number(-NEEDLE - HEAD_RADIUS);
    $radius = number(HEAD_RADIUS);
    $shine = [number(-HEAD_RADIUS * 0.36), number(-NEEDLE - HEAD_RADIUS * 1.42), number(HEAD_RADIUS * 0.36), number(HEAD_RADIUS * 0.24)];
    $natural = NATURAL_EARTH;
    $point = "{$city['source']}, {$city['reference']}";

    return <<<SVG
        <svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
          <!-- {$city['word']}: a map of Azerbaijan with a pin on the city. Elifba own art, MIT. Generated by scripts/city-maps/generate.php; do not edit by hand. -->
          <!-- Outline: {$natural}. Point: {$city['lat']}, {$city['lon']} ({$point}). -->
          <defs>
            <radialGradient id="{$id}_land" cx="0.3" cy="0.25" r="0.8"><stop offset="0" stop-color="{$leafLight}"/><stop offset="0.5" stop-color="{$leaf}"/><stop offset="1" stop-color="{$leafShade}"/></radialGradient>
            <radialGradient id="{$id}_shade" cx="0.85" cy="0.9" r="0.7"><stop offset="0" stop-color="{$leafShade}" stop-opacity="0.6"/><stop offset="0.9" stop-color="{$leafShade}" stop-opacity="0"/></radialGradient>
            <linearGradient id="{$id}_needle" x1="0" y1="0" x2="0" y2="{$needle}" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="{$stoneShade}"/><stop offset="1" stop-color="{$stone}"/></linearGradient>
            <radialGradient id="{$id}_head" cx="0.3" cy="0.25" r="0.8"><stop offset="0" stop-color="{$redLight}"/><stop offset="0.5" stop-color="{$red}"/><stop offset="1" stop-color="{$redShade}"/></radialGradient>
            <filter id="{$id}_blur" x="-1" y="-1" width="3" height="3"><feGaussianBlur stdDeviation="0.3"/></filter>
          </defs>
          <path d="{$outline}" fill="url(#{$id}_land)" fill-rule="evenodd"/>
          <path d="{$outline}" fill="url(#{$id}_shade)" fill-rule="evenodd" stroke="{$leafShade}" stroke-width="{$rim}" stroke-linejoin="round"/>
          <g transform="translate({$x} {$y})">
            <path d="M0,0 L0,{$needle}" stroke="url(#{$id}_needle)" stroke-width="0.8" stroke-linecap="round"/>
            <circle cx="0" cy="{$head}" r="{$radius}" fill="url(#{$id}_head)"/>
            <ellipse cx="{$shine[0]}" cy="{$shine[1]}" rx="{$shine[2]}" ry="{$shine[3]}" transform="rotate(-35 {$shine[0]} {$shine[1]})" fill="#FFE3E8" fill-opacity="0.85" filter="url(#{$id}_blur)"/>
          </g>
        </svg>

        SVG;
}

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}
