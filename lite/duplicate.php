<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

require "functions.php";

$configsArray = explode("\n", file_get_contents("config.txt"));

$seen = [];
$finalOutput = [];
$dropped = 0;

foreach ($configsArray as $config) {
    $config = trim($config);
    if ($config === '') {
        continue;
    }

    $fp = configFingerprint($config);
    if ($fp === null) {
        // keep parseable-looking unique raw lines as a fallback
        $fp = 'raw:' . sha1($config);
    }

    if (isset($seen[$fp])) {
        $dropped++;
        continue;
    }

    $seen[$fp] = true;
    $finalOutput[] = $config;
}

file_put_contents("config.txt", implode("\n", $finalOutput));

$tempConfig = hiddifyHeader("@SiNAVM |lite |MIX") . urldecode(implode("\n", $finalOutput));
$base64TempConfig = base64_encode($tempConfig);

@mkdir("subscriptions/xray/normal", 0777, true);
@mkdir("subscriptions/xray/base64", 0777, true);

file_put_contents("subscriptions/xray/normal/mix", $tempConfig);
file_put_contents("subscriptions/xray/base64/mix", $base64TempConfig);

echo "Removing Duplicates Done! Kept: " . count($finalOutput) . " Dropped: {$dropped}\n";
