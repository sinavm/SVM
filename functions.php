<?php

/**
 * Basic validators + QA helpers
 */
function isValidUuid($uuid) {
    return is_string($uuid) && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $uuid);
}
function isValidHostName($host) {
    if (!is_string($host) || $host === '') return false;
    if (filter_var($host, FILTER_VALIDATE_IP)) return true;
    return (bool)preg_match('/^(?:\*\.)?[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $host);
}
function isValidPort($port) {
    if (is_string($port) && ctype_digit($port)) $port = intval($port, 10);
    return is_int($port) && $port >= 1 && $port <= 65535;
}
function isValidRealitySid($sid) {
    return is_string($sid) && preg_match('/^[0-9a-fA-F]{2,64}$/', $sid);
}
function isValidRealityPbk($pbk) {
    return is_string($pbk) && preg_match('/^[A-Za-z0-9+\/_=-]{20,200}$/', $pbk);
}
function normalizeLabel($s) {
    $s = (string)$s;
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/\s+/', ' ', $s);
    $s = preg_replace('/(\/\/\/\/\/\/|\\+|\|\|\|\|\|\|)+/u', ' ', $s);
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
    $s = trim($s, " \t\n\r\0\x0B|");
    return $s;
}

function validateConfig($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return ['ok'=>false,'type'=>null,'score'=>0,'reason'=>'empty','parsed'=>null];

    $type = detect_type($raw);
    if (!$type) return ['ok'=>false,'type'=>null,'score'=>0,'reason'=>'unknown_type','parsed'=>null];

    $parsed = configParse($raw);
    if ($parsed === null) return ['ok'=>false,'type'=>$type,'score'=>0,'reason'=>'parse_failed','parsed'=>null];

    $score = 50;
    $reason = null;

    if ($type === 'vmess') {
        $id = $parsed['id'] ?? ($parsed['uuid'] ?? null);
        $add = $parsed['add'] ?? ($parsed['host'] ?? null);
        $port = $parsed['port'] ?? null;
        if (!isValidUuid($id)) { $reason = 'vmess_invalid_uuid'; }
        elseif (!isValidHostName($add)) { $reason = 'vmess_invalid_host'; }
        elseif (!isValidPort(is_numeric($port)?intval($port):$port)) { $reason = 'vmess_invalid_port'; }
        else { $score += 40; }
    } elseif (in_array($type, ['vless','trojan','tuic','hy2'], true)) {
        $uuid = $parsed['uuid'] ?? ($parsed['id'] ?? ($parsed['username'] ?? null));
        $host = $parsed['hostname'] ?? ($parsed['server'] ?? ($parsed['host'] ?? ($parsed['add'] ?? null)));
        $port = $parsed['port'] ?? ($parsed['server_port'] ?? null);
        $params = isset($parsed['params']) && is_array($parsed['params']) ? $parsed['params'] : [];

        if ($type === 'vless' && $uuid !== null && $uuid !== '' && !isValidUuid($uuid)) $reason = 'vless_invalid_uuid';
        if ($type === 'vless' && ($uuid === null || $uuid === '')) $reason = 'vless_missing_uuid';
        if ($reason === null && ($host === null || $host === '')) $reason = $type . '_missing_host';
        if ($reason === null && $host !== null && $host !== '' && !isValidHostName($host)) $reason = $type . '_invalid_host';
        if ($reason === null && ($port === null || $port === '')) $reason = $type . '_missing_port';
        if ($reason === null && $port !== null && $port !== '' && !isValidPort(is_numeric($port)?intval($port):$port)) $reason = $type . '_invalid_port';

        $sec = $parsed['security'] ?? ($params['security'] ?? null);
        if ($reason === null && $type === 'vless' && $sec === 'reality') {
            $pbk = $parsed['pbk'] ?? ($params['pbk'] ?? null);
            $sid = $parsed['sid'] ?? ($params['sid'] ?? '');
            if (!isValidRealityPbk((string)$pbk)) {
                $reason = 'reality_missing_or_invalid_pbk';
            } elseif ($sid !== '' && $sid !== null && !isValidRealitySid((string)$sid)) {
                $reason = 'reality_invalid_sid';
            } else {
                $score += 50;
            }
        } else {
            if ($reason === null) $score += 25;
        }
    } elseif ($type === 'ss') {
        $score += 25;
    } else {
        $score += 10;
    }

    if ($reason !== null) return ['ok'=>false,'type'=>$type,'score'=>$score,'reason'=>$reason,'parsed'=>$parsed];
    return ['ok'=>true,'type'=>$type,'score'=>$score,'reason'=>null,'parsed'=>$parsed];
}

function buildStandardName($type, $n) {
    $type = strtoupper((string)$type);
    return "SiNAVM | {$type} | #{$n}";
}

function is_ip($string)
{
    $ip_pattern = '/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/';
    return preg_match($ip_pattern, $string);
}

function convertToJson($input)
{
    $lines = explode("\n", $input);
    $data = [];
    foreach ($lines as $line) {
        $parts = explode("=", $line);
        if (count($parts) == 2 && !empty($parts[0]) && !empty($parts[1])) {
            $data[trim($parts[0])] = trim($parts[1]);
        }
    }
    return json_encode($data);
}

function ip_info($ip)
{
    if (is_cloudflare_ip($ip)) {
        $traceUrl = "http://$ip/cdn-cgi/trace";
        $traceData = json_decode(convertToJson(file_get_contents($traceUrl)), true);
        return (object) ["country" => $traceData['loc'] ?? "CF"];
    }
    if (!is_ip($ip)) {
        $ip_address_array = dns_get_record($ip, DNS_A);
        if (empty($ip_address_array)) return null;
        $ip = $ip_address_array[array_rand($ip_address_array)]["ip"];
    }
    $endpoints = [
        "https://ipapi.co/{ip}/json/",
        "https://ipwhois.app/json/{ip}",
        "http://www.geoplugin.net/json.gp?ip={ip}",
        "https://api.ipbase.com/v1/json/{ip}",
    ];
    $result = (object) ["country" => "XX"];
    foreach ($endpoints as $endpoint) {
        $url = str_replace("{ip}", $ip, $endpoint);
        $options = ["http" => ["header" => "User-Agent: Mozilla/5.0\r\n"]];
        $response = @file_get_contents($url, false, stream_context_create($options));
        if ($response !== false) {
            $data = json_decode($response);
            if ($endpoint === $endpoints[0]) $result->country = $data->country_code ?? "XX";
            elseif ($endpoint === $endpoints[1]) $result->country = $data->country_code ?? "XX";
            elseif ($endpoint === $endpoints[2]) $result->country = $data->geoplugin_countryCode ?? "XX";
            elseif ($endpoint === $endpoints[3]) $result->country = $data->country_code ?? "XX";
            break;
        }
    }
    return $result;
}

function is_cloudflare_ip($ip)
{
    $cloudflare_ranges = explode("\n", file_get_contents('https://www.cloudflare.com/ips-v4'));
    foreach ($cloudflare_ranges as $range) {
        if (cidr_match($ip, $range)) return true;
    }
    return false;
}

function cidr_match($ip, $range)
{
    list($subnet, $bits) = explode('/', $range);
    $bits = $bits ?? 32;
    $ip = ip2long($ip);
    $subnet = ip2long($subnet);
    $mask = -1 << (32 - $bits);
    $subnet &= $mask;
    return ($ip & $mask) == $subnet;
}

function is_valid($input)
{
    if (empty($input) || stripos($input, "…") !== false || stripos($input, "...") !== false) {
        return false;
    }
    return preg_match('/^(vmess|vless|trojan|ss|hysteria2?|hy2|tuic):\/\//', $input);
}

function isEncrypted($input)
{
    $decodedConfig = configParse($input);
    $configType = detect_type($input);
    if ($configType === "vmess" && !empty($decodedConfig['tls']) && $decodedConfig['scy'] !== "none") return true;
    if (in_array($configType, ["vless", "trojan"]) && !empty($decodedConfig['params']['security']) && $decodedConfig['params']['security'] !== "none") return true;
    if ($configType === "ss") return true;
    if ($configType === "tuic" && !empty($decodedConfig['params']['allow_insecure']) && $decodedConfig['params']['allow_insecure'] === "0") return true;
    if ($configType === "hy2" && !empty($decodedConfig['params']['insecure']) && $decodedConfig['params']['insecure'] === "0") return true;
    return false;
}

function getFlags($country_code)
{
    if (strlen($country_code) !== 2) return "🏳️";
    $flag = mb_convert_encoding("&#" . (127397 + ord($country_code[0])) . ";", "UTF-8", "HTML-ENTITIES");
    $flag .= mb_convert_encoding("&#" . (127397 + ord($country_code[1])) . ";", "UTF-8", "HTML-ENTITIES");
    return $flag;
}

function detect_type($input)
{
    if (substr($input, 0, 8) === "vmess://") return "vmess";
    if (substr($input, 0, 8) === "vless://") return "vless";
    if (substr($input, 0, 9) === "trojan://") return "trojan";
    if (substr($input, 0, 5) === "ss://") return "ss";
    if (substr($input, 0, 7) === "tuic://") return "tuic";
    if (substr($input, 0, 6) === "hy2://" || substr($input, 0, 12) === "hysteria2://") return "hy2";
    if (substr($input, 0, 11) === "hysteria://") return "hysteria";
    return null;
}

function extractLinksByType($inputString, $configType)
{
    $configType = str_replace('hy2', 'hy2|hysteria2', $configType);
    $pattern = "/(" . $configType . '):\/\/[^"\'\s]+/';
    preg_match_all($pattern, $inputString, $matches);
    return empty($matches[0]) ? null : $matches[0];
}

function parseQuery($query)
{
    $params = [];
    parse_str($query, $params);
    return $params;
}

function configParse($input)
{
    $configType = detect_type($input);
    if (!$configType) return null;

    if ($configType === "vmess") {
        $decoded_data = json_decode(base64_decode(substr($input, 8)), true);
        if (!$decoded_data) return null;
        foreach ($decoded_data as $k => $v) {
            if (in_array($k, ['host', 'sni'])) {
                preg_match('/^[a-zA-Z0-9.-_*]+/', $v, $m);
                $decoded_data[$k] = $m[0] ?? '';
            } elseif ($k === 'path' || $k === 'ps') {
                $decoded_data[$k] = strip_tags($v);
            }
        }
        return $decoded_data;
    } elseif (in_array($configType, ["vless", "trojan", "tuic", "hy2"], true)) {
        $parsedUrl = parse_url($input);
        if (!$parsedUrl) return null;
        $params = isset($parsedUrl["query"]) ? parseQuery($parsedUrl["query"]) : [];
        if (isset($params['publicKey']) && !isset($params['pbk'])) {
            $params['pbk'] = $params['publicKey'];
            unset($params['publicKey']);
        }
        if (isset($params['shortId']) && !isset($params['sid'])) {
            $params['sid'] = $params['shortId'];
            unset($params['shortId']);
        }
        foreach ($params as $key => $val) {
            $val = trim(strip_tags($val));
            switch ($key) {
                case 'sid':
                    preg_match('/^[0-9a-fA-F]+/', $val, $m);
                    $params[$key] = $m[0] ?? '';
                    break;
                case 'pbk':
                    preg_match('/^[A-Za-z0-9+\/=_-]+/', $val, $m);
                    $params[$key] = rtrim(str_replace(' ', '', $m[0] ?? ''), '/');
                    break;
                case 'sni':
                case 'host':
                case 'server_name':
                    preg_match('/^[a-zA-Z0-9.-_*]+/', $val, $m);
                    $params[$key] = $m[0] ?? '';
                    break;
                case 'path':
                case 'serviceName':
                    $params[$key] = preg_replace('/<[^>]*>/', '', $val);
                    break;
                default:
                    $params[$key] = $val;
            }
        }
        $hash = isset($parsedUrl["fragment"]) ? urldecode($parsedUrl["fragment"]) : "SiNAVM" . getRandomName();
        if ($configType === "vless" && is_reality($input)) {
            $hash = "SiNAVM-reality-" . getRandomName();
        }
        $hash = preg_replace('/\s+/', ' ', trim(preg_replace('/[\r\n\t]+/', ' ', $hash)));
        $output = [
            "protocol" => $configType,
            "username" => $parsedUrl["user"] ?? "",
            "hostname" => $parsedUrl["host"] ?? "",
            "port" => $parsedUrl["port"] ?? "",
            "params" => $params,
            "hash" => $hash,
        ];
        if ($configType === "tuic") {
            $output["pass"] = $params["password"] ?? "";
            if (empty($output["username"]) || empty($output["pass"])) return null;
        }
        return $output;
    } elseif ($configType === "ss") {
        $url = parse_url($input);
        if (!$url) return null;
        $user = $url["user"] ?? "";
        if (isBase64($user)) $user = base64_decode($user);
        $userParts = explode(":", $user);
        if (count($userParts) < 2) return null;
        $output = [
            "encryption_method" => $userParts[0],
            "password" => $userParts[1],
            "server_address" => $url["host"] ?? "",
            "server_port" => $url["port"] ?? "",
            "name" => isset($url["fragment"]) ? strip_tags(urldecode($url["fragment"])) : "SiNAVM" . getRandomName(),
        ];
        if (empty($output["server_address"]) || empty($output["password"])) return null;
        return $output;
    }
    return null;
}

function reparseConfig($configArray, $configType)
{
    if ($configType === "vmess") {
        return "vmess://" . base64_encode(json_encode($configArray));
    } elseif (in_array($configType, ["vless", "trojan", "tuic", "hy2"], true)) {
        $url = $configType . "://";
        $url .= addUsernameAndPassword($configArray);
        $url .= $configArray["hostname"];
        $url .= addPort($configArray);
        $url .= addParams($configArray);
        $url .= addHash($configArray);
        return $url;
    } elseif ($configType === "ss") {
        $user = base64_encode($configArray["encryption_method"] . ":" . $configArray["password"]);
        $url = "ss://$user@{$configArray["server_address"]}:{$configArray["server_port"]}";
        if (!empty($configArray["name"])) $url .= "#" . str_replace(" ", "%20", $configArray["name"]);
        return $url;
    }
    return null;
}

function addUsernameAndPassword($obj)
{
    $url = "";
    if (!empty($obj["username"])) {
        $url .= $obj["username"];
        if (isset($obj["pass"]) && !empty($obj["pass"])) $url .= ":" . $obj["pass"];
        $url .= "@";
    }
    return $url;
}
function addPort($obj) { return !empty($obj["port"]) ? ":" . $obj["port"] : ""; }
function addParams($obj) { return !empty($obj["params"]) ? "?" . http_build_query($obj["params"]) : ""; }
function addHash($obj) { return !empty($obj["hash"]) ? "#" . str_replace(" ", "%20", $obj["hash"]) : ""; }
function is_reality($input) { return detect_type($input) === "vless" && stripos($input, "security=reality") !== false; }
function isBase64($input) { return (base64_encode(base64_decode($input, true)) === $input); }
function getRandomName() { return substr(md5(uniqid()), 0, 8); }
function deleteFolder($folder) {
    if (!is_dir($folder)) return;
    foreach (glob($folder . '/*') as $file) {
        is_dir($file) ? deleteFolder($file) : unlink($file);
    }
    rmdir($folder);
}
function tehran_time() { date_default_timezone_set("Asia/Tehran"); return date("Y-m-d H:i:s", time()); }
function hiddifyHeader($subscriptionName) {
    return "#profile-title: base64:" . base64_encode($subscriptionName) . "\n" .
           "#profile-update-interval: 1\n" .
           "#subscription-userinfo: upload=0; download=0; total=10737418240000000; expire=2546249531\n" .
           "#support-url: https://t.me/sinavm\n" .
           "#profile-web-page-url: https://github.com/sinavm/SVM\n\n";
}

function configFingerprint($raw) {
    $raw = trim((string)$raw);
    $type = detect_type($raw);
    $parsed = configParse($raw);
    if (!$type || $parsed === null) return null;
    if ($type === 'vmess') {
        $parts = ['vmess', strtolower((string)($parsed['add'] ?? '')), (string)($parsed['port'] ?? ''), strtolower((string)($parsed['id'] ?? '')), strtolower((string)($parsed['net'] ?? '')), strtolower((string)($parsed['tls'] ?? '')), strtolower((string)($parsed['sni'] ?? ($parsed['host'] ?? ''))), (string)($parsed['path'] ?? ''), strtolower((string)($parsed['scy'] ?? ''))];
    } elseif ($type === 'ss') {
        $parts = ['ss', strtolower((string)($parsed['server_address'] ?? '')), (string)($parsed['server_port'] ?? ''), strtolower((string)($parsed['encryption_method'] ?? '')), (string)($parsed['password'] ?? '')];
    } else {
        $params = isset($parsed['params']) && is_array($parsed['params']) ? $parsed['params'] : [];
        $parts = [$type, strtolower((string)($parsed['hostname'] ?? '')), (string)($parsed['port'] ?? ''), strtolower((string)($parsed['username'] ?? '')), strtolower((string)($params['security'] ?? '')), strtolower((string)($params['type'] ?? '')), strtolower((string)($params['sni'] ?? ($params['host'] ?? ''))), (string)($params['path'] ?? ($params['serviceName'] ?? '')), (string)($params['pbk'] ?? ''), strtolower((string)($params['sid'] ?? '')), strtolower((string)($params['flow'] ?? ''))];
    }
    return sha1(implode('|', $parts));
}

function looksLikeBase64Sub($text) {
    $t = trim((string)$text);
    if ($t === '') return false;
    if (preg_match('/\b(vmess|vless|trojan|ss|tuic|hy2|hysteria2):\/\//i', $t)) return false;
    return (bool)preg_match('/^[A-Za-z0-9+\/_=\-\r\n]+$/', $t) && strlen($t) > 100;
}
function decodeMaybeBase64($text) {
    if (!looksLikeBase64Sub($text)) return $text;
    $decoded = base64_decode(preg_replace('/\s+/', '', trim($text)), true);
    return $decoded !== false ? $decoded : $text;
}
function expandProtocolPattern($protocols) {
    $list = is_array($protocols) ? $protocols : preg_split('/\|/', (string)$protocols);
    $out = [];
    foreach ($list as $p) {
        $p = trim((string)$p);
        if ($p === '') continue;
        $out[] = $p;
        if ($p === 'hy2') $out[] = 'hysteria2';
        if ($p === 'hysteria2') $out[] = 'hy2';
    }
    return implode('|', array_values(array_unique($out)));
}

function setConfigName($raw, $newName) {
    $raw = trim((string)$raw);
    $newName = normalizeLabel($newName);
    $type = detect_type($raw);
    if ($type === 'vmess') {
        $decoded = json_decode(base64_decode(substr($raw, 8)), true);
        if (!$decoded) return $raw;
        $decoded['ps'] = $newName;
        return "vmess://" . base64_encode(json_encode($decoded, JSON_UNESCAPED_UNICODE));
    }
    if (in_array($type, ['vless','trojan','tuic','hy2'], true)) {
        $p = parse_url($raw);
        if (!$p) return $raw;
        $scheme = $p['scheme'] ?? $type;
        $user = $p['user'] ?? '';
        $pass = isset($p['pass']) ? ':' . $p['pass'] : '';
        $host = $p['host'] ?? '';
        $port = isset($p['port']) ? ':' . $p['port'] : '';
        $query = isset($p['query']) ? '?' . $p['query'] : '';
        return $scheme . '://' . $user . $pass . '@' . $host . $port . $query . '#' . rawurlencode($newName);
    }
    if ($type === 'ss') {
        return explode('#', $raw, 2)[0] . '#' . rawurlencode($newName);
    }
    return $raw;
}
