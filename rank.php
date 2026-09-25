<?php
ini_set("display_errors", 1);
error_reporting(E_ERROR | E_PARSE);
require_once __DIR__ . "/functions.php";

$CWD = getcwd();
$isLite = (basename($CWD) === "lite");
$OUT = $isLite ? $CWD : ($CWD . "/lite");
$CONFIG = $CWD . "/config.txt";
$REPORTS = $OUT . "/reports";
@mkdir($REPORTS, 0777, true);
@mkdir($OUT . "/subscriptions/xray/normal", 0777, true);
@mkdir($OUT . "/subscriptions/xray/base64", 0777, true);
@mkdir($OUT . "/docs/subscriptions", 0777, true);
@mkdir($OUT . "/docs/reports", 0777, true);

foreach (["mci", "irancell", "national-net"] as $dead) {
    foreach ([
        $CWD . "/subscriptions/xray/normal/" . $dead,
        $CWD . "/subscriptions/xray/base64/" . $dead,
        $CWD . "/docs/subscriptions/" . $dead,
        $OUT . "/subscriptions/xray/normal/" . $dead,
        $OUT . "/subscriptions/xray/base64/" . $dead,
        $OUT . "/docs/subscriptions/" . $dead,
    ] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

$now = time();
$historyFile = $REPORTS . "/history.json";
$history = [];
if (is_file($historyFile)) {
    $decoded = json_decode((string)file_get_contents($historyFile), true);
    if (is_array($decoded)) {
        $history = $decoded;
    }
}

$test = [];
if (is_file($CWD . "/reports/test.json")) {
    $test = json_decode((string)file_get_contents($CWD . "/reports/test.json"), true) ?: [];
} elseif (is_file($REPORTS . "/test.json")) {
    $test = json_decode((string)file_get_contents($REPORTS . "/test.json"), true) ?: [];
}

$lines = is_file($CONFIG) ? file($CONFIG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$seen = [];
$ranked = [];
$pos = 0;
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === "" || !detect_type($line)) {
        continue;
    }
    $fp = configFingerprint($line);
    if (!$fp || isset($seen[$fp])) {
        continue;
    }
    $seen[$fp] = true;
    $pos++;
    if (!isset($history[$fp]) || !is_array($history[$fp])) {
        $history[$fp] = [
            "first_seen" => $now,
            "last_ok" => $now,
            "ok" => 0,
            "fail" => 0,
            "fail_streak" => 0,
        ];
    }
    $history[$fp]["last_ok"] = $now;
    $history[$fp]["ok"] = (int)($history[$fp]["ok"] ?? 0) + 1;
    $history[$fp]["fail_streak"] = 0;
    $ok = max(1, (int)$history[$fp]["ok"]);
    $fail = (int)($history[$fp]["fail"] ?? 0);
    $success = $ok / max(1, $ok + $fail);
    $ageHours = max(0, ($now - (int)$history[$fp]["first_seen"]) / 3600);
    $ageBonus = $ageHours <= 24 ? 25 : ($ageHours <= 72 ? 10 : 0);
    $score = (int)round(($success * 50) + max(0, 120 - $pos) + $ageBonus);
    $ranked[] = [
        "fp" => $fp,
        "uri" => $line,
        "type" => detect_type($line),
        "score" => $score,
        "rank_index" => $pos,
    ];
}

if (empty($ranked)) {
    fwrite(STDERR, "rank.php: no nodes; compact files left unchanged\n");
    exit(0);
}

foreach ($history as $fp => $row) {
    if (isset($seen[$fp])) {
        continue;
    }
    $history[$fp]["fail"] = (int)($row["fail"] ?? 0) + 1;
    $history[$fp]["fail_streak"] = (int)($row["fail_streak"] ?? 0) + 1;
    if ($history[$fp]["fail_streak"] >= 8) {
        unset($history[$fp]);
    }
}
if (count($history) > 2500) {
    uasort($history, function ($a, $b) {
        return ((int)($b["last_ok"] ?? 0)) <=> ((int)($a["last_ok"] ?? 0));
    });
    $history = array_slice($history, 0, 2500, true);
}

$public = array_values(array_filter($ranked, function ($n) use ($history) {
    return (int)($history[$n["fp"]]["fail_streak"] ?? 0) < 2;
}));
usort($public, function ($a, $b) {
    if ($a["score"] === $b["score"]) {
        return $a["rank_index"] <=> $b["rank_index"];
    }
    return $b["score"] <=> $a["score"];
});

function pickDiverse($nodes, $limit, $perType = 8) {
    $out = [];
    $count = [];
    foreach ($nodes as $n) {
        $t = $n["type"] ?: "other";
        if (($count[$t] ?? 0) >= $perType) {
            continue;
        }
        $out[] = $n;
        $count[$t] = ($count[$t] ?? 0) + 1;
        if (count($out) >= $limit) {
            break;
        }
    }
    foreach ($nodes as $n) {
        if (count($out) >= $limit) {
            break;
        }
        if (!in_array($n, $out, true)) {
            $out[] = $n;
        }
    }
    return $out;
}

function subHeader($title, $count) {
    $expire = time() + (30 * 86400);
    return "#profile-title: base64:" . base64_encode($title) . "\n" .
        "#profile-update-interval: 1\n" .
        "#subscription-userinfo: upload=0; download=0; total=10737418240000000; expire={$expire}\n" .
        "#support-url: https://t.me/sinavm\n" .
        "#profile-web-page-url: https://github.com/sinavm/SVM\n" .
        "#announce: nodes={$count} updated=" . gmdate("Y-m-d\\TH:i:s\\Z") . "\n\n";
}

function writeLiteSub($name, $title, $nodes) {
    global $OUT;
    if (empty($nodes)) {
        return 0;
    }
    $uris = [];
    foreach ($nodes as $n) {
        $uris[] = $n["uri"];
    }
    $body = subHeader($title, count($uris)) . implode("\n", $uris) . "\n";
    file_put_contents($OUT . "/subscriptions/xray/normal/" . $name, $body);
    file_put_contents($OUT . "/subscriptions/xray/base64/" . $name, base64_encode($body));
    file_put_contents($OUT . "/docs/subscriptions/" . $name, $body);
    return count($uris);
}

$fast = pickDiverse($public, 25, 8);
$stable = array_slice($public, 0, 50);
$nFast = writeLiteSub("alive-fast", "SiNAVM LITE FAST", $fast);
$nStable = writeLiteSub("alive-stable", "SiNAVM LITE STABLE", $stable);

$delays = [];
if (!empty($test["fastest"]) && is_array($test["fastest"])) {
    foreach ($test["fastest"] as $row) {
        if (isset($row["delay"])) {
            $delays[] = (int)$row["delay"];
        }
    }
}
$status = [
    "updated_at" => gmdate("c"),
    "scope" => "lite-compact-only",
    "tested_at" => $test["tested_at"] ?? null,
    "source_nodes" => count($ranked),
    "alive_fast" => $nFast,
    "alive_stable" => $nStable,
    "avg_delay_ms_top15" => $delays ? (int)round(array_sum($delays) / count($delays)) : null,
    "links" => [
        "raw_fast" => "https://raw.githubusercontent.com/sinavm/SVM/main/lite/subscriptions/xray/normal/alive-fast",
        "raw_stable" => "https://raw.githubusercontent.com/sinavm/SVM/main/lite/subscriptions/xray/normal/alive-stable",
        "full_mix" => "https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/mix",
    ],
];
file_put_contents($REPORTS . "/status.json", json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents($OUT . "/docs/reports/status.json", json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents($historyFile, json_encode($history));

echo "rank.php lite compact fast={$nFast} stable={$nStable} (full mix not touched)\n";
