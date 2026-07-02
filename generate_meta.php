<?php

namespace App\Scripts;

require_once __DIR__ . '/meta_fields.php';

/**
 * generate_meta.php
 * -----------------------------------------------------------------------------
 * Generates DETERMINISTIC dummy metadata, one record per unique show
 * (keyed by match_key), for the tier feature.
 *
 *   - Deterministic: the same match_key always yields the same metadata, on
 *     every run, on any machine. Seed = crc32(match_key). Re-running is
 *     idempotent and never churns existing rows.
 *   - Tier-agnostic: generates EVERY field for EVERY show. Whether a tier may
 *     SEE a field is decided later, at read time, via MetaFields::visibleColumns().
 *   - Source-shaped: field set mirrors what iTunes lookup + RSS would really
 *     return, so the schema is honest and a real fetcher can replace this with
 *     no schema migration.
 *
 * INPUT  : scans data/{apple,spotify,youtube}/<country>/<slug>.json for shows.
 * OUTPUT : data/meta/podcast_meta.json   (array of meta records)
 *          and (optional) a CREATE TABLE + INSERT .sql alongside it.
 *
 * NOTE ON FAKER: this uses a small self-contained deterministic generator so
 * the script runs with zero dependencies. If fakerphp/faker is installed in
 * your project, you can swap the body of Gen::* for $faker->...  calls — but
 * you MUST seed it per show: `$faker->seed(crc32($matchKey));` to preserve
 * determinism. The inline approach is kept so a reviewer needs no composer step.
 * -----------------------------------------------------------------------------
 */

// --------------------------------------------------------------------------
// Paths & config
// --------------------------------------------------------------------------
$scriptDir   = __DIR__;
$projectRoot = realpath($scriptDir . '/..') ?: $scriptDir;
$dataDir     = getenv('DATA_DIR') ?: $scriptDir . '/data';
$outDir      = $dataDir . '/meta';
$logDir      = getenv('LOG_DIR') ?: $scriptDir . '/logs';
$emitSql     = in_array('--sql', $argv ?? [], true);
$runDate     = date('Y-m-d');

if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}
$logFile = $logDir . "/generate_meta_{$runDate}.log";

