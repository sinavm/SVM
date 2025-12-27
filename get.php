<?php

ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

require "functions.php";


function fetchWithCache($url, $cacheFile, $ttlSeconds = 7200, $retries = 3) {
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }


    if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $ttlSeconds)) {
        return file_get_contents($cacheFile);
    }

    $headers = "User-Agent: Mozilla/5.0 (compatible; SiNAVM/1.0)\r\n";
    $context = stream_context_create([
        "http" => [
            "method" => "GET",
            "header" => $headers,
            "timeout" => 20,
        ]
    ]);

    $lastErr = null;
    $backoffs = [1, 3, 7]; // seconds
    for ($i = 0; $i < $retries; $i++) {
        $data = @file_get_contents($url, false, $context);
        if ($data !== false && strlen($data) > 50) {
            file_put_contents($cacheFile, $data);
            return $data;
        }
        $lastErr = error_get_last();
        $sleep = $backoffs[min($i, count($backoffs) - 1)];
        usleep($sleep * 1000000);
    }


    if (is_file($cacheFile)) {
        return file_get_contents($cacheFile);
    }

    throw new Exception("Fetch failed: " . ($lastErr['message'] ?? 'unknown error'));
}

function looksLikeBase64Sub($text) {
    $t = trim($text);
    if ($t === '') return false;
    // If it already contains scheme lines, it's not base64-only
    if (preg_match('/\b(vmess|vless|trojan|ss|tuic|hy2):\/\//i', $t)) return false;
    // base64 alphabet-ish and long enough
    return (bool)preg_match('/^[A-Za-z0-9+\/_=\-\r\n]+$/', $t) && strlen($t) > 100;
}

function decodeMaybeBase64($text) {
    if (!looksLikeBase64Sub($text)) return $text;
    $t = preg_replace('/\s+/', '', trim($text));
    $decoded = base64_decode($t, true);
    return $decoded !== false ? $decoded : $text;
}


$sourcesArray = json_decode(file_get_contents("channels.json"), true);


$sublinksJson = file_get_contents("sublinks.json");
$sublinksJson = str_replace(
    ["__PRIVATE_LINK_SiNAVM_1__", "__PRIVATE_LINK_SiNAVM_2__", "__PRIVATE_LINK_SiNAVM_3__"],
    [getenv("PRIVATE_LINK_SiNAVM_1"), getenv("PRIVATE_LINK_SiNAVM_2"), getenv("PRIVATE_LINK_SiNAVM_3")],
    $sublinksJson
);
$sublinksArray = json_decode($sublinksJson, true);

$totalSources = count($sourcesArray) + count($sublinksArray['sublinks']);
$tempCounter = 1;

$configsRaw = [];   // raw lines (strings)
$sourceOf = [];     // raw line => source label (best-effort)

// Ensure folders
@mkdir("cache/telegram", 0777, true);
@mkdir("cache/subs", 0777, true);
@mkdir("reports", 0777, true);


foreach ($sourcesArray as $source => $types) {
    $percentage = ($tempCounter / $totalSources) * 100;
    echo "\rProgress: [";
    echo str_repeat("=", floor($percentage / (100 / $totalSources)));
    echo str_repeat(" ", $totalSources - floor($percentage / (100 / $totalSources)));
    echo "] " . number_format($percentage, 2) . "%";
    $tempCounter++;

    $cacheFile = "cache/telegram/" . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $source) . ".html";
    try {
        $html = fetchWithCache("https://t.me/s/" . $source, $cacheFile, 7200, 3);
        $type = implode("|", $types);
        $extracted = extractLinksByType($html, $type);
        if (!is_null($extracted) && is_array($extracted)) {
            foreach ($extracted as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $configsRaw[] = $line;
                $sourceOf[$line] = "tg:" . $source;
            }
        }
    } catch (Exception $e) {
        file_put_contents("reports/fetch_errors.txt", "[" . date('c') . "] tg:$source " . $e->getMessage() . "\n", FILE_APPEND);
    }
}


foreach ($sublinksArray['sublinks'] as $sublink) {
    $percentage = ($tempCounter / $totalSources) * 100;
    echo "\rProgress: [";
    echo str_repeat("=", floor($percentage / (100 / $totalSources)));
    echo str_repeat(" ", $totalSources - floor($percentage / (100 / $totalSources)));
    echo "] " . number_format($percentage, 2) . "%";
    $tempCounter++;

    $url = $sublink['url'];
    $protocols = implode("|", $sublink['protocols']);

    if (!$url) continue;

    $cacheKey = substr(sha1($url), 0, 16);
    $cacheFile = "cache/subs/" . $cacheKey . ".txt";

    try {
        $resp = fetchWithCache($url, $cacheFile, 1800, 3); // 30m ttl for subs
        $resp = decodeMaybeBase64($resp);

        $lines = preg_split('/\r?\n/', $resp);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match("/^($protocols):\/\//i", $line)) continue;
            $configsRaw[] = $line;
            $sourceOf[$line] = "sub:" . $cacheKey;
        }
    } catch (Exception $e) {
        file_put_contents("reports/fetch_errors.txt", "[" . date('c') . "] sub:$url " . $e->getMessage() . "\n", FILE_APPEND);
    }
}


$invalid = [];
$valid = [];
$stats = [
    "total_raw" => count($configsRaw),
    "total_valid" => 0,
    "total_invalid" => 0,
    "by_type" => [],
    "invalid_reasons" => [],
];

$counterByType = [];

foreach ($configsRaw as $raw) {
    $res = validateConfig($raw);
    $type = $res['type'] ?? 'unknown';

    if (!isset($stats["by_type"][$type])) $stats["by_type"][$type] = 0;
    if (!isset($counterByType[$type])) $counterByType[$type] = 0;

    if (!$res['ok']) {
        $stats["total_invalid"] += 1;
        $reason = $res['reason'] ?? 'invalid';
        if (!isset($stats["invalid_reasons"][$reason])) $stats["invalid_reasons"][$reason] = 0;
        $stats["invalid_reasons"][$reason] += 1;

        $invalid[] = ($sourceOf[$raw] ?? "unknown") . " | " . $reason . " | " . $raw;
        continue;
    }

    $stats["total_valid"] += 1;
    $stats["by_type"][$type] += 1;

    $counterByType[$type] += 1;
    $n = $counterByType[$type];


    $raw2 = setConfigName($raw, buildStandardName($type, $n));
    $valid[] = $raw2;
}


file_put_contents("reports/invalid.txt", implode("\n", $invalid));
file_put_contents("reports/stats.json", json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));


file_put_contents("config.txt", implode("\n", $valid));

echo "\nDone. Valid: {$stats['total_valid']} | Invalid: {$stats['total_invalid']}\n";
