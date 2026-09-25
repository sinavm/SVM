<?php
ini_set("display_errors", 1);
error_reporting(E_ERROR | E_PARSE);

$src = "subscriptions/xray/normal/mix";
if (!is_file($src)) {
    fwrite(STDERR, "mix not found\n");
    exit(0);
}

$lines = file($src, FILE_IGNORE_NEW_LINES);
$header = [];
$nodes = [];
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === "") {
        continue;
    }
    if ($line[0] === "#" || str_starts_with($line, "//")) {
        $header[] = $line;
        continue;
    }
    if (!in_array($line, $nodes, true)) {
        $nodes[] = $line;
    }
}

@mkdir("subscriptions/xray/normal", 0777, true);
@mkdir("subscriptions/xray/base64", 0777, true);

function write_list(string $name, array $header, array $nodes, int $limit): int {
    $slice = array_slice($nodes, 0, $limit);
    $body = implode("\n", array_merge($header, $slice));
    file_put_contents("subscriptions/xray/normal/" . $name, $body . "\n");
    file_put_contents("subscriptions/xray/base64/" . $name, base64_encode($body));
    return count($slice);
}

$fast = write_list("alive-fast", $header, $nodes, 25);
$stable = write_list("alive-stable", $header, $nodes, 50);

$reportDir = "reports";
@mkdir($reportDir, 0777, true);
$status = [
    "updated_at" => gmdate("c"),
    "timezone_note" => "UTC",
    "source" => "subscriptions/xray/normal/mix",
    "total_unique_nodes" => count($nodes),
    "alive_fast" => $fast,
    "alive_stable" => $stable,
    "links" => [
        "pages_fast" => "https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/alive-fast",
        "pages_stable" => "https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/alive-stable",
        "raw_mix" => "https://raw.githubusercontent.com/sinavm/SVM/main/subscriptions/xray/normal/mix",
    ],
];
file_put_contents($reportDir . "/status.json", json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "compact lists written: fast={$fast} stable={$stable} total=" . count($nodes) . PHP_EOL;
