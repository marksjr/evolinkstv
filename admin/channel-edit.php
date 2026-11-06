<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

$user = require_auth($pdo);

$id = (int)($_GET['id'] ?? 0);
if (is_post()) {
    $id = (int)($_POST['id'] ?? 0);
}

$channel = [
    'id'          => 0,
    'slug'        => '',
    'name'        => '',
    'url'         => '',
    'url_backup'  => '',
    'category'    => '',
    'player_type' => 'auto',
    'drm_key'     => '',
    'popup_ads'   => '',
    'sort_order'  => 0,
    'active'      => 1,
];

if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM channels WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        exit('Canal não encontrado.');
    }

    $channel = $row;
}

$errors = [];

if (is_post()) {
    csrf_check();

    $channel['slug'] = trim(input('slug'));
    $channel['name'] = input('name');
    $channel['url'] = input('url');
    $channel['url_backup'] = input('url_backup');
    $channel['category'] = input('category');
    $pType = input('player_type');
    $channel['player_type'] = in_array($pType, ['auto', 'native', 'iframe'], true) ? $pType : 'auto';
    $channel['drm_key'] = input('drm_key');
    $channel['popup_ads'] = (string)($_POST['popup_ads'] ?? '');
    $channel['sort_order'] = (int)($_POST['sort_order'] ?? 0);
    $channel['active'] = isset($_POST['active']) ? 1 : 0;

    if ($channel['slug'] === '') {
        $channel['slug'] = slugify_camel($channel['name']);
    }

    if (!valid_slug($channel['slug'])) {
        $errors[] = 'Slug inválido. Use letras, números, hífen ou underline (1 a 64 caracteres).';
    }

    if ($channel['name'] === '' || mb_strlen($channel['name']) > 120) {
        $errors[] = 'Nome obrigatório, com no máximo 120 caracteres.';
    }

    if (!valid_http_url($channel['url'])) {
        $errors[] = 'Link do player inválido. Use uma URL http:// ou https://.';
    }

    if ($channel['url_backup'] !== '' && !valid_http_url($channel['url_backup'])) {
        $errors[] = 'Link alternativo inválido. Deixe vazio ou use http:// / https://.';
    }

    if (mb_strlen($channel['category']) > 60) {
        $errors[] = 'Categoria deve ter no máximo 60 caracteres.';
    }

    if ($channel['sort_order'] < 0 || $channel['sort_order'] > 99999) {
        $errors[] = 'Ordem deve ficar entre 0 e 99999.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM channels WHERE slug = ? AND id <> ? LIMIT 1');
        $stmt->execute([$channel['slug'], (int)$channel['id']]);

        if ($stmt->fetch()) {
            $errors[] = 'Este slug já está em uso por outro canal.';
        }
    }

    if (!$errors) {
        if ((int)$channel['id'] > 0) {
            $stmt = $pdo->prepare(
                'UPDATE channels
                 SET slug = ?, name = ?, url = ?, url_backup = ?, category = ?,
                     player_type = ?, drm_key = ?, popup_ads = ?,
                     sort_order = ?, active = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $stmt->execute([
                $channel['slug'],
                $channel['name'],
                $channel['url'],
                $channel['url_backup'] !== '' ? $channel['url_backup'] : null,
                $channel['category'] !== '' ? $channel['category'] : null,
                $channel['player_type'],
                $channel['drm_key'] !== '' ? $channel['drm_key'] : null,
                $channel['popup_ads'] !== '' ? $channel['popup_ads'] : null,
                $channel['sort_order'],
                $channel['active'],
                (int)$channel['id'],
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO channels (slug, name, url, url_backup, category, player_type, drm_key, popup_ads, sort_order, active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $channel['slug'],
                $channel['name'],
                $channel['url'],
                $channel['url_backup'] !== '' ? $channel['url_backup'] : null,
                $channel['category'] !== '' ? $channel['category'] : null,
                $channel['player_type'],
                $channel['drm_key'] !== '' ? $channel['drm_key'] : null,
                $channel['popup_ads'] !== '' ? $channel['popup_ads'] : null,
                $channel['sort_order'],
                $channel['active'],
            ]);
        }

        redirect('channels.php?msg=saved');
    }
}

$categories = $pdo->query(
    "SELECT DISTINCT category FROM channels
     WHERE category IS NOT NULL AND category <> ''
     ORDER BY category ASC"
)->fetchAll(PDO::FETCH_COLUMN);

$embedUrl = app_base_url() . '/?v=' . rawurlencode((string)$channel['slug']);
$previewUrl = '../?v=' . rawurlencode((string)$channel['slug']) . '&preview=' . rawurlencode(preview_token((string)$channel['slug']));
$embedB64 = base64_encode($embedUrl);
$embedCode = <<<HTML
<div id="tv-player-{$channel['slug']}"></div>
<script>
(function () {
    var encoded = "{$embedB64}";
    var frame = document.createElement("iframe");
    frame.src = atob(encoded);
    frame.style.cssText = "position:absolute;inset:0;width:100%;height:100%;border:0";
    frame.allow = "autoplay; fullscreen; encrypted-media; picture-in-picture";
    frame.allowFullscreen = true;
    frame.referrerPolicy = "strict-origin-when-cross-origin";

    var host = document.getElementById("tv-player-{$channel['slug']}");
    host.style.cssText = "position:relative;padding-top:56.25%;background:#000";
    host.appendChild(frame);
})();
</script>
HTML;

admin_header($id > 0 ? 'Editar canal' : 'Novo canal', $user);
?>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <ul class="errors">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" class="card form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$channel['id'] ?>">

    <div class="row-actions" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid var(--line);">
        <div class="row-actions">
            <button class="btn primary" type="submit">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                    <polyline points="17 21 17 13 7 13 7 21"/>
                    <polyline points="7 3 7 8 15 8"/>
                </svg>
                <span>Salvar canal</span>
            </button>
            <a class="btn ghost" href="channels.php">Cancelar</a>
        </div>
        <?php if ($channel['slug'] !== ''): ?>
            <a class="preview-link" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
                    <polyline points="15 3 21 3 21 9"/>
                    <line x1="10" y1="14" x2="21" y2="3"/>
                </svg>
                <span>Abrir prévia</span>
            </a>
        <?php endif; ?>
    </div>

    <div class="form-grid">
        <div class="field">
            <label for="name">Nome do canal</label>
            <input id="name" name="name" type="text" maxlength="120" required
                   value="<?= e($channel['name']) ?>" data-slug-source>
        </div>

        <div class="field">
            <label for="slug">Slug (URL)</label>
            <input id="slug" name="slug" type="text" maxlength="64"
                   value="<?= e($channel['slug']) ?>"
                   placeholder="espn" data-slug-target>
            <p class="hint">Ex.: <code>espn</code> → <code>?v=espn</code> ou <code>bandSportsAlt2</code> → <code>?v=bandSportsAlt2</code></p>
        </div>
    </div>

    <div class="field">
        <label for="url">Link da transmissão ou player</label>
        <input id="url" name="url" type="url" maxlength="2048" required
               value="<?= e($channel['url']) ?>"
               placeholder="https://... (.m3u8, .mpd ou link de player)">
        <p class="hint">
            Suporta links diretos <code>.m3u8</code> (HLS) e <code>.mpd</code> (DASH) via Player Nativo interno, ou páginas web com player via iframe.
        </p>
        <div id="stream-detector-badge" class="mt-8" style="display:none;"></div>
    </div>

    <div class="form-grid">
        <div class="field">
            <label for="player_type">Modo de Player</label>
            <select id="player_type" name="player_type">
                <option value="auto" <?= ($channel['player_type'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>
                    ⚡ Automático (Detecta .m3u8 / .mpd para Player Nativo, outros para Iframe)
                </option>
                <option value="native" <?= ($channel['player_type'] ?? '') === 'native' ? 'selected' : '' ?>>
                    🎬 Player Nativo Interno (Clappr / DASH Shaka / HLS)
                </option>
                <option value="iframe" <?= ($channel['player_type'] ?? '') === 'iframe' ? 'selected' : '' ?>>
                    🌐 Iframe Externo (Página que já possui player)
                </option>
            </select>
            <p class="hint">Defina manual ou deixe no Automático para auto-detecção.</p>
        </div>

        <div class="field" id="drm-container">
            <label for="drm_key">Chave DRM ClearKey DASH (Opcional)</label>
            <input id="drm_key" name="drm_key" type="text" maxlength="255"
                   value="<?= e($channel['drm_key'] ?? '') ?>"
                   placeholder="kid:key (ex.: 51c0ef23...:8823f713...)">
            <p class="hint">Usado em transmissões MPEG-DASH CENC (.mpd). Formato: <code>kid:key</code>.</p>
        </div>
    </div>

    <div class="field">
        <label for="url_backup">Link alternativo (opcional)</label>
        <input id="url_backup" name="url_backup" type="url" maxlength="2048"
               value="<?= e($channel['url_backup']) ?>"
               placeholder="https://...">
        <p class="hint">Tentado automaticamente se a transmissão principal não responder.</p>
    </div>

    <div class="field">
        <label for="popup_ads">Anúncios, Popups & Analytics deste canal (Opcional)</label>
        <textarea id="popup_ads" name="popup_ads" class="code-area" rows="3"
                  placeholder="<!-- Códigos de anúncios, popups, popunders ou scripts específicos deste canal -->"><?= e($channel['popup_ads'] ?? '') ?></textarea>
        <p class="hint">Executa dentro do player deste canal (soma com os anúncios e popups globais definidos em <a href="settings.php" target="_blank">Configurações</a>).</p>
    </div>

    <div class="form-grid">
        <div class="field">
            <label for="category">Categoria</label>
            <input id="category" name="category" type="text" maxlength="60"
                   value="<?= e($channel['category']) ?>" list="categories"
                   placeholder="Esportes">
            <datalist id="categories">
                <?php foreach ($categories as $category): ?>
                    <option value="<?= e($category) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="field">
            <label for="sort_order">Ordem</label>
            <input id="sort_order" name="sort_order" type="number" min="0" max="99999"
                   value="<?= (int)$channel['sort_order'] ?>">
        </div>
    </div>

    <label class="check">
        <input type="checkbox" name="active" value="1" <?= (int)$channel['active'] === 1 ? 'checked' : '' ?>>
        <span>
            <strong>Canal No Ar (Transmissão Ativa)</strong>
            <span>Desativado, o link público retorna 404 e interrompe o stream.</span>
        </span>
    </label>

    <?php if ($channel['slug'] !== ''): ?>
        <div class="field">
            <label>Link final para o WordPress</label>
            <div class="copy-row">
                <input type="text" readonly value="<?= e($embedUrl) ?>" data-select>
                <button type="button" class="btn ghost" data-copy="<?= e($embedUrl) ?>">Copiar</button>
                <a class="preview-link" href="<?= e($previewUrl) ?>" target="_blank" rel="noopener">
                    Abrir prévia
                </a>
            </div>
            <p class="hint">
                Use este link no iframe do WordPress. O domínio do WordPress precisa estar em
                <a href="settings.php">Configurações → Domínios autorizados</a>.
            </p>
        </div>

        <div class="field">
            <label for="embed-code">Código embed ofuscado (WordPress)</label>
            <textarea id="embed-code" class="code-area" readonly rows="12"><?= e($embedCode) ?></textarea>
            <div class="row-actions mt-12">
                <button type="button" class="btn ghost" data-copy-target="#embed-code">Copiar código</button>
            </div>
            <p class="hint">
                Cole em um bloco HTML personalizado. A URL vai em Base64: ofuscação casual, não segurança.
            </p>
        </div>
    <?php endif; ?>

    <div class="row-actions">
        <button class="btn primary" type="submit">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                <polyline points="17 21 17 13 7 13 7 21"/>
                <polyline points="7 3 7 8 15 8"/>
            </svg>
            <span>Salvar canal</span>
        </button>
        <a class="btn ghost" href="channels.php">Cancelar</a>
    </div>
</form>

<script>
(function() {
    var urlInput = document.getElementById('url');
    var playerSelect = document.getElementById('player_type');
    var badge = document.getElementById('stream-detector-badge');
    var drmContainer = document.getElementById('drm-container');

    function checkStream() {
        if (!urlInput || !badge) return;
        var val = urlInput.value.trim().toLowerCase();
        var isM3u8 = val.indexOf('.m3u8') !== -1;
        var isMpd = val.indexOf('.mpd') !== -1;

        if (isM3u8 || isMpd) {
            badge.style.display = 'inline-block';
            badge.className = 'badge badge-ok';
            badge.innerHTML = (isMpd ? '🎬 Transmissão MPEG-DASH (.mpd)' : '⚡ Transmissão HLS (.m3u8)') +
                ' detectada! O Player Nativo interno (Clappr/Shaka) será utilizado.';
            if (isMpd && drmContainer) {
                drmContainer.style.outline = '1px solid var(--accent-cyan, #00f0ff)';
                drmContainer.style.borderRadius = '6px';
            }
        } else if (val.length > 5) {
            badge.style.display = 'inline-block';
            badge.className = 'badge badge-muted';
            badge.innerHTML = '🌐 Link web comum detectado. Será aberto via iframe seguro com proteção.';
            if (drmContainer) drmContainer.style.outline = 'none';
        } else {
            badge.style.display = 'none';
            if (drmContainer) drmContainer.style.outline = 'none';
        }
    }

    if (urlInput) {
        urlInput.addEventListener('input', checkStream);
        urlInput.addEventListener('change', checkStream);
        checkStream();
    }
})();
</script>

<?php admin_footer(); ?>
