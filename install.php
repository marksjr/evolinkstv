<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}
$installCsrf = (string)$_SESSION['install_csrf'];

$lockFile   = __DIR__ . '/install.lock';
$configFile = __DIR__ . '/config.php';
$schemaFile = __DIR__ . '/sql/schema.sql';

$isLocked = file_exists($lockFile);
$errors   = [];
$success  = false;

// Diagnóstico de Pré-requisitos da Hospedagem (Pre-Flight Checks)
$phpVersionOk  = version_compare(PHP_VERSION, '8.1.0', '>=');
$extPdoOk      = extension_loaded('pdo_mysql');
$extOpensslOk  = extension_loaded('openssl');
$extCurlOk     = extension_loaded('curl');
$extMbstringOk = extension_loaded('mbstring');
$extJsonOk     = extension_loaded('json');
$rootWritable  = is_writable(__DIR__);
$cacheWritable = is_dir(__DIR__ . '/cache') ? is_writable(__DIR__ . '/cache') : is_writable(__DIR__);

$requirements = [
    'PHP 8.1 ou Superior'   => ['ok' => $phpVersionOk, 'val' => 'PHP ' . PHP_VERSION, 'hint' => 'Selecione PHP 8.1, 8.2 ou 8.3 no painel cPanel'],
    'PDO MySQL'             => ['ok' => $extPdoOk, 'val' => $extPdoOk ? 'Ativo' : 'Ausente', 'hint' => 'Habilite pdo_mysql no painel da hospedagem'],
    'OpenSSL (AES-256-GCM)' => ['ok' => $extOpensslOk, 'val' => $extOpensslOk ? 'Ativo' : 'Ausente', 'hint' => 'Necessário para proteção criptográfica dos streams'],
    'cURL'                  => ['ok' => $extCurlOk, 'val' => $extCurlOk ? 'Ativo' : 'Ausente', 'hint' => 'Necessário para importações remotas e IA'],
    'MBString'              => ['ok' => $extMbstringOk, 'val' => $extMbstringOk ? 'Ativo' : 'Ausente', 'hint' => 'Necessário para manipulação segura de UTF-8'],
    'Permissão de Escrita'  => ['ok' => $rootWritable, 'val' => $rootWritable ? 'Gravável' : 'Somente Leitura', 'hint' => 'CHMOD 755 ou 775 na pasta do sistema'],
];

$allRequirementsMet = $phpVersionOk && $extPdoOk && $extOpensslOk && $extCurlOk && $extMbstringOk && $extJsonOk && $rootWritable;

// Carrega configurações existentes se já houver config.php
$currentConfig = file_exists($configFile) ? (include $configFile) : [];
if (!is_array($currentConfig)) {
    $currentConfig = [];
}

// Auto-trava de segurança: impede sequestro de conta ou sobrescrita em produção
if (!$isLocked && file_exists($configFile) && !empty($currentConfig['db']['host']) && !empty($currentConfig['db']['name'])) {
    $configuredKey = (string)($currentConfig['app']['key'] ?? '');
    $isCustomKey = ($configuredKey !== '' && $configuredKey !== 'CHAVE_DE_64_CARACTERES_GERADA_NO_INSTALL_PHP');

    try {
        $dsnCheck = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string)$currentConfig['db']['host'],
            (int)($currentConfig['db']['port'] ?? 3306),
            (string)$currentConfig['db']['name']
        );
        $pdoCheck = new PDO($dsnCheck, (string)($currentConfig['db']['user'] ?? ''), (string)($currentConfig['db']['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
            PDO::ATTR_TIMEOUT => 2,
        ]);
        $hasUsers = (int)$pdoCheck->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($hasUsers > 0) {
            $isLocked = true;
            @file_put_contents($lockFile, "Auto-locked on " . date('Y-m-d H:i:s') . " (detected active database)\n", LOCK_EX);
        }
    } catch (Throwable) {
        // Se já possui chave customizada e banco configurado, trava por precaução contra quedas de banco
        if ($isCustomKey) {
            $isLocked = true;
        }
    }
}

