<?php

$scrapersDir = __DIR__;
$projectRoot = realpath($scrapersDir . '/..');
$configFile = $projectRoot . '/config/apple_config.json';
$outDir = $projectRoot . '/data/apple';

define('MAX_WORKERS', 5);
define('RETRIES', 3);
define('PAUSE_US', 300000); // 0.3 seconds in microseconds

// --- Load Config ---
if (!file_exists($configFile)) {
    die("ERROR: config file not found: {$configFile}\n");
}
$configJson = file_get_contents($configFile);
$config = json_decode($configJson, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("ERROR: invalid JSON in {$configFile}: " . json_last_error_msg() . "\n");
}
if (!isset($config['limit']) || !isset($config['countries']) || !isset($config['genres'])) {
    die("ERROR: missing key in {$configFile}\n");
}
$limit = $config['limit'];

// --- Single Instance Locking ---
$lockFile = $projectRoot . '/.apple.lock';
$lockHandle = fopen($lockFile, 'w');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    die("ERROR: another instance is already running\n");
}

// --- Logging Setup ---
$logDir = $projectRoot . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
$logFile = $logDir . '/apple.log';

function logMsg($level, $message) {
    global $logFile;
    $time = date('Y-m-d H:i:s,000'); 
    $line = "{$time} {$level} {$message}\n";
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

// --- URL Builder ---
function build_url($country, $genre_id, $limit) {
    $base = "https://itunes.apple.com/{$country}/rss/toppodcasts/limit={$limit}";
    if ($genre_id) {
        $base .= "/genre={$genre_id}";
    }
    return $base . "/json";
}

// --- Data Cleaner ---
function clean_entry($entry, $rank) {
    // Keep only the fields that do a job.
    $images = $entry['im:image'] ?? [];
    $lastImage = end($images); // last image = largest

    return [
        "rank"     => $rank,
        "apple_id" => $entry['id']['attributes']['im:id'] ?? null,
        "name"     => $entry['im:name']['label'] ?? null,
        "artist"   => $entry['im:artist']['label'] ?? null,
        "artwork"  => $lastImage['label'] ?? null,
        "genre"    => $entry['category']['attributes']['term'] ?? null,
        "genre_id" => $entry['category']['attributes']['im:id'] ?? null,
        "url"      => $entry['id']['label'] ?? null,
    ];
}

// --- Initialize Jobs Queue ---
$jobs = [];
foreach ($config['countries'] as $c) {
    foreach ($config['genres'] as $g) {
        $jobs[] = [
            'country' => $c,
            'genre'   => $g,
            'attempt' => 1
        ];
    }
}

logMsg('INFO', "Fetching " . count($jobs) . " charts into {$outDir}/ ...");

$okCount = 0;
$failCount = 0;

// --- Concurrency via curl_multi (Equivalent to ThreadPoolExecutor) ---
$mh = curl_multi_init();
$activeHandles = [];
$jobIndex = 0;

// Helper to push a job into the active HTTP pool
$addJobToMulti = function($job) use (&$mh, &$activeHandles, $limit) {
    usleep(PAUSE_US); // polite pause before requesting
    $ch = curl_init();
    $url = build_url($job['country'], $job['genre']['id'], $limit);
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    curl_multi_add_handle($mh, $ch);
    $activeHandles[(int)$ch] = ['ch' => $ch, 'job' => $job];
};

// 1. Prime the pump up to MAX_WORKERS
while ($jobIndex < count($jobs) && count($activeHandles) < MAX_WORKERS) {
    $addJobToMulti($jobs[$jobIndex]);
    $jobIndex++;
}

// 2. The Execution Loop
do {
    // Execute the active requests
    $status = curl_multi_exec($mh, $active);
    if ($active) {
        curl_multi_select($mh); // Block slightly until there is activity to avoid maxing CPU
    }

    // 3. Process completed requests as they finish
    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        $handleData = $activeHandles[(int)$ch];
        $job = $handleData['job'];
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content = curl_multi_getcontent($ch);
        $curlError = curl_error($ch);

        // Cleanup this specific handle
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        unset($activeHandles[(int)$ch]);

        $success = false;
        $errorMsg = "";
        $shows = [];

        if ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($content, true);
            if ($data && isset($data['feed'])) {
                $entries = $data['feed']['entry'] ?? [];
                
                // Edge case: Apple RSS sometimes returns an associative array instead of a list if count is exactly 1
                if (isset($entries['im:name'])) { $entries = [$entries]; }

                $shows = [];
                $rank = 1;
                foreach ($entries as $e) {
                    $shows[] = clean_entry($e, $rank++);
                }

                $chart = [
                    "platform"   => "apple",
                    "country"    => $job['country'],
                    "genre"      => $job['genre']['name'],
                    "genre_id"   => $job['genre']['id'],
                    "slug"       => $job['genre']['slug'],
                    "fetched_at" => gmdate("Y-m-d\TH:i:s\Z"), // UTC timestamp
                    "count"      => count($shows),
                    "shows"      => $shows
                ];

                $folder = $outDir . '/' . $job['country'];
                if (!is_dir($folder)) mkdir($folder, 0777, true);
                
                $path = $folder . '/' . $job['genre']['slug'] . '.json';
                $tmp = $path . '.tmp';
                
                // JSON_UNESCAPED_UNICODE perfectly matches ensure_ascii=False
                file_put_contents($tmp, json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                rename($tmp, $path); // Atomic replace

                $success = true;
            } else {
                $errorMsg = "Invalid JSON or missing 'feed'";
            }
        } else {
            $errorMsg = $curlError ? $curlError : "HTTP Code $httpCode";
        }

        // 4. Handle Results and Retries
        if ($success) {
            $okCount++;
            logMsg('INFO', "ok   {$job['country']}/{$job['genre']['slug']}: " . count($shows) . " shows");
        } else {
            if ($job['attempt'] < RETRIES) {
                $job['attempt']++;
                // Instead of Python's time.sleep(), we push the job to the back of the array
                // so it gets retried later naturally without blocking other connections.
                $jobs[] = $job; 
            } else {
                $failCount++;
                logMsg('ERROR', "FAIL {$job['country']}/{$job['genre']['slug']}: {$errorMsg}");
            }
        }

        // 5. Keep the concurrency maxed out by adding the next job
        if ($jobIndex < count($jobs)) {
            $addJobToMulti($jobs[$jobIndex]);
            $jobIndex++;
        }
    }
} while ($active || count($activeHandles) > 0);

curl_multi_close($mh);

logMsg('INFO', "Done. {$okCount} ok, {$failCount} failed.");

// Release the instance lock
flock($lockHandle, LOCK_UN);
fclose($lockHandle);

// Exit code for run_pipeline tracking
if ($failCount > 0) {
    exit(1);
}