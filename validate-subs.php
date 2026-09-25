<?php
require_once __DIR__ . "/functions.php";
$dir = getcwd() . "/subscriptions/xray/normal";
$files = ["mix", "alive-fast", "alive-stable", "vless", "vmess", "ss", "trojan"];
$errors = 0;
foreach ($files as $name) {
    $path = $dir . "/" . $name;
    if (!is_file($path)) {
        echo "missing {$name}\n";
        continue;
    }
    $ok = 0;
    $bad = 0;
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === "" || $line[0] === "#") {
            continue;
        }
        if (detect_type($line) && configParse($line)) {
            $ok++;
        } else {
            $bad++;
        }
    }
    echo "{$name}: ok={$ok} bad={$bad}\n";
    if ($bad > 0) {
        $errors++;
    }
}
exit($errors > 0 ? 1 : 0);
