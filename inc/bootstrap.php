<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

if (!file_exists(APP_ROOT . '/config.php')) {
    if (file_exists(APP_ROOT . '/install.php') && !file_exists(APP_ROOT . '/install.lock')) {
        $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $prefix = (str_contains($scriptPath, '/admin') || str_contains($scriptPath, '/api') || str_contains($scriptPath, '/scratch')) ? '../' : '';
        header('Location: ' . $prefix . 'install.php');
        exit;
    }
    http_response_code(503);
    exit('Sistema não configurado. Execute install.php para iniciar a instalação.');
}

$config = require APP_ROOT . '/config.php';

// Auto-detecção dinâmica da URL do sistema (suporte multi-domínio e subpastas)
if (isset($_SERVER['HTTP_HOST'])) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
        || (isset($_SERVER['HTTP_CF_VISITOR']) && str_contains((string)$_SERVER['HTTP_CF_VISITOR'], '"https"'))
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    $proto = $isHttps ? 'https://' : 'http://';

    $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
    if (str_contains($host, ',')) {
        $host = trim(explode(',', $host)[0]);
    }
    if (!preg_match('/^[a-zA-Z0-9.-]+(:[0-9]+)?$/', $host)) {
        $host = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
        if (!preg_match('/^[a-zA-Z0-9.-]+(:[0-9]+)?$/', $host)) {
            $host = 'localhost';
        }
    }

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = dirname($scriptName);
    $baseDir = preg_replace('#/(?:admin|api)(?:/.*)?$#i', '', $dir);
    $baseDir = rtrim(str_replace('\\', '/', $baseDir), '/');

    $configuredUrl = trim((string)($config['app']['url'] ?? ''));
    if ($configuredUrl === '' || str_contains($configuredUrl, 'site.com')) {
        $config['app']['url'] = $proto . $host . $baseDir;
    }
} elseif (empty($config['app']['url'])) {
    $config['app']['url'] = 'http://localhost';
}
$GLOBALS['config'] = $config;

date_default_timezone_set((string)($config['app']['timezone'] ?? 'America/Sao_Paulo'));
mb_internal_encoding('UTF-8');

if (!empty($config['app']['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
}

set_exception_handler(static function (Throwable $e) use ($config): void {
    error_log('[player] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    if (!empty($config['app']['debug'])) {
        echo '<pre style="white-space:pre-wrap;padding:16px;background:#111;color:#f88;font:13px monospace">'
            . htmlspecialchars((string)$e, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</pre>';
    } else {
        echo 'Erro interno. Tente novamente.';
    }
    exit;
});

require_once APP_ROOT . '/inc/helpers.php';
require_once APP_ROOT . '/inc/db.php';
require_once APP_ROOT . '/inc/settings.php';
require_once APP_ROOT . '/inc/crypto.php';
require_once APP_ROOT . '/inc/auth.php';
require_once APP_ROOT . '/inc/openrouter.php';
require_once APP_ROOT . '/inc/channels_service.php';
require_once APP_ROOT . '/inc/file_shield_service.php';
