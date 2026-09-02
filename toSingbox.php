<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

require "functions.php";

function processWsPath($input)
{
    if (empty($input)) return ["path" => "/", "max_early_data" => 0];
    if (strpos($input, "/") === 0) $input = substr($input, 1);
    $max_early_data = 0;
    $path = $input;
    if (strpos($input, "?ed=") !== false) {
        $parts = explode("?ed=", $input);
        $path = $parts[0];
        $max_early_data = intval($parts[1] ?? 0);
    }
    return ["path" => "/" . $path, "max_early_data" => $max_early_data];
}

function sanitizeUtlsFingerprint($raw)
{
    if (is_array($raw)) {
        $raw = reset($raw);
    }
    $fp = strtolower(trim(urldecode((string)$raw)));
    $fp = str_replace([" ", "_"], ["", "-"], $fp);
    $allowed = [
        "chrome" => "chrome",
        "firefox" => "firefox",
        "edge" => "edge",
        "safari" => "safari",
        "360" => "360",
        "qq" => "qq",
        "ios" => "ios",
        "android" => "android",
        "random" => "random",
        "randomized" => "randomized",
        "chrome-psk" => "chrome",
        "chrome-psk-shuffle" => "chrome",
        "chrome-padding-psk-shuffle" => "chrome",
        "chrome-pq" => "chrome",
        "chrome-pq-psk" => "chrome",
        "chromeauto" => "chrome",
        "hellochrome" => "chrome",
        "hellofirefox" => "firefox",
        "helloios" => "ios",
    ];
    if (isset($allowed[$fp])) {
        return $allowed[$fp];
    }
    return "chrome";
}

function sanitizeVlessFlow($raw)
{
    $flow = strtolower(trim(urldecode((string)$raw)));
    if ($flow === "" || $flow === "none" || $flow === "0") {
        return "";
    }
    if ($flow === "xtls-rprx-vision" || $flow === "xtls-rprx-vision-udp443" || $flow === "vision") {
        return "xtls-rprx-vision";
    }
    return "";
}

function sanitizePacketEncoding($raw)
{
    $enc = strtolower(trim((string)$raw));
    if ($enc === "xudp" || $enc === "packetaddr") {
        return $enc;
    }
    return "xudp";
}

function sanitizeDomainStrategy($raw)
{
    $s = strtolower(trim((string)$raw));
    $allowed = ["prefer_ipv4", "prefer_ipv6", "ipv4_only", "ipv6_only"];
    return in_array($s, $allowed, true) ? $s : "prefer_ipv4";
}

function sanitizeAlpn($alpn)
{
    if (is_string($alpn)) {
        $alpn = explode(",", $alpn);
    }
    if (!is_array($alpn)) {
        return ["h3"];
    }
    $clean = [];
    foreach ($alpn as $item) {
        $item = trim((string)$item);
        if ($item !== "") {
            $clean[] = $item;
        }
    }
    return empty($clean) ? ["h3"] : array_values(array_unique($clean));
}

function sanitizeOutboundForSingbox114($ob)
{
    if (!is_array($ob) || empty($ob["type"])) {
        return null;
    }

    $type = $ob["type"];
    if (in_array($type, ["selector", "urltest", "direct", "block", "dns"], true)) {
        return $ob;
    }

    if (isset($ob["domain_strategy"])) {
        $ob["domain_strategy"] = sanitizeDomainStrategy($ob["domain_strategy"]);
    }

    if (isset($ob["tls"]) && is_array($ob["tls"])) {
        if (isset($ob["tls"]["utls"]["fingerprint"])) {
            $ob["tls"]["utls"]["fingerprint"] = sanitizeUtlsFingerprint($ob["tls"]["utls"]["fingerprint"]);
        }
        if (isset($ob["tls"]["fingerprint"])) {
            $ob["tls"]["fingerprint"] = sanitizeUtlsFingerprint($ob["tls"]["fingerprint"]);
        }
        if (isset($ob["tls"]["alpn"])) {
            $ob["tls"]["alpn"] = sanitizeAlpn($ob["tls"]["alpn"]);
        }
        if (isset($ob["tls"]["reality"]) && is_array($ob["tls"]["reality"])) {
            if (empty($ob["tls"]["reality"]["public_key"])) {
                unset($ob["tls"]["reality"]);
            }
        }
        $verAllowed = ["1.0", "1.1", "1.2", "1.3"];
        if (isset($ob["tls"]["min_version"]) && !in_array((string)$ob["tls"]["min_version"], $verAllowed, true)) {
            $ob["tls"]["min_version"] = "1.2";
        }
        if (isset($ob["tls"]["max_version"]) && !in_array((string)$ob["tls"]["max_version"], $verAllowed, true)) {
            $ob["tls"]["max_version"] = "1.3";
        }
    }

    if ($type === "vless") {
        $flow = sanitizeVlessFlow($ob["flow"] ?? "");
        $hasTransport = !empty($ob["transport"]["type"]) && $ob["transport"]["type"] !== "tcp";
        $hasTls = !empty($ob["tls"]["enabled"]);
        if ($flow !== "" && (!$hasTls || $hasTransport)) {
            $flow = "";
        }
        if ($flow === "") {
            unset($ob["flow"]);
        } else {
            $ob["flow"] = $flow;
            $ob["packet_encoding"] = sanitizePacketEncoding($ob["packet_encoding"] ?? "xudp");
        }
        if (isset($ob["packet_encoding"])) {
            $ob["packet_encoding"] = sanitizePacketEncoding($ob["packet_encoding"]);
        }
        if (isset($ob["transport"]["type"]) && $ob["transport"]["type"] === "grpc" && empty($ob["transport"]["service_name"])) {
            return null;
        }
    }

    if ($type === "hysteria2") {
        if (isset($ob["obfs"]) && is_array($ob["obfs"])) {
            $obfsType = strtolower(trim((string)($ob["obfs"]["type"] ?? "")));
            if (!in_array($obfsType, ["salamander", "gecko"], true) || empty($ob["obfs"]["password"])) {
                unset($ob["obfs"]);
            } else {
                $ob["obfs"]["type"] = $obfsType;
            }
        }
        if (isset($ob["server_ports"]) && !is_array($ob["server_ports"])) {
            $ports = trim((string)$ob["server_ports"]);
            if ($ports === "" || !preg_match('/^\d{1,5}([:-]\d{1,5})?(,\d{1,5}([:-]\d{1,5})?)*$/', $ports)) {
                unset($ob["server_ports"]);
            } else {
                $ob["server_ports"] = array_values(array_filter(explode(",", str_replace("-", ":", $ports))));
            }
        }
    }

    if ($type === "tuic") {
        $cc = strtolower((string)($ob["congestion_control"] ?? "bbr"));
        $ob["congestion_control"] = in_array($cc, ["bbr", "cubic", "new_reno"], true) ? $cc : "bbr";
        $urm = strtolower((string)($ob["udp_relay_mode"] ?? "native"));
        $ob["udp_relay_mode"] = in_array($urm, ["native", "quic"], true) ? $urm : "native";
    }

    if ($type === "vmess") {
        $sec = strtolower((string)($ob["security"] ?? "auto"));
        $allowedSec = ["auto", "aes-128-gcm", "chacha20-poly1305", "none", "zero"];
        $ob["security"] = in_array($sec, $allowedSec, true) ? $sec : "auto";
    }

    if (isset($ob["transport"]) && is_array($ob["transport"])) {
        $tt = $ob["transport"]["type"] ?? "";
        if (!in_array($tt, ["ws", "grpc", "http", "httpupgrade", "quic"], true)) {
            unset($ob["transport"]);
        } elseif ($tt === "ws" && isset($ob["transport"]["early_data_header_name"]) && $ob["transport"]["early_data_header_name"] === "") {
            unset($ob["transport"]["early_data_header_name"]);
        }
    }

    if (empty($ob["server"]) || intval($ob["server_port"] ?? 0) < 1) {
        return null;
    }
    return $ob;
}

function sanitizeStructureForSingbox114(&$structure)
{
    if (!is_array($structure) || empty($structure["outbounds"]) || !is_array($structure["outbounds"])) {
        return;
    }
    $clean = [];
    $keptTags = [];
    foreach ($structure["outbounds"] as $ob) {
        $sanitized = sanitizeOutboundForSingbox114($ob);
        if ($sanitized === null) {
            continue;
        }
        $clean[] = $sanitized;
        if (!empty($sanitized["tag"])) {
            $keptTags[$sanitized["tag"]] = true;
        }
    }
    foreach ($clean as &$ob) {
        if (($ob["type"] ?? "") === "selector" || ($ob["type"] ?? "") === "urltest") {
            if (!empty($ob["outbounds"]) && is_array($ob["outbounds"])) {
                $ob["outbounds"] = array_values(array_filter($ob["outbounds"], function ($tag) use ($keptTags) {
                    return isset($keptTags[$tag]);
                }));
            }
        }
    }
    unset($ob);
    $structure["outbounds"] = $clean;
}

