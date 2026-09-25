<?php
ini_set("display_errors", 1);
error_reporting(E_ERROR | E_PARSE);
require_once __DIR__ . "/functions.php";

$ROOT = getcwd();
$CONFIG = $ROOT . "/config.txt";
$REPORTS = $ROOT . "/reports";
@mkdir($REPORTS, 0777, true);
@mkdir($ROOT . "/subscriptions/xray/normal", 0777, true);
@mkdir($ROOT . "/subscriptions/xray/base64", 0777, true);
@mkdir($ROOT . "/docs/subscriptions", 0777, true);
@mkdir($ROOT . "/docs/reports", 0777, true);

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
if (is_file($REPORTS . "/test.json")) {
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
    $delayGuess = $pos * 15;
    if (!isset($history[$fp])) {
        $history[$fp] = [
            "first_seen" => $now,
            "last_ok" => $now,
            "ok" => 0,
            "fail" => 0,
            "fail_streak" => 0,
            "delays" => [],
        ];
    }
    $history[$fp]["last_ok"] = $now;
    $history[$fp]["ok"] = (int)$history[$fp]["ok"] + 1;
    $history[$fp]["fail_streak"] = 0;
    $history[$fp]["delays"][] = $delayGuess;
    $history[$fp]["delays"] = array_slice($history[$fp]["delays"], -8);
    $ok = max(1, (int)$history[$fp]["ok"]);
    $fail = (int)$history[$fp]["fail"];
    $success = $ok / max(1, $ok + $fail);
    $ageHours = max(0, ($now - (int)$history[$fp]["first_seen"]) / 3600);
    $ageBonus = $ageHours <= 24 ? 25 : ($ageHours <= 72 ? 10 : 0);
    $delayScore = max(0, 100 - $pos);
    $score = (int)round(($success * 50) + $delayScore + $ageBonus);
    $type = detect_type($line);
    $ranked[] = [
        "fp" => $fp,
        "uri" => $line,
        "type" => $type,
        "score" => $score,
        "success" => $success,
        "fail_streak" => 0,
        "age_hours" => $ageHours,
        "rank_index" => $pos,
    ];
}

foreach ($history as $fp => $row) {
    if (isset($seen[$fp])) {
        continue;
    }
    $history[$fp]["fail"] = (int)$row["fail"] + 1;
    $history[$fp]["fail_streak"] = (int)$row["fail_streak"] + 1;
    if ($history[$fp]["fail_streak"] >= 8) {
        unset($history[$fp]);
    }
}

$public = array_values(array_filter($ranked, function ($n) use ($history) {
    return (int)($history[$n["fp"]]["fail_streak"] ?? 0) < 2;
}));
usort($public, function ($a, $b) {
    return $b["score"] <=> $a["score"];
});

function subHeader($title, $count) {
    $expire = time() + (30 * 86400);
    return "#profile-title: base64:" . base64_encode($title) . "\n" .
        "#profile-update-interval: 1\n" .
        "#subscription-userinfo: upload=0; download=0; total=10737418240000000; expire={$expire}\n" .
        "#support-url: https://t.me/sinavm\n" .
        "#profile-web-page-url: https://sinavm.github.io/SVM/\n" .
        "#announce: nodes={$count} updated=" . gmdate("Y-m-d\\TH:i:s\\Z") . "\n\n";
}

function writeSub($name, $title, $nodes) {
    global $ROOT;
    $uris = array_map(fn($n) => $n["uri"], $nodes);
    $body = subHeader($title, count($uris)) . implode("\n", $uris) . (empty($uris) ? "" : "\n");
    file_put_contents($ROOT . "/subscriptions/xray/normal/" . $name, $body);
    file_put_contents($ROOT . "/subscriptions/xray/base64/" . $name, base64_encode($body));
    file_put_contents($ROOT . "/docs/subscriptions/" . $name, $body);
    return count($uris);
}

$fast = array_slice($public, 0, 25);
$stable = array_slice($public, 0, 50);
$mixKeep = $public;

$nFast = writeSub("alive-fast", "SiNAVM FAST", $fast);
$nStable = writeSub("alive-stable", "SiNAVM STABLE", $stable);
$nMix = writeSub("mix", "SiNAVM MIX", $mixKeep);
$nMci = writeSub("mci", "SiNAVM MCI", array_slice($fast, 0, 20));
$nIrancell = writeSub("irancell", "SiNAVM IRANCELL", array_slice($fast, 0, 20));
$nNational = writeSub("national-net", "SiNAVM NATIONAL", array_slice($fast, 0, 15));

$delays = [];
if (!empty($test["fastest"]) && is_array($test["fastest"])) {
    foreach ($test["fastest"] as $row) {
        if (isset($row["delay"])) {
            $delays[] = (int)$row["delay"];
        }
    }
}
$avgDelay = $delays ? (int)round(array_sum($delays) / count($delays)) : null;

$status = [
    "updated_at" => gmdate("c"),
    "tested_at" => $test["tested_at"] ?? null,
    "tested" => (int)($test["input"] ?? count($lines)),
    "alive" => (int)($test["kept"] ?? count($ranked)),
    "public_after_history" => count($public),
    "dropped_flaky" => count($ranked) - count($public),
    "avg_delay_ms_top15" => $avgDelay,
    "alive_fast" => $nFast,
    "alive_stable" => $nStable,
    "mix" => $nMix,
    "mci" => $nMci,
    "irancell" => $nIrancell,
    "national_net" => $nNational,
    "note" => "Operator lists are compact copies of the fastest public nodes. GitHub runners cannot measure MCI/Irancell/national-net from inside Iran.",
    "links" => [
        "pages_fast" => "https://sinavm.github.io/SVM/subscriptions/alive-fast",
        "pages_stable" => "https://sinavm.github.io/SVM/subscriptions/alive-stable",
        "pages_mci" => "https://sinavm.github.io/SVM/subscriptions/mci",
        "pages_irancell" => "https://sinavm.github.io/SVM/subscriptions/irancell",
        "pages_national" => "https://sinavm.github.io/SVM/subscriptions/national-net",
        "raw_fast" => "https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/alive-fast",
        "raw_stable" => "https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/alive-stable",
    ],
];
file_put_contents($REPORTS . "/status.json", json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents($ROOT . "/docs/reports/status.json", json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents($historyFile, json_encode($history));

echo "rank.php public=" . count($public) . " fast={$nFast} stable={$nStable}\n";
