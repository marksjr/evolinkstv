<?php
declare(strict_types=1);

function admin_header(string $title, array $user): void
{
    send_admin_headers();

    $appName = (string)($GLOBALS['config']['app']['name'] ?? 'Evo Links TV');
    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $isChannels = in_array($current, ['channels.php', 'channel-edit.php'], true);
    $isFileProtect = in_array($current, ['file-protect.php', 'file-protect-edit.php'], true);
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · <?= e($appName) ?> Control</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="../assets/admin.css?v=<?= file_exists(APP_ROOT . '/assets/admin.css') ? filemtime(APP_ROOT . '/assets/admin.css') : time() ?>">
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-signal">
                <span class="signal-ring"></span>
                <span class="brand-dot"></span>
            </div>
            <span class="brand-title"><?= e($appName) ?></span>
        </div>

        <nav class="nav">
            <a class="<?= $current === 'index.php' ? 'active' : '' ?>" href="index.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
                    <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
                </svg>
                <span>Dashboard</span>
            </a>
            <a class="<?= $isChannels ? 'active' : '' ?>" href="channels.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="7" width="20" height="15" rx="2" ry="2"/>
                    <polyline points="17 2 12 7 7 2"/>
                </svg>
                <span>Canais</span>
            </a>
            <a class="<?= $current === 'channel-import.php' ? 'active' : '' ?>" href="channel-import.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="7 10 12 15 17 10"/>
                    <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                <span>Importar Lista</span>
            </a>
            <a class="<?= $current === 'channel-export.php' ? 'active' : '' ?>" href="channel-export.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                <span>Exportar Lista</span>
            </a>
            <a class="<?= $current === 'mass-replace.php' ? 'active' : '' ?>" href="mass-replace.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
                </svg>
                <span>Mudar Links</span>
            </a>
            <a class="<?= $isFileProtect ? 'active' : '' ?>" href="file-protect.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
                <span>Proteger Arquivos</span>
            </a>
            <a class="<?= $current === 'settings.php' ? 'active' : '' ?>" href="settings.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="3"/>
                    <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                </svg>
                <span>Configurações</span>
            </a>
            <a class="<?= $current === 'account.php' ? 'active' : '' ?>" href="account.php">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                </svg>
                <span>Minha Conta</span>
            </a>
        </nav>

        <div class="sidebar-foot">
            <div class="who">
                <span class="avatar"><?= e(mb_strtoupper(mb_substr((string)$user['username'], 0, 1))) ?></span>
                <div>
                    <div class="who-name"><?= e($user['username']) ?></div>
                    <div class="who-role">MASTER ADMIN</div>
                </div>
            </div>
            <form method="post" action="logout.php">
                <?= csrf_field() ?>
                <button type="submit" class="link-danger">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    Encerrar Sessão
                </button>
            </form>
        </div>
    </aside>

    <main class="main">
        <header class="topbar">
            <div class="topbar-info">
                <h1><?= e($title) ?></h1>
                <div class="topbar-sub">BROADCAST & STREAM CONTROL</div>
            </div>
            <div class="topbar-actions">
                <span class="badge badge-ok">LIVE STREAM ONLINE</span>
            </div>
        </header>
    <?php
}

function admin_footer(): void
{
    ?>
    </main>
</div>
<script src="../assets/admin.js?v=<?= file_exists(APP_ROOT . '/assets/admin.js') ? filemtime(APP_ROOT . '/assets/admin.js') : time() ?>"></script>
</body>
</html>
    <?php
}
