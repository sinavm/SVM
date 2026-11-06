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

    @mkdir("subscriptions/singbox", 0777, true);

    foreach ($directoryOfFiles as $directory) {
        $profileName = explode("/", $directory)[3];
        $outputPath = "subscriptions/singbox/" . $profileName . ".json";

        // Remove stale profiles when the corresponding source disappears.
        if (!is_file($directory)) {
            if (is_file($outputPath)) {
                unlink($outputPath);
            }
            continue;
        }

        $configsName = "@SiNAVM | " . $profileName;
        $configsData = file_get_contents($directory);
        $convertionResult = processConvertion($configsData, $configsName);

        // Never publish an empty selector/urltest profile: sing-box rejects it.
        if (!is_string($convertionResult) || trim($convertionResult) === "") {
            if (is_file($outputPath)) {
                unlink($outputPath);
            }
            echo "Skipped empty Sing-box profile: {$profileName}\\n";
            continue;
        }

        if (file_put_contents($outputPath, $convertionResult) === false) {
            throw new RuntimeException("Unable to write Sing-box profile: {$outputPath}");
        }
    }

    echo "Conversion to Sing-box completed successfully!\n";
}
