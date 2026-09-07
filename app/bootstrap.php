<?php
declare(strict_types=1);

/**
 * Application bootstrap: constants, autoloader, config, error handling.
 */

define('APP_ROOT', dirname(__DIR__));
define('APP_PATH', __DIR__);
define('APP_START', microtime(true));
define('APP_VERSION', '1.3.0');

if (version_compare(PHP_VERSION, '8.1.0', '<')) {
    http_response_code(500);
    echo 'VoiceAgent requires PHP 8.1 or newer. Current version: ' . PHP_VERSION;
    exit;
}

// PSR-4 style autoloader for the App\ namespace (no Composer required)
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = APP_PATH . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require APP_PATH . '/helpers.php';

// Make sure runtime directories exist (they are not tracked in git)
foreach (['storage', 'storage/documents', 'storage/cache', 'storage/cache/tts', 'storage/logs', 'storage/tmp', 'uploads'] as $dir) {
    if (!is_dir(APP_ROOT . '/' . $dir)) {
        @mkdir(APP_ROOT . '/' . $dir, 0755, true);
    }
}

// Load configuration (installer creates config/config.php)
$configFile = APP_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "VoiceAgent is not installed yet. Open install.php in your browser.\n");
        exit(1);
    }
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    header('Location: ' . $base . '/install.php');
    exit;
}

$config = require $configFile;
\App\Core\App::init($config);
