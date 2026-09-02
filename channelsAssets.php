<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

require "functions.php";

$sourcesArray = json_decode(file_get_contents("channels.json"), true);
if (!is_array($sourcesArray)) {
    $sourcesArray = [];
}

deleteFolder("channelsData/logos");
deleteFolder("channelsData");
@mkdir("channelsData", 0777, true);
@mkdir("channelsData/logos", 0777, true);

$channelArray = [];
$context = stream_context_create([
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: Mozilla/5.0 (compatible; SiNAVM/1.0)\r\n",
        "timeout" => 20,
        "follow_location" => 1,
    ],
]);

function extractMeta($html, $patterns) {
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $m) && !empty($m[1])) {
            return html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, "UTF-8");
        }
    }
    return "";
}

foreach ($sourcesArray as $source => $types) {
    $source = trim((string)$source);
    if ($source === '') {
        continue;
    }

    $html = false;
    $cacheFile = "cache/telegram/" . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $source) . ".html";
    if (is_file($cacheFile) && filesize($cacheFile) > 50) {
        $html = file_get_contents($cacheFile);
    }
    if ($html === false || $html === '') {
        $html = @file_get_contents("https://t.me/s/" . rawurlencode($source), false, $context);
    }
    if ($html === false || $html === '') {
        echo "skip {$source}: page fetch failed\n";
        $channelArray[$source] = [
            "types" => $types,
            "title" => $source,
            "logo" => "",
        ];
        continue;
    }

    $title = extractMeta($html, [
        '#<meta property="twitter:title" content="(.*?)">#',
        '#<meta property="og:title" content="(.*?)">#',
        '#<title>(.*?)</title>#is',
    ]);
    if ($title === '') {
        $title = $source;
    }

    $imageUrl = extractMeta($html, [
        '#<meta property="twitter:image" content="(.*?)">#',
        '#<meta property="og:image" content="(.*?)">#',
        '#<meta name="twitter:image" content="(.*?)">#',
    ]);

    $logoPath = "";
    if ($imageUrl !== '' && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
        $imageBin = @file_get_contents($imageUrl, false, $context);
        if ($imageBin !== false && strlen($imageBin) > 32) {
            $logoFile = "channelsData/logos/" . $source . ".jpg";
            file_put_contents($logoFile, $imageBin);
            $logoPath = "https://raw.githubusercontent.com/sinavm/SVM/main/channelsData/logos/" . $source . ".jpg";
        } else {
            echo "skip {$source}: logo download failed\n";
        }
    } else {
        echo "skip {$source}: no logo meta\n";
    }

    $channelArray[$source] = [
        "types" => $types,
        "title" => $title,
        "logo" => $logoPath,
    ];
}

file_put_contents(
    "channelsData/channelsAssets.json",
    json_encode($channelArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "channelsAssets done. channels: " . count($channelArray) . "\n";