function setTls($decodedConfig, $configType)
{
    $serverNameTypes = [
        "vmess" => $decodedConfig["sni"] ?? $decodedConfig["add"] ?? "",
        "vless" => $decodedConfig["params"]["sni"] ?? $decodedConfig["hostname"] ?? "",
        "trojan" => $decodedConfig["params"]["sni"] ?? $decodedConfig["hostname"] ?? "",
        "tuic" => $decodedConfig["params"]["sni"] ?? $decodedConfig["hostname"] ?? "",
        "hy2" => $decodedConfig["params"]["sni"] ?? $decodedConfig["hostname"] ?? ""
    ];
    $rawFp = $decodedConfig["params"]["fp"]
        ?? $decodedConfig["fp"]
        ?? "chrome";
    $insecure = false;
    if (isset($decodedConfig["params"]["insecure"]) && in_array((string)$decodedConfig["params"]["insecure"], ["1", "true"], true)) {
        $insecure = true;
    }
    if (isset($decodedConfig["params"]["allowInsecure"]) && in_array((string)$decodedConfig["params"]["allowInsecure"], ["1", "true"], true)) {
        $insecure = true;
    }
    $tlsConfig = [
        "enabled" => true,
        "server_name" => $serverNameTypes[$configType],
        "insecure" => $insecure,
        "alpn" => sanitizeAlpn($decodedConfig["params"]["alpn"] ?? ($configType === "tuic" ? "h3" : "h3")),
        "utls" => ["enabled" => true, "fingerprint" => sanitizeUtlsFingerprint($rawFp)]
    ];
    if ($configType === "vless" && !empty($decodedConfig["params"]["security"]) && $decodedConfig["params"]["security"] === "reality") {
        $tlsConfig["reality"] = [
            "enabled" => true,
            "public_key" => $decodedConfig["params"]["pbk"] ?? "",
            "short_id" => $decodedConfig["params"]["sid"] ?? ""
        ];
    }
    if ($configType === "hy2" && !empty($decodedConfig["params"]["ech"])) {
        $tlsConfig["ech"] = ["enabled" => true, "config" => explode(",", $decodedConfig["params"]["ech"])];
    }
    return $tlsConfig;
}

function setTransport($decodedConfig, $configType, $transportType)
{
    $serverNameTypes = [
        "vmess" => $decodedConfig["sni"] ?? $decodedConfig["add"] ?? "",
        "vless" => $decodedConfig["params"]["sni"] ?? $decodedConfig["hostname"] ?? "",
        "trojan" => $decodedConfig["params"]["sni"] ?? $decodedConfig["hostname"] ?? "",
        "tuic" => $decodedConfig["params"]["sni"] ?? $decodedConfig["hostname"] ?? "",
        "hy2" => $decodedConfig["params"]["sni"] ?? $decodedConfig["hostname"] ?? ""
    ];
    $pathTypes = [
        "vmess" => processWsPath($decodedConfig["path"] ?? "")['path'],
        "vless" => processWsPath($decodedConfig["params"]["path"] ?? "")["path"],
        "trojan" => processWsPath($decodedConfig["params"]["path"] ?? "")["path"],
        "tuic" => processWsPath($decodedConfig["params"]["path"] ?? "")["path"],
        "hy2" => processWsPath($decodedConfig["params"]["path"] ?? "")["path"]
    ];
    $earlyData = [
        "vmess" => processWsPath($decodedConfig["path"] ?? "")['max_early_data'],
        "vless" => processWsPath($decodedConfig["params"]["path"] ?? "")["max_early_data"],
        "trojan" => processWsPath($decodedConfig["params"]["path"] ?? "")["max_early_data"],
        "tuic" => processWsPath($decodedConfig["params"]["path"] ?? "")["max_early_data"],
        "hy2" => processWsPath($decodedConfig["params"]["path"] ?? "")["max_early_data"]
    ];
    $servicenameTypes = [
        "vmess" => $decodedConfig["path"] ?? "",
        "vless" => $decodedConfig["params"]["serviceName"] ?? "",
        "trojan" => $decodedConfig["params"]["serviceName"] ?? "",
        "tuic" => $decodedConfig["params"]["serviceName"] ?? "",
        "hy2" => $decodedConfig["params"]["serviceName"] ?? ""
    ];
    $transportTypes = [
        "ws" => ["type" => "ws", "path" => $pathTypes[$configType], "headers" => ["Host" => $serverNameTypes[$configType]], "max_early_data" => $earlyData[$configType], "early_data_header_name" => $earlyData[$configType] > 0 ? "Sec-WebSocket-Protocol" : ""],
        "grpc" => ["type" => "grpc", "service_name" => $servicenameTypes[$configType], "idle_timeout" => "15s", "ping_timeout" => "15s", "permit_without_stream" => false],
        "http" => ["type" => "http", "host" => [$serverNameTypes[$configType]], "path" => $pathTypes[$configType]]
    ];
    return $transportTypes[$transportType] ?? null;
}

