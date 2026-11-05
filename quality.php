<?php
ini_set("display_errors", 1);
error_reporting(E_ERROR | E_PARSE);
require_once __DIR__ . "/functions.php";

$root = getcwd();
$reports = $root . "/reports";
$normal = $root . "/subscriptions/xray/normal";
$base64 = $root . "/subscriptions/xray/base64";
@mkdir($reports, 0777, true);
@mkdir($normal, 0777, true);
@mkdir($base64, 0777, true);

$testPath = $reports . "/test.json";
$test = is_file($testPath) ? json_decode((string)file_get_contents($testPath), true) : [];
$statsPath = $reports . "/stats.json";
$stats = is_file($statsPath) ? json_decode((string)file_get_contents($statsPath), true) : [];
$sourcesPath = $reports . "/sources.json";
$sources = is_file($sourcesPath) ? json_decode((string)file_get_contents($sourcesPath), true) : [];
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
    if ($type === "trojan" || $type === "hy2" || $type === "hysteria") return true;
    if ($type === "vmess") return !empty($parsed["tls"]) && strtolower((string)$parsed["tls"]) !== "none";
    $params = $parsed["params"] ?? [];
    $security = strtolower((string)($params["security"] ?? $parsed["security"] ?? ""));
    if (in_array($security, ["tls", "reality"], true)) return true;
    if ($type === "tuic") return !isset($params["disable_sni"]) || (string)$params["disable_sni"] !== "true";
    return false;
}

$tiers = ["verified" => [], "fast" => [], "secure" => []];
$testUsable = !empty($test["infra_ok"]) && !empty($test["node_results"]) && is_array($test["node_results"]);
$protocols = [];
$nodeSummary = [];
$now = time();
foreach ($lines as $line) {
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
    $entry = [
        "fingerprint" => $fp,
        "protocol" => $type,
        "delay_ms" => $delay,
        "successful_rounds" => $successes,
        "total_rounds" => 3,
        "encrypted_transport" => isSecureTransport($line, $type)
    ];
    $nodeSummary[] = $entry;
    if ($successes >= 3 && $delay > 0 && $delay <= 800) $tiers["verified"][] = $line;
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
if (count($history) > 5000) {
    uasort($history, function ($a, $b) { return ($b["last_seen"] ?? 0) <=> ($a["last_seen"] ?? 0); });
    $history = array_slice($history, 0, 5000, true);
}
file_put_contents($historyPath, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$sourceRows = $sources["sources"] ?? [];
$healthySources = (int)($sources["healthy_sources"] ?? 0);
$failedSources = (int)($sources["failed_sources"] ?? 0);
$health = [
    "schema_version" => 1,
    "generated_at" => gmdate("c"),
    "overall" => [
        "status" => !empty($test["infra_ok"]) ? (($failedSources > 0) ? "degraded" : "healthy") : "unknown",
        "pipeline_ok" => !empty($test["infra_ok"]),
        "source_health" => ["total" => count($sourceRows), "healthy" => $healthySources, "failed" => $failedSources],
        "nodes" => ["input" => (int)($test["input"] ?? 0), "alive" => (int)($test["kept"] ?? 0), "dropped" => (int)($test["dropped"] ?? 0)]
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
    "protocols_alive" => $protocols,
    "quality_data_fresh" => $testUsable,
    "links" => [
        "verified" => "https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/verified",
        "fast" => "https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/fast",
        "secure" => "https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/secure"
    ]
];
$index = [
    "schema_version" => 1,
    "generated_at" => gmdate("c"),
    "project" => "SVM",
    "health" => "https://raw.githubusercontent.com/sinavm/SVM/main/reports/health.json",
    "stats" => "https://raw.githubusercontent.com/sinavm/SVM/main/reports/stats.json",
    "sources" => "https://raw.githubusercontent.com/sinavm/SVM/main/reports/sources.json",
    "test" => "https://raw.githubusercontent.com/sinavm/SVM/main/reports/test.json",
    "subscriptions" => $health["links"],
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
