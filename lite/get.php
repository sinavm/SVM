<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

require "functions.php";

$sourcesArray = json_decode(
    file_get_contents("channels.json"),
    true
);

$sublinksArray = ["sublinks" => []];
if (file_exists("sublinks.json")) {
    $sublinksJson = file_get_contents("sublinks.json");
    $sublinksJson = preg_replace_callback('/__PRIVATE_LINK_SiNAVM_(\d+)__/', function ($m) {
        $n = $m[1];
        foreach ([getenv("PRIVATE_LINK_SiNAVM_" . $n), getenv("PRIVATE_LINK_SINAVM_" . $n)] as $val) {
            if (is_string($val) && trim($val) !== '') {
                return trim($val);
            }
        }
        return '';
    }, $sublinksJson);

    $decoded = json_decode($sublinksJson, true);
    if (is_array($decoded) && isset($decoded["sublinks"]) && is_array($decoded["sublinks"])) {
        $sublinksArray = $decoded;
    }
}

$totalSources = count($sourcesArray) + count($sublinksArray["sublinks"]);
$tempCounter = 1;

$configsList = [];
echo "Fetching Configs\n";

foreach ($sourcesArray as $source => $types) {
    $percentage = ($tempCounter / $totalSources) * 100;
    echo "\rProgress: [";
    echo str_repeat("=", $tempCounter);
    echo str_repeat(" ", $totalSources - $tempCounter);
    echo "] $percentage%";
    $tempCounter++;

    $tempData = file_get_contents("https://t.me/s/" . $source);
    $type = implode("|", $types);
    $tempExtract = extractLinksByType($tempData, $type);
    if (!is_null($tempExtract)) {
        $configsList[$source] = $tempExtract;
    }
}

foreach ($sublinksArray["sublinks"] as $sublink) {
    $percentage = ($tempCounter / $totalSources) * 100;
    echo "\rProgress: [";
    echo str_repeat("=", floor($percentage / (100 / $totalSources)));
    echo str_repeat(" ", $totalSources - floor($percentage / (100 / $totalSources)));
    echo "] " . number_format($percentage, 2) . "%";
    $tempCounter++;

    $url = preg_replace('/#.*$/', '', trim((string)($sublink["url"] ?? "")));
    $protocols = expandProtocolPattern($sublink["protocols"] ?? []);

    if ($url === '' || strpos($url, '__PRIVATE_LINK_') !== false || $protocols === '') {
        continue;
    }

    try {
        $response = file_get_contents($url);
        if ($response === false) {
            continue;
        }
        $response = decodeMaybeBase64($response);

        $sublink_configs = array_filter(explode("\n", $response), function($config) use ($protocols) {
            $config = trim($config);
            return $config !== "" && preg_match("/^($protocols):\/\//i", $config);
        });

        if (!empty($sublink_configs)) {
            $configsList[$url] = $sublink_configs;
        }
    } catch (Exception $e) {
        echo "\nError fetching sublink\n";
    }
}

$finalOutput = [];
$locationBased = [];
$needleArray = ["amp%3B"];
$replaceArray = [""];

$configsHash = [
    "vmess" => "ps",
    "vless" => "hash",
    "trojan" => "hash",
    "tuic" => "hash",
    "hy2" => "hash",
    "ss" => "name",
];
$configsIp = [
    "vmess" => "add",
    "vless" => "hostname",
    "trojan" => "hostname",
    "tuic" => "hostname",
    "hy2" => "hostname",
    "ss" => "server_address",
];

echo "\nProcessing Configs\n";
$totalSources = count($configsList);
$tempSource = 1;

foreach ($configsList as $source => $configs) {
    $totalConfigs = count($configs);
    $tempCounter = 1;
    echo "\n" . strval($tempSource) . "/" . strval($totalSources) . "\n";

    $limitKey = count($configs) - 2;
    foreach (array_reverse($configs) as $key => $config) {
        $percentage = ($tempCounter / $totalConfigs) * 100;
        echo "\rProgress: [";
        echo str_repeat("=", $tempCounter);
        echo str_repeat(" ", $totalConfigs - $tempCounter);
        echo "] $percentage%";
        $tempCounter++;

        if (is_valid($config) && $key >= $limitKey) {
            $type = detect_type($config);
            $configHash = $configsHash[$type];
            $configIp = $configsIp[$type];
            $decodedConfig = configParse(explode("<", $config)[0]);
            $configLocation =
                ip_info($decodedConfig[$configIp])->country ?? "XX";
            $configFlag =
                $configLocation === "XX" ? "❔" : ($configLocation === "CF" ? "🚩" : getFlags($configLocation));
            $isEncrypted =
                isEncrypted($config) ? "🟢" : "🔴";
            $decodedConfig[$configHash] =
                $configFlag .
                $configLocation .
                " | " .
                $isEncrypted .
                " | " .
                $type .
                " | @" .
                $source .
                " | " .
                strval($key);
            $encodedConfig = reparseConfig($decodedConfig, $type);
            if (substr($encodedConfig, 0, 10) !== "ss://Og==@") {
                $finalOutput[] = str_replace(
                    $needleArray,
                    $replaceArray,
                    $encodedConfig
                );
                $locationBased[$configLocation][] = str_replace(
                    $needleArray,
                    $replaceArray,
                    $encodedConfig
                );
            }
        }
    }
    $tempSource++;
}
deleteFolder("subscriptions/location/normal");
deleteFolder("subscriptions/location/base64");
mkdir("subscriptions/location/normal");
mkdir("subscriptions/location/base64");

foreach ($locationBased as $location => $configs) {
    $tempConfig = urldecode(implode("\n", $configs));
    $base64TempConfig = base64_encode($tempConfig);
    file_put_contents(
        "subscriptions/location/normal/" . $location,
        $tempConfig
    );
    file_put_contents(
        "subscriptions/location/base64/" . $location,
        $base64TempConfig
    );
}

file_put_contents("config.txt", implode("\n", $finalOutput));

echo "\nGetting Configs Done!\n";
