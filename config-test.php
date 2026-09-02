<?php
$API_URL = "http://127.0.0.1:6756";
$BEARER_TOKEN = "xXxTestxXx";
$TEST_URL = "http://cp.cloudflare.com";
$TIMEOUT = 1500;
$BATCH_SIZE = 10;

function runHiddify()
{
    $hiddifyDir = __DIR__ . '/hiddify-cli';
    if (!is_dir($hiddifyDir)) {
        mkdir($hiddifyDir, 0755, true);
    }
    $cwd = getcwd();
    chdir($hiddifyDir);

    if (!is_file('HiddifyCli')) {
        $downloadUrl = 'https://github.com/hiddify/hiddify-core/releases/download/v1.3.6/hiddify-cli-linux-amd64.tar.gz';
        $downloadedFile = 'hiddify-cli.tar.gz';
        $data = @file_get_contents($downloadUrl);
        if ($data === false) {
            chdir($cwd);
            throw new Exception("Failed to download HiddifyCli");
        }
        file_put_contents($downloadedFile, $data);
        shell_exec("tar -zxvf " . escapeshellarg($downloadedFile) . " 2>/dev/null");
        if (is_file('HiddifyCli')) {
            chmod('HiddifyCli', 0755);
        }
    }

    if (!is_file('HiddifyCli')) {
        chdir($cwd);
        throw new Exception("HiddifyCli binary not found");
    }

    $configPath = escapeshellarg($cwd . '/config.txt');
    $hiddifyConfigPath = escapeshellarg($cwd . '/hiddify-conf.json');
    $command = "./HiddifyCli run -c {$configPath} --hiddify {$hiddifyConfigPath} > /dev/null 2>&1 & echo $!";
    $pid = (int)shell_exec($command);
    file_put_contents('hiddify.pid', $pid);
    chdir($cwd);

    echo "Hiddify started in background with PID: $pid\n";
}

function stopHiddify()
{
    $pidFile = 'hiddify-cli/hiddify.pid';
    if (file_exists($pidFile)) {
        $pid = (int)file_get_contents($pidFile);
        if ($pid) {
            @posix_kill($pid, 9);
            echo "Hiddify process (PID: $pid) stopped.\n";
        }
        @unlink($pidFile);
    }
}

function get_custom_headers()
{
    global $BEARER_TOKEN;
    return ["Authorization: Bearer $BEARER_TOKEN"];
}

function waitForApi($seconds = 60)
{
    global $API_URL;
    for ($i = 0; $i < $seconds; $i++) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$API_URL/proxies");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, get_custom_headers());
        $response = curl_exec($ch);
        $httpcode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpcode === 200 && $response) {
            return true;
        }
        sleep(1);
    }
    return false;
}

function get_proxies()
{
    global $API_URL;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "$API_URL/proxies");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, get_custom_headers());
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpcode === 200) {
        $decoded = json_decode($response, true);
        return isset($decoded["proxies"]) && is_array($decoded["proxies"]) ? $decoded["proxies"] : [];
    }
    throw new Exception("Failed to get proxies: HTTP $httpcode");
}

function get_real_delay_batch($proxy_names)
{
    global $API_URL, $TIMEOUT, $TEST_URL;

    $multiHandle = curl_multi_init();
    $curlHandles = [];

    foreach ($proxy_names as $proxy_name) {
        $ch = curl_init();
        $encoded_proxy_name = rawurlencode($proxy_name);
        curl_setopt($ch, CURLOPT_URL, "$API_URL/proxies/$encoded_proxy_name/delay?timeout=$TIMEOUT&url=" . rawurlencode($TEST_URL));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $TIMEOUT + 500);
        curl_setopt($ch, CURLOPT_HTTPHEADER, get_custom_headers());
        curl_multi_add_handle($multiHandle, $ch);
        $curlHandles[$proxy_name] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        curl_multi_select($multiHandle, 0.2);
    } while ($running);

    $results = [];
    foreach ($curlHandles as $proxy_name => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $delay = $TIMEOUT;
        if ($httpcode === 200 && $response) {
            $decoded = json_decode($response, true);
            if (isset($decoded["delay"]) && is_numeric($decoded["delay"])) {
                $delay = (int)$decoded["delay"];
            }
        }
        $results[$proxy_name] = $delay;
        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }

    curl_multi_close($multiHandle);
    return $results;
}

function update_delay_info($proxies)
{
    global $BATCH_SIZE;
    $proxy_names = array_keys($proxies);
    $batches = array_chunk($proxy_names, $BATCH_SIZE);
    foreach ($batches as $batch) {
        $delays = get_real_delay_batch($batch);
        foreach ($delays as $proxy_name => $delay) {
            $proxies[$proxy_name]["delay_single"] = $delay;
        }
    }
    return $proxies;
}

