<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

$user = require_auth($pdo);

$errors = [];
$successMsg = '';
$previewItems = [];
$step = 'input'; // 'input' | 'preview' | 'imported'
$stats = ['total' => 0, 'new' => 0, 'alternatives' => 0];

$openrouterReady = openrouter_is_configured($pdo);

if (is_post()) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'parse') {
        $rawText = trim((string)($_POST['raw_text'] ?? ''));
        $useLlm = isset($_POST['use_llm']) && (string)$_POST['use_llm'] === '1';

        // Suporte a upload de arquivo .txt
        if (isset($_FILES['txt_file']) && is_array($_FILES['txt_file']) && $_FILES['txt_file']['error'] === UPLOAD_ERR_OK) {
            $uploadedPath = (string)$_FILES['txt_file']['tmp_name'];
            if (is_uploaded_file($uploadedPath)) {
                $fileContent = file_get_contents($uploadedPath);
                if (is_string($fileContent) && $fileContent !== '') {
                    $rawText = ($rawText !== '' ? $rawText . "\n" : '') . $fileContent;
                }
            }
        }

        if ($rawText === '') {
            $errors[] = 'Informe uma lista de canais/links no campo de texto ou envie um arquivo .txt.';
        } else {
            $parsedChannels = [];

            if ($useLlm) {
                if (!$openrouterReady) {
                    $errors[] = 'A chave da API OpenRouter não está configurada em Configurações. Usando parser direto.';
                    $parsedChannels = parse_channel_lines($rawText);
                } else {
                    $llmRes = llm_parse_channel_list($pdo, $rawText);
                    if ($llmRes['ok']) {
                        $parsedChannels = $llmRes['channels'];
                    } else {
                        $errors[] = 'Erro na IA OpenRouter: ' . ($llmRes['error'] ?? 'Falha ao processar') . '. O parser direto foi executado como contingência.';
                        $parsedChannels = parse_channel_lines($rawText);
                    }
                }
            } else {
                $parsedChannels = parse_channel_lines($rawText);
            }

            if (!$parsedChannels && !$errors) {
                $errors[] = 'Nenhum link ou canal válido pôde ser extraído. Verifique o formato dos links informados.';
            } else {
                // Monta o preview e simula a regra de nomes e slugs alternativos (ESPN Alt 2 -> espnAlt2)
                $reserved = [];
                foreach ($parsedChannels as $item) {
                    $name = trim((string)($item['name'] ?? ''));
                    $url = trim((string)($item['url'] ?? ''));
                    $urlBackup = trim((string)($item['url_backup'] ?? ''));
                    $category = trim((string)($item['category'] ?? ''));
                    $baseSlug = trim((string)($item['suggested_slug'] ?? $item['slug'] ?? ''));

                    if ($url === '' || !valid_http_url($url)) {
                        continue;
                    }

                    if ($name === '') {
                        $name = 'Canal ' . (count($previewItems) + 1);
                    }

                    $resolution = resolve_channel_duplicate($pdo, $name, $baseSlug, null, $reserved);
                    $finalName = $resolution['name'];
                    $finalSlug = $resolution['slug'];
                    $isAlt = $resolution['is_alt'];
                    $baseSlugClean = $resolution['base_slug'];

                    $reserved[] = $finalSlug;

                    if ($isAlt) {
                        $stats['alternatives']++;
                    } else {
                        $stats['new']++;
                    }
                    $stats['total']++;

                    $pType = trim((string)($item['player_type'] ?? ''));
                    if (!in_array($pType, ['native', 'iframe'], true)) {
                        $pType = detect_stream_player_type($url);
                    }
                    $drmKey = trim((string)($item['drm_key'] ?? ''));

                    $previewItems[] = [
                        'name'        => $finalName,
                        'slug'        => $finalSlug,
                        'base_slug'   => $baseSlugClean,
                        'is_alt'      => $isAlt,
                        'url'         => $url,
                        'url_backup'  => $urlBackup,
                        'category'    => $category,
                        'player_type' => $pType,
                        'drm_key'     => $drmKey,
                    ];
                }

                if ($previewItems) {
                    $step = 'preview';
                }
            }
        }
    } elseif ($action === 'confirm_import') {
        $encodedItems = (string)($_POST['items_json'] ?? '');
        $items = json_decode($encodedItems, true);

        if (!is_array($items) || !$items) {
            $errors[] = 'Nenhum item válido para importação.';
        } else {
            try {
                $result = import_channels_batch($pdo, $items);
                $successMsg = sprintf('%d canais cadastrados com sucesso!', $result['inserted']);
                $step = 'imported';
            } catch (Throwable $e) {
                $errors[] = 'Erro ao gravar no banco de dados: ' . $e->getMessage();
            }
        }
    }
}

admin_header('Importar Canais em Massa', $user);
?>

<?php if ($successMsg): ?>
    <div class="alert alert-ok"><?= e($successMsg) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <ul class="errors">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($step === 'input'): ?>
    <div class="card">
        <div class="toolbar" style="margin-bottom:12px;">
            <div>
                <h2 class="section-title">Importação de Links e Canais</h2>
                <p class="hint">
                    Cole uma lista de canais ou envie um arquivo <code>.txt</code>.
                    Se o canal já existir no banco (ex.: <code>ESPN</code> ou <code>BandSports</code>),
                    serão criadas automaticamente as opções com nome sequencial (ex.: <code>ESPN Alt 2</code>, <code>BandSports Alt 2</code>)
                    e slug em camelCase (<code>espnAlt2</code>, <code>bandSportsAlt2</code>).
                </p>
            </div>
            <span class="badge <?= $openrouterReady ? 'badge-ok' : 'badge-off' ?>">
                <?= $openrouterReady ? 'OpenRouter IA Pronto' : 'OpenRouter Sem Chave' ?>
            </span>
        </div>

        <form method="post" enctype="multipart/form-data" class="form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="parse">

            <div class="field">
                <label for="raw_text">Lista de Canais / Links</label>
                <textarea id="raw_text" name="raw_text" rows="12" class="code-area"
                          placeholder="Cole sua lista aqui. Formatos aceitos:
ESPN - http://site.com/canal.php?id=espn
SBT | http://site.com/canal.php?id=sbt
Globo SP, http://site.com/canal.php?id=globo, Abertos
http://site.com/canais.php?v=espn2
http://site.com/canal.php?id=sbt"></textarea>
                <p class="hint">Aceita qualquer formato contendo nome e URL, ou apenas URLs diretas.</p>
            </div>

            <div class="field">
                <label for="txt_file">Ou envie um arquivo .txt</label>
                <input id="txt_file" name="txt_file" type="file" accept=".txt,.m3u,.m3u8,.csv">
            </div>

            <label class="check mt-12">
                <input type="checkbox" name="use_llm" value="1" <?= $openrouterReady ? 'checked' : '' ?>>
                <span>
                    <strong>Usar Inteligência Artificial OpenRouter</strong>
                    <span>Analisa textos despadronizados, categoriza canais e extrai nomes limpos automaticamente.</span>
                </span>
            </label>

            <div class="row-actions mt-16">
                <button type="submit" class="btn primary">Processar e Revisar Lista</button>
                <a href="channels.php" class="btn ghost">Cancelar</a>
            </div>
        </form>
    </div>

    <div class="card mt-16">
        <h3 style="font-size:15px;margin-bottom:8px;">💡 Como funciona a regra de canais repetidos:</h3>
        <ul style="margin:0;padding-left:20px;color:var(--muted);font-size:14px;line-height:1.7;">
            <li>Se o canal <code>ESPN</code> ainda não existir no banco, ele é cadastrado com nome <code>ESPN</code> e slug <code>espn</code>.</li>
            <li>Se você cadastrar outro link de <code>ESPN</code>, o nome vira <strong><code>ESPN Alt 2</code></strong> e o slug gerado será <strong><code>espnAlt2</code></strong>.</li>
            <li>Um terceiro link para <code>ESPN</code> virará <strong><code>ESPN Alt 3</code></strong> com slug <strong><code>espnAlt3</code></strong>, e assim por diante.</li>
            <li>Para canais compostos como <code>BandSports</code>, o slug segue camelCase: <strong><code>bandSports</code></strong> e alternativas <strong><code>bandSportsAlt2</code></strong>, <strong><code>bandSportsAlt3</code></strong>...</li>
        </ul>
    </div>

<?php elseif ($step === 'preview'): ?>
    <div class="card">
        <div class="toolbar" style="margin-bottom:16px;">
            <div>
                <h2 class="section-title">Prévia da Importação</h2>
                <p class="hint">
                    Foram identificados <strong><?= count($previewItems) ?> canais</strong>.
                    Revise os nomes e slugs calculados antes de confirmar a gravação.
                </p>
            </div>
            <div style="display:flex;gap:8px;">
                <span class="badge badge-ok"><?= $stats['new'] ?> Novos</span>
                <?php if ($stats['alternatives'] > 0): ?>
                    <span class="badge badge-warn"><?= $stats['alternatives'] ?> Alternativas (Alt 2, Alt 3...)</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Canal</th>
                    <th>Slug Atribuído</th>
                    <th>Tipo de Player</th>
                    <th>Categoria</th>
                    <th>URL do Stream / Player</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($previewItems as $item): ?>
                    <tr>
                        <td><strong><?= e($item['name']) ?></strong></td>
                        <td>
                            <code class="<?= $item['is_alt'] ? 'badge badge-warn' : 'mono' ?>">
                                <?= e($item['slug']) ?>
                            </code>
                        </td>
                        <td>
                            <?php if ($item['player_type'] === 'native'): ?>
                                <span class="badge badge-ok" title="Player Nativo Clappr / DASH Shaka">🎬 Nativo (Stream)</span>
                            <?php else: ?>
                                <span class="badge badge-muted" title="Player web via Iframe">🌐 Iframe</span>
                            <?php endif; ?>
                            <?php if (!empty($item['drm_key'])): ?>
                                <span class="badge badge-warn" title="DRM ClearKey detectada">🔑 DRM</span>
                            <?php endif; ?>
                        </td>
                        <td class="sub"><?= e($item['category'] ?: '—') ?></td>
                        <td class="sub mono" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= e($item['url']) ?>
                        </td>
                        <td>
                            <?php if ($item['is_alt']): ?>
                                <span class="badge badge-warn">Opção Alternativa (<?= e($item['slug']) ?>)</span>
                            <?php else: ?>
                                <span class="badge badge-ok">Canal Novo</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <form method="post" class="mt-16">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm_import">
            <input type="hidden" name="items_json" value="<?= e(json_encode($previewItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">

            <div class="row-actions">
                <button type="submit" class="btn primary">Confirmar e Cadastrar <?= count($previewItems) ?> Canais</button>
                <a href="channel-import.php" class="btn ghost">Voltar / Descartar</a>
            </div>
        </form>
    </div>

<?php elseif ($step === 'imported'): ?>
    <div class="card empty">
        <h2 style="font-size:20px;color:#6ee7a0;margin-bottom:12px;">✅ Importação Concluída com Sucesso!</h2>
        <p class="hint">Todos os canais foram processados e salvos no banco de dados.</p>
        <div class="mt-16" style="display:flex;gap:12px;justify-content:center;">
            <a href="channels.php" class="btn primary">Ver Lista de Canais</a>
            <a href="channel-import.php" class="btn ghost">Importar Mais Canais</a>
            <a href="mass-replace.php" class="btn ghost">Mudar Links em Massa</a>
        </div>
    </div>
<?php endif; ?>

<?php admin_footer(); ?>
