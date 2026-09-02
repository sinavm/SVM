<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

require "functions.php";

function metaProxyLine($proxy) {
    return "  - " . json_encode($proxy, JSON_UNESCAPED_UNICODE);
}

function hy2ToMeta($input) {
    $decoded = configParse($input);
    if (!$decoded || empty($decoded["hostname"])) {
        return null;
    }
    $params = isset($decoded["params"]) && is_array($decoded["params"]) ? $decoded["params"] : [];
    $password = $decoded["username"] ?? ($params["password"] ?? ($params["auth"] ?? ""));
    if ($password === "") {
        return null;
    }
    $proxy = [
        "name" => urldecode($decoded["hash"] ?? "SiNAVM-hy2"),
        "type" => "hysteria2",
        "server" => $decoded["hostname"],
        "port" => intval($decoded["port"] ?? 443),
        "password" => $password,
        "sni" => $params["sni"] ?? ($params["obfs"] ?? $decoded["hostname"]),
        "skip-cert-verify" => (($params["insecure"] ?? "0") === "1"),
        "udp" => true,
    ];
    if (!empty($params["obfs"])) {
        $proxy["obfs"] = $params["obfs"];
    }
    if (!empty($params["obfs-password"])) {
        $proxy["obfs-password"] = $params["obfs-password"];
    }
    return metaProxyLine($proxy);
}

function tuicToMeta($input) {
    $decoded = configParse($input);
    if (!$decoded || empty($decoded["hostname"])) {
        return null;
    }
    $params = isset($decoded["params"]) && is_array($decoded["params"]) ? $decoded["params"] : [];
    $uuid = $decoded["username"] ?? "";
    $password = $decoded["pass"] ?? ($params["password"] ?? "");
    if ($uuid === "" || $password === "") {
        return null;
    }
    $proxy = [
        "name" => urldecode($decoded["hash"] ?? "SiNAVM-tuic"),
        "type" => "tuic",
        "server" => $decoded["hostname"],
        "port" => intval($decoded["port"] ?? 443),
        "uuid" => $uuid,
        "password" => $password,
        "sni" => $params["sni"] ?? $decoded["hostname"],
        "udp-relay-mode" => $params["udp_relay_mode"] ?? "native",
        "congestion-controller" => $params["congestion_control"] ?? "bbr",
        "skip-cert-verify" => (($params["allow_insecure"] ?? "0") === "1"),
        "udp" => true,
    ];
    return metaProxyLine($proxy);
}

function buildMetaFile($title, $proxyLines) {
    $names = "";
    foreach ($proxyLines as $line) {
        if (preg_match('/"name":"(.*?)"/', $line, $m)) {
            $names .= "      - '" . $m[1] . "'\n";
        }
    }
    if ($names === "") {
        $names = "      - DIRECT\n";
        $proxyBlock = "proxies:\n  - {name: DIRECT, type: http, server: 127.0.0.1, port: 9}\n";
    } else {
        $proxyBlock = "proxies:\n" . implode("\n", $proxyLines) . "\n";
    }
    return implode("\n", [
        "mixed-port: 7890",
        "allow-lan: true",
        "mode: rule",
        "log-level: error",
        "external-controller: 127.0.0.1:9090",
        "",
        $proxyBlock,
        "proxy-groups:",
        "  - name: MANUAL",
        "    type: select",
        "    proxies:",
        "      - URL-TEST",
        "      - FALLBACK",
        $names,
        "  - name: URL-TEST",
        "    type: url-test",
        "    url: http://cp.cloudflare.com/",
        "    interval: 60",
        "    tolerance: 50",
        "    proxies:",
        $names,
        "  - name: FALLBACK",
        "    type: fallback",
        "    url: http://cp.cloudflare.com/",
        "    interval: 60",
        "    proxies:",
        $names,
        "rules:",
        "  - DOMAIN-SUFFIX,ir,DIRECT",
        "  - MATCH,MANUAL",
        "",
    ]);
}

@mkdir("subscriptions/meta", 0777, true);

$types = [
    "hy2" => "hy2ToMeta",
    "tuic" => "tuicToMeta",
];

foreach ($types as $type => $fn) {
    $src = "subscriptions/xray/normal/" . $type;
    $lines = [];
    if (is_file($src)) {
        foreach (explode("\n", file_get_contents($src)) as $raw) {
            $raw = trim($raw);
            if ($raw === "" || $raw[0] === "#") {
                continue;
            }
            $converted = $fn($raw);
            if (is_string($converted) && $converted !== "") {
                $lines[] = $converted;
            }
        }
    }
    file_put_contents("subscriptions/meta/" . $type, buildMetaFile("SiNAVM | " . strtoupper($type), array_values(array_unique($lines))));
}

echo "Clash.Meta hy2/tuic convertion done!\n";
