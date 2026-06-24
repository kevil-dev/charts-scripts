<?php

$scrapersDir = __DIR__;
$projectRoot = realpath($scrapersDir . '/..');
$configFile = $projectRoot . '/config/youtube_config.json';
$outDir = $projectRoot . '/data/youtube';

define('URL', 'https://charts.youtube.com/youtubei/v1/browse?alt=json');
define('RETRIES', 3);
define('PAUSE_SEC', 1); // 1.0 second pause

// --- Load Config ---
if (!file_exists($configFile)) {
    die("ERROR: config file not found: {$configFile}\n");
}
$configJson = file_get_contents($configFile);
$config = json_decode($configJson, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("ERROR: invalid JSON in {$configFile}: " . json_last_error_msg() . "\n");
}
if (!isset($config['countries']) || !isset($config['context'])) {
    die("ERROR: missing key in {$configFile}\n");
}

// --- Single Instance Locking ---
$lockFile = $projectRoot . '/.youtube.lock';
$lockHandle = fopen($lockFile, 'w');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    die("ERROR: another instance is already running\n");
}

// --- Logging Setup ---
$logDir = $projectRoot . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
$logFile = $logDir . '/youtube.log';

function logMsg($level, $message) {
    global $logFile;
    $time = date('Y-m-d H:i:s,000'); 
    $line = "{$time} {$level} {$message}\n";
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

// --- URL/Body Builder ---
function build_body($country, $context) {
    $query = "flags=MusicCharts__enable_apac_and_shorts_charts_expansion"
           . "&perspective=PODCAST_SHOW"
           . "&chart_params_country_code={$country}"
           . "&chart_params_chart_type=PODCAST_SHOWS_BY_WATCH_TIME"
           . "&chart_params_period_type=WEEKLY";
           
    return [
        "context"  => $context,
        "browseId" => "FEmusic_analytics_charts_home",
        "query"    => $query,
    ];
}

// --- Data Cleaners ---
function clean_genre($raw) {
    // PODCAST_GENRE_TRUE_CRIME -> True Crime
    if (!$raw || $raw === "PODCAST_GENRE_UNSPECIFIED") {
        return null;
    }
    
    $cleaned = str_replace("PODCAST_GENRE_", "", $raw);
    $words = explode('_', $cleaned);
    
    // Capitalize each word, lowercasing the rest (e.g., TRUE -> True)
    $capitalizedWords = array_map(function($w) {
        return ucfirst(strtolower($w));
    }, $words);
    
    return implode(" ", $capitalizedWords);
}

function find_entries($data) {
    // Dig down to podcastShowEntries through YouTube's deep nesting safely
    $sections = $data['contents']['sectionListRenderer']['contents'] ?? [];
    
    foreach ($sections as $section) {
        $block = $section['musicAnalyticsSectionRenderer'] ?? null;
        if ($block) {
            return $block['content']['podcastShows'][0]['podcastShowEntries'] ?? [];
        }
    }
    return [];
}

function clean_entry($entry) {
    $thumbs = $entry['thumbnail']['thumbnails'] ?? [];
    $lastThumb = end($thumbs); // last = largest

    $channelUrl = $entry['channelNavigationEndpoint']['urlEndpoint']['url'] ?? "";

    return [
        "rank"                => $entry['chartEntryMetadata']['currentPosition'] ?? null,
        "youtube_playlist_id" => $entry['externalPlaylistId'] ?? null,
        "name"                => $entry['name'] ?? null,
        "channel"             => $entry['channelName'] ?? "",
        "artwork"             => $lastThumb ? $lastThumb['url'] : "",
        "genre"               => clean_genre($entry['primaryGenre'] ?? null),
        "channel_url"         => $channelUrl,
    ];
}

// --- Main Execution ---
$countries = $config['countries'];
$context = $config['context'];
if (isset($context['capabilities']) && empty($context['capabilities'])) {
    $context['capabilities'] = new stdClass();
}

logMsg('INFO', "Fetching " . count($countries) . " YouTube charts into {$outDir}/ ...");

$okCount = 0;
$failCount = 0;

foreach ($countries as $country) {
    $body = build_body($country, $context);
    $jsonBody = json_encode($body);
    
    $success = false;
    $errorMsg = "";
    $shows = [];

    for ($attempt = 1; $attempt <= RETRIES; $attempt++) {
        $ch = curl_init(URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        // Mimic the Python script so YouTube doesn't block us
        curl_setopt($ch, CURLOPT_USERAGENT, 'python-requests/2.31.0'); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
       

        if ($httpCode >= 200 && $httpCode < 300 && $response) {
            $data = json_decode($response, true);
            $entries = find_entries($data);
            
            if (empty($entries)) {
                $errorMsg = "200 OK, but no entries found in JSON structure.";
            } else {
                foreach ($entries as $e) {
                    $shows[] = clean_entry($e);
                }

                $chartData = [
                    "platform"   => "youtube",
                    "country"    => $country,
                    "slug"       => "top",
                    "fetched_at" => gmdate("Y-m-d\TH:i:s\Z"),
                    "count"      => count($shows),
                    "shows"      => $shows
                ];

                $folder = $outDir . '/' . $country;
                if (!is_dir($folder)) mkdir($folder, 0777, true);
                
                $path = $folder . '/top.json';
                $tmp = $path . '.tmp';
                
                file_put_contents($tmp, json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                rename($tmp, $path); // Atomic replace

                $success = true;
                break; // Break out of the retry loop!
            }
        } else {
            $errorMsg = $curlError ? $curlError : "HTTP Code $httpCode. Response: " . substr($response, 0, 500);
        }

        // Wait before retrying
        if (!$success && $attempt < RETRIES) {
            sleep(2 * $attempt);
        }
    }

    if ($success) {
        $okCount++;
        logMsg('INFO', "ok   {$country}/top: " . count($shows) . " shows");
    } else {
        $failCount++;
        logMsg('ERROR', "FAIL {$country}/top: {$errorMsg}");
    }

    // Be gentle to YouTube
    sleep(PAUSE_SEC);
}

logMsg('INFO', "Done. {$okCount} ok, {$failCount} failed.");

if ($failCount == count($countries)) {
    logMsg('ERROR', "All countries failed — YouTube API may be broken or credentials expired");
}

// Release the instance lock
flock($lockHandle, LOCK_UN);
fclose($lockHandle);

if ($failCount > 0) {
    exit(1);
}