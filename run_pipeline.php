<?php

namespace App\Scripts;

$baseDir = __DIR__;
$logDir = realpath($baseDir . '/../logs');
if (!$logDir) {
    mkdir($baseDir . '/../logs', 0777, true);
    $logDir = realpath($baseDir . '/../logs');
}

$runDate = date('Y-m-d');
$logFile = $logDir . "/pipeline_{$runDate}.log";

// 1. Define the stages and their order
$stages = [
    ['label' => 'apple fetch',   'script' => 'scrapers/apple_test.php'],
    ['label' => 'spotify fetch', 'script' => 'scrapers/spotify_test.php'],
    ['label' => 'youtube fetch', 'script' => 'scrapers/youtube_test.php'],
    ['label' => 'meta gen',      'script' => 'generate_meta.php'],
    ['label' => 'loader',        'script' => 'loader.php'],
];

// --- Helpers ---
function logMsg($level, $message) {
    global $logFile;
    $time = date('Y-m-d H:i:s,000');
    $line = "{$time}  " . str_pad($level, 7) . "  {$message}\n";
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

function tail($text, $lines = 25) {
    if (empty(trim($text))) return "";
    $linesArr = explode("\n", trim($text));
    $tailArr = array_slice($linesArr, -$lines);
    return implode("\n", $tailArr);
}

// --- Main Execution ---
function main() {
    global $baseDir, $runDate, $stages;

    logMsg('INFO', str_repeat('=', 70));
    logMsg('INFO', "pipeline start  run_date={$runDate}  dir={$baseDir}");

    $results = [];

    foreach ($stages as $stage) {
        $label = $stage['label'];
        $script = $stage['script'];
        $scriptPath = $baseDir . '/' . $script;

        if (!file_exists($scriptPath)) {
            logMsg('ERROR', "[{$label}] script not found: {$scriptPath} — skipping stage");
            $results[] = ['label' => $label, 'status' => 'missing'];
            continue;
        }

        logMsg('INFO', "[{$label}] starting  ({$script})");
        $start = microtime(true);

        // Execute the script as an isolated sub-process.
        // "2>&1" ensures we capture both standard output and fatal PHP errors (stderr).
        $cmd = "php " . escapeshellarg($scriptPath) . " 2>&1";
        
        // Run the command and capture output
        exec($cmd, $outputArray, $exitCode);
        
        $elapsed = round(microtime(true) - $start);
        $outputText = implode("\n", $outputArray);
        
        // Clear the array for the next loop iteration
        unset($outputArray); 

        if ($outputText) {
            logMsg('INFO', "[{$label}] output (tail):\n" . tail($outputText));
        }

        if ($exitCode !== 0) {
            logMsg('ERROR', "[{$label}] FAILED rc={$exitCode} after {$elapsed}s — continuing");
            $results[] = ['label' => $label, 'status' => 'failed'];
        } else {
            logMsg('INFO', "[{$label}] done  rc=0  {$elapsed}s");
            $results[] = ['label' => $label, 'status' => 'ok'];
        }
    }

    logMsg('INFO', str_repeat('-', 70));
    logMsg('INFO', "pipeline summary:");

    $failures = 0;
    foreach ($results as $res) {
        logMsg('INFO', "    " . str_pad($res['label'], 14) . " " . strtoupper($res['status']));
        if ($res['status'] !== 'ok') {
            $failures++;
        }
    }

    $okCount = count($stages) - $failures;
    $totalCount = count($stages);
    logMsg('INFO', "pipeline done  {$okCount}/{$totalCount} stages ok");
    logMsg('INFO', str_repeat('=', 70));

    // Exit code = number of stages that didn't succeed
    return $failures;
}

exit(main());