<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';

if (auth_user($pdo)) {
    redirect('index.php');
}

$error = '';
$username = '';
$configApp = $config['app'];

if (is_post()) {
    csrf_check();

    $username = mb_substr(input('username'), 0, 50);
    $password = (string)($_POST['password'] ?? '');
    $ipHash = client_ip_hash((string)$config['app']['key']);

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip_hash = ? AND success = 0 AND created_at > (NOW() - INTERVAL 15 MINUTE)'
    );
    $stmt->execute([$ipHash]);
    $failuresIp = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip_hash = ? AND username = ? AND success = 0
           AND created_at > (NOW() - INTERVAL 15 MINUTE)'
    );
    $stmt->execute([$ipHash, $username]);
    $failuresUser = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE username = ? AND success = 0
           AND created_at > (NOW() - INTERVAL 15 MINUTE)'
    );
    $stmt->execute([$username]);
    $failuresUserGlobal = (int)$stmt->fetchColumn();

    if ($failuresIp >= 20 || $failuresUser >= 5 || $failuresUserGlobal >= 15) {
        $error = 'Muitas tentativas. Aguarde 15 minutos.';
    } elseif ($username === '' || $password === '') {
        $error = 'Informe usuário e senha.';
    } else {
        $ok = auth_login($pdo, $username, $password);

        $insert = $pdo->prepare(
            'INSERT INTO login_attempts (ip_hash, username, success) VALUES (?, ?, ?)'
        );
        $insert->execute([$ipHash, mb_substr($username, 0, 50), $ok ? 1 : 0]);

        if (random_int(1, 100) === 1) {
            $pdo->exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 7 DAY)');
        }

        if ($ok) {
            redirect('index.php');
        }

        $error = 'Usuário ou senha inválidos.';
    }
}

send_admin_headers();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acesso Master · <?= e($configApp['name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="../assets/admin.css?v=<?= file_exists(APP_ROOT . '/assets/admin.css') ? filemtime(APP_ROOT . '/assets/admin.css') : time() ?>">
</head>
<body>
<div class="auth-wrap">
    <div class="card login-card">
        <div class="brand login-brand">
            <div class="brand-signal">
                <span class="signal-ring"></span>
                <span class="brand-dot"></span>
            </div>
            <span class="brand-title"><?= e($configApp['name']) ?></span>
        </div>

        <h1>Centro de Transmissão</h1>
        <p>Autenticação de operador de streams ao vivo.</p>

        <?php if (isset($_GET['installed'])): ?>
            <div class="alert alert-ok">Admin criado. Entre e troque a senha padrão.</div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" class="form">
            <?= csrf_field() ?>

            <div class="field">
                <label for="username">Usuário</label>
                <input id="username" name="username" autocomplete="username"
                       value="<?= e($username) ?>" required autofocus>
            </div>

            <div class="field">
                <label for="password">Senha</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
            </div>

            <button class="btn primary" type="submit">Entrar</button>
        </form>

        <div class="login-foot">Acesso restrito.</div>
    </div>
</div>
</body>
</html>