$defaultHost = (string)($currentConfig['db']['host'] ?? '127.0.0.1');
$defaultPort = (int)($currentConfig['db']['port'] ?? 3306);
$defaultDb   = (string)($currentConfig['db']['name'] ?? 'pro');
$defaultUser = (string)($currentConfig['db']['user'] ?? 'root');
$defaultName = (string)($currentConfig['app']['name'] ?? 'Evo Links TV');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$isLocked) {
    $sentCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($installCsrf, $sentCsrf)) {
        $errors[] = 'Sessão expirada ou token de segurança inválido. Recarregue a página e tente novamente.';
    }

    if (!$allRequirementsMet) {
        $errors[] = 'Seu servidor não atende a todos os requisitos mínimos obrigatórios exibidos no diagnóstico.';
    }

    $dbHost = trim((string)($_POST['db_host'] ?? '127.0.0.1'));
    $dbPort = (int)($_POST['db_port'] ?? 3306);
    $dbName = trim((string)($_POST['db_name'] ?? 'pro'));
    $dbUser = trim((string)($_POST['db_user'] ?? 'root'));
    $dbPass = (string)($_POST['db_pass'] ?? '');

    $appName          = trim((string)($_POST['app_name'] ?? 'Evo Links TV'));
    $adminUser        = trim((string)($_POST['admin_user'] ?? 'admin'));
    $adminPass        = (string)($_POST['admin_pass'] ?? '');
    $adminPassConfirm = (string)($_POST['admin_pass_confirm'] ?? '');

    if ($dbHost === '') {
        $errors[] = 'Informe o host do MySQL (ex.: 127.0.0.1 ou localhost).';
    }
    if ($dbName === '') {
        $errors[] = 'Informe o nome do banco de dados.';
    } elseif (!preg_match('/^[a-zA-Z0-9_\-]+$/', $dbName)) {
        $errors[] = 'O nome do banco de dados deve conter apenas letras, números, hífen e underline.';
    }
    if ($dbUser === '') {
        $errors[] = 'Informe o usuário do banco de dados.';
    }

    if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $adminUser)) {
        $errors[] = 'Nome de usuário do admin inválido (use 3 a 50 caracteres: letras, números, hífen ou underline).';
    }
    if (strlen($adminPass) < 6) {
        $errors[] = 'A senha do administrador precisa ter pelo menos 6 caracteres.';
    }
    if ($adminPass !== $adminPassConfirm) {
        $errors[] = 'A confirmação de senha do administrador não confere.';
    }

    if (!$rootWritable) {
        $errors[] = 'A pasta raiz do projeto precisa ter permissão de escrita para gerar config.php e install.lock.';
    }

    if (!$errors) {
        try {
            // 1. Tenta conectar ao servidor MySQL para criar o banco de dados se não existir (em VPS / dedicado)
            try {
                $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, $dbPort);
                $testPdo = new PDO($serverDsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT            => 5,
                ]);

                $safeDbName = str_replace('`', '``', $dbName);
                $testPdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            } catch (Throwable) {
                // Em hospedagens compartilhadas (cPanel, Plesk), o usuário não possui privilégio de CREATE DATABASE
                // O instalador prossegue conectando diretamente ao banco já criado no painel da hospedagem.
            }

            // 2. Conecta no banco especificado
            $dbDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
            $pdo = new PDO($dbDsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);

            // 3. Executa criação das tabelas atualizadas (100% alinhadas ao schema.sql)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `users` (
                  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `username` VARCHAR(50) NOT NULL,
                  `password_hash` VARCHAR(255) NOT NULL,
                  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_users_username` (`username`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `channels` (
                  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `slug` VARCHAR(64) NOT NULL,
                  `name` VARCHAR(120) NOT NULL,
                  `url` VARCHAR(2048) NOT NULL,
                  `url_backup` VARCHAR(2048) DEFAULT NULL,
                  `category` VARCHAR(60) DEFAULT NULL,
                  `player_type` VARCHAR(20) NOT NULL DEFAULT 'auto',
                  `drm_key` VARCHAR(255) DEFAULT NULL,
                  `popup_ads` MEDIUMTEXT DEFAULT NULL,
                  `active` TINYINT(1) NOT NULL DEFAULT 1,
                  `sort_order` INT NOT NULL DEFAULT 0,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uq_channels_slug` (`slug`),
                  KEY `idx_channels_active_sort` (`active`, `sort_order`, `name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `settings` (
                  `setting_key` VARCHAR(64) NOT NULL,
                  `setting_value` LONGTEXT DEFAULT NULL,
                  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`setting_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `login_attempts` (
                  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `ip_hash` CHAR(64) NOT NULL,
                  `username` VARCHAR(50) NOT NULL,
                  `success` TINYINT(1) NOT NULL DEFAULT 0,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_attempts_ip_time` (`ip_hash`, `created_at`),
                  KEY `idx_attempts_user_time` (`username`, `created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

                CREATE TABLE IF NOT EXISTS `protected_files` (
                  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                  `name` VARCHAR(150) NOT NULL,
                  `filename` VARCHAR(150) NOT NULL DEFAULT 'canal.php',
                  `allowed_domains` TEXT DEFAULT NULL,
                  `block_direct` TINYINT(1) NOT NULL DEFAULT 1,
                  `block_iframe` TINYINT(1) NOT NULL DEFAULT 1,
                  `anti_adblock` TINYINT(1) NOT NULL DEFAULT 1,
                  `anti_sandbox` TINYINT(1) NOT NULL DEFAULT 1,
                  `anti_inspect` TINYINT(1) NOT NULL DEFAULT 1,
                  `obfuscate` TINYINT(1) NOT NULL DEFAULT 1,
                  `redirect_url` VARCHAR(2048) DEFAULT NULL,
                  `popup_ads` LONGTEXT DEFAULT NULL,
                  `source_code` LONGTEXT DEFAULT NULL,
                  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_protected_files_name` (`name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");

            // 4. Configurações padrão de segurança
            $defaultSettings = [
                ['protect_iframe', '1'],
                ['block_direct_access', '1'],
                ['anti_adblock', '1'],
                ['anti_sandbox', '1'],
                ['inspect_protection', '1'],
                ['allowed_domains', 'localhost,127.0.0.1'],
                ['redirect_url', ''],
                ['watermark_text', ''],
                ['player_brand_name', 'Ao Vivo'],
                ['player_ads_code', ''],
            ];
            $stmtSet = $pdo->prepare('INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES (?, ?)');
            foreach ($defaultSettings as [$sk, $sv]) {
                $stmtSet->execute([$sk, $sv]);
            }

            // 5. Cadastra o usuário administrador (com transação atômica)
            $pdo->beginTransaction();
            $pdo->exec('DELETE FROM `users`');
            $adminStmt = $pdo->prepare('INSERT INTO `users` (`username`, `password_hash`, `must_change_password`) VALUES (?, ?, 0)');
            $adminStmt->execute([
                $adminUser,
                password_hash($adminPass, PASSWORD_DEFAULT),
            ]);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            // 6. Gera chave criptográfica forte de 64 caracteres hexadecimais (256 bits)
            $appKey = bin2hex(random_bytes(32));

            // 7. Salva o config.php atualizado com verificação estrita
            $configContent = "<?php\ndeclare(strict_types=1);\n\nreturn [\n"
                . "    'db' => [\n"
                . "        'host'    => " . var_export($dbHost, true) . ",\n"
                . "        'port'    => " . $dbPort . ",\n"
                . "        'name'    => " . var_export($dbName, true) . ",\n"
                . "        'user'    => " . var_export($dbUser, true) . ",\n"
                . "        'pass'    => " . var_export($dbPass, true) . ",\n"
                . "        'charset' => 'utf8mb4',\n"
                . "    ],\n"
                . "    'app' => [\n"
                . "        'name'         => " . var_export($appName, true) . ",\n"
                . "        'url'          => '', // Vazio para auto-detectar qualquer domínio e subpasta\n"
                . "        'timezone'     => 'America/Sao_Paulo',\n"
                . "        'session_name' => 'player_admin',\n"
                . "        'key'          => " . var_export($appKey, true) . ",\n"
                . "        'debug'        => false,\n"
                . "    ],\n"
                . "];\n";

            $writeConfig = @file_put_contents($configFile, $configContent, LOCK_EX);
            if ($writeConfig === false) {
                throw new RuntimeException("Falha ao gravar o arquivo config.php na raiz. Verifique as permissões de pasta (CHMOD 755/775).");
            }
            @chmod($configFile, 0640);

            // 8. Cria arquivo de trava de instalação (anti-abuso em produção)
            $writeLock = @file_put_contents($lockFile, "Installed on " . date('Y-m-d H:i:s') . " by " . ($_SERVER['REMOTE_ADDR'] ?? 'local') . "\n", LOCK_EX);
            if ($writeLock === false) {
                @touch($lockFile);
            }
            @chmod($lockFile, 0640);

            $success = true;
            $isLocked = true;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Falha na instalação: ' . $e->getMessage();
        }
    }
}

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalador · <?= e($appName ?? 'Evo Links TV') ?></title>
    <link rel="stylesheet" href="assets/admin.css">
    <style>
        .install-box {
            max-width: 700px;
            margin: 40px auto;
            padding: 32px 28px;
        }
        .install-step {
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--line);
        }
        .install-step-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--cyan);
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .install-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: rgba(0, 240, 255, 0.15);
            color: var(--cyan);
            font-size: 12px;
            font-weight: 800;
        }
        .req-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }
        .req-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .req-name {
            font-size: 13px;
            color: var(--text-bright);
            font-weight: 600;
        }
        .req-status {
            font-size: 12px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 4px;
        }
        .req-ok {
            background: rgba(46, 213, 115, 0.15);
            color: #2ed573;
            border: 1px solid rgba(46, 213, 115, 0.3);
        }
        .req-fail {
            background: rgba(255, 71, 87, 0.15);
            color: #ff4757;
            border: 1px solid rgba(255, 71, 87, 0.3);
        }
    </style>
