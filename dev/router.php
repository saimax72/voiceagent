<?php
// Router for PHP's built-in web server (local development only). Mirrors the .htaccess rules.
$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$file = __DIR__ . '/..' . $uri;
if ($uri !== '/' && is_file($file) && !preg_match('~^/(app|config|database|storage|cron)/~', $uri)) {
    return false; // serve the static file
}
require __DIR__ . '/../index.php';
