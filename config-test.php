<?php
/**
 * Live-node tester for SiNAVM.
 *
 * 1) Convert each URI to a sing-box 1.14 outbound.
 * 2) Fast concurrent TCP probe (skipped for hy2/tuic).
 * 3) Real protocol delay-test via sing-box clash_api.
 * 4) Rewrite config.txt with ONLY working nodes, fastest first.
 *
 * Mapping is by synthetic ASCII tags (n0001…) so remark mismatches
 * can never keep a dead line or drop a live one.
 */
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

define("SINAVM_SKIP_CONVERT", true);
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/toSingbox.php";

$API_HOST = "127.0.0.1";
$API_PORT = 16790;
$API_URL = "http://{$API_HOST}:{$API_PORT}";
$API_SECRET = "xXxTestxXx";
$TEST_URL = "http://www.gstatic.com/generate_204";
$TIMEOUT_MS = 4500;
$BATCH_SIZE = 24;
$DELAY_CONCURRENCY = 8;
$TCP_CONCURRENCY = 64;
$TCP_TIMEOUT_MS = 1500;
$SINGBOX_VERSION = getenv("SINGBOX_VERSION") ?: "1.14.0";

function testLog($msg)
{
    echo "[" . date("H:i:s") . "] " . $msg . "\n";
}

function findSingboxBinary()
{
    $candidates = [];
    $env = getenv("SINGBOX_BIN");
    if (is_string($env) && $env !== "") {
        $candidates[] = $env;
    }
    $candidates[] = "sing-box";
    $candidates[] = "/usr/local/bin/sing-box";
    $candidates[] = __DIR__ . "/sing-box-bin/sing-box";
    $candidates[] = getcwd() . "/sing-box-bin/sing-box";

    foreach ($candidates as $bin) {
        $resolved = $bin;
        if ($bin === "sing-box") {
            $which = trim((string)shell_exec("command -v sing-box 2>/dev/null"));
            if ($which === "") {
                continue;
            }
            $resolved = $which;
        }
        if (is_file($resolved) && is_executable($resolved)) {
            return $resolved;
        }
    }
    return null;
}

function ensureSingboxBinary($version)
{
    $existing = findSingboxBinary();
    if ($existing) {
        return $existing;
    }

    $dir = __DIR__ . "/sing-box-bin";
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tar = $dir . "/sing-box.tar.gz";
    $url = "https://github.com/SagerNet/sing-box/releases/download/v{$version}/sing-box-{$version}-linux-amd64.tar.gz";
    testLog("Downloading sing-box {$version} …");
    $data = @file_get_contents($url);
    if ($data === false || strlen($data) < 10000) {
        throw new Exception("Failed to download sing-box {$version}");
    }
    file_put_contents($tar, $data);
    $cmd = "tar -xzf " . escapeshellarg($tar) . " -C " . escapeshellarg($dir) . " --strip-components=1";
    shell_exec($cmd . " 2>/dev/null");
    $bin = $dir . "/sing-box";
    if (!is_file($bin)) {
        throw new Exception("sing-box binary missing after extract");
    }
    chmod($bin, 0755);
    return $bin;
}

function apiHeaders()
{
    global $API_SECRET;
    return [
        "Authorization: Bearer {$API_SECRET}",
        "Accept: application/json",
    ];
}

function curlJson($url, $timeoutSec = 3)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    curl_setopt($ch, CURLOPT_HTTPHEADER, apiHeaders());
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($body) || $body === "") {
        return [null, $code];
    }
    $json = json_decode($body, true);
    return [is_array($json) ? $json : null, $code];
}

function waitForApi($seconds = 20)
{
    global $API_URL;
    $deadline = time() + $seconds;
    while (time() < $deadline) {
        [$json, $code] = curlJson($API_URL . "/proxies", 2);
        if ($code === 200 && is_array($json)) {
            return true;
        }
        usleep(250000);
    }
    return false;
}

function stopPid($pid)
{
    $pid = (int)$pid;
    if ($pid <= 1) {
        return;
    }
    @posix_kill($pid, 15);
    usleep(200000);
    if (@posix_kill($pid, 0)) {
        @posix_kill($pid, 9);
    }
}

function killPort($port)
{
    $port = (int)$port;
    $out = trim((string)shell_exec("lsof -ti tcp:{$port} 2>/dev/null"));
    if ($out === "") {
        return;
    }
    foreach (preg_split('/\s+/', $out) as $pid) {
        stopPid($pid);
    }
}

function isUdpProtocol($type)
{
    return in_array($type, ["hy2", "tuic", "hysteria"], true);
}

