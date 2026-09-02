<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ERROR | E_PARSE);

require_once "functions.php";

if (!function_exists("sinavm_should_convert_singbox")) {
    function sinavm_should_convert_singbox() {
        return !defined("SINAVM_SKIP_CONVERT") || !SINAVM_SKIP_CONVERT;
    }
}
