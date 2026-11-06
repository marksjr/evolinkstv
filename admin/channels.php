<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

$user = require_auth($pdo);

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM channels WHERE id = ?');
            $stmt->execute([$id]);
        }
        redirect('channels.php?msg=deleted');
    }

    if ($action === 'bulk_delete') {
        $ids = array_filter(
            array_map('intval', (array)($_POST['selected_ids'] ?? [])),
            static fn(int $id): bool => $id > 0
        );

        if ($ids) {
            $inQuery = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM channels WHERE id IN ($inQuery)");
            $pdo->beginTransaction();
            try {
                $stmt->execute(array_values($ids));
                $pdo->commit();
                redirect('channels.php?msg=bulk_deleted&count=' . count($ids));
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        redirect('channels.php');
    }
}

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$cat = mb_substr(trim((string)($_GET['category'] ?? '')), 0, 60);

// Carrega todos os canais para live search instantâneo no frontend
$sql = 'SELECT * FROM channels ORDER BY sort_order ASC, name ASC LIMIT 1000';
$stmt = $pdo->query($sql);
$channels = $stmt->fetchAll();

$categories = $pdo->query(
    "SELECT DISTINCT category FROM channels
     WHERE category IS NOT NULL AND category <> ''
     ORDER BY category ASC"
)->fetchAll(PDO::FETCH_COLUMN);

$base = app_base_url() . '/';
$msg = (string)($_GET['msg'] ?? '');

admin_header('Canais', $user);
?>

<?php if ($msg === 'saved'): ?>
    <div class="alert alert-ok">Canal salvo.</div>
<?php elseif ($msg === 'deleted'): ?>
    <div class="alert alert-ok">Canal removido.</div>
<?php elseif ($msg === 'bulk_deleted'): ?>
    <div class="alert alert-ok"><?= (int)($_GET['count'] ?? 0) ?> canal(is) excluído(s) com sucesso.</div>
<?php endif; ?>

<?php if (!$channels): ?>
    <div class="toolbar">
        <div></div>
        <div class="toolbar-actions">
            <a class="btn ghost" href="channel-import.php">Importar lista</a>
            <a class="btn primary" href="channel-edit.php">Novo canal</a>
        </div>
    </div>

    <div class="card empty">
        Nenhum canal cadastrado no momento.
        <div class="mt-12"><a class="btn primary" href="channel-edit.php">Cadastrar o primeiro</a></div>
    </div>
<?php else: ?>
    <div class="toolbar">
        <form method="get" class="search" id="channel-search-form" autocomplete="off">
            <div class="search-wrap">
                <input type="text" name="q" id="channel-search-input" value="<?= e($q) ?>" placeholder="Buscar por nome ou slug" autocomplete="off">
                <button type="button" id="channel-search-clear" class="search-clear-btn" title="Limpar busca">&times;</button>
            </div>
            <select name="category" id="channel-category-select">
                <option value="">Todas as categorias</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>>
                        <?= e($c) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit">Buscar</button>
            <a class="btn ghost small <?= ($q !== '' || $cat !== '') ? '' : 'hidden' ?>" id="channel-reset-btn" href="channels.php" title="Limpar filtros">Limpar</a>
            <span id="channel-search-count" class="badge badge-muted search-count"></span>
        </form>
        <div class="toolbar-actions">
            <a class="btn ghost" href="channel-export.php">Exportar lista</a>
            <a class="btn ghost" href="mass-replace.php">Mudar links</a>
            <a class="btn ghost" href="channel-import.php">Importar lista</a>
            <a class="btn primary" href="channel-edit.php">Novo canal</a>
        </div>
    </div>

    <form method="post" id="bulk-form" data-confirm="Tem certeza que deseja excluir permanentemente todos os canais selecionados?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk_delete">
    </form>

    <div id="bulk-actions" class="card bulk-bar">
        <div class="d-flex align-center gap-10">
            <span class="badge badge-warn" id="bulk-count">0</span>
            <strong>canal(is) selecionado(s)</strong>
        </div>
        <div class="d-flex align-center gap-8">
            <button type="submit" form="bulk-form" formaction="channel-export.php" formmethod="post" class="btn ghost small btn-cyan">
                Exportar selecionados
            </button>
            <button type="submit" form="bulk-form" id="bulk-delete-btn" class="btn danger small">
                Excluir selecionados
            </button>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th class="th-select">
                    <input type="checkbox" id="select-all" title="Selecionar todos" class="cursor-pointer">
                </th>
                <th>Canal</th>
                <th>Status</th>
                <th>Categoria</th>
                <th>Atualizado</th>
                <th>Ações</th>
            </tr>
            </thead>
            <tbody>
            <tr id="live-empty-row" class="live-empty-row hidden">
                <td colspan="6">
                    <div class="live-empty-title">Nenhum canal encontrado</div>
                    <div class="live-empty-desc">Nenhum resultado corresponde a: <strong id="live-empty-term" class="live-empty-term"></strong></div>
                    <div class="live-empty-btn-wrap">
                        <button type="button" class="btn ghost small" id="live-empty-clear">Limpar busca</button>
                    </div>
                </td>
            </tr>
            <?php foreach ($channels as $channel): ?>
                <?php
                $embedUrl = $base . '?v=' . rawurlencode((string)$channel['slug']);
                $previewUrl = '../?v=' . rawurlencode((string)$channel['slug']) . '&preview=' . rawurlencode(preview_token((string)$channel['slug']));
                ?>
                <tr data-category="<?= e((string)($channel['category'] ?? '')) ?>">
                    <td class="td-select">
                        <input type="checkbox" name="selected_ids[]" value="<?= (int)$channel['id'] ?>" form="bulk-form" class="channel-checkbox cursor-pointer">
                    </td>
                    <td>
                        <div class="name"><?= e($channel['name']) ?></div>
                        <div class="sub mono" style="display:flex;align-items:center;gap:6px;margin-top:2px;">
                            <span>?v=<?= e($channel['slug']) ?></span>
                            <?php
                            $pType = (string)($channel['player_type'] ?? 'auto');
                            $isNative = ($pType === 'native' || ($pType === 'auto' && is_stream_url((string)$channel['url'])));
                            ?>
                            <span class="badge <?= $isNative ? 'badge-ok' : 'badge-muted' ?>" style="font-size:10px;padding:1px 6px;">
                                <?= $isNative ? '🎬 Nativo' : '🌐 Iframe' ?>
                            </span>
                        </div>
                    </td>
                    <td>
                        <span class="badge <?= (int)$channel['active'] === 1 ? 'badge-ok' : 'badge-off' ?>">
                            <?= (int)$channel['active'] === 1 ? 'No Ar' : 'Desativado' ?>
                        </span>
                    </td>
                    <td class="sub">
                        <?php if (!empty($channel['category'])): ?>
                            <a href="channels.php?category=<?= rawurlencode((string)$channel['category']) ?>" class="cat-link" title="Filtrar por <?= e($channel['category']) ?>">
                                <span class="badge badge-muted cat-badge"><?= e($channel['category']) ?></span>
                            </a>
                        <?php else: ?>
                            —
                        <?php endif; ?>
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

                            <form method="post" data-confirm="Remover o canal <?= e($channel['name']) ?>?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$channel['id'] ?>">
                                <button type="submit" class="btn danger small">Excluir</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php admin_footer(); ?>
