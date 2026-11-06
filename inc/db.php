<?php
declare(strict_types=1);

$dbConfig = $GLOBALS['config']['db'];

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $dbConfig['host'],
    (int)$dbConfig['port'],
    $dbConfig['name'],
    $dbConfig['charset']
);

try {
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec("SET time_zone = '-03:00'");
} catch (PDOException $e) {
    error_log('[player] DB: ' . $e->getMessage());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }

    if (!empty($GLOBALS['config']['app']['debug'])) {
        echo '<pre>DB: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    } else {
        if (!file_exists(APP_ROOT . '/install.lock') && file_exists(APP_ROOT . '/install.php')) {
            $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
            $prefix = (str_contains($scriptPath, '/admin') || str_contains($scriptPath, '/api') || str_contains($scriptPath, '/scratch')) ? '../' : '';
            echo '<div style="font-family:sans-serif;max-width:500px;margin:50px auto;padding:24px;background:#0e1320;color:#fff;border:1px solid #1e293b;border-radius:10px;text-align:center;">'
                . '<h3 style="color:#ff4757;margin-top:0;font-size:18px;">Falha de Conexão com o Banco</h3>'
                . '<p style="color:#94a3b8;font-size:14px;line-height:1.5;">O sistema não conseguiu conectar com as credenciais informadas no banco MySQL.</p>'
                . '<a href="' . htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') . 'install.php" style="display:inline-block;margin-top:16px;padding:10px 22px;background:#00f0ff;color:#05080e;text-decoration:none;font-weight:700;border-radius:6px;font-size:14px;">Abrir Instalador &rarr;</a>'
                . '</div>';
        } else {
            echo 'Não foi possível conectar ao banco de dados.';
        }
    }
    exit;
}
