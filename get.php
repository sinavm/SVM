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
    $backoffs = [1, 3, 7];
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

function resolvePrivateSublink($n) {
    $candidates = [
        getenv("PRIVATE_LINK_SiNAVM_" . $n),
        getenv("PRIVATE_LINK_SINAVM_" . $n),
    ];
    foreach ($candidates as $val) {
        if (is_string($val) && trim($val) !== '') {
            return trim($val);
        }
    }
    return '';
}

function fetchPublicSource($url, $cacheFile, $ttlSeconds = 900) {
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
    $hasCache = is_file($cacheFile);
    $cacheAge = $hasCache ? max(0, time() - filemtime($cacheFile)) : null;
    if ($hasCache && $cacheAge < $ttlSeconds) {
        return [file_get_contents($cacheFile), ["status" => "ok", "fetch_mode" => "cache", "http_code" => null, "attempts" => 0, "latency_ms" => 0, "cache_age_seconds" => $cacheAge, "error" => null]];
    }
    if (!function_exists("curl_init")) {
        try {
            $body = fetchWithCache($url, $cacheFile, $ttlSeconds, 3);
            return [$body, ["status" => "ok", "fetch_mode" => "stream", "http_code" => null, "attempts" => 1, "latency_ms" => null, "cache_age_seconds" => 0, "error" => null]];
        } catch (Exception $e) {
            if ($hasCache) return [file_get_contents($cacheFile), ["status" => "stale", "fetch_mode" => "stale-cache", "http_code" => null, "attempts" => 3, "latency_ms" => null, "cache_age_seconds" => $cacheAge, "error" => "refresh_failed"]];
            return ["", ["status" => "error", "fetch_mode" => "network", "http_code" => null, "attempts" => 3, "latency_ms" => null, "cache_age_seconds" => null, "error" => "fetch_failed"]];
        }
    }
    $lastCode = 0;
    $lastError = null;
    $attempts = 0;
    $latency = 0;
    $backoffs = [1, 2, 4];
    for ($i = 0; $i < 3; $i++) {
        $attempts++;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => "SVM-Upstream-Aggregator/2.0",
            CURLOPT_HTTPHEADER => ["Accept: text/plain, */*"],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $started = microtime(true);
        $body = curl_exec($ch);
        $lastCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $latency = (int)round((microtime(true) - $started) * 1000);
        $errno = curl_errno($ch);
        $lastError = $errno ? curl_error($ch) : null;
        curl_close($ch);
        if ($lastCode >= 200 && $lastCode < 300 && is_string($body) && strlen($body) > 50 && strlen($body) <= 12000000) {
            $tmp = $cacheFile . ".tmp";
            if (file_put_contents($tmp, $body, LOCK_EX) !== false) @rename($tmp, $cacheFile);
            else @unlink($tmp);
            return [$body, ["status" => "ok", "fetch_mode" => "network", "http_code" => $lastCode, "attempts" => $attempts, "latency_ms" => $latency, "cache_age_seconds" => 0, "error" => null]];
        }
        if (is_string($body) && strlen($body) > 12000000) $lastError = "response_too_large";
        elseif (!$lastError) $lastError = $lastCode ? "http_" . $lastCode : "empty_response";
        if ($lastCode >= 400 && $lastCode < 500 && $lastCode !== 429) break;
        if ($i < 2) sleep($backoffs[$i]);
    }
    if ($hasCache) return [file_get_contents($cacheFile), ["status" => "stale", "fetch_mode" => "stale-cache", "http_code" => $lastCode ?: null, "attempts" => $attempts, "latency_ms" => $latency, "cache_age_seconds" => $cacheAge, "error" => $lastError ?: "refresh_failed"]];
    return ["", ["status" => "error", "fetch_mode" => "network", "http_code" => $lastCode ?: null, "attempts" => $attempts, "latency_ms" => $latency, "cache_age_seconds" => null, "error" => $lastError ?: "fetch_failed"]];
}

$sourcesArray = json_decode(file_get_contents("channels.json"), true);
if (!is_array($sourcesArray)) $sourcesArray = [];
$publicCatalog = is_file("public_sources.json") ? json_decode(file_get_contents("public_sources.json"), true) : [];
$publicSources = is_array($publicCatalog["sources"] ?? null) ? $publicCatalog["sources"] : [];
$publicSources = array_values(array_filter($publicSources, function ($source) {
    return is_array($source) && !empty($source["enabled"]) && !empty($source["url"]) && !empty($source["id"]);
}));

$sublinksJson = file_get_contents("sublinks.json");
$sublinksJson = preg_replace_callback('/__PRIVATE_LINK_SiNAVM_(\d+)__/', function ($m) {
    return resolvePrivateSublink($m[1]);
}, $sublinksJson);
$sublinksArray = json_decode($sublinksJson, true);
if (!is_array($sublinksArray) || !isset($sublinksArray['sublinks']) || !is_array($sublinksArray['sublinks'])) {
    $sublinksArray = ['sublinks' => []];
}

