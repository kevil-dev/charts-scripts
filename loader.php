<?php

namespace App\Scripts;

use Exception;
use PDO;
use PDOException;

require_once __DIR__ . '/adapters/Normalize.php';
require_once __DIR__ . '/adapters/apple_adapter.php';
require_once __DIR__ . '/adapters/spotify_adapter.php';
require_once __DIR__ . '/adapters/youtube_adapter.php';

// If you have your DB config in your MVC framework, you can require it here.
// For now, we will use the exact fallback logic the Python script used.
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASSWORD') ?: '';
$dbName = getenv('DB_NAME') ?: 'charts';

$dataDir = getenv('DATA_DIR') ?: __DIR__ . '/data';
$logDir = getenv('LOG_DIR') ?: __DIR__ . '/logs';
$countryCodeCase = getenv('COUNTRY_CODE_CASE') ?: 'upper'; // "upper", "lower", "asis"
$runDate = date('Y-m-d');

// --- Logging Setup ---
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
$logFile = $logDir . "/loader_{$runDate}.log";

function logMsg($level, $message) {
    global $logFile;
    $time = date('Y-m-d H:i:s,000'); 
    $line = "{$time}  " . str_pad($level, 7) . "  {$message}\n";
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

// --- Platform Configurations ---
$platformSpec = [
    "apple" => [
        "main_table" => "apple_main",
        "id_col" => "apple_id",
        "chart_col" => "genre_id",
        "has_arrow" => true,
        "adapter" => function($records, $country, $chart) use ($runDate) {
            return Adapters\AppleAdapter::adapt($records, $country, $chart, $runDate);
        },
        "allowed_columns" => array_flip([
            "country_code", "genre_id", "chart_rank", "apple_id", "name", "artist",
            "artwork", "url", "match_key", "run_date", "rank_move"
        ]),
    ],
    "spotify" => [
        "main_table" => "spotify_main",
        "id_col" => "spotify_id",
        "chart_col" => "chart",
        "has_arrow" => false,
        "adapter" => function($records, $country, $chart) use ($runDate) {
            return Adapters\SpotifyAdapter::adapt($records, $country, $chart, $runDate);
        },
        "allowed_columns" => array_flip([
            "country_code", "chart", "chart_rank", "spotify_id", "name", "publisher",
            "artwork", "rank_move", "match_key", "run_date"
        ]),
    ],
    "youtube" => [
        "main_table" => "youtube_main",
        "id_col" => "youtube_id",
        "chart_col" => null,
        "has_arrow" => true,
        "history_chart" => "top",
        "adapter" => function($records, $country, $chart) use ($runDate) {
            return Adapters\YoutubeAdapter::adapt($records, $country, $runDate);
        },
        "allowed_columns" => array_flip([
            "country_code", "chart_rank", "youtube_id", "name", "channel", "artwork",
            "channel_url", "match_key", "run_date", "rank_move"
        ]),
    ]
];

// --- Helpers ---

function parse_path($path) {
    global $countryCodeCase;
    // Normalize path separators to handle Windows/Linux consistently
    $normalizedPath = str_replace('\\', '/', $path);
    $parts = explode('/', $normalizedPath);
    
    $slug = basename(array_pop($parts), '.json');
    $rawCountry = array_pop($parts);
    $platform = array_pop($parts);

    if ($countryCodeCase === "upper") {
        $country = strtoupper($rawCountry);
    } elseif ($countryCodeCase === "lower") {
        $country = strtolower($rawCountry);
    } else {
        $country = $rawCountry;
    }
    
    return [$platform, $country, $slug];
}

function read_records($path) {
    $content = file_get_contents($path);
    $data = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON formatting in file");
    }

    $records = null;

    if (is_array($data) && isset($data[0])) { // It's a plain list
        $records = $data;
    } elseif (is_array($data)) { // It's a dictionary wrapping a list
        $possibleKeys = ["records", "results", "items", "data", "entries", "shows"];
        foreach ($possibleKeys as $key) {
            if (isset($data[$key]) && is_array($data[$key]) && isset($data[$key][0])) {
                $records = $data[$key];
                break;
            }
        }
        if ($records === null && isset($data['feed']) && is_array($data['feed'])) {
            $feedKeys = ["results", "entry"];
            foreach ($feedKeys as $key) {
                if (isset($data['feed'][$key]) && is_array($data['feed'][$key])) {
                    // Handle weird apple case where 1 entry is an object, not an array of 1
                    if (isset($data['feed'][$key]['im:name'])) {
                        $records = [$data['feed'][$key]];
                    } else {
                        $records = $data['feed'][$key];
                    }
                    break;
                }
            }
        }
        if ($records === null) {
            throw new Exception("no record list found in JSON object");
        }
    } else {
        throw new Exception("JSON root is neither a list nor an object");
    }

    if (empty($records)) {
        throw new Exception("file contains zero records");
    }

    return $records;
}