function logMsg(string $level, string $message): void
{
    global $logFile;
    $line = date('Y-m-d H:i:s') . '  ' . str_pad($level, 7) . "  {$message}\n";
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

// --------------------------------------------------------------------------
// match_key normaliser — MUST match adapters/Normalize.php exactly so the
// keys generated here line up with the keys already in the main tables.
// --------------------------------------------------------------------------
function makeMatchKey(?string $name): string
{
    if (empty($name)) {
        return '';
    }
    $text = strtolower($name);
    $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

// --------------------------------------------------------------------------
// Deterministic value generators. Every method takes an integer $seed and
// returns a stable value for that seed. We derive sub-seeds per field so two
// fields on the same show don't collide into identical-looking values.
// --------------------------------------------------------------------------
final class Gen
{
    /** Stable PRNG: linear congruential, seeded. Returns float in [0,1). */
    private static function rng(int $seed): float
    {
        // Knuth LCG constants; keep within 32-bit safe range.
        $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
        return ($seed % 1000000) / 1000000.0;
    }

    private static function pick(int $seed, array $pool): string
    {
        return $pool[(int) floor(self::rng($seed) * count($pool)) % count($pool)];
    }

    private static function intRange(int $seed, int $min, int $max): int
    {
        return $min + (int) floor(self::rng($seed) * ($max - $min + 1));
    }

    private static function floatRange(int $seed, float $min, float $max): float
    {
        return round($min + self::rng($seed) * ($max - $min), 1);
    }

    // ---- pools (curated so output reads believable, not lorem-ipsum) -------
    private const ADJ = ['candid','weekly','unscripted','deep','sharp','honest','no-nonsense','long-form','intimate','irreverent'];
    private const NOUN = ['conversations','stories','interviews','deep dives','debates','breakdowns','reports','dispatches'];
    private const TOPIC = ['technology','true crime','business','culture','history','science','wellness','sports','politics','film'];
    private const VERB = ['unpacks','explores','dissects','celebrates','investigates','demystifies','chronicles'];
    private const HOSTS = ['Maya Chen','Daniel Okafor','Priya Nair','Liam Walsh','Sofia Rossi','Noah Bergström','Aisha Rahman','Marcus Webb','Elena Petrova','Kenji Tanaka'];
    private const NETWORKS = ['Wondery','iHeartPodcasts','NPR','Pushkin Industries','Vox Media','Radiotopia','The Ringer','independent','Spotify Studios','Acast Creator'];
    private const GENRES = ['Arts','Business','Comedy','Education','Fiction','Health & Fitness','History','Leisure','Music','News','Science','Society & Culture','Sports','Technology','True Crime','TV & Film'];
    private const LANGS = ['en','en','en','es','fr','de','pt','it','ja','hi']; // weighted toward en
    private const FREQ = ['Daily','Weekly','Weekly','Weekly','Biweekly','Monthly'];
    private const ADVISORY = ['Clean','Clean','Clean','Explicit'];
    private const COUNTRIES = ['us','gb','ca','au','de','fr','es','it','br','mx','jp','in','nl','se','ie'];

    public static function shortText(int $seed): string
    {
        $adj   = self::pick($seed + 1, self::ADJ);
        $noun  = self::pick($seed + 2, self::NOUN);
        $topic = self::pick($seed + 3, self::TOPIC);
        return ucfirst("{$adj} {$noun} about {$topic}, released on a regular schedule.");
    }

    public static function longText(int $seed): string
    {
        $host  = self::pick($seed + 4, self::HOSTS);
        $verb  = self::pick($seed + 5, self::VERB);
        $topic = self::pick($seed + 6, self::TOPIC);
        $noun  = self::pick($seed + 7, self::NOUN);
        $net   = self::pick($seed + 8, self::NETWORKS);
        return "Hosted by {$host}, this show {$verb} the world of {$topic} through {$noun} "
             . "with guests, experts, and the occasional surprise. Each episode blends reporting "
             . "and discussion to give listeners context they won't get from the headlines. "
             . ($net === 'independent' ? "Produced independently." : "A {$net} production.");
    }

    public static function genre(int $seed): string       { return self::pick($seed + 9, self::GENRES); }
    public static function publisher(int $seed): string   { return self::pick($seed + 10, self::HOSTS); }
    public static function language(int $seed): string    { return self::pick($seed + 11, self::LANGS); }
    public static function freq(int $seed): string        { return self::pick($seed + 12, self::FREQ); }
    public static function advisory(int $seed): string    { return self::pick($seed + 13, self::ADVISORY); }

    public static function url(int $seed): string
    {
        $slug = self::pick($seed + 14, self::TOPIC);
        $n    = self::intRange($seed + 15, 1, 999);
        return "https://{$slug}-show-{$n}.example.com";
    }

    public static function feedUrl(int $seed): string
    {
        $n = self::intRange($seed + 16, 100000, 999999);
        return "https://feeds.example.com/podcasts/{$n}/rss.xml";
    }

    public static function intRangeField(int $seed, int $min, int $max): int
    {
        return self::intRange($seed + 17, $min, $max);
    }

    public static function floatRangeField(int $seed, float $min, float $max): float
    {
        return self::floatRange($seed + 18, $min, $max);
    }

    /** A date 1–12 years in the past, stable per seed. */
    public static function datePast(int $seed): string
    {
        $daysAgo = self::intRange($seed + 19, 365, 365 * 12);
        return date('Y-m-d', strtotime("-{$daysAgo} days"));
    }

    /** A recent date 0–45 days ago, stable per seed. */
    public static function dateRecent(int $seed): string
    {
        $daysAgo = self::intRange($seed + 20, 0, 45);
        return date('Y-m-d', strtotime("-{$daysAgo} days"));
    }

    /**
     * 12 weeks of fake rank history (most recent last), values 1..200.
     * Stored as JSON; the read path / frontend renders the sparkline.
     */
    public static function jsonHistory(int $seed): string
    {
        $points = [];
        $rank = self::intRange($seed + 21, 1, 120);
        for ($w = 0; $w < 12; $w++) {
            // wander +-15 each week, clamp to 1..200
            $delta = self::intRange($seed + 30 + $w, -15, 15);
            $rank  = max(1, min(200, $rank + $delta));
            $points[] = [
                'week' => date('Y-m-d', strtotime('-' . ((11 - $w) * 7) . ' days')),
                'rank' => $rank,
            ];
        }
        return json_encode($points, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Fake cross-platform country presence: a handful of countries with the
     * platforms the show "charts" in. Mirrors the real global_footprint shape
     * you'd later derive from match_key joins.
     */
    public static function jsonFootprint(int $seed): string
    {
        $platformsAll = ['apple', 'spotify', 'youtube'];
        $countN = self::intRange($seed + 50, 1, 6);
        $out = [];
        $used = [];
        for ($i = 0; $i < $countN; $i++) {
            $c = self::pick($seed + 60 + $i, self::COUNTRIES);
            if (isset($used[$c])) {
                continue;
            }
            $used[$c] = true;
            $plats = [];
            foreach ($platformsAll as $pi => $p) {
                if (self::rng($seed + 70 + $i * 3 + $pi) > 0.45) {
                    $plats[] = $p;
                }
            }
            if (empty($plats)) {
                $plats[] = 'apple';
            }
            $out[] = ['country' => $c, 'platforms' => $plats];
        }
        return json_encode($out, JSON_UNESCAPED_SLASHES);
    }
}

// --------------------------------------------------------------------------
// Build one metadata record for a show, driven entirely by the catalogue.
// --------------------------------------------------------------------------
function buildMetaRecord(string $matchKey): array
{
    $seed = crc32($matchKey);
    $record = ['match_key' => $matchKey];

    foreach (MetaFields::catalogue() as $field) {
        $col  = $field['column'];
        $type = $field['type'];

        if (str_starts_with($type, 'int_range:')) {
            [, $min, $max] = explode(':', $type);
            $record[$col] = Gen::intRangeField($seed, (int) $min, (int) $max);
            continue;
        }
        if (str_starts_with($type, 'float_range:')) {
            [, $min, $max] = explode(':', $type);
            $record[$col] = Gen::floatRangeField($seed, (float) $min, (float) $max);
            continue;
        }

        $record[$col] = match ($type) {
            'short_text'     => Gen::shortText($seed),
            'long_text'      => Gen::longText($seed),
            'genre'          => Gen::genre($seed),
            'publisher'      => Gen::publisher($seed),
            'language'       => Gen::language($seed),
            'freq'           => Gen::freq($seed),
            'advisory'       => Gen::advisory($seed),
            'url'            => Gen::url($seed),
            'feed_url'       => Gen::feedUrl($seed),
            'date_past'      => Gen::datePast($seed),
            'date_recent'    => Gen::dateRecent($seed),
            'json_history'   => Gen::jsonHistory($seed),
            'json_footprint' => Gen::jsonFootprint($seed),
            default          => null,
        };
    }

    return $record;
}

// --------------------------------------------------------------------------
// Scan existing chart JSON to collect unique shows by match_key.
// Falls back to a small built-in sample if no data dir exists yet (so the
// script is runnable today, before you're connected to WAMP).
// --------------------------------------------------------------------------
function collectShows(string $dataDir): array
{
    $names = [];

    $platforms = ['apple', 'spotify', 'youtube'];
    foreach ($platforms as $platform) {
        $base = $dataDir . '/' . $platform;
        if (!is_dir($base)) {
            continue;
        }
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }
            $json = json_decode(file_get_contents($file->getPathname()), true);
            if (!isset($json['shows']) || !is_array($json['shows'])) {
                continue;
            }
            foreach ($json['shows'] as $show) {
                $name = $show['name'] ?? null;
                if ($name) {
                    $names[] = $name;
                }
            }
        }
    }

    // Fallback sample so the generator is runnable before DB/data is wired up.
    if (empty($names)) {
        logMsg('WARNING', 'no chart JSON found under ' . $dataDir . ' — using built-in sample names');
        $names = [
            'The Daily', 'Crime Junkie', 'Huberman Lab', 'SmartLess',
            'This American Life', 'The Joe Rogan Experience', 'Stuff You Should Know',
            'Pod Save America', 'Radiolab', 'Planet Money', 'My Favorite Murder',
            'The Tim Ferriss Show', 'Lex Fridman Podcast', 'Hidden Brain', '99% Invisible',
        ];
    }

    // Dedupe by match_key.
    $unique = [];
    foreach ($names as $name) {
        $key = makeMatchKey($name);
        if ($key !== '' && !isset($unique[$key])) {
            $unique[$key] = true;
        }
    }
    return array_keys($unique);
}

// --------------------------------------------------------------------------
// Optional: emit a CREATE TABLE + INSERTs so tomorrow's SQL is copy-paste.
// Column types are inferred from the catalogue field types.
// --------------------------------------------------------------------------
function sqlColumnType(string $type): string
{
    if (str_starts_with($type, 'int_range:'))   return 'INT';
    if (str_starts_with($type, 'float_range:')) return 'DECIMAL(3,1)';
    return match ($type) {
        'short_text'                 => 'VARCHAR(512)',
        'long_text'                  => 'TEXT',
        'genre', 'publisher', 'freq',
        'advisory', 'language'       => 'VARCHAR(64)',
        'url', 'feed_url'            => 'VARCHAR(512)',
        'date_past', 'date_recent'   => 'DATE',
        'json_history', 'json_footprint' => 'JSON',
        default                      => 'VARCHAR(255)',
    };
}

function openSqlFile(string $path): mixed
{
    $fh = fopen($path, 'w');
    $header = [
        '-- podcast_meta: one row per show, keyed by match_key.',
        '-- Separate from *_main time-series tables (slow-changing dimension).',
        '-- utf8mb4_unicode_ci to match existing tables (avoids JOIN collation errors).',
        'CREATE TABLE IF NOT EXISTS podcast_meta (',
        '  match_key VARCHAR(255) NOT NULL,',
    ];
    foreach (MetaFields::catalogue() as $field) {
        $header[] = '  ' . $field['column'] . ' ' . sqlColumnType($field['type']) . ' NULL,';
    }
    $header[] = '  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,';
    $header[] = '  PRIMARY KEY (match_key)';
    $header[] = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';
    $header[] = '';
    fwrite($fh, implode("\n", $header));
    return $fh;
}

function writeSqlRow(mixed $fh, array $record, array $cols): void
{
    $vals = [];
    foreach ($cols as $c) {
        $v = $record[$c] ?? null;
        if ($v === null) {
            $vals[] = 'NULL';
        } elseif (is_int($v) || is_float($v)) {
            $vals[] = (string) $v;
        } else {
            $vals[] = "'" . str_replace("'", "''", (string) $v) . "'";
        }
    }
    $updates = [];
    foreach ($cols as $c) {
        if ($c === 'match_key') continue;
        $updates[] = "{$c}=VALUES({$c})";
    }
    fwrite($fh, 'INSERT INTO podcast_meta (' . implode(', ', $cols) . ') VALUES ('
             . implode(', ', $vals) . ') ON DUPLICATE KEY UPDATE '
             . implode(', ', $updates) . ";\n");
}

// --------------------------------------------------------------------------
// Main
// --------------------------------------------------------------------------
logMsg('INFO', str_repeat('=', 60));
logMsg('INFO', "generate_meta start  run_date={$runDate}");

$shows = collectShows($dataDir);
logMsg('INFO', 'unique shows (by match_key): ' . count($shows));

if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

// Stream-write JSON one record at a time to stay within PHP's memory limit.
// With ~30k shows, holding all records in memory before json_encode() would OOM.
$jsonPath = $outDir . '/podcast_meta.json';
$tmp      = $jsonPath . '.tmp';
$jfh      = fopen($tmp, 'w');

$sqlFh   = null;
$sqlCols = null;
if ($emitSql) {
    $sqlPath = $outDir . '/podcast_meta.sql';
    $sqlFh   = openSqlFile($sqlPath);
    $sqlCols = array_merge(['match_key'], array_column(MetaFields::catalogue(), 'column'));
}

fwrite($jfh, '[');
$first = true;
$count = 0;
foreach ($shows as $matchKey) {
    $record = buildMetaRecord($matchKey);
    fwrite($jfh, ($first ? "\n" : ",\n") . json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $first = false;
    $count++;

    if ($sqlFh !== null) {
        writeSqlRow($sqlFh, $record, $sqlCols);
    }
}
fwrite($jfh, "\n]");
fclose($jfh);
rename($tmp, $jsonPath);
logMsg('INFO', "wrote {$jsonPath}  ({$count} records)");

if ($sqlFh !== null) {
    fclose($sqlFh);
    logMsg('INFO', "wrote {$sqlPath}");
}

logMsg('INFO', 'generate_meta done');
logMsg('INFO', str_repeat('=', 60));
