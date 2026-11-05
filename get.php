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
            if ($hasCache && $cacheAge < 86400) return [file_get_contents($cacheFile), ["status" => "stale", "fetch_mode" => "stale-cache", "http_code" => null, "attempts" => 3, "latency_ms" => null, "cache_age_seconds" => $cacheAge, "error" => "refresh_failed"]];
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
    if ($hasCache && $cacheAge < 86400) return [file_get_contents($cacheFile), ["status" => "stale", "fetch_mode" => "stale-cache", "http_code" => $lastCode ?: null, "attempts" => $attempts, "latency_ms" => $latency, "cache_age_seconds" => $cacheAge, "error" => $lastError ?: "refresh_failed"]];
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
$sourceOrigins = [];
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
                $sourceOrigins[$line][] = "telegram:" . $source;
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
            $sourceOrigins[$line][] = "private:" . $cacheKey;
            $sourceHealth["subscription:" . $cacheKey]["configs_found"]++;
        }
        $sourceHealth["subscription:" . $cacheKey]["status"] = (time() - filemtime($cacheFile) > 1800) ? "stale" : ($sourceHealth["subscription:" . $cacheKey]["configs_found"] > 0 ? "ok" : "empty");
    } catch (Exception $e) {
        $sourceHealth["subscription:" . $cacheKey]["status"] = "error";
        $sourceHealth["subscription:" . $cacheKey]["error"] = "fetch_failed";
        file_put_contents("reports/fetch_errors.txt", "[" . date('c') . "] sub:$cacheKey fetch_failed\n", FILE_APPEND);
    }
}


$sourceHistoryPath = "reports/source-history.json";
$sourceHistory = is_file($sourceHistoryPath) ? json_decode((string)file_get_contents($sourceHistoryPath), true) : [];
if (!is_array($sourceHistory)) $sourceHistory = [];
if (!is_array($sourceHistory["sources"] ?? null)) $sourceHistory["sources"] = [];
$globalSourceFingerprints = [];

foreach ($publicSources as $source) {
    $sourceId = preg_replace('/[^A-Za-z0-9_.-]/', '-', (string)$source["id"]);
    $sourceName = trim((string)($source["name"] ?? $sourceId));
    $sourceGroup = in_array(($source["group"] ?? ""), ["curated", "heavy"], true) ? $source["group"] : "other";
    $url = trim((string)$source["url"]);
    $cacheFile = "cache/public/" . $sourceId . ".txt";
    $key = "public:" . $sourceId;
    $sourceHealth[$key] = [
        "kind" => "public",
        "group" => $sourceGroup,
        "name" => $sourceName,
        "url" => $url,
        "status" => "unknown",
        "configs_found" => 0,
        "unique_configs" => 0,
        "checked_at" => gmdate("c"),
        "error" => null
    ];

    $percentage = ($tempCounter / max(1, $totalSources)) * 100;
    echo "\rProgress: [";
    echo str_repeat("=", (int)floor($percentage / (100 / max(1, $totalSources))));
    echo str_repeat(" ", max(0, $totalSources - (int)floor($percentage / (100 / max(1, $totalSources)))));
    echo "] " . number_format($percentage, 2) . "%";
    $tempCounter++;

    [$body, $fetchMeta] = fetchPublicSource($url, $cacheFile, 900);
    $sourceHealth[$key] = array_merge($sourceHealth[$key], $fetchMeta);
    if (!is_string($body) || trim($body) === "") {
        $sourceHealth[$key]["status"] = "error";
        $sourceHealth[$key]["error"] = $fetchMeta["error"] ?? "empty_response";
        continue;
    }

    $decodedBody = decodeMaybeBase64($body);
    $protocolPattern = expandProtocolPattern($source["protocols"] ?? ["vmess", "vless", "trojan", "ss", "tuic", "hy2"]);
    $extracted = extractLinksByType($decodedBody, $protocolPattern);
    $sourceFingerprints = [];
    if (is_array($extracted)) {
        foreach ($extracted as $line) {
            $line = trim($line);
            if ($line === "" || !detect_type($line)) continue;
            $configsRaw[] = $line;
            $sourceOf[$line] = $key;
            $sourceOrigins[$line][] = $key;
            $sourceHealth[$key]["configs_found"]++;
            $fingerprint = configFingerprint($line);
            if ($fingerprint !== null) {
                $sourceFingerprints[$fingerprint] = true;
                $globalSourceFingerprints[$fingerprint] = true;
            }
        }
    }
    $sourceHealth[$key]["unique_configs"] = count($sourceFingerprints);
    if ($sourceHealth[$key]["status"] === "ok" && $sourceHealth[$key]["configs_found"] === 0) {
        $sourceHealth[$key]["status"] = "empty";
    }
}

$sourceHistory["schema_version"] = 1;
$sourceHistory["updated_at"] = gmdate("c");
foreach ($sourceHealth as $key => $entry) {
    if (($entry["kind"] ?? "") !== "public") continue;
    $old = $sourceHistory["sources"][$key] ?? [];
    $yield = (int)($entry["unique_configs"] ?? 0);
    $history = is_array($old["yield_history"] ?? null) ? $old["yield_history"] : [];
    $history[] = $yield;
    $history = array_slice($history, -20);
    $zeroStreak = $yield > 0 ? 0 : ((int)($old["zero_unique_streak"] ?? 0) + 1);
    $lastSuccess = $old["last_success_at"] ?? null;
    if ($yield > 0 && ($entry["fetch_mode"] ?? "") === "network") $lastSuccess = gmdate("c");
    $sourceHistory["sources"][$key] = [
        "name" => $entry["name"],
        "group" => $entry["group"],
        "url" => $entry["url"],
        "runs" => (int)($old["runs"] ?? 0) + 1,
        "successful_runs" => (int)($old["successful_runs"] ?? 0) + (($yield > 0 && $entry["status"] === "ok") ? 1 : 0),
        "last_status" => $entry["status"],
        "last_http_code" => $entry["http_code"] ?? null,
        "last_configs_found" => (int)($entry["configs_found"] ?? 0),
        "last_unique_configs" => $yield,
        "zero_unique_streak" => $zeroStreak,
        "disable_candidate" => $zeroStreak >= 8,
        "last_success_at" => $lastSuccess,
        "yield_history" => $history,
        "updated_at" => gmdate("c")
    ];
}
$sourceHistoryTmp = $sourceHistoryPath . ".tmp";
file_put_contents($sourceHistoryTmp, json_encode($sourceHistory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
@rename($sourceHistoryTmp, $sourceHistoryPath);

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
$validProvenance = [];

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
    $fingerprint = configFingerprint($raw);
    if ($fingerprint !== null) {
        $origins = array_values(array_unique($sourceOrigins[$raw] ?? [$sourceOf[$raw] ?? "unknown"]));
        $groups = [];
        foreach ($origins as $origin) {
            if (strpos($origin, "public:") === 0) {
                $groups[] = $sourceHistory["sources"][$origin]["group"] ?? "public";
            } elseif (strpos($origin, "telegram:") === 0) {
                $groups[] = "telegram";
            } elseif (strpos($origin, "private:") === 0) {
                $groups[] = "private";
            }
        }
        if (!isset($validProvenance[$fingerprint])) {
            $validProvenance[$fingerprint] = ["sources" => $origins, "groups" => array_values(array_unique($groups))];
        } else {
            $validProvenance[$fingerprint]["sources"] = array_values(array_unique(array_merge($validProvenance[$fingerprint]["sources"], $origins)));
            $validProvenance[$fingerprint]["groups"] = array_values(array_unique(array_merge($validProvenance[$fingerprint]["groups"], $groups)));
        }
    }
    $valid[] = setConfigName($raw, buildStandardName($type, $counterByType[$type]));
}

file_put_contents("reports/invalid.txt", implode("\n", $invalid));
file_put_contents("reports/source-provenance.json", json_encode([
    "schema_version" => 1,
    "updated_at" => gmdate("c"),
    "nodes" => $validProvenance
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
file_put_contents("reports/stats.json", json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$publicHealth = array_filter($sourceHealth, function ($s) { return ($s["kind"] ?? "") === "public"; });
$publicCurated = array_filter($publicHealth, function ($s) { return ($s["group"] ?? "") === "curated"; });
$publicHeavy = array_filter($publicHealth, function ($s) { return ($s["group"] ?? "") === "heavy"; });
$disableCandidates = array_filter($sourceHistory["sources"] ?? [], function ($s) { return !empty($s["disable_candidate"]); });
file_put_contents("reports/sources.json", json_encode([
    "updated_at" => gmdate("c"),
    "catalog" => "https://raw.githubusercontent.com/sinavm/SVM/main/public_sources.json",
    "public_sources_total" => count($publicHealth),
    "public_sources_healthy" => count(array_filter($publicHealth, function ($s) { return $s["status"] === "ok"; })),
    "curated_sources" => count($publicCurated),
    "heavy_sources" => count($publicHeavy),
    "auto_disable_candidates" => array_keys($disableCandidates),
    "unique_public_configs" => count($globalSourceFingerprints),
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