function previous_ranks(PDO $pdo, $platform, $country, $chart) {
    global $runDate;
    
    $sql = "
        SELECT external_id, chart_rank
        FROM history
        WHERE platform = ? AND country_code = ? AND chart = ?
          AND run_date = (
              SELECT MAX(run_date) FROM history
              WHERE platform = ? AND country_code = ? AND chart = ?
                AND run_date < ?
          )
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$platform, $country, $chart, $platform, $country, $chart, $runDate]);
    
    // PDO::FETCH_KEY_PAIR perfectly matches Python's dict comprehension: {external_id: chart_rank}
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
}

function arrow_for($todayRank, $prevRank) {
    if ($prevRank === null) return "NEW";
    if ($todayRank < $prevRank) return "UP"; // Lower number is a higher rank!
    if ($todayRank > $prevRank) return "DOWN";
    return "UNCHANGED";
}

function ensure_genre(PDO $pdo, $platform, $slug, $records) {
    if ($platform === "youtube" || $slug === "top") return;
    
    try {
        $display = null;
        if ($platform === "apple") {
            foreach ($records as $rec) {
                if (strval($rec['genre_id'] ?? '') === strval($slug) && !empty($rec['genre'])) {
                    $display = $rec['genre'];
                    break;
                }
            }
        }
        if (!$display) {
            $display = ucwords(str_replace(['-', '_'], ' ', $slug));
        }

        $stmt = $pdo->prepare("INSERT IGNORE INTO genres (platform, native_id, display_name) VALUES (?, ?, ?)");
        $stmt->execute([$platform, $slug, $display]);
        
    } catch (Exception $exc) {
        logMsg('WARNING', "genre auto-register skipped for {$platform}/{$slug}: " . $exc->getMessage());
    }
}

