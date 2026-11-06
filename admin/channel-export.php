<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

$user = require_auth($pdo);

$appName = (string)($config['app']['name'] ?? 'Evo Links TV');
$base = app_base_url() . '/';

// Parâmetros de Filtro & Opções
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$linkType = (string)($_GET['link_type'] ?? $_POST['link_type'] ?? 'player');
if (!in_array($linkType, ['player', 'stream', 'backup'], true)) {
    $linkType = 'player';
}

$status = (string)($_GET['status'] ?? $_POST['status'] ?? 'all');
if (!in_array($status, ['all', 'active', 'inactive'], true)) {
    $status = 'all';
}

$cat = mb_substr(trim((string)($_GET['category'] ?? $_POST['category'] ?? '')), 0, 60);

// Suporte a IDs selecionados via checkbox
$selectedIdsRaw = $_REQUEST['selected_ids'] ?? [];
$selectedIds = [];
if (is_array($selectedIdsRaw)) {
    $selectedIds = array_filter(array_map('intval', $selectedIdsRaw), static fn(int $id): bool => $id > 0);
} elseif (is_string($selectedIdsRaw) && $selectedIdsRaw !== '') {
    $parts = explode(',', $selectedIdsRaw);
    $selectedIds = array_filter(array_map('intval', $parts), static fn(int $id): bool => $id > 0);
}

// Construção da Query
$where = [];
$params = [];

if ($selectedIds) {
    $inQuery = implode(',', array_fill(0, count($selectedIds), '?'));
    $where[] = "id IN ($inQuery)";
    $params = array_values($selectedIds);
} else {
    if ($cat !== '') {
        $where[] = 'category = ?';
        $params[] = $cat;
    }
    if ($status === 'active') {
        $where[] = 'active = 1';
    } elseif ($status === 'inactive') {
        $where[] = 'active = 0';
    }
}

$sql = 'SELECT * FROM channels';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY sort_order ASC, name ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$channels = $stmt->fetchAll();

// Categorias para o seletor
$categories = $pdo->query(
    "SELECT DISTINCT category FROM channels
     WHERE category IS NOT NULL AND category <> ''
     ORDER BY category ASC"
)->fetchAll(PDO::FETCH_COLUMN);

// Manipulação de Ações Diretas de Download
if ($action === 'download_txt') {
    $txt = channels_export_to_txt($channels, $linkType, $base);
    $filename = 'canais-evolinkstv-' . date('Y-m-d_His') . '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($txt));
    echo $txt;
    exit;
}

if ($action === 'download_html') {
    $html = channels_export_to_html($channels, $linkType, $base, $appName);
    $filename = 'canais-evolinkstv-' . date('Y-m-d_His') . '.html';
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($html));
    echo $html;
    exit;
}

if ($action === 'view_html') {
    header('Content-Type: text/html; charset=utf-8');
    echo channels_export_to_html($channels, $linkType, $base, $appName);
    exit;
}

// Renderização do Painel Administrativo
$txtPreview = channels_export_to_txt($channels, $linkType, $base);
$totalChannels = count($channels);

admin_header('Exportar Canais', $user);
?>

<div class="card mb-16">
    <div class="toolbar" style="margin-bottom:0;">
        <div>
            <h2 class="section-title">Central de Exportação de Canais</h2>
            <p class="hint">
                Exporte sua grade de canais com nome e link formatados em <strong>TXT</strong> ou em <strong>HTML interativo</strong> com botão de cópia individual.
            </p>
        </div>
        <span class="badge badge-ok"><?= $totalChannels ?> canal(is) listado(s)</span>
    </div>
</div>

<div class="card form mb-16">
    <h3 style="font-size:15px;color:var(--text-bright);margin-bottom:12px;">1. Filtros & Configuração do Link</h3>
    <form method="get" class="form-grid">
        <?php if ($selectedIds): ?>
            <input type="hidden" name="selected_ids" value="<?= e(implode(',', $selectedIds)) ?>">
        <?php endif; ?>

        <div class="field">
            <label for="link_type">Qual link exportar abaixo do nome?</label>
            <select id="link_type" name="link_type" onchange="this.form.submit()">
                <option value="player" <?= $linkType === 'player' ? 'selected' : '' ?>>
                    Link do Player / Incorporação (ex: ?v=slug) [Recomendado]
                </option>
                <option value="stream" <?= $linkType === 'stream' ? 'selected' : '' ?>>
                    URL de Origem / Stream direto (campo url original)
                </option>
                <option value="backup" <?= $linkType === 'backup' ? 'selected' : '' ?>>
                    URL de Backup / Reserva (campo url_backup)
                </option>
            </select>
            <p class="hint">Define se abaixo do nome sairá o link do player ou a URL direta de origem.</p>
        </div>

        <div class="field">
            <label for="category">Filtrar por categoria</label>
            <select id="category" name="category" onchange="this.form.submit()" <?= $selectedIds ? 'disabled' : '' ?>>
                <option value="">Todas as categorias</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>>
                        <?= e($c) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="hint">Exporte apenas uma categoria específica se desejar.</p>
        </div>

        <div class="field">
            <label for="status">Status do canal</label>
            <select id="status" name="status" onchange="this.form.submit()" <?= $selectedIds ? 'disabled' : '' ?>>
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Todos (No Ar e Desativados)</option>
                <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Somente canais No Ar (Ativos)</option>
                <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Somente canais Desativados</option>
            </select>
        </div>

        <div class="field" style="display:flex;flex-direction:column;justify-content:flex-end;">
            <?php if ($selectedIds): ?>
                <div style="margin-bottom:6px;">
                    <span class="badge badge-warn">Exportando <?= count($selectedIds) ?> canais selecionados manualmente</span>
                </div>
                <a href="channel-export.php" class="btn ghost small">Limpar seleção e exportar todos</a>
            <?php else: ?>
                <button type="submit" class="btn ghost">Aplicar filtros</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card mb-16">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:18px;">
        <div>
            <h3 style="font-size:15px;color:var(--text-bright);margin-bottom:4px;">2. Opções de Download & Cópia</h3>
            <p class="hint">Escolha o formato desejado para baixar ou visualize o resultado abaixo.</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <?php
            $queryBase = http_build_query([
                'link_type' => $linkType,
                'category' => $cat,
                'status' => $status,
                'selected_ids' => $selectedIds ? implode(',', $selectedIds) : '',
            ]);
            ?>
            <a href="channel-export.php?action=download_txt&<?= $queryBase ?>" class="btn primary">
                📥 Baixar Arquivo .TXT
            </a>
            <a href="channel-export.php?action=download_html&<?= $queryBase ?>" class="btn">
                🌐 Baixar Arquivo .HTML (com Botões Copiar)
            </a>
            <a href="channel-export.php?action=view_html&<?= $queryBase ?>" target="_blank" rel="noopener" class="btn ghost">
                👁️ Abrir HTML em Nova Aba
            </a>
            <button type="button" class="btn ghost" data-copy-target="#txt-area">
                📋 Copiar Tudo em TXT
            </button>
        </div>
    </div>

    <!-- Área de Texto com os canais prontos -->
    <div class="field">
        <label for="txt-area" style="display:flex;justify-content:space-between;">
            <span>Conteúdo no formato TXT (Nome e abaixo o link do canal):</span>
            <span class="hint"><?= $totalChannels ?> canais formatados</span>
        </label>
        <textarea id="txt-area" class="code-area" readonly style="min-height:220px;" data-select><?= e($txtPreview) ?></textarea>
    </div>
</div>

<!-- Prévia Visual dos Canais com botão Copiar ao lado -->
<div class="card">
    <div class="toolbar">
        <div>
            <h3 style="font-size:15px;color:var(--text-bright);margin-bottom:4px;">3. Prévia com Botão de Cópia Individual</h3>
            <p class="hint">Copie o link direto de qualquer canal com 1 clique.</p>
        </div>
    </div>

    <?php if (!$channels): ?>
        <div class="empty">Nenhum canal encontrado para os filtros informados.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th style="width:240px;">Canal</th>
                    <th>Link Formatado (Abaixo do Nome)</th>
                    <th style="width:140px;text-align:right;">Ação</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($channels as $idx => $c): ?>
                    <?php
                    $channelLink = channels_resolve_export_url($c, $linkType, $base);
                    ?>
                    <tr>
                        <td>
                            <strong style="color:var(--text-bright);font-size:14.5px;"><?= e($c['name']) ?></strong>
                            <div class="sub mono" style="margin-top:2px;">?v=<?= e($c['slug']) ?></div>
                            <?php if (!empty($c['category'])): ?>
                                <span class="badge badge-muted" style="margin-top:4px;"><?= e($c['category']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-size:12px;color:var(--muted);margin-bottom:2px;">Link:</div>
                            <code class="mono" style="color:#8ed6ff;word-break:break-all;font-size:12.5px;display:block;">
                                <?= e($channelLink) ?>
                            </code>
                        </td>
                        <td style="text-align:right;">
                            <button type="button" class="btn ghost small" data-copy="<?= e($channelLink) ?>" style="border-color:rgba(0,240,255,0.3);color:var(--cyan);">
                                Copiar link
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php admin_footer(); ?>
