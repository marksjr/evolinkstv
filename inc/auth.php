<?php
declare(strict_types=1);

function app_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $config = $GLOBALS['config'];
    $https = is_https();

    session_name((string)$config['app']['session_name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    session_start();

    if (isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > 7200) {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    $_SESSION['last_activity'] = time();
}

function auth_user(PDO $pdo): ?array
{
    app_session_start();

    $uid = $_SESSION['uid'] ?? 0;
    if (!is_int($uid) || $uid < 1) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash, must_change_password, created_at
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$uid]);
    $user = $stmt->fetch();

    if (!$user) {
        unset($_SESSION['uid']);
        return null;
    }

    return $user;
}

function require_auth(PDO $pdo): array
{
    $user = auth_user($pdo);
    if (!$user) {
        redirect('login.php');
    }

    return $user;
}

function auth_login(PDO $pdo, string $username, string $password): bool
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, (string)$user['password_hash'])) {
        return false;
    }

    if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
        $update = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $update->execute([password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
    }

    app_session_start();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    $_SESSION['last_activity'] = time();

    return true;
}

function auth_logout(): void
{
    app_session_start();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

function csrf_token(): string
{
    app_session_start();

    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    app_session_start();
    $sent = $_POST['_csrf'] ?? '';
    $expected = (string)($_SESSION['csrf'] ?? '');

    // Exige token existente e token enviado; '' === '' não pode passar.
    if ($expected === '' || !is_string($sent) || $sent === '' || !hash_equals($expected, $sent)) {
        http_response_code(419);
        exit('Sessão expirada. Recarregue a página.');
    }
}