// PHP doesn't have an exact `cur.executemany()` equivalent, so this helper dynamically builds 
// extremely fast Bulk-Insert queries like: INSERT INTO table (a,b) VALUES (?,?), (?,?)
function executeBulkInsert(PDO $pdo, $table, $rows, $onDuplicateUpdate = false) {
    if (empty($rows)) return;
    
    $columns = array_keys($rows[0]);
    $colString = implode(', ', array_map(fn($c) => "`$c`", $columns));
    
    // Create (?, ?, ?) for a single row
    $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    
    $insertValues = [];
    $flatParams = [];
    
    foreach ($rows as $row) {
        $insertValues[] = $placeholders;
        foreach ($columns as $col) {
            $flatParams[] = $row[$col];
        }
    }
    
    $sql = "INSERT INTO `$table` ($colString) VALUES " . implode(', ', $insertValues);
    
    if ($onDuplicateUpdate) {
        $sql .= " ON DUPLICATE KEY UPDATE chart_rank = VALUES(chart_rank)";
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($flatParams);
}

function load_chart(PDO $pdo, $platform, $country, $slug, $records) {
    global $platformSpec, $runDate;
    
    $spec = $platformSpec[$platform];
    $chartForMain = $slug;
    $chartForHistory = $spec['history_chart'] ?? $slug;

    // Run the adapter
    list($rows, $skipped) = $spec['adapter']($records, $country, $chartForMain);
    
    if (empty($rows)) {
        throw new Exception("adapter returned no usable rows (skipped {$skipped})");
    }

    $idCol = $spec['id_col'];
    $allowed = $spec['allowed_columns'];

    // Arrows first, BEFORE we touch history, so "previous run" excludes today
    $prevRanks = $spec['has_arrow'] ? previous_ranks($pdo, $platform, $country, $chartForHistory) : [];

    $cleanRows = [];
    $historyRows = [];

    foreach ($rows as $row) {
        $row['run_date'] = $row['run_date'] ?? $runDate;
        
        if (!isset($row[$idCol]) || $row['chart_rank'] === null) {
            $skipped++;
            continue;
        }

        if ($spec['has_arrow']) {
            $prevRank = $prevRanks[$row[$idCol]] ?? null;
            $row['rank_move'] = arrow_for((int)$row['chart_rank'], $prevRank);
        }

        // Validate columns
        $badColumns = array_diff_key($row, $allowed);
        if (!empty($badColumns)) {
            $badKeys = implode(', ', array_keys($badColumns));
            throw new Exception("row has columns not in {$spec['main_table']}: {$badKeys}");
        }

        $cleanRows[] = $row;
        
        // Prepare history row matching the python parameters exactly
        $historyRows[] = [
            'platform'     => $platform,
            'country_code' => $country,
            'chart'        => $chartForHistory,
            'external_id'  => $row[$idCol],
            'chart_rank'   => (int)$row['chart_rank'],
            'run_date'     => $runDate
        ];
    }

    if (empty($cleanRows)) {
        throw new Exception("every row was skipped during validation");
    }

    // --- The Transactional Swap ---
    try {
        $pdo->beginTransaction();

        // 1. Delete Old
        if ($spec['chart_col']) {
            $stmt = $pdo->prepare("DELETE FROM `{$spec['main_table']}` WHERE country_code = ? AND `{$spec['chart_col']}` = ?");
            $stmt->execute([$country, $chartForMain]);
        } else { // youtube: one chart per country
            $stmt = $pdo->prepare("DELETE FROM `{$spec['main_table']}` WHERE country_code = ?");
            $stmt->execute([$country]);
        }

        // 2. Insert Main (Bulk)
        executeBulkInsert($pdo, $spec['main_table'], $cleanRows);

        // 3. Insert History (Bulk ON DUPLICATE)
        executeBulkInsert($pdo, 'history', $historyRows, true);

        // Commit transaction!
        $pdo->commit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e; // Rethrow to let the main loop catch and log it
    }

    return ["loaded" => count($cleanRows), "skipped" => $skipped];
}

// --- Driver ---
function main() {
    global $dbHost, $dbPort, $dbUser, $dbPass, $dbName, $dataDir, $logDir, $runDate, $platformSpec;

    $started = date('c');
    logMsg('INFO', "loader start  run_date={$runDate}  data_dir={$dataDir}");

    if (!is_dir($dataDir)) {
        logMsg('ERROR', "data dir {$dataDir} does not exist — nothing to load");
        return 1;
    }

    try {
        $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        logMsg('ERROR', "could not connect to MySQL: " . $e->getMessage());
        return 1;
    }

    $manifest = ["run_date" => $runDate, "started_at" => $started, "files" => []];
    $totals = ["files" => 0, "ok" => 0, "skipped_files" => 0, "rows_loaded" => 0, "rows_skipped" => 0];

    // Find all JSON files safely
    $files = glob($dataDir . '/*/*/*.json');
    if ($files) {
        sort($files);
        
        foreach ($files as $path) {
            $totals['files']++;
            list($platform, $country, $slug) = parse_path($path);
            
            $entry = ["file" => $path, "platform" => $platform, "country" => $country, "chart" => $slug];

            if (!array_key_exists($platform, $platformSpec)) {
                $entry["status"] = "skipped";
                $entry["error"] = "unknown platform '{$platform}'";
                $totals["skipped_files"]++;
                $manifest["files"][] = $entry;
                logMsg('WARNING', "skip {$path}: unknown platform");
                continue;
            }

            try {
                $records = read_records($path);
                ensure_genre($pdo, $platform, $slug, $records);
                $stats = load_chart($pdo, $platform, $country, $slug, $records);
                
                $entry["status"] = "ok";
                $entry["loaded"] = $stats['loaded'];
                $entry["skipped"] = $stats['skipped'];
                
                $totals["ok"]++;
                $totals["rows_loaded"] += $stats["loaded"];
                $totals["rows_skipped"] += $stats["skipped"];
                
                $formattedPlatform = str_pad($platform, 7);
                $formattedSlug = str_pad($slug, 12);
                logMsg('INFO', "ok   {$formattedPlatform} {$country}/{$formattedSlug} loaded={$stats['loaded']} skipped={$stats['skipped']}");

            } catch (Exception $exc) {
                // If it fails here, the rollback already happened inside load_chart OR the transaction never started.
                // We keep yesterday's data safely.
                $entry["status"] = "skipped";
                $entry["error"] = $exc->getMessage();
                $totals["skipped_files"]++;
                logMsg('WARNING', "skip {$path}: {$exc->getMessage()}  (yesterday's data kept)");
            }
            
            $manifest["files"][] = $entry;
        }
    }

    $manifest["finished_at"] = date('c');
    $manifest["totals"] = $totals;
    
    $manifestPath = $logDir . "/manifest_{$runDate}.json";
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    logMsg('INFO', "loader done  files={$totals['files']} ok={$totals['ok']} skipped={$totals['skipped_files']} rows_loaded={$totals['rows_loaded']} rows_skipped={$totals['rows_skipped']}");
    logMsg('INFO', "manifest -> {$manifestPath}");

    // Exit non-zero only if we loaded nothing at all (so cron can alarm on a dead run)
    return $totals["ok"] > 0 ? 0 : 2;
}

exit(main());