<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

$user = require_auth($pdo);

// Ação de download direto do arquivo .php gerado
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    $id = (int)($_GET['id'] ?? 0);
    $item = file_shield_get($pdo, $id);
    if ($item) {
        $code = file_shield_generate_full_file($item);
        $filename = $item['filename'] ?: 'canal.php';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($code));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $code;
        exit;
    }
    redirect('file-protect.php');
}

// Ação de exclusão
if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            file_shield_delete($pdo, $id);
        }
        redirect('file-protect.php?msg=deleted');
    }
}

$files = file_shield_list($pdo);
$msg = (string)($_GET['msg'] ?? '');

admin_header('Proteção de Arquivos', $user);
?>

<div class="card mb-16">
    <div class="toolbar" style="margin-bottom:0;">
        <div>
            <h2 class="section-title">Proteção & Blindagem de Arquivos</h2>
            <p class="hint">
                Proteja os arquivos PHP dos seus canais (ex.: <code>canais/espn.php</code>). Gere código com anti-acesso direto, restrição de domínios via CSP, anti-adblock, anti-sandbox, anúncios popup próprios e ofuscação de código.
            </p>
        </div>
        <div class="toolbar-actions">
            <a class="btn primary" href="file-protect-edit.php">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                <span>Novo Arquivo Protegido</span>
            </a>
        </div>
    </div>
</div>

<?php if ($msg === 'deleted'): ?>
    <div class="alert alert-ok">Arquivo protegido removido com sucesso.</div>
<?php elseif ($msg === 'saved'): ?>
    <div class="alert alert-ok">Arquivo protegido e configurações salvas com sucesso.</div>
<?php endif; ?>

<?php if (!$files): ?>
    <div class="card empty">
        Nenhum arquivo protegido configurado ainda.
        <div class="mt-12">
            <a class="btn primary" href="file-protect-edit.php">Configurar o primeiro arquivo</a>
        </div>
    </div>
<?php else: ?>
    <div class="card table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Identificação / Nome</th>
                    <th>Arquivo</th>
                    <th>Domínios Autorizados</th>
                    <th>Proteções Ativas</th>
                    <th class="actions">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($files as $f): ?>
                    <?php
                    $domCount = count(domains_list((string)$f['allowed_domains']));
                    ?>
                    <tr>
                        <td>
                            <strong><?= e($f['name']) ?></strong>
                            <?php if (!empty($f['redirect_url'])): ?>
                                <div class="hint" style="font-size:11px;">Redireciona: <?= e($f['redirect_url']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <code style="color:var(--cyan);font-weight:600;"><?= e($f['filename']) ?></code>
                        </td>
                        <td>
                            <?php if ($domCount > 0): ?>
                                <span class="badge badge-cyan" title="<?= e($f['allowed_domains']) ?>">
                                    <?= $domCount ?> domínio(s)
                                </span>
                            <?php else: ?>
                                <span class="badge" style="opacity:0.6;">Todos os domínios</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                <?php if (!empty($f['block_direct'])): ?>
                                    <span class="badge badge-ok" title="Bloqueia abertura direta no navegador">Anti-Direto</span>
                                <?php endif; ?>
                                <?php if (!empty($f['block_iframe'])): ?>
                                    <span class="badge badge-cyan" title="Restringe Iframe aos domínios autorizados via CSP">CSP Iframe</span>
                                <?php endif; ?>
                                <?php if (!empty($f['anti_adblock'])): ?>
                                    <span class="badge" title="Alerta ou bloqueia Adblock">Anti-Adblock</span>
                                <?php endif; ?>
                                <?php if (!empty($f['anti_sandbox'])): ?>
                                    <span class="badge" title="Anti-Sandbox em iframes restritos">Anti-Sandbox</span>
                                <?php endif; ?>
                                <?php if (!empty($f['anti_inspect'])): ?>
                                    <span class="badge" title="Bloqueia DevTools e atalhos de inspeção">Anti-DevTools</span>
                                <?php endif; ?>
                                <?php if (!empty($f['obfuscate'])): ?>
                                    <span class="badge badge-warn" title="Código e Links do canal ofuscados com cifra reversível">Ofuscado</span>
                                <?php endif; ?>
                                <?php if (!empty($f['popup_ads'])): ?>
                                    <span class="badge" style="border-color:#ff2e56;color:#ff2e56;" title="Anúncios Popup personalizados injetados">Popups</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="actions">
                            <a class="btn ghost small" href="file-protect-edit.php?id=<?= (int)$f['id'] ?>" title="Ver código e editar">
                                Código / Editar
                            </a>
                            <a class="btn primary small" href="file-protect.php?action=download&id=<?= (int)$f['id'] ?>" title="Baixar arquivo .php pronto">
                                Baixar .php
                            </a>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Deseja realmente excluir este arquivo protegido?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                <button type="submit" class="btn danger small">Excluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php admin_footer(); ?>
