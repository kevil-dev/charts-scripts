<?php

$scrapersDir = __DIR__;
$projectRoot = realpath($scrapersDir . '/..');
$configFile = $projectRoot . '/config/spotify_config.json';
$outDir = $projectRoot . '/data/spotify';

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
$lockFile = $projectRoot . '/.spotify.lock';
$lockHandle = fopen($lockFile, 'w');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    die("ERROR: another instance is already running\n");
}

// --- Logging Setup ---
$logDir = $projectRoot . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
$logFile = $logDir . '/spotify.log';

function logMsg($level, $message) {
    global $logFile;
    $time = date('Y-m-d H:i:s,000'); 
    $line = "{$time} {$level} {$message}\n";
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

// --- URL Builder ---
function build_url($country, $slug, $limit) {
    return "https://podcastcharts.byspotify.com/api/charts/{$slug}?region={$country}&limit={$limit}";
}

// --- Data Cleaner ---
function clean_entry($entry, $rank) {
    $uri = $entry['showUri'] ?? '';
    $parts = explode(':', $uri);
    $spotifyId = end($parts); // gets the last item after the colon
    
    return [
        "rank"       => $rank,
        "spotify_id" => $spotifyId ? $spotifyId : "",
        "name"       => $entry['showName'] ?? '',
        "publisher"  => $entry['showPublisher'] ?? '',
        "artwork"    => $entry['showImageUrl'] ?? '',
        "rank_move"  => $entry['chartRankMove'] ?? null,
        "url"        => $spotifyId ? "https://open.spotify.com/show/{$spotifyId}" : "",
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

logMsg('INFO', "Trying " . count($jobs) . " charts into {$outDir}/ ...");

$okCount = 0;
$skipCount = 0;
$failCount = 0;

// --- Concurrency via curl_multi ---
$mh = curl_multi_init();
$activeHandles = [];
$jobIndex = 0;

$addJobToMulti = function($job) use (&$mh, &$activeHandles, $limit) {
    usleep(PAUSE_US);
    $ch = curl_init();
    $url = build_url($job['country'], $job['genre']['slug'], $limit);
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    curl_multi_add_handle($mh, $ch);
    $activeHandles[(int)$ch] = ['ch' => $ch, 'job' => $job];
};

// 1. Prime the pump
while ($jobIndex < count($jobs) && count($activeHandles) < MAX_WORKERS) {
    $addJobToMulti($jobs[$jobIndex]);
    $jobIndex++;
}

// 2. The Execution Loop
do {
    $status = curl_multi_exec($mh, $active);
    if ($active) {
        curl_multi_select($mh);
    }

    // 3. Process completed requests
    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        $handleData = $activeHandles[(int)$ch];
        $job = $handleData['job'];
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content = curl_multi_getcontent($ch);
        $curlError = curl_error($ch);

        // Cleanup handle
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        unset($activeHandles[(int)$ch]);

        $jobStatus = "fail"; // default to fail, override if ok or skip
        $errorMsg = "";
        $shows = []; // initialized to prevent Intelephense warnings

        // Check for 404 (Chart not offered in this country)
        if ($httpCode == 404) {
            $jobStatus = "skip";
        } 
        // Process successful 2xx responses
        elseif ($httpCode >= 200 && $httpCode < 300) {
            $entries = json_decode($content, true);
            
            // If the JSON is completely empty (e.g., []), skip it
            if (empty($entries)) {
                $jobStatus = "skip";
            } else {
                $rank = 1;
                foreach ($entries as $e) {
                    $shows[] = clean_entry($e, $rank++);
                }

                $chartData = [
                    "platform"   => "spotify",
                    "country"    => $job['country'],
                    "genre"      => $job['genre']['name'],
                    "slug"       => $job['genre']['slug'],
                    "fetched_at" => gmdate("Y-m-d\TH:i:s\Z"),
                    "count"      => count($shows),
                    "shows"      => $shows
                ];

                $folder = $outDir . '/' . $job['country'];
                if (!is_dir($folder)) mkdir($folder, 0777, true);
                
                $path = $folder . '/' . $job['genre']['slug'] . '.json';
                $tmp = $path . '.tmp';
                
                file_put_contents($tmp, json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                rename($tmp, $path); // Atomic replace

                $jobStatus = "ok";
            }
        } else {
            $errorMsg = $curlError ? $curlError : "HTTP Code $httpCode";
        }

        // 4. Handle Results and Retries
        if ($jobStatus === "ok") {
            $okCount++;
            logMsg('INFO', "ok   {$job['country']}/{$job['genre']['slug']}: " . count($shows) . " shows");
        } elseif ($jobStatus === "skip") {
            $skipCount++;
            // We do not log skips to keep the console clean, matching the Python script's behavior
        } else {
            // It failed. Check if we have retries left.
            if ($job['attempt'] < RETRIES) {
                $job['attempt']++;
                // Push back into the array to be retried naturally later
                $jobs[] = $job; 
            } else {
                $failCount++;
                logMsg('ERROR', "FAIL {$job['country']}/{$job['genre']['slug']}: {$errorMsg}");
            }
        }

        // 5. Keep the queue full
        if ($jobIndex < count($jobs)) {
            $addJobToMulti($jobs[$jobIndex]);
            $jobIndex++;
        }
    }
} while ($active || count($activeHandles) > 0);

curl_multi_close($mh);

logMsg('INFO', "Done. {$okCount} ok, {$skipCount} skipped (not offered), {$failCount} failed.");

// Release the instance lock
flock($lockHandle, LOCK_UN);
fclose($lockHandle);

// Exit code for run_pipeline tracking
if ($failCount > 0) {
    exit(1);
}