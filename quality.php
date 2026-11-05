<?php
ini_set("display_errors", 1);
error_reporting(E_ERROR | E_PARSE);
require_once __DIR__ . "/functions.php";

$root = getcwd();
$reports = $root . "/reports";
$normal = $root . "/subscriptions/xray/normal";
$base64 = $root . "/subscriptions/xray/base64";
$repoPrefix = basename($root) === "lite" ? "lite/" : "";
@mkdir($reports, 0777, true);
@mkdir($normal, 0777, true);
@mkdir($base64, 0777, true);

$testPath = $reports . "/test.json";
$test = is_file($testPath) ? json_decode((string)file_get_contents($testPath), true) : [];
$statsPath = $reports . "/stats.json";
$stats = is_file($statsPath) ? json_decode((string)file_get_contents($statsPath), true) : [];
$sourcesPath = $reports . "/sources.json";
$sources = is_file($sourcesPath) ? json_decode((string)file_get_contents($sourcesPath), true) : [];
$provenancePath = $reports . "/source-provenance.json";
$provenance = is_file($provenancePath) ? json_decode((string)file_get_contents($provenancePath), true) : [];
$provenanceNodes = is_array($provenance["nodes"] ?? null) ? $provenance["nodes"] : [];
$historyPath = $reports . "/quality-history.json";
$history = is_file($historyPath) ? json_decode((string)file_get_contents($historyPath), true) : [];
if (!is_array($history)) $history = [];

$lines = is_file($root . "/config.txt") ? file($root . "/config.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$nodeResults = [];
foreach (($test["node_results"] ?? []) as $row) {
    if (!empty($row["fingerprint"])) $nodeResults[$row["fingerprint"]] = $row;
}

function isSecureTransport($line, $type) {
    $parsed = configParse($line);
    if (!is_array($parsed)) return false;
    if ($type === "trojan" || $type === "hy2" || $type === "hysteria" || $type === "tuic") return true;
    if ($type === "vmess") return !empty($parsed["tls"]) && strtolower((string)$parsed["tls"]) !== "none";
    $params = $parsed["params"] ?? [];
    $security = strtolower((string)($params["security"] ?? $parsed["security"] ?? ""));
    return in_array($security, ["tls", "reality"], true);
}

function nodeHost($line, $type) {
    $parsed = configParse($line);
    if (!is_array($parsed)) return "";
    if ($type === "vmess") return trim((string)($parsed["add"] ?? ""));
    if ($type === "ss") return trim((string)($parsed["server_address"] ?? ""));
    return trim((string)($parsed["hostname"] ?? ""));
}

$testUsable = !empty($test["infra_ok"]) && !empty($test["node_results"]) && is_array($test["node_results"]);
$geoCachePath = $reports . "/geo-cache.json";
$geoCache = is_file($geoCachePath) ? json_decode((string)file_get_contents($geoCachePath), true) : [];
if (!is_array($geoCache)) $geoCache = [];
$geoTtl = 30 * 86400;
$publicIpFlags = FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
foreach (array_keys($geoCache) as $cachedIp) {
    if (!filter_var($cachedIp, FILTER_VALIDATE_IP, $publicIpFlags)) unset($geoCache[$cachedIp]);
}
$hostIps = [];
$missingIps = [];
if ($testUsable) {
    foreach ($lines as $line) {
        $type = detect_type(trim($line));
        if (!$type) continue;
        $host = nodeHost(trim($line), $type);
        if ($host === "") continue;
        $ip = filter_var($host, FILTER_VALIDATE_IP, $publicIpFlags) ? $host : gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP, $publicIpFlags)) continue;
        $hostIps[configFingerprint(trim($line))] = $ip;
        $cached = $geoCache[$ip] ?? [];
        if (empty($cached["country"]) || (time() - (int)($cached["checked_at"] ?? 0)) > $geoTtl) $missingIps[$ip] = true;
    }
}
if (function_exists("curl_init") && !empty($missingIps)) {
    foreach (array_chunk(array_keys($missingIps), 100) as $ipBatch) {
        $payload = json_encode(array_map(function ($ip) { return ["query" => $ip]; }, $ipBatch));
        $ch = curl_init("http://ip-api.com/batch?fields=status,countryCode,query");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Accept: application/json"],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $rows = ($code === 200 && is_string($body)) ? json_decode($body, true) : null;
        if (!is_array($rows)) continue;
        foreach ($rows as $row) {
            $ip = (string)($row["query"] ?? "");
            $country = strtoupper((string)($row["countryCode"] ?? ""));
            if (($row["status"] ?? "") === "success" && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && preg_match('/^[A-Z]{2}$/', $country)) {
                $geoCache[$ip] = ["country" => $country, "checked_at" => time()];
            }
        }
    }
}
file_put_contents($geoCachePath, json_encode($geoCache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$nodeCountries = [];
foreach ($hostIps as $fp => $ip) {
    $country = $geoCache[$ip]["country"] ?? null;
    if (is_string($country) && preg_match('/^[A-Z]{2}$/', $country)) $nodeCountries[$fp] = $country;
}

$tiers = ["verified" => [], "fast" => [], "secure" => []];
$sourceTiers = ["all" => [], "curated" => [], "heavy" => []];
$verifiedRows = [];
$protocols = [];
$protocolNodes = [];
$countryNodes = [];
$nodeSummary = [];
$now = time();
if ($testUsable) foreach ($lines as $line) {
    $line = trim($line);
    $type = detect_type($line);
    $fp = configFingerprint($line);
    if (!$type || !$fp) continue;
    $result = $nodeResults[$fp] ?? [];
    $delay = (int)($result["delay_ms"] ?? 0);
    $successes = (int)($result["successes"] ?? 0);
    $rounds = $result["rounds"] ?? [];
    if (!isset($protocols[$type])) $protocols[$type] = 0;
    $protocols[$type]++;
    $protocolNodes[$type][] = $line;
    $country = $nodeCountries[$fp] ?? null;
    if ($country !== null) $countryNodes[$country][] = $line;
    $entry = [
        "fingerprint" => $fp,
        "protocol" => $type,
        "delay_ms" => $delay,
        "successful_rounds" => $successes,
        "total_rounds" => 3,
        "encrypted_transport" => isSecureTransport($line, $type),
        "country_code" => $country
    ];
    $nodeSummary[] = $entry;
    $sourceTiers["all"][] = $line;
    $originGroups = $provenanceNodes[$fp]["groups"] ?? [];
    if (in_array("curated", $originGroups, true)) $sourceTiers["curated"][] = $line;
    if (in_array("heavy", $originGroups, true)) $sourceTiers["heavy"][] = $line;
    if ($successes >= 3 && $delay > 0 && $delay <= 800) {
        $tiers["verified"][] = $line;
        $verifiedRows[] = ["line" => $line, "delay_ms" => $delay];
    }
    if ($successes >= 2 && $delay > 0 && $delay <= 350) $tiers["fast"][] = $line;
    if ($entry["encrypted_transport"]) $tiers["secure"][] = $line;
    $history[$fp] = [
        "last_seen" => $now,
        "last_delay_ms" => $delay,
        "successful_rounds" => $successes,
        "total_rounds" => 3,
        "protocol" => $type
    ];
}
if ($testUsable) foreach ($tiers as $name => $items) {
    $body = "#profile-title: base64:" . base64_encode("SVM " . strtoupper($name)) . "\n";
    $body .= "#profile-update-interval: 1\n";
    $body .= "#support-url: https://t.me/sinavm\n";
    $body .= "#profile-web-page-url: https://github.com/sinavm/SVM\n\n";
    $body .= implode("\n", $items) . (count($items) ? "\n" : "");
    file_put_contents($normal . "/" . $name, $body);
    file_put_contents($base64 . "/" . $name, base64_encode($body));
}
if ($testUsable) {
    $normalSourceTiers = $normal . "/source-tiers";
    $base64SourceTiers = $base64 . "/source-tiers";
    @mkdir($normalSourceTiers, 0777, true);
    @mkdir($base64SourceTiers, 0777, true);
    foreach (["all", "curated", "heavy"] as $tierName) {
        $items = $sourceTiers[$tierName];
        $body = "#profile-title: base64:" . base64_encode("SVM " . strtoupper($tierName)) . "\n";
        $body .= "#profile-update-interval: 1\n";
        $body .= "#support-url: https://t.me/sinavm\n";
        $body .= "#profile-web-page-url: https://github.com/sinavm/SVM\n\n";
        $body .= implode("\n", $items) . (count($items) ? "\n" : "");
        file_put_contents($normalSourceTiers . "/" . $tierName, $body);
        file_put_contents($base64SourceTiers . "/" . $tierName, base64_encode($body));
    }
    usort($verifiedRows, function ($a, $b) { return $a["delay_ms"] <=> $b["delay_ms"]; });
    $topLines = array_column(array_slice($verifiedRows, 0, 100), "line");
    file_put_contents($root . "/top100.txt", implode("\n", $topLines) . (count($topLines) ? "\n" : ""));
    @mkdir($normal . "/protocols", 0777, true);
    @mkdir($base64 . "/protocols", 0777, true);
    @mkdir($normal . "/countries", 0777, true);
    @mkdir($base64 . "/countries", 0777, true);
    foreach ([$normal . "/protocols", $base64 . "/protocols", $normal . "/countries", $base64 . "/countries"] as $directory) {
        foreach (glob($directory . "/*") ?: [] as $oldFile) {
            if (is_file($oldFile)) @unlink($oldFile);
        }
    }
    foreach ($protocolNodes as $type => $items) {
        $body = "#profile-title: base64:" . base64_encode("SVM " . strtoupper($type)) . "\n";
        $body .= "#profile-update-interval: 1\n";
        $body .= "#support-url: https://t.me/sinavm\n";
        $body .= "#profile-web-page-url: https://github.com/sinavm/SVM\n\n";
        $body .= implode("\n", $items) . "\n";
        file_put_contents($normal . "/protocols/" . $type, $body);
        file_put_contents($base64 . "/protocols/" . $type, base64_encode($body));
    }
    foreach ($countryNodes as $country => $items) {
        $body = "#profile-title: base64:" . base64_encode("SVM " . $country) . "\n";
        $body .= "#profile-update-interval: 1\n";
        $body .= "#support-url: https://t.me/sinavm\n";
        $body .= "#profile-web-page-url: https://github.com/sinavm/SVM\n\n";
        $body .= implode("\n", $items) . "\n";
        file_put_contents($normal . "/countries/" . $country, $body);
        file_put_contents($base64 . "/countries/" . $country, base64_encode($body));
    }
}
if (count($history) > 5000) {
    uasort($history, function ($a, $b) { return ($b["last_seen"] ?? 0) <=> ($a["last_seen"] ?? 0); });
    $history = array_slice($history, 0, 5000, true);
}
file_put_contents($historyPath, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$sourceRows = $sources["sources"] ?? [];
$healthySources = (int)($sources["healthy_sources"] ?? 0);
$failedSources = (int)($sources["failed_sources"] ?? 0);
$staleSources = (int)($sources["stale_sources"] ?? 0);
$unconfiguredSources = (int)($sources["unconfigured_sources"] ?? 0);
$emptySources = (int)($sources["empty_sources"] ?? 0);
$health = [
    "schema_version" => 1,
    "generated_at" => gmdate("c"),
    "overall" => [
        "status" => !empty($test["infra_ok"]) ? (($failedSources + $staleSources + $emptySources > 0) ? "degraded" : "healthy") : "unknown",
        "pipeline_ok" => !empty($test["infra_ok"]),
        "source_health" => ["total" => count($sourceRows), "healthy" => $healthySources, "stale" => $staleSources, "failed" => $failedSources, "unconfigured" => $unconfiguredSources, "empty" => $emptySources],
        "nodes" => ["input" => (int)($test["input"] ?? 0), "alive" => count($nodeSummary), "tested_alive" => (int)($test["kept"] ?? 0), "duplicates_removed" => max(0, (int)($test["kept"] ?? 0) - count($nodeSummary)), "dropped" => (int)($test["dropped"] ?? 0)]
    ],
    "collection" => $sources,
    "testing" => [
        "tested_at" => $test["tested_at"] ?? null,
        "engine" => $test["engine"] ?? null,
        "infra_ok" => (bool)($test["infra_ok"] ?? false),
        "stability_rounds" => (int)($test["stability_rounds"] ?? 0),
        "protocol_alive" => (int)($test["protocol_alive"] ?? 0),
        "protocol_dead" => (int)($test["protocol_dead"] ?? 0),
        "error" => $test["error"] ?? null
    ],
    "validation" => [
        "total_raw" => (int)($stats["total_raw"] ?? 0),
        "total_valid" => (int)($stats["total_valid"] ?? 0),
        "total_invalid" => (int)($stats["total_invalid"] ?? 0),
        "by_type" => $stats["by_type"] ?? []
    ],
    "tiers" => [
        "verified" => $testUsable ? count($tiers["verified"]) : null,
        "fast" => $testUsable ? count($tiers["fast"]) : null,
        "secure" => $testUsable ? count($tiers["secure"]) : null
    ],
    "source_tiers" => ["all" => $testUsable ? count($sourceTiers["all"]) : null, "curated" => $testUsable ? count($sourceTiers["curated"]) : null, "heavy" => $testUsable ? count($sourceTiers["heavy"]) : null],
    "protocols_alive" => $protocols,
    "country_nodes" => array_map("count", $countryNodes),
    "geoip" => ["provider" => "ip-api.com", "known_nodes" => count($nodeCountries), "unknown_nodes" => max(0, count($nodeSummary) - count($nodeCountries)), "resolved_ipv4_nodes" => count($hostIps), "unresolved_hosts" => max(0, count($nodeSummary) - count($hostIps)), "cache_ttl_days" => 30],
    "quality_data_fresh" => $testUsable,
    "links" => [
        "verified" => "https://raw.githubusercontent.com/sinavm/SVM/main/" . $repoPrefix . "subscriptions/xray/normal/verified",
        "fast" => "https://raw.githubusercontent.com/sinavm/SVM/main/" . $repoPrefix . "subscriptions/xray/normal/fast",
        "secure" => "https://raw.githubusercontent.com/sinavm/SVM/main/" . $repoPrefix . "subscriptions/xray/normal/secure",
        "protocols" => "https://raw.githubusercontent.com/sinavm/SVM/main/" . $repoPrefix . "subscriptions/xray/normal/protocols/",
        "countries" => "https://raw.githubusercontent.com/sinavm/SVM/main/" . $repoPrefix . "subscriptions/xray/normal/countries/",
        "source_tiers" => "https://raw.githubusercontent.com/sinavm/SVM/main/" . $repoPrefix . "subscriptions/xray/normal/source-tiers/",
        "source_tiers_base64" => "https://raw.githubusercontent.com/sinavm/SVM/main/" . $repoPrefix . "subscriptions/xray/base64/source-tiers/",
        "top100" => "https://raw.githubusercontent.com/sinavm/SVM/main/" . $repoPrefix . "top100.txt"
    ]
];
$index = [
    "schema_version" => 1,
    "generated_at" => gmdate("c"),
    "project" => "SVM",
    "health" => "https://raw.githubusercontent.com/sinavm/SVM/main/" . $repoPrefix . "reports/health.json",
    "stats" => "https://raw.githubusercontent.com/sinavm/SVM/main/" . $repoPrefix . "reports/stats.json",
    "sources" => "https://raw.githubusercontent.com/sinavm/SVM/main/" . $repoPrefix . "reports/sources.json",
    "test" => "https://raw.githubusercontent.com/sinavm/SVM/main/" . $repoPrefix . "reports/test.json",
    "source_provenance" => "https://raw.githubusercontent.com/sinavm/SVM/main/" . $repoPrefix . "reports/source-provenance.json",
    "source_history" => "https://raw.githubusercontent.com/sinavm/SVM/main/" . $repoPrefix . "reports/source-history.json",
    "subscriptions" => $health["links"],
    "protocol_subscriptions" => array_map(function ($type) use ($health) { return $health["links"]["protocols"] . $type; }, array_keys($protocols)),
    "country_subscriptions" => array_map(function ($country) use ($health) { return $health["links"]["countries"] . $country; }, array_keys($countryNodes)),
    "source_tier_subscriptions" => array_map(function ($tier) use ($health) { return $health["links"]["source_tiers"] . $tier; }, ["all", "curated", "heavy"]),
    "counts" => $health["tiers"],
    "nodes" => $nodeSummary
];
file_put_contents($reports . "/health.json", json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents($reports . "/index.json", json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
if (is_dir($root . "/docs/reports")) {
    file_put_contents($root . "/docs/reports/health.json", json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    file_put_contents($root . "/docs/reports/index.json", json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
}
echo $testUsable ? ("Quality tiers generated: verified=" . count($tiers["verified"]) . " fast=" . count($tiers["fast"]) . " secure=" . count($tiers["secure"]) . PHP_EOL) : "Quality tiers unchanged because no fresh successful test data is available." . PHP_EOL;