function extractServerPort($line)
{
    $type = detect_type($line);
    $parsed = configParse($line);
    if (!$type || !$parsed) {
        return [null, null, $type];
    }
    if ($type === "vmess") {
        return [$parsed["add"] ?? null, intval($parsed["port"] ?? 0), $type];
    }
    if ($type === "ss") {
        return [$parsed["server_address"] ?? null, intval($parsed["server_port"] ?? 0), $type];
    }
    return [$parsed["hostname"] ?? null, intval($parsed["port"] ?? 0), $type];
}

function tcpProbeMany($targets, $timeoutMs, $concurrency)
{
    $results = [];
    foreach ($targets as $t) {
        $results[$t["tag"]] = false;
    }
    $chunks = array_chunk($targets, max(1, $concurrency));
    foreach ($chunks as $chunk) {
        $sockets = [];
        $deadline = microtime(true) + ($timeoutMs / 1000);
        foreach ($chunk as $t) {
            $tag = $t["tag"];
            $host = $t["host"];
            $port = (int)$t["port"];
            if ($host === "" || $port < 1) {
                $results[$tag] = false;
                continue;
            }
            $remote = "tcp://" . $host . ":" . $port;
            $errno = 0;
            $errstr = "";
            $sock = @stream_socket_client(
                $remote,
                $errno,
                $errstr,
                0,
                STREAM_CLIENT_ASYNC_CONNECT
            );
            if (!$sock) {
                $results[$tag] = false;
                continue;
            }
            stream_set_blocking($sock, false);
            $sockets[$tag] = $sock;
        }
        while (!empty($sockets) && microtime(true) < $deadline) {
            $read = [];
            $write = $sockets;
            $except = $sockets;
            $left = max(0.05, $deadline - microtime(true));
            $n = @stream_select($read, $write, $except, 0, (int)($left * 1000000));
            if ($n === false) {
                break;
            }
            foreach ($write as $tag => $sock) {
                $peer = @stream_socket_get_name($sock, true);
                $meta = stream_get_meta_data($sock);
                $results[$tag] = ($peer !== false && empty($meta["timed_out"]));
                fclose($sock);
                unset($sockets[$tag]);
            }
            foreach ($except as $tag => $sock) {
                $results[$tag] = false;
                fclose($sock);
                unset($sockets[$tag]);
            }
        }
        foreach ($sockets as $tag => $sock) {
            $results[$tag] = false;
            fclose($sock);
        }
    }
    return $results;
}

function buildTesterConfig($outbounds)
{
    global $API_HOST, $API_PORT, $API_SECRET;
    $all = array_merge(
        [
            [
                "type" => "direct",
                "tag" => "direct",
            ],
        ],
        $outbounds
    );
    return [
        "log" => [
            "level" => "error",
            "timestamp" => true,
        ],
        "dns" => [
            "servers" => [
                [
                    "type" => "udp",
                    "tag" => "dns_direct",
                    "server" => "8.8.8.8",
                ],
            ],
            "final" => "dns_direct",
            "strategy" => "ipv4_only",
        ],
        "inbounds" => [
            [
                "type" => "mixed",
                "tag" => "mixed-in",
                "listen" => "127.0.0.1",
                "listen_port" => 16756,
            ],
        ],
        "outbounds" => $all,
        "route" => [
            "rules" => [
                ["action" => "sniff"],
            ],
            "final" => "direct",
            "auto_detect_interface" => true,
            "default_domain_resolver" => "dns_direct",
        ],
        "experimental" => [
            "clash_api" => [
                "external_controller" => "{$API_HOST}:{$API_PORT}",
                "secret" => $API_SECRET,
            ],
        ],
    ];
}

function startSingbox($bin, $configPath)
{
    global $API_PORT;
    killPort($API_PORT);
    killPort(16756);
    $log = getcwd() . "/reports/singbox-test.log";
    @mkdir(dirname($log), 0777, true);
    $cmd = escapeshellarg($bin) . " run -c " . escapeshellarg($configPath) . " > " . escapeshellarg($log) . " 2>&1 & echo $!";
    $pid = (int)shell_exec($cmd);
    if ($pid <= 0) {
        throw new Exception("Failed to spawn sing-box");
    }
    return $pid;
}