function vmessToSingbox($input)
{
    $decodedConfig = configParse($input);
    if (!$decodedConfig) return null;
    $configResult = ["type" => "vmess", "server" => $decodedConfig["add"] ?? "", "server_port" => intval($decodedConfig["port"] ?? 0), "uuid" => $decodedConfig["id"] ?? "", "security" => $decodedConfig["scy"] ?? "auto", "alter_id" => intval($decodedConfig["aid"] ?? 0), "domain_strategy" => "prefer_ipv4"];
    if (($decodedConfig["port"] === "443" || ($decodedConfig["tls"] ?? "") === "tls") && !empty($configResult["server"])) {
        $tls = setTls($decodedConfig, "vmess");
        if (!empty($tls["server_name"])) $configResult["tls"] = $tls;
    }
    if (!empty($decodedConfig["net"]) && in_array($decodedConfig["net"], ["ws", "grpc", "http"])) {
        $transport = setTransport($decodedConfig, "vmess", $decodedConfig["net"]);
        if ($transport) $configResult["transport"] = $transport;
    }
    return sanitizeOutboundForSingbox114($configResult);
}

function vlessToSingbox($input)
{
    $decodedConfig = configParse($input);
    if (!$decodedConfig) return null;
    $isReality = !empty($decodedConfig["params"]["security"]) && $decodedConfig["params"]["security"] === "reality";
    $configResult = ["type" => "vless", "server" => $decodedConfig["hostname"] ?? "", "server_port" => intval($decodedConfig["port"] ?? 0), "uuid" => $decodedConfig["username"] ?? "", "packet_encoding" => "xudp", "domain_strategy" => "prefer_ipv4"];
    $flow = sanitizeVlessFlow($decodedConfig["params"]["flow"] ?? "");
    if ($flow !== "") {
        $configResult["flow"] = $flow;
    }
    if (($decodedConfig["port"] === "443" || (!empty($decodedConfig["params"]["security"]) && in_array($decodedConfig["params"]["security"], ["tls", "reality"]))) && !empty($configResult["server"])) {
        $tls = setTls($decodedConfig, "vless");
        if (!empty($tls["server_name"]) || ($tls["reality"]["enabled"] ?? false)) $configResult["tls"] = $tls;
    }
    if (!empty($decodedConfig["params"]["type"]) && in_array($decodedConfig["params"]["type"], ["ws", "grpc", "http"])) {
        $transport = setTransport($decodedConfig, "vless", $decodedConfig["params"]["type"]);
        if ($transport) $configResult["transport"] = $transport;
    }
    if ($isReality && (empty($decodedConfig["params"]["pbk"]) || empty($configResult["server"]))) return null;
    return sanitizeOutboundForSingbox114($configResult);
}

function trojanToSingbox($input)
{
    $decodedConfig = configParse($input);
    if (!$decodedConfig) return null;
    $configResult = ["type" => "trojan", "server" => $decodedConfig["hostname"] ?? "", "server_port" => intval($decodedConfig["port"] ?? 0), "password" => $decodedConfig["username"] ?? "", "domain_strategy" => "prefer_ipv4"];
    if (($decodedConfig["port"] === "443" || (!empty($decodedConfig["params"]["security"]) && $decodedConfig["params"]["security"] === "tls")) && !empty($configResult["server"])) {
        $tls = setTls($decodedConfig, "trojan");
        if (!empty($tls["server_name"])) $configResult["tls"] = $tls;
    }
    if (!empty($decodedConfig["params"]["type"]) && in_array($decodedConfig["params"]["type"], ["ws", "grpc", "http"])) {
        $transport = setTransport($decodedConfig, "trojan", $decodedConfig["params"]["type"]);
        if ($transport) $configResult["transport"] = $transport;
    }
    return sanitizeOutboundForSingbox114($configResult);
}

function ssToSingbox($input)
{
    $decodedConfig = configParse($input);
    if (!$decodedConfig) return null;
    $encryptionMethods = ["chacha20-ietf-poly1305", "xchacha20-ietf-poly1305", "aes-256-gcm", "aes-128-gcm", "2022-blake3-aes-256-gcm", "2022-blake3-aes-128-gcm", "2022-blake3-chacha20-poly1305"];
    if (!in_array($decodedConfig["encryption_method"] ?? "", $encryptionMethods)) return null;
    $configResult = ["type" => "shadowsocks", "server" => $decodedConfig["server_address"] ?? "", "server_port" => intval($decodedConfig["server_port"] ?? 0), "method" => $decodedConfig["encryption_method"], "password" => $decodedConfig["password"] ?? "", "udp_over_tcp" => true, "domain_strategy" => "prefer_ipv4"];
    return sanitizeOutboundForSingbox114($configResult);
}

