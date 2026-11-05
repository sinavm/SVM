<?php
function vmessToSingbox($input)
{
    $decodedConfig = configParse($input);
    if (!$decodedConfig) return null;
    $configResult = ["type" => "vmess", "server" => $decodedConfig["add"] ?? "", "server_port" => intval($decodedConfig["port"] ?? 0), "uuid" => $decodedConfig["id"] ?? "", "security" => $decodedConfig["scy"] ?? "auto", "alter_id" => intval($decodedConfig["aid"] ?? 0)];
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
    $configResult = ["type" => "vless", "server" => $decodedConfig["hostname"] ?? "", "server_port" => intval($decodedConfig["port"] ?? 0), "uuid" => $decodedConfig["username"] ?? "", "packet_encoding" => "xudp"];
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
    $configResult = ["type" => "trojan", "server" => $decodedConfig["hostname"] ?? "", "server_port" => intval($decodedConfig["port"] ?? 0), "password" => $decodedConfig["username"] ?? ""];
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
    $configResult = ["type" => "shadowsocks", "server" => $decodedConfig["server_address"] ?? "", "server_port" => intval($decodedConfig["server_port"] ?? 0), "method" => $decodedConfig["encryption_method"], "password" => $decodedConfig["password"] ?? "", "udp_over_tcp" => true];
    return sanitizeOutboundForSingbox114($configResult);
}

function tuicToSingbox($input)
{
    $decodedConfig = configParse($input);
    if (!$decodedConfig) return null;
    $configResult = ["type" => "tuic", "server" => $decodedConfig["hostname"] ?? "", "server_port" => intval($decodedConfig["port"] ?? 0), "uuid" => $decodedConfig["username"] ?? "", "password" => $decodedConfig["pass"] ?? "", "congestion_control" => $decodedConfig["params"]["congestion_control"] ?? "bbr", "udp_relay_mode" => $decodedConfig["params"]["udp_relay_mode"] ?? "native", "zero_rtt_handshake" => false, "heartbeat" => "10s", "network" => "tcp"];
    $tls = setTls($decodedConfig, "tuic");
    if (!empty($tls["server_name"])) $configResult["tls"] = $tls;
    return sanitizeOutboundForSingbox114($configResult);
}

function hy2ToSingbox($input)
{
    $decodedConfig = configParse($input);
    if (!$decodedConfig) return null;
    $configResult = ["type" => "hysteria2", "server" => $decodedConfig["hostname"] ?? "", "server_port" => intval($decodedConfig["port"] ?? 0), "password" => $decodedConfig["username"] ?? "", "hop_interval" => "10s"];
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
    $urltest['tag'] = "@SiNAVM";
    $selector['tag'] = "انتخابگر دستی";
    $selector['outbounds'] = array_values(array_unique(array_merge(["@SiNAVM"], $urltest['outbounds'])));
    $tail = [];
    for ($i = 2; $i < count($structure['outbounds']); $i++) $tail[] = $structure['outbounds'][$i];
    if (empty($tail)) $tail[] = ["type" => "direct", "tag" => "direct"];
    $structure['outbounds'] = array_merge([$selector, $urltest], $proxyOutbounds, $tail);
    sanitizeStructureForSingbox114($structure);
    return hiddifyHeader($configsName) . json_encode($structure, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
