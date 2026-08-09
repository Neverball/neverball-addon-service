<?php
const IN_APP = true;

umask(0022);

define('BASE_DIR', dirname(__DIR__));
define('STORAGE_DIR', BASE_DIR . '/storage');
define('LOG_DIR', STORAGE_DIR . '/logs');

require_once BASE_DIR . '/vendor/autoload.php';
(Dotenv\Dotenv::createImmutable(BASE_DIR))->safeLoad();

define('BASE_URL', $_ENV['BASE_URL'] ?? 'https://addons.neverball.org');

ini_set('log_errors', '1');
ini_set('error_log', LOG_DIR . '/php_errors.log');

$GLOBALS['vite'] = new mindplay\vite\Manifest(
    dev:           ($_ENV['APP_ENV'] ?? 'production') === 'development',
    manifest_path: BASE_DIR . '/public/dist/.vite/manifest.json',
    base_path:     '/dist/',
);

require_once BASE_DIR . '/src/AddonTool.php';