function filter_single_working_proxies($proxies)
{
    global $TIMEOUT;
    $allowed = ["VLESS", "Trojan", "Shadowsocks", "VMess", "TUIC", "Hysteria2", "Hysteria", "WireGuard"];
    $working_proxies = array_filter($proxies, function ($proxy_data) use ($TIMEOUT, $allowed) {
        $type = $proxy_data["type"] ?? "";
        return in_array($type, $allowed, true) &&
            isset($proxy_data["delay_single"]) &&
            $proxy_data["delay_single"] > 0 &&
            $proxy_data["delay_single"] < $TIMEOUT;
    });

    usort($working_proxies, function ($a, $b) {
        return $a["delay_single"] - $b["delay_single"];
    });

    return $working_proxies;
}

function collectProxyNames($working_proxies)
{
    $names = [];
    foreach ($working_proxies as $item) {
        foreach (["name", "tag"] as $field) {
            if (isset($item[$field]) && is_string($item[$field]) && $item[$field] !== "") {
                $names[] = $item[$field];
            }
        }
        foreach (["tags", "labels"] as $field) {
            if (isset($item[$field]) && is_array($item[$field])) {
                foreach ($item[$field] as $t) {
                    if (is_string($t) && $t !== "") {
                        $names[] = $t;
                    }
                }
            }
        }
    }
    return array_values(array_unique($names));
}

function nameVariants($name)
{
    $variants = [];
    $raw = trim((string)$name);
    if ($raw === "") return $variants;

    $decoded = urldecode(str_replace("+", " ", $raw));
    $decoded = trim($decoded);
    $variants[] = $raw;
    $variants[] = $decoded;
    $variants[] = rawurlencode($decoded);
    $variants[] = str_replace(" ", "%20", $decoded);
    $variants[] = str_replace(" | ", "|", $decoded);
    $variants[] = str_replace("|", " | ", $decoded);

    if (preg_match('/#\d+/', $decoded, $m)) {
        $variants[] = $m[0];
    }
    return array_values(array_unique(array_filter($variants, function ($v) {
        return $v !== "";
    })));
}

function filterConfigs($names, $configFile)
{
    if (!file_exists($configFile)) {
        echo "\nConfig file not found: {$configFile}\n";
        return;
    }

    $configs = file($configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($configs === false) {
        echo "\nUnable to read config file: {$configFile}\n";
        return;
    }

    $searchPatterns = [];
    foreach ($names as $name) {
        foreach (nameVariants($name) as $variant) {
            $searchPatterns[] = $variant;
        }
    }
    $searchPatterns = array_values(array_unique($searchPatterns));

    if (empty($searchPatterns)) {
        echo "\nNo search keys available. Config file not written.\n";
        return;
    }

    $filteredConfigs = [];
    foreach ($configs as $configLine) {
        $line = trim($configLine);
        if ($line === "") continue;
        $decodedLine = urldecode($line);
        foreach ($searchPatterns as $pattern) {
            if (strpos($line, $pattern) !== false || strpos($decodedLine, $pattern) !== false) {
                $filteredConfigs[] = $line;
                break;
            }
        }
    }

    $filteredConfigs = array_values(array_unique($filteredConfigs));

    if (!empty($filteredConfigs)) {
        file_put_contents("config.txt", implode("\n", $filteredConfigs) . "\n");
        echo "\nConfig file written successfully. " . count($filteredConfigs) . " working lines.\n";
    } else {
        echo "\nNo matching working configurations found. Keeping original config.txt.\n";
    }
}

function main()
{
    try {
        runHiddify();
        if (!waitForApi(60)) {
            throw new Exception("Hiddify Clash API did not become ready on :6756");
        }

        $proxies = get_proxies();
        if (empty($proxies)) {
            echo "No proxies reported by API. Keeping original config.txt.\n";
            return;
        }

        $proxies = update_delay_info($proxies);
        $working_proxies = filter_single_working_proxies($proxies);
        $names = collectProxyNames($working_proxies);

        echo "Working proxies: " . count($working_proxies) . "\n";
        filterConfigs($names, "config.txt");
        echo "\nTesting Configs Done!\n";
    } catch (Exception $e) {
        echo "An error occurred: " . $e->getMessage() . "\n";
        echo "Keeping original config.txt so the pipeline can continue.\n";
    }
}

echo "Running The Config-Test Script...\n";
register_shutdown_function("stopHiddify");
main();
