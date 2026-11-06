<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

$user = require_auth($pdo);

$counts = [
    'channels' => (int)$pdo->query('SELECT COUNT(*) FROM channels')->fetchColumn(),
    'active'   => (int)$pdo->query('SELECT COUNT(*) FROM channels WHERE active = 1')->fetchColumn(),
];

$recent = $pdo->query(
    'SELECT id, name, slug, active, updated_at
     FROM channels ORDER BY updated_at DESC LIMIT 5'
)->fetchAll();

$domainCount = count(domains_list(normalize_domains(setting($pdo, 'allowed_domains', ''))));

$base = app_base_url() . '/';
$keyIsDefault = str_contains((string)$config['app']['key'], 'TROQUE');

admin_header('Dashboard', $user);
?>

<?php if ((int)$user['must_change_password'] === 1): ?>
    <div class="alert alert-warn">
        Você está usando a senha padrão. <a href="account.php">Troque agora</a>.
    </div>
<?php endif; ?>

<?php if ($keyIsDefault): ?>
    <div class="alert alert-warn">
        A chave de ofuscação ainda é a padrão. Troque <code>app.key</code> no <code>config.php</code>.
    </div>
<?php endif; ?>

<section class="grid cards">
    <article class="card stat">
        <span class="stat-label">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--cyan)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="15" rx="2" ry="2"/><polyline points="17 2 12 7 7 2"/></svg>
            Canais Sintonizados
        </span>
        <strong style="color:var(--cyan);text-shadow:0 0 24px rgba(0,240,255,0.25);"><?= $counts['channels'] ?></strong>
    </article>
    <article class="card stat">
        <span class="stat-label">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--ok)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/></svg>
            Streams Ativos no Ar
        </span>
        <strong style="color:var(--ok);text-shadow:0 0 24px rgba(0,255,157,0.25);"><?= $counts['active'] ?></strong>
    </article>
    <article class="card stat">
        <span class="stat-label">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--muted-light)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Domínios Autorizados
        </span>
        <strong><?= $domainCount ?></strong>
        <a class="hint" href="settings.php" style="margin-top:2px;">Gerenciar domínios autorizados →</a>
    </article>
</section>

<section class="card">
    <div class="topbar">
        <h2 class="section-title">Últimos canais</h2>
        <a class="btn primary" href="channel-edit.php">Novo canal</a>
    </div>

    <?php if (!$recent): ?>
        <div class="empty">Nenhum canal cadastrado ainda.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Canal</th>
                    <th>Status</th>
                    <th>Atualizado</th>
                    <th>Ações</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $channel): ?>
                    <?php
                    $embedUrl = $base . '?v=' . rawurlencode((string)$channel['slug']);
                    $previewUrl = '../?v=' . rawurlencode((string)$channel['slug']) . '&preview=' . rawurlencode(preview_token((string)$channel['slug']));
                    ?>
                    <tr>
                        <td>
                            <div class="name"><?= e($channel['name']) ?></div>
                            <div class="sub mono">?v=<?= e($channel['slug']) ?></div>
                        </td>
                        <td>
                            <span class="badge <?= (int)$channel['active'] === 1 ? 'badge-ok' : 'badge-off' ?>">
                                <?= (int)$channel['active'] === 1 ? 'No Ar' : 'Desativado' ?>
                            </span>
                        </td>
                        <td class="sub"><?= e($channel['updated_at']) ?></td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="btn ghost small"
                                        data-copy="<?= e($embedUrl) ?>">
                                    Copiar link
                                </button>
                                <a class="preview-link" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener">
                                    Abrir prévia
                                </a>
                                <a class="btn ghost small" href="channel-edit.php?id=<?= (int)$channel['id'] ?>">Editar</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php admin_footer(); ?>