function tuicToSingbox($input)
{
    $decodedConfig = configParse($input);
    if (!$decodedConfig) return null;
    $configResult = ["type" => "tuic", "server" => $decodedConfig["hostname"] ?? "", "server_port" => intval($decodedConfig["port"] ?? 0), "uuid" => $decodedConfig["username"] ?? "", "password" => $decodedConfig["pass"] ?? "", "congestion_control" => $decodedConfig["params"]["congestion_control"] ?? "bbr", "udp_relay_mode" => $decodedConfig["params"]["udp_relay_mode"] ?? "native", "zero_rtt_handshake" => false, "heartbeat" => "10s", "network" => "tcp", "domain_strategy" => "prefer_ipv4"];
    $tls = setTls($decodedConfig, "tuic");
    if (!empty($tls["server_name"])) $configResult["tls"] = $tls;
    return sanitizeOutboundForSingbox114($configResult);
}

function hy2ToSingbox($input)
{
    $decodedConfig = configParse($input);
    if (!$decodedConfig) return null;
    $configResult = ["type" => "hysteria2", "server" => $decodedConfig["hostname"] ?? "", "server_port" => intval($decodedConfig["port"] ?? 0), "password" => $decodedConfig["username"] ?? "", "domain_strategy" => "ipv4_only", "hop_interval" => "10s"];
    if (!empty($decodedConfig["params"]["ports"])) $configResult["server_ports"] = $decodedConfig["params"]["ports"];
    if (!empty($decodedConfig["params"]["obfs"])) {
        $configResult["obfs"] = ["type" => $decodedConfig["params"]["obfs"], "password" => $decodedConfig["params"]["obfs-password"] ?? ""];
    }
    $tls = setTls($decodedConfig, "hy2");
    if (!empty($tls["server_name"]) || !empty($tls["ech"])) $configResult["tls"] = $tls;
    return sanitizeOutboundForSingbox114($configResult);
}

function toSingbox($input)
{
    if (!is_valid($input)) return null;
    $configType = detect_type($input);
    $functionsArray = ["vmess" => "vmessToSingbox", "vless" => "vlessToSingbox", "trojan" => "trojanToSingbox", "tuic" => "tuicToSingbox", "hy2" => "hy2ToSingbox", "ss" => "ssToSingbox"];
    return isset($functionsArray[$configType]) ? $functionsArray[$configType]($input) : null;
}

function processConvertion($base64ConfigsList, $configsName = "Created By sinavm")
{
    $decodedList = base64_decode($base64ConfigsList);
    if ($decodedList === false) $decodedList = $base64ConfigsList;
    $configsArray = array_filter(array_map('trim', explode("\n", $decodedList)), 'strlen');
    $structure = json_decode(file_get_contents('structure.json'), true);
    if (!is_array($structure) || !isset($structure['outbounds'][0], $structure['outbounds'][1])) {
        throw new Exception("Invalid structure.json (Sing-box 1.14 template required)");
    }
    $selector = $structure['outbounds'][0];
    $urltest = $structure['outbounds'][1];
    if (!isset($selector['outbounds']) || !is_array($selector['outbounds'])) $selector['outbounds'] = [];
    if (!isset($urltest['outbounds']) || !is_array($urltest['outbounds'])) $urltest['outbounds'] = [];
    $proxyOutbounds = [];
    $index = 1;
    $usedTags = [];
    foreach ($configsArray as $config) {
        $converted = toSingbox($config);
        if (!$converted) continue;
        $tag = "@SiNAVM-" . $index;
        while (isset($usedTags[$tag])) { $index++; $tag = "@SiNAVM-" . $index; }
        $converted['tag'] = $tag;
        $usedTags[$tag] = true;
        $proxyOutbounds[] = $converted;
        $urltest['outbounds'][] = $tag;
        $index++;
    }
    $tail = [];
    for ($i = 2; $i < count($structure['outbounds']); $i++) $tail[] = $structure['outbounds'][$i];
    if (empty($tail)) $tail[] = ["type" => "direct", "tag" => "direct"];
    $structure['outbounds'] = array_merge([$selector, $urltest], $proxyOutbounds, $tail);
    sanitizeStructureForSingbox114($structure);
    return hiddifyHeader($configsName) . json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

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
