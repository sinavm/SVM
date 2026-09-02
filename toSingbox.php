<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

require_once "functions.php";

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
