<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

require_once "functions.php";
require_once __DIR__ . "/toSingbox.part1.php";
require_once __DIR__ . "/toSingbox.part2.php";

if (!defined("SINAVM_SKIP_CONVERT") || !SINAVM_SKIP_CONVERT) {
    $directoryOfFiles = [
        "subscriptions/xray/base64/mix",
        "subscriptions/xray/base64/vmess",
        "subscriptions/xray/base64/vless",
        "subscriptions/xray/base64/reality",
        "subscriptions/xray/base64/tuic",
        "subscriptions/xray/base64/hy2",
        "subscriptions/xray/base64/ss",
        "subscriptions/xray/base64/trojan"
    ];

    foreach ($directoryOfFiles as $directory) {
        if (!is_file($directory)) {
            continue;
        }
        $configsName = "@SiNAVM | " . explode("/", $directory)[3];
        $configsData = file_get_contents($directory);
        $convertionResult = processConvertion($configsData, $configsName);
        @mkdir("subscriptions/singbox", 0777, true);
        file_put_contents("subscriptions/singbox/" . explode("/", $directory)[3] . ".json", $convertionResult);
    }

    echo "Conversion to Sing-box completed successfully!\n";
}
