<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

require "functions.php";

@mkdir("subscriptions/xray/normal", 0777, true);
@mkdir("subscriptions/xray/base64", 0777, true);

$configsArray = explode("\n", file_get_contents("config.txt"));
$sortArray = [
    "mix" => [],
    "vmess" => [],
    "vless" => [],
    "reality" => [],
    "trojan" => [],
    "ss" => [],
    "tuic" => [],
    "hy2" => [],
];

foreach ($configsArray as $config) {
    $config = trim($config);
    if ($config === "") {
        continue;
    }
    $decoded = urldecode($config);
    $configType = detect_type($config);
    if (!$configType) {
        continue;
    }
    $sortArray["mix"][] = $decoded;
    $sortArray[$configType][] = $decoded;
    if ($configType === "vless" && is_reality($config)) {
        $sortArray["reality"][] = $decoded;
    }
}

foreach ($sortArray as $type => $sort) {
    $tempConfigs = hiddifyHeader("SiNAVM | " . strtoupper($type)) . implode("\n", $sort);
    file_put_contents("subscriptions/xray/normal/" . $type, $tempConfigs);
    file_put_contents("subscriptions/xray/base64/" . $type, base64_encode($tempConfigs));
}

echo "Sorting Done!";