</head>
<body>
<div class="shell" style="display:block;min-height:100vh;background:var(--bg);">
    <div class="card install-box">
        <div class="brand" style="justify-content:center;margin-bottom:24px;border:none;padding:0;">
            <div class="brand-signal">
                <span class="signal-ring"></span>
                <span class="brand-dot"></span>
            </div>
            <span class="brand-title" style="font-size:20px;"><?= e($defaultName) ?></span>
        </div>

        <h1 style="text-align:center;font-size:24px;margin-bottom:8px;">Instalador do Sistema</h1>
        <p style="text-align:center;color:var(--muted);margin-bottom:24px;font-size:14px;">
            Configure seu banco de dados e conta de administrador para produção.
        </p>

        <?php if ($success): ?>
            <div class="alert alert-ok" style="padding:20px;margin-bottom:24px;">
                <h3 style="font-size:16px;margin-bottom:6px;color:#fff;">🎉 Instalação concluída com sucesso!</h3>
                <p style="font-size:13px;line-height:1.5;">
                    O banco de dados foi criado, as tabelas atualizadas foram migradas e o administrador foi cadastrado com sucesso.
                    A chave de segurança única e a trava <code>install.lock</code> foram geradas automaticamente.
                </p>
            </div>
            <div style="text-align:center;margin-top:24px;">
                <a class="btn primary" href="admin/login.php" style="padding:12px 28px;font-size:15px;">
                    Acessar Painel de Controle &rarr;
                </a>
            </div>
        <?php elseif ($isLocked): ?>
            <div class="alert alert-warn" style="padding:20px;margin-bottom:24px;">
                <h3 style="font-size:16px;margin-bottom:6px;color:#fff;">🔒 Sistema Já Instalado e Protegido</h3>
                <p style="font-size:13px;line-height:1.5;">
                    O sistema já está configurado e protegido contra re-instalações indesejadas pela trava de segurança.<br>
                    Para realizar uma reinstalação manual do zero, remova o arquivo <code>install.lock</code> e o <code>config.php</code> via FTP ou painel cPanel.
                </p>
            </div>
            <div style="text-align:center;margin-top:20px;">
                <a class="btn primary" href="admin/login.php" style="padding:10px 24px;">Ir para o Login do Painel</a>
            </div>
        <?php else: ?>

            <?php if (!$allRequirementsMet): ?>
                <div class="alert alert-warn" style="margin-bottom:20px;">
                    <strong>Atenção:</strong> Alguns requisitos do servidor não estão em conformidade. Ajuste as configurações da sua hospedagem antes de continuar.
                </div>
            <?php endif; ?>

            <!-- Diagnóstico de Pré-requisitos -->
            <div class="install-step">
                <div class="install-step-title">
                    <span class="install-badge">✓</span>
                    <span>Diagnóstico da Hospedagem</span>
                </div>
                <div class="req-grid">
                    <?php foreach ($requirements as $name => $info): ?>
                        <div class="req-card" title="<?= e($info['hint']) ?>">
                            <div>
                                <div class="req-name"><?= e($name) ?></div>
                                <div style="font-size:11px;color:var(--muted);"><?= e($info['val']) ?></div>
                            </div>
                            <span class="req-status <?= $info['ok'] ? 'req-ok' : 'req-fail' ?>">
                                <?= $info['ok'] ? 'OK' : 'Falha' ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-error" style="margin-bottom:24px;">
                    <ul class="errors">
                        <?php foreach ($errors as $error): ?>
                            <li><?= e($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" class="form">
                <input type="hidden" name="csrf_token" value="<?= e($installCsrf) ?>">

                <div class="install-step">
                    <div class="install-step-title">
                        <span class="install-badge">1</span>
                        <span>Banco de Dados MySQL</span>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label for="db_host">Host do MySQL</label>
                            <input id="db_host" name="db_host" type="text" value="<?= e($_POST['db_host'] ?? $defaultHost) ?>" required>
                            <p class="hint">Geralmente <code>localhost</code> ou <code>127.0.0.1</code>.</p>
                        </div>
                        <div class="field">
                            <label for="db_port">Porta</label>
                            <input id="db_port" name="db_port" type="number" value="<?= e((string)($_POST['db_port'] ?? $defaultPort)) ?>" required>
                        </div>
                    </div>

                    <div class="field mt-16">
                        <label for="db_name">Nome do Banco de Dados</label>
                        <input id="db_name" name="db_name" type="text" value="<?= e($_POST['db_name'] ?? $defaultDb) ?>" required>
                        <p class="hint">Em hospedagem cPanel, crie o banco antes e informe o nome aqui (ex: <code>usuario_pro</code>).</p>
                    </div>

                    <div class="form-grid mt-16">
                        <div class="field">
                            <label for="db_user">Usuário do Banco</label>
                            <input id="db_user" name="db_user" type="text" value="<?= e($_POST['db_user'] ?? $defaultUser) ?>" required>
                        </div>
                        <div class="field">
                            <label for="db_pass">Senha do Banco</label>
                            <input id="db_pass" name="db_pass" type="password" value="<?= e($_POST['db_pass'] ?? '') ?>" placeholder="Senha do banco MySQL">
                        </div>
                    </div>
                </div>

                <div class="install-step">
                    <div class="install-step-title">
                        <span class="install-badge">2</span>
                        <span>Conta do Administrador</span>
                    </div>

                    <div class="field">
                        <label for="app_name">Nome do Sistema / Painel</label>
                        <input id="app_name" name="app_name" type="text" value="<?= e($_POST['app_name'] ?? $defaultName) ?>" required>
                    </div>

                    <div class="field mt-16">
                        <label for="admin_user">Usuário Administrador</label>
                        <input id="admin_user" name="admin_user" type="text" value="<?= e($_POST['admin_user'] ?? 'admin') ?>" required>
                    </div>

                    <div class="form-grid mt-16">
                        <div class="field">
                            <label for="admin_pass">Senha do Administrador</label>
                            <input id="admin_pass" name="admin_pass" type="password" required minlength="6">
                            <p class="hint">Mínimo de 6 caracteres.</p>
                        </div>
                        <div class="field">
                            <label for="admin_pass_confirm">Confirmar Senha</label>
                            <input id="admin_pass_confirm" name="admin_pass_confirm" type="password" required minlength="6">
                        </div>
                    </div>
                </div>

                <div style="text-align:center;margin-top:24px;">
                    <button class="btn primary" type="submit" style="width:100%;padding:14px;font-size:15px;font-weight:700;">
                        Instalar e Configurar Sistema
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