function delayBatch($tags)
{
    global $API_URL, $TIMEOUT_MS, $TEST_URL;
    $mh = curl_multi_init();
    $handles = [];
    foreach ($tags as $tag) {
        $ch = curl_init();
        $url = $API_URL . "/proxies/" . rawurlencode($tag) . "/delay?timeout=" . $TIMEOUT_MS . "&url=" . rawurlencode($TEST_URL);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $TIMEOUT_MS + 1200);
        curl_setopt($ch, CURLOPT_HTTPHEADER, apiHeaders());
        curl_multi_add_handle($mh, $ch);
        $handles[$tag] = $ch;
    }
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.2);
    } while ($running);

    $out = [];
    foreach ($handles as $tag => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $delay = 0;
        if ($code === 200 && $body) {
            $decoded = json_decode($body, true);
            if (isset($decoded["delay"]) && is_numeric($decoded["delay"])) {
                $delay = (int)$decoded["delay"];
            }
        }
        $out[$tag] = $delay;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

function protocolTestBatch($bin, $items)
{
    global $TIMEOUT_MS;
    $outbounds = [];
    $tags = [];
    foreach ($items as $item) {
        $ob = $item["outbound"];
        $ob["tag"] = $item["tag"];
        $outbounds[] = $ob;
        $tags[] = $item["tag"];
    }
    $cfg = buildTesterConfig($outbounds);
    $path = getcwd() . "/current-config.json";
    file_put_contents($path, json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $check = [];
    exec(escapeshellarg($bin) . " check -c " . escapeshellarg($path) . " 2>&1", $check, $code);
    if ($code !== 0) {
        testLog("sing-box check failed for batch of " . count($items) . ", falling back to singles");
        $results = [];
        if (count($items) === 1) {
            $results[$items[0]["tag"]] = 0;
            return $results;
        }
        foreach ($items as $one) {
            $part = protocolTestBatch($bin, [$one]);
            $results = array_merge($results, $part);
        }
        return $results;
    }

    $pid = 0;
    try {
        $pid = startSingbox($bin, $path);
        if (!waitForApi(25)) {
            throw new Exception("clash_api not ready");
        }
        $results = [];
        $chunks = array_chunk($tags, 8);
        foreach ($chunks as $chunk) {
            $part = delayBatch($chunk);
            foreach ($part as $tag => $delay) {
                $results[$tag] = ($delay > 0 && $delay < $TIMEOUT_MS) ? $delay : 0;
            }
        }
        return $results;
    } finally {
        if ($pid) {
            stopPid($pid);
        }
        killPort(16790);
        killPort(16756);
        usleep(150000);
    }
}

function writeReport($report)
{
    @mkdir("reports", 0777, true);
    file_put_contents("reports/test.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function writeAliveConfigs($alive)
{
    usort($alive, function ($a, $b) {
        return $a["delay"] <=> $b["delay"];
    });
    $counter = [];
    $lines = [];
    foreach ($alive as $item) {
        $type = $item["type"] ?: "unknown";
        if (!isset($counter[$type])) {
            $counter[$type] = 0;
        }
        $counter[$type]++;
        $lines[] = setConfigName($item["uri"], buildStandardName($type, $counter[$type]));
    }
    file_put_contents("config.txt", implode("\n", $lines) . (empty($lines) ? "" : "\n"));
    return $counter;
}

function main()
{
    global $BATCH_SIZE, $TCP_TIMEOUT_MS, $TCP_CONCURRENCY, $TIMEOUT_MS, $SINGBOX_VERSION, $TEST_URL;

    testLog("Running live config-test (sing-box clash_api)…");
    $configFile = getcwd() . "/config.txt";
    if (!is_file($configFile)) {
        throw new Exception("config.txt not found in " . getcwd());
    }

    $rawLines = file($configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($rawLines === false) {
        throw new Exception("Unable to read config.txt");
    }

    $nodes = [];
    $index = 0;
    $convertFail = 0;
    foreach ($rawLines as $line) {
        $line = trim($line);
        if ($line === "") {
            continue;
        }
        $index++;
        $tag = sprintf("n%04d", $index);
        $type = detect_type($line);
        [$host, $port, $type2] = extractServerPort($line);
        $outbound = toSingbox($line);
        if (!$outbound) {
            $convertFail++;
            continue;
        }
        $nodes[] = [
            "tag" => $tag,
            "uri" => $line,
            "type" => $type ?: $type2,
            "host" => $host,
            "port" => $port,
            "outbound" => $outbound,
            "delay" => 0,
        ];
    }

    $report = [
        "engine" => "sing-box-" . $SINGBOX_VERSION,
        "tested_at" => date("c"),
        "test_url" => $TEST_URL,
        "timeout_ms" => $TIMEOUT_MS,
        "input" => count($rawLines),
        "converted" => count($nodes),
        "convert_failed" => $convertFail,
        "tcp_alive" => 0,
        "tcp_dead" => 0,
        "tcp_skipped_udp" => 0,
        "protocol_alive" => 0,
        "protocol_dead" => 0,
        "kept" => 0,
        "dropped" => 0,
        "by_type_kept" => [],
        "fastest" => [],
        "error" => null,
        "infra_ok" => false,
    ];

    if (empty($nodes)) {
        $report["error"] = "no convertible nodes";
        writeReport($report);
        testLog("No convertible nodes. Keeping original config.txt.");
        return;
    }

    $bin = ensureSingboxBinary($SINGBOX_VERSION);
    testLog("Using binary: {$bin}");
    $verLine = trim((string)shell_exec(escapeshellarg($bin) . " version 2>/dev/null | head -n 1"));
    testLog($verLine !== "" ? $verLine : "sing-box version unknown");

    $tcpTargets = [];
    $udpNodes = [];
    $tcpDead = [];
    foreach ($nodes as $n) {
        if (isUdpProtocol($n["type"])) {
            $udpNodes[] = $n;
            $report["tcp_skipped_udp"]++;
            continue;
        }
        $tcpTargets[] = $n;
    }

    testLog("TCP probe: " . count($tcpTargets) . " nodes (concurrency {$TCP_CONCURRENCY}, {$TCP_TIMEOUT_MS}ms)");
    $tcpMap = tcpProbeMany($tcpTargets, $TCP_TIMEOUT_MS, $TCP_CONCURRENCY);
    $tcpAlive = [];
    foreach ($tcpTargets as $n) {
        if (!empty($tcpMap[$n["tag"]])) {
            $tcpAlive[] = $n;
        } else {
            $tcpDead[] = $n;
        }
    }
    $report["tcp_alive"] = count($tcpAlive);
    $report["tcp_dead"] = count($tcpDead);
    testLog("TCP alive=" . count($tcpAlive) . " dead=" . count($tcpDead) . " udp=" . count($udpNodes));

    $candidates = array_merge($tcpAlive, $udpNodes);
    $alive = [];
    $protocolDead = 0;
    $infraOk = false;

    $batches = array_chunk($candidates, $BATCH_SIZE);
    $bNum = 0;
    foreach ($batches as $batch) {
        $bNum++;
        testLog("Protocol batch {$bNum}/" . count($batches) . " (" . count($batch) . " nodes)");
        try {
            $delays = protocolTestBatch($bin, $batch);
            $infraOk = true;
            foreach ($batch as $n) {
                $d = isset($delays[$n["tag"]]) ? (int)$delays[$n["tag"]] : 0;
                if ($d > 0 && $d < $TIMEOUT_MS) {
                    $n["delay"] = $d;
                    $alive[] = $n;
                } else {
                    $protocolDead++;
                }
            }
        } catch (Exception $e) {
            testLog("Batch {$bNum} failed: " . $e->getMessage());
            if (count($batch) > 1) {
                foreach (array_chunk($batch, 1) as $one) {
                    try {
                        $delays = protocolTestBatch($bin, $one);
                        $infraOk = true;
                        $n = $one[0];
                        $d = isset($delays[$n["tag"]]) ? (int)$delays[$n["tag"]] : 0;
                        if ($d > 0 && $d < $TIMEOUT_MS) {
                            $n["delay"] = $d;
                            $alive[] = $n;
                        } else {
                            $protocolDead++;
                        }
                    } catch (Exception $e2) {
                        $protocolDead++;
                    }
                }
            } else {
                $protocolDead++;
            }
        }
    }

    $report["infra_ok"] = $infraOk;
    $report["protocol_alive"] = count($alive);
    $report["protocol_dead"] = $protocolDead;

    if (!$infraOk && empty($alive)) {
        $report["error"] = "sing-box clash_api never became ready; original config.txt kept";
        $report["dropped"] = 0;
        $report["kept"] = count($rawLines);
        writeReport($report);
        testLog("Tester infrastructure failed. Keeping original config.txt.");
        return;
    }

    $byType = writeAliveConfigs($alive);
    $report["by_type_kept"] = $byType;
    $report["kept"] = count($alive);
    $report["dropped"] = count($rawLines) - count($alive);

    $fastest = $alive;
    usort($fastest, function ($a, $b) {
        return $a["delay"] <=> $b["delay"];
    });
    $report["fastest"] = array_map(function ($n) {
        return [
            "tag" => $n["tag"],
            "type" => $n["type"],
            "delay" => $n["delay"],
            "host" => $n["host"],
        ];
    }, array_slice($fastest, 0, 15));

    writeReport($report);
    testLog("Kept " . count($alive) . " live / dropped " . $report["dropped"] . " dead.");
    testLog("Testing Configs Done!");
}

echo "Running The Config-Test Script...\n";
register_shutdown_function(function () {
    killPort(16790);
    killPort(16756);
});
try {
    main();
} catch (Exception $e) {
    echo "An error occurred: " . $e->getMessage() . "\n";
    @mkdir("reports", 0777, true);
    $existing = [];
    if (is_file("reports/test.json")) {
        $existing = json_decode(file_get_contents("reports/test.json"), true) ?: [];
    }
    $existing["error"] = $e->getMessage();
    $existing["infra_ok"] = false;
    $existing["tested_at"] = date("c");
    file_put_contents("reports/test.json", json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Keeping original config.txt so the pipeline can continue.\n";
}