$totalSources = count($sourcesArray) + count($sublinksArray['sublinks']) + count($publicSources);
$tempCounter = 1;

$configsRaw = [];
$sourceOf = [];
$sourceHealth = [];

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
    $sourceHealth["telegram:" . $source] = ["kind" => "telegram", "name" => $source, "status" => "unknown", "configs_found" => 0, "checked_at" => gmdate("c"), "error" => null];
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
                $sourceHealth["telegram:" . $source]["configs_found"]++;
            }
        }
        $sourceHealth["telegram:" . $source]["status"] = (time() - filemtime($cacheFile) > 7200) ? "stale" : ($sourceHealth["telegram:" . $source]["configs_found"] > 0 ? "ok" : "empty");
    } catch (Exception $e) {
        $sourceHealth["telegram:" . $source]["status"] = "error";
        $sourceHealth["telegram:" . $source]["error"] = $e->getMessage();
        file_put_contents("reports/fetch_errors.txt", "[" . date('c') . "] tg:$source " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

foreach ($sublinksArray['sublinks'] as $sublinkIndex => $sublink) {
    $percentage = ($tempCounter / $totalSources) * 100;
    echo "\rProgress: [";
    echo str_repeat("=", floor($percentage / (100 / $totalSources)));
    echo str_repeat(" ", $totalSources - floor($percentage / (100 / $totalSources)));
    echo "] " . number_format($percentage, 2) . "%";
    $tempCounter++;

    $url = trim((string)($sublink['url'] ?? ''));
    $url = preg_replace('/#.*$/', '', $url);
    $protocols = expandProtocolPattern($sublink['protocols'] ?? []);

    if ($url === '' || strpos($url, '__PRIVATE_LINK_') !== false || $protocols === '') {
        $slot = (string)($sublinkIndex + 1);
        if (preg_match('/__PRIVATE_LINK_SiNAVM_(\d+)__/', $url, $match)) $slot = $match[1];
        $key = "subscription_slot:" . $slot;
        $sourceHealth[$key] = [
            "kind" => "subscription",
            "name" => "private-source-" . $slot,
            "status" => "unconfigured",
            "configs_found" => 0,
            "checked_at" => gmdate("c"),
            "error" => "missing_secret_or_protocol_filter"
        ];
        continue;
    }

    $cacheKey = substr(sha1($url), 0, 16);
    $cacheFile = "cache/subs/" . $cacheKey . ".txt";

    $sourceHealth["subscription:" . $cacheKey] = ["kind" => "subscription", "name" => "source-" . substr($cacheKey, 0, 8), "status" => "unknown", "configs_found" => 0, "checked_at" => gmdate("c"), "error" => null];
    try {
        $resp = fetchWithCache($url, $cacheFile, 1800, 3);
        $resp = decodeMaybeBase64($resp);
        $lines = preg_split('/\r?\n/', $resp);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match("/^($protocols):\/\//i", $line)) continue;
            $configsRaw[] = $line;
            $sourceOf[$line] = "sub:" . $cacheKey;
            $sourceHealth["subscription:" . $cacheKey]["configs_found"]++;
        }
        $sourceHealth["subscription:" . $cacheKey]["status"] = (time() - filemtime($cacheFile) > 1800) ? "stale" : ($sourceHealth["subscription:" . $cacheKey]["configs_found"] > 0 ? "ok" : "empty");
    } catch (Exception $e) {
        $sourceHealth["subscription:" . $cacheKey]["status"] = "error";
        $sourceHealth["subscription:" . $cacheKey]["error"] = "fetch_failed";
        file_put_contents("reports/fetch_errors.txt", "[" . date('c') . "] sub:$cacheKey fetch_failed\n", FILE_APPEND);
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
    $valid[] = setConfigName($raw, buildStandardName($type, $counterByType[$type]));
}

file_put_contents("reports/invalid.txt", implode("\n", $invalid));
file_put_contents("reports/stats.json", json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
file_put_contents("reports/sources.json", json_encode([
    "updated_at" => gmdate("c"),
    "total_sources" => count($sourceHealth),
    "healthy_sources" => count(array_filter($sourceHealth, function ($s) { return $s["status"] === "ok"; })),
    "stale_sources" => count(array_filter($sourceHealth, function ($s) { return $s["status"] === "stale"; })),
    "empty_sources" => count(array_filter($sourceHealth, function ($s) { return $s["status"] === "empty"; })),
    "failed_sources" => count(array_filter($sourceHealth, function ($s) { return $s["status"] === "error"; })),
    "unconfigured_sources" => count(array_filter($sourceHealth, function ($s) { return $s["status"] === "unconfigured"; })),
    "sources" => $sourceHealth
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents("config.txt", implode("\n", $valid));

echo "\nDone. Valid: {$stats['total_valid']} | Invalid: {$stats['total_invalid']}\n";
