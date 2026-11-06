<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

$user = require_auth($pdo);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];
$successMsg = '';

// Domínios padrão do sistema para facilitar preenchimento
$systemHost = parse_url(app_base_url(), PHP_URL_HOST) ?: 'localhost';
$systemDomains = setting($pdo, 'allowed_domains', '');
$defaultDomains = $systemDomains !== '' ? $systemDomains : $systemHost;
$systemRedirect = setting($pdo, 'redirect_url', '');

$hasLocalPPhpFile = file_exists(APP_ROOT . '/p.php');
$localPPhpContent = $hasLocalPPhpFile ? file_get_contents(APP_ROOT . '/p.php') : '';

$file = [
    'id'              => 0,
    'name'            => '',
    'filename'        => 'p.php',
    'allowed_domains' => $defaultDomains,
    'block_direct'    => 1,
    'block_iframe'    => 1,
    'anti_adblock'    => 1,
    'anti_sandbox'    => 1,
    'anti_inspect'    => 1,
    'obfuscate'       => 0,
    'redirect_url'    => $systemRedirect,
    'popup_ads'       => '',
    'source_code'     => '',
];

if ($id > 0) {
    $existing = file_shield_get($pdo, $id);
    if ($existing) {
        $file = $existing;
    } else {
        $errors[] = 'Arquivo protegido não encontrado.';
    }
}

if (is_post()) {
    csrf_check();

    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'clean_code') {
        $source = (string)($_POST['source_code'] ?? '');
        $mode = (string)($_POST['clean_mode'] ?? 'ai');
        $model = trim((string)($_POST['model'] ?? 'deepseek/deepseek-chat'));

        if ($mode === 'deterministic') {
            $cleanResult = file_shield_clean_code_deterministic($source);
        } else {
            $cleanResult = file_shield_clean_code_ai($pdo, $source, $model);
        }

        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
            || isset($_POST['ajax']);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($cleanResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($cleanResult['ok']) {
            $file['source_code'] = $cleanResult['cleaned_code'];
            $successMsg = 'Código organizado com sucesso! (' . $cleanResult['summary'] . ')';
        } else {
            $errors[] = 'Falha ao organizar código: ' . ($cleanResult['error'] ?? 'Erro desconhecido');
        }
    } else {
        $file['name'] = trim((string)($_POST['name'] ?? ''));
        $file['filename'] = trim((string)($_POST['filename'] ?? 'canal.php'));
        $file['allowed_domains'] = normalize_domains((string)($_POST['allowed_domains'] ?? ''));
        $file['block_direct'] = isset($_POST['block_direct']) ? 1 : 0;
        $file['block_iframe'] = isset($_POST['block_iframe']) ? 1 : 0;
        $file['anti_adblock'] = isset($_POST['anti_adblock']) ? 1 : 0;
        $file['anti_sandbox'] = isset($_POST['anti_sandbox']) ? 1 : 0;
        $file['anti_inspect'] = isset($_POST['anti_inspect']) ? 1 : 0;
        $file['obfuscate'] = isset($_POST['obfuscate']) ? 1 : 0;
        $file['redirect_url'] = trim((string)($_POST['redirect_url'] ?? ''));
        $file['popup_ads'] = (string)($_POST['popup_ads'] ?? '');
        $file['source_code'] = (string)($_POST['source_code'] ?? '');

        if ($file['name'] === '') {
            $errors[] = 'Informe um nome de identificação para o arquivo/canal.';
        }

        if ($file['filename'] === '') {
            $file['filename'] = slugify($file['name']) . '.php';
        } elseif (!str_ends_with(strtolower($file['filename']), '.php')) {
            $file['filename'] .= '.php';
        }

        if ($file['redirect_url'] !== '' && !valid_http_url($file['redirect_url'])) {
            $errors[] = 'URL de redirecionamento inválida. Use http:// ou https://.';
        }

        if (!$errors) {
            try {
                $savedId = file_shield_save($pdo, $file, $id > 0 ? $id : null);
                $file['id'] = $savedId;
                $id = $savedId;
                $successMsg = 'Arquivo protegido e regras salvas com sucesso! Códigos atualizados abaixo.';
            } catch (Throwable $e) {
                $errors[] = 'Erro ao salvar no banco: ' . $e->getMessage();
            }
        }
    }
}

// Gera os trechos de código em tempo de execução
$fullPhpCode = file_shield_generate_full_file($file);
$topPhpSnippet = file_shield_generate_php_snippet($file);
$clientSnippet = file_shield_generate_client_snippet($file);

$pageTitle = $id > 0 ? 'Editar: ' . ($file['name'] ?: 'Arquivo Protegido') : 'Novo Arquivo Protegido';
admin_header($pageTitle, $user);
?>

<div class="card mb-16">
    <div class="toolbar" style="margin-bottom:0;">
        <div>
            <h2 class="section-title"><?= e($pageTitle) ?></h2>
            <p class="hint">
                Configure as diretrizes de blindagem para este canal. Ao salvar, você pode baixar o arquivo <code>.php</code> pronto ou copiar os snippets para o seu servidor.
            </p>
        </div>
        <div class="toolbar-actions">
            <a class="btn ghost" href="file-protect.php">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
                <span>Voltar para Lista</span>
            </a>
            <?php if ($id > 0): ?>
                <a class="btn primary" href="file-protect.php?action=download&id=<?= $id ?>">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    <span>Baixar <?= e($file['filename']) ?></span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($successMsg): ?>
    <div class="alert alert-ok"><?= e($successMsg) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-error">
        <ul class="errors">
            <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="alert mb-16" style="background:rgba(0, 240, 255, 0.05);border:1px solid rgba(0, 240, 255, 0.25);padding:16px 20px;border-radius:12px;">
    <h3 style="font-size:15px;color:var(--cyan);margin:0 0 6px;display:flex;align-items:center;gap:8px;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
        </svg>
        Arquitetura de Blindagem em Dupla Camada (Proteção de p.php)
    </h3>
    <div style="font-size:13px;line-height:1.6;color:var(--text);">
        <strong>1. Arquivo de Canais Fonte (ex.: <code>p.php</code>):</strong> Onde ficam suas URLs de transmissão e chaves DRM. Com o código gerado aqui, o seu <code>p.php</code> <u>só poderá ser aberto dentro deste sistema Evo Links TV</u>, impedindo qualquer acesso direto na URL ou roubo por sites concorrentes.<br>
        <strong>2. Seu Sistema Evo Links TV:</strong> Carrega o canal protegido em segurança e gera a URL final ofuscada (<code>?v=slug</code>) para ser incorporada no WordPress ou site do cliente autorizado.
    </div>
</div>

<form method="post" class="card form mb-16">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$file['id'] ?>">

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;border-bottom:1px solid var(--line);padding-bottom:8px;flex-wrap:wrap;gap:8px;">
        <h3 style="font-size:15px;color:var(--text-bright);margin:0;">
            1. Identificação do Arquivo
        </h3>
        <?php if ($hasLocalPPhpFile): ?>
            <button type="button" class="btn ghost small" id="btn-preset-pphp" style="font-size:12px;color:var(--cyan);border-color:var(--line-cyan);">
                ⚡ Preencher com Dados do p.php Local
            </button>
        <?php endif; ?>
    </div>

    <div class="form-grid">
        <div class="field">
            <label for="name">Nome / Identificador do Arquivo de Canais</label>
            <input id="name" name="name" type="text" value="<?= e($file['name'] ?: 'Grade de Canais (p.php)') ?>" required
                   placeholder="Ex.: Grade de Canais (p.php)">
            <p class="hint">Usado internamente para identificação no painel.</p>
        </div>

        <div class="field">
            <label for="filename">Nome do Arquivo (.php)</label>
            <input id="filename" name="filename" type="text" value="<?= e($file['filename'] ?: 'p.php') ?>" required
                   placeholder="Ex.: p.php ou canais/espn.php">
            <p class="hint">Nome que será dado ao arquivo ao baixar ou salvar.</p>
        </div>
    </div>

    <h3 style="font-size:15px;color:var(--text-bright);margin-top:20px;margin-bottom:14px;border-bottom:1px solid var(--line);padding-bottom:8px;">
        2. Configurações de Domínio e Redirecionamento
    </h3>

    <div class="form-grid">
        <div class="field">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:6px;">
                <label for="allowed_domains" style="margin-bottom:0;">Domínios Autorizados no Iframe (CSP frame-ancestors)</label>
                <button type="button" class="btn ghost small" id="btn-use-system-host" style="font-size:11px;padding:2px 8px;">
                    + Apenas Este Sistema (<?= e($systemHost) ?>)
                </button>
            </div>
            <input id="allowed_domains" name="allowed_domains" type="text" value="<?= e($file['allowed_domains']) ?>"
                   placeholder="Ex.: <?= e($systemHost) ?>, *.seusite.com">
            <p class="hint">
                Coloque o domínio deste sistema (<code><?= e($systemHost) ?></code>). Assim, o <code>p.php</code> só carregará quando embutido pelo seu sistema central!
            </p>
        </div>

        <div class="field">
            <label for="redirect_url">URL de Redirecionamento (se o acesso for negado)</label>
            <input id="redirect_url" name="redirect_url" type="url" value="<?= e($file['redirect_url']) ?>"
                   placeholder="https://site.com/aviso">
            <p class="hint">Para onde enviar quem tentar acessar o arquivo diretamente. Deixe vazio para exibir tela 403.</p>
        </div>
    </div>

    <h3 style="font-size:15px;color:var(--text-bright);margin-top:20px;margin-bottom:14px;border-bottom:1px solid var(--line);padding-bottom:8px;">
        3. Camadas de Proteção Ativas
    </h3>

    <div class="field-check-grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(280px, 1fr));gap:14px;">
        <label class="check-card" style="display:flex;align-items:flex-start;gap:10px;background:rgba(255,255,255,0.02);border:1px solid var(--line);padding:12px;border-radius:10px;cursor:pointer;">
            <input type="checkbox" name="block_direct" value="1" <?= !empty($file['block_direct']) ? 'checked' : '' ?> style="margin-top:3px;">
            <div>
                <strong style="color:var(--text-bright);font-size:13.5px;display:block;">Bloquear Acesso Direto</strong>
                <span class="hint" style="font-size:12px;">Não permite abrir a URL de <code>p.php</code> digitando na barra do navegador. Exige carregamento via iframe.</span>
            </div>
        </label>

        <label class="check-card" style="display:flex;align-items:flex-start;gap:10px;background:rgba(255,255,255,0.02);border:1px solid var(--line);padding:12px;border-radius:10px;cursor:pointer;">
            <input type="checkbox" name="block_iframe" value="1" <?= !empty($file['block_iframe']) ? 'checked' : '' ?> style="margin-top:3px;">
            <div>
                <strong style="color:var(--text-bright);font-size:13.5px;display:block;">Bloquear Iframes Externos (CSP)</strong>
                <span class="hint" style="font-size:12px;">Aplica CSP <code>frame-ancestors</code> garantindo que apenas seu sistema Evo Links TV consiga incorporar o <code>p.php</code>.</span>
            </div>
        </label>

        <label class="check-card" style="display:flex;align-items:flex-start;gap:10px;background:rgba(255,255,255,0.02);border:1px solid var(--line);padding:12px;border-radius:10px;cursor:pointer;">
            <input type="checkbox" name="anti_adblock" value="1" <?= !empty($file['anti_adblock']) ? 'checked' : '' ?> style="margin-top:3px;">
            <div>
                <strong style="color:var(--text-bright);font-size:13.5px;display:block;">Ativar Anti-Adblock</strong>
                <span class="hint" style="font-size:12px;">Detecta se o visitante está com bloqueador de anúncios e solicita a desativação para liberar a transmissão.</span>
            </div>
        </label>

        <label class="check-card" style="display:flex;align-items:flex-start;gap:10px;background:rgba(255,255,255,0.02);border:1px solid var(--line);padding:12px;border-radius:10px;cursor:pointer;">
            <input type="checkbox" name="anti_sandbox" value="1" <?= !empty($file['anti_sandbox']) ? 'checked' : '' ?> style="margin-top:3px;">
            <div>
                <strong style="color:var(--text-bright);font-size:13.5px;display:block;">Ativar Anti-Sandbox</strong>
                <span class="hint" style="font-size:12px;">Impede que sites de terceiros coloquem o iframe em modo <code>sandbox</code> para anular seus scripts e anúncios.</span>
            </div>
        </label>

        <label class="check-card" style="display:flex;align-items:flex-start;gap:10px;background:rgba(255,255,255,0.02);border:1px solid var(--line);padding:12px;border-radius:10px;cursor:pointer;">
            <input type="checkbox" name="anti_inspect" value="1" <?= !empty($file['anti_inspect']) ? 'checked' : '' ?> style="margin-top:3px;">
            <div>
                <strong style="color:var(--text-bright);font-size:13.5px;display:block;">Anti-Inspeção / DevTools</strong>
                <span class="hint" style="font-size:12px;">Desabilita botão direito, F12, Ctrl+U e atalhos de devtools para proteger suas chaves ClearKey e streams.</span>
            </div>
        </label>

        <label class="check-card" style="display:flex;align-items:flex-start;gap:10px;background:rgba(255,255,255,0.02);border:1px solid var(--line);padding:12px;border-radius:10px;cursor:pointer;">
            <input type="checkbox" name="obfuscate" value="1" <?= !empty($file['obfuscate']) ? 'checked' : '' ?> style="margin-top:3px;">
            <div>
                <strong style="color:var(--cyan);font-size:13.5px;display:block;">Criptografia & Ofuscação de Código</strong>
                <span class="hint" style="font-size:12px;">Se ativo para snippets avulsos, empacota o código em payload cifrado com decodificador runtime.</span>
            </div>
        </label>
    </div>

    <h3 style="font-size:15px;color:var(--text-bright);margin-top:20px;margin-bottom:14px;border-bottom:1px solid var(--line);padding-bottom:8px;">
        4. Código do Canal (ou Arquivo p.php) & Anúncios Popups
    </h3>

    <div class="field mb-16">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:6px;">
            <label for="source_code" style="margin-bottom:0;">Código do Arquivo (p.php, Iframe, Embed ou Script do Player)</label>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                <?php if ($hasLocalPPhpFile): ?>
                    <button type="button" class="btn ghost small" id="btn-load-pphp-code" style="font-size:11px;padding:2px 8px;color:var(--cyan);">
                        + Inserir p.php Atual
                    </button>
                <?php endif; ?>
                <button type="button" class="btn ghost small" id="btn-clean-det" style="font-size:11px;padding:2px 8px;" title="Formata e limpa o código sem usar IA (ultra rápido)">
                    🧹 Limpar sem IA
                </button>
                <button type="button" class="btn small" id="btn-clean-ai" style="font-size:11px;padding:2px 10px;background:linear-gradient(135deg, #00f0ff 0%, #7000ff 100%);color:#fff;border:0;font-weight:600;" title="Usa DeepSeek via OpenRouter para limpar lixo/adware e organizar o código mantendo streams intactas">
                    ✨ Organizar com DeepSeek (IA)
                </button>
                <button type="button" class="btn ghost small" id="btn-undo-clean" style="font-size:11px;padding:2px 8px;display:none;color:#ff6b81;" title="Restaurar código anterior">
                    ↩ Desfazer
                </button>
            </div>
        </div>
        <div id="clean-status-box" style="display:none;margin-bottom:8px;font-size:12px;padding:8px 12px;border-radius:8px;"></div>
        <textarea id="source_code" name="source_code" class="code-area" rows="8"
                  placeholder='Cole aqui o código original do seu arquivo p.php ou a tag de transmissão.&#10;Se você colar o p.php completo, o sistema entrega o arquivo inteiro blindado na aba 2.'><?= e($file['source_code']) ?></textarea>
        <p class="hint">Aceita qualquer código: o arquivo <code>p.php</code> completo com Clappr/Shaka, iframes avulsos ou tags HTML. Clique em <strong>Organizar com DeepSeek</strong> para remover scripts inúteis e formatar antes de salvar.</p>
    </div>

    <div class="field mb-16">
        <label for="popup_ads">Anúncios Popup / Popunder Personalizados (Opcional)</label>
        <textarea id="popup_ads" name="popup_ads" class="code-area" rows="3"
                  placeholder='Cole aqui scripts de anúncios popup/popunder que você deseja que disparem no primeiro clique do visitante.'><?= e($file['popup_ads']) ?></textarea>
        <p class="hint">Os anúncios serão disparados com segurança sem interferir na reprodução do stream.</p>
    </div>

    <div class="row-actions mt-16">
        <button class="btn primary" type="submit">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
            <span>Salvar & Gerar Códigos de Proteção</span>
        </button>
        <a class="btn ghost" href="file-protect.php">Cancelar</a>
    </div>
</form>

<!-- ÁREA DE EXIBIÇÃO DE CÓDIGOS GERADOS -->
<div class="card" id="generated-section">
    <div class="toolbar" style="margin-bottom:12px;">
        <div>
            <h3 style="font-size:16px;color:var(--text-bright);margin-bottom:4px;">
                ⚡ Códigos Gerados para o Canal
            </h3>
            <p class="hint" style="margin:0;">
                Escolha a forma que deseja aplicar no seu arquivo:
            </p>
        </div>
        <div class="btn-group">
            <button type="button" class="btn primary small" id="btn-copy-active">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                </svg>
                <span id="copy-btn-text">Copiar Código Ativo</span>
            </button>
            <?php if ($id > 0): ?>
                <a class="btn ghost small" href="file-protect.php?action=download&id=<?= $id ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    <span>Baixar <?= e($file['filename']) ?></span>
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Navegação por Abas -->
    <div style="display:flex;gap:8px;border-bottom:1px solid var(--line);padding-bottom:8px;margin-bottom:14px;overflow-x:auto;">
        <button type="button" class="btn small tab-btn active" data-tab="tab-top" style="border-radius:6px;">
            ⚡ 1. Snippet para a Linha 1 de <?= e($file['filename']) ?> [Mais Fácil]
        </button>
        <button type="button" class="btn ghost small tab-btn" data-tab="tab-full" style="border-radius:6px;">
            📦 2. Arquivo Completo Blindado (<?= e($file['filename']) ?>)
        </button>
        <button type="button" class="btn ghost small tab-btn" data-tab="tab-client" style="border-radius:6px;">
            🛡️ 3. Snippet Anti-DevTools / F12 para o Rodapé
        </button>
    </div>

    <!-- Conteúdo da Aba 1: Topo PHP -->
    <div id="tab-top" class="tab-pane">
        <div class="alert alert-ok mb-12" style="font-size:12.5px;padding:10px 14px;">
            👉 <strong>Como aplicar no seu arquivo:</strong> Abra seu arquivo <code><?= e($file['filename']) ?></code> no editor, copie o bloco abaixo e cole exatamente na <strong>Linha 1</strong> (antes do <code>&lt;!DOCTYPE html&gt;</code>). Salve o arquivo. A partir desse momento ele só abrirá dentro do seu sistema Evo Links TV!
        </div>
        <textarea id="code-top" class="code-area" rows="18" readonly style="background:#03060a;color:#a5d6ff;font-size:12.5px;"><?= e($topPhpSnippet) ?></textarea>
    </div>

    <!-- Conteúdo da Aba 2: Arquivo Completo -->
    <div id="tab-full" class="tab-pane" style="display:none;">
        <p class="hint" style="margin-bottom:8px;">
            Arquivo PHP completo com todas as proteções, o player e scripts embutidos. Pronto para subir ou substituir no servidor:
        </p>
        <textarea id="code-full" class="code-area" rows="18" readonly style="background:#03060a;color:#a5d6ff;font-size:12.5px;"><?= e($fullPhpCode) ?></textarea>
    </div>

    <!-- Conteúdo da Aba 3: Snippet Cliente JS/HTML -->
    <div id="tab-client" class="tab-pane" style="display:none;">
        <p class="hint" style="margin-bottom:8px;">
            Trecho contendo proteções client-side (Anti-DevTools, Anti-Adblock e Anúncios). Pode ser colado antes de <code>&lt;/body&gt;</code>:
        </p>
        <textarea id="code-client" class="code-area" rows="16" readonly style="background:#03060a;color:#a5d6ff;font-size:12.5px;"><?= e($clientSnippet) ?></textarea>
    </div>
</div>

<?php if ($hasLocalPPhpFile): ?>
    <textarea id="raw-pphp-storage" style="display:none;" aria-hidden="true"><?= e($localPPhpContent) ?></textarea>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var storageEl = document.getElementById('raw-pphp-storage');
    var rawLocalPPhp = storageEl ? storageEl.value : '';
    var currentHost = <?= json_encode($systemHost, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

    var btnUseHost = document.getElementById('btn-use-system-host');
    if (btnUseHost) {
        btnUseHost.addEventListener('click', function() {
            var domainInput = document.getElementById('allowed_domains');
            if (domainInput) domainInput.value = currentHost;
        });
    }

    var btnLoadPPhpCode = document.getElementById('btn-load-pphp-code');
    if (btnLoadPPhpCode && rawLocalPPhp) {
        btnLoadPPhpCode.addEventListener('click', function() {
            var area = document.getElementById('source_code');
            if (area) area.value = rawLocalPPhp;
        });
    }

    var btnPresetPPhp = document.getElementById('btn-preset-pphp');
    if (btnPresetPPhp && rawLocalPPhp) {
        btnPresetPPhp.addEventListener('click', function() {
            var nameInput = document.getElementById('name');
            var fileInput = document.getElementById('filename');
            var domainInput = document.getElementById('allowed_domains');
            var area = document.getElementById('source_code');
            if (nameInput) nameInput.value = 'Grade de Canais (p.php)';
            if (fileInput) fileInput.value = 'p.php';
            if (domainInput) domainInput.value = currentHost;
            if (area) area.value = rawLocalPPhp;
        });
    }

    var tabs = document.querySelectorAll('.tab-btn');
    var panes = document.querySelectorAll('.tab-pane');
    var activeTab = 'tab-top';

    tabs.forEach(function(btn) {
        btn.addEventListener('click', function() {
            tabs.forEach(function(b) {
                b.classList.remove('active');
                b.classList.add('ghost');
            });
            panes.forEach(function(p) {
                p.style.display = 'none';
            });

            btn.classList.add('active');
            btn.classList.remove('ghost');
            activeTab = btn.getAttribute('data-tab');
            var target = document.getElementById(activeTab);
            if (target) target.style.display = 'block';
        });
    });

    var copyBtn = document.getElementById('btn-copy-active');
    var copyBtnText = document.getElementById('copy-btn-text');

    if (copyBtn) {
        copyBtn.addEventListener('click', function() {
            var activeArea = null;
            if (activeTab === 'tab-full') activeArea = document.getElementById('code-full');
            else if (activeTab === 'tab-top') activeArea = document.getElementById('code-top');
            else if (activeTab === 'tab-client') activeArea = document.getElementById('code-client');

            if (activeArea) {
                activeArea.select();
                navigator.clipboard.writeText(activeArea.value).then(function() {
                    var originalText = copyBtnText.textContent;
                    copyBtnText.textContent = '✓ Código Copiado!';
                    setTimeout(function() {
                        copyBtnText.textContent = originalText;
                    }, 2500);
                }).catch(function() {
                    document.execCommand('copy');
                    copyBtnText.textContent = '✓ Código Copiado!';
                    setTimeout(function() {
                        copyBtnText.textContent = 'Copiar Código Ativo';
                    }, 2500);
                });
            }
        });
    }

    // Organização de Código (DeepSeek IA & Determinístico)
    var areaSource = document.getElementById('source_code');
    var btnCleanAi = document.getElementById('btn-clean-ai');
    var btnCleanDet = document.getElementById('btn-clean-det');
    var btnUndoClean = document.getElementById('btn-undo-clean');
    var statusBox = document.getElementById('clean-status-box');
    var previousSourceCode = null;

    function runCleanCode(mode) {
        if (!areaSource) return;
        var codeVal = areaSource.value.trim();
        if (!codeVal) {
            alert('Cole o código do player no campo antes de organizar.');
            areaSource.focus();
            return;
        }

        previousSourceCode = areaSource.value;
        if (btnUndoClean) btnUndoClean.style.display = 'none';

        var originalAiText = btnCleanAi ? btnCleanAi.innerHTML : '';
        var originalDetText = btnCleanDet ? btnCleanDet.innerHTML : '';

        if (mode === 'ai' && btnCleanAi) {
            btnCleanAi.disabled = true;
            btnCleanAi.innerHTML = '⏳ DeepSeek analisando...';
        } else if (btnCleanDet) {
            btnCleanDet.disabled = true;
            btnCleanDet.innerHTML = '⏳ Organizando...';
        }

        if (statusBox) {
            statusBox.style.display = 'block';
            statusBox.className = 'alert';
            statusBox.style.background = 'rgba(0, 240, 255, 0.08)';
            statusBox.style.border = '1px solid rgba(0, 240, 255, 0.25)';
            statusBox.style.color = 'var(--text-bright)';
            statusBox.innerHTML = mode === 'ai'
                ? '🤖 Inspecionando embeds, chaves DRM e removendo lixo com DeepSeek...'
                : '🧹 Formatando e limpando código de forma determinística...';
        }

        var csrfToken = '';
        var csrfInput = document.querySelector('input[name="_csrf"]');
        if (csrfInput) csrfToken = csrfInput.value;

        var formData = new FormData();
        formData.append('action', 'clean_code');
        formData.append('clean_mode', mode);
        formData.append('model', 'deepseek/deepseek-chat');
        formData.append('source_code', codeVal);
        formData.append('_csrf', csrfToken);
        formData.append('ajax', '1');

        fetch('file-protect-edit.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(function(res) {
            return res.json();
        })
        .then(function(data) {
            if (btnCleanAi) {
                btnCleanAi.disabled = false;
                btnCleanAi.innerHTML = originalAiText;
            }
            if (btnCleanDet) {
                btnCleanDet.disabled = false;
                btnCleanDet.innerHTML = originalDetText;
            }

            if (data.ok && data.cleaned_code) {
                areaSource.value = data.cleaned_code;
                if (btnUndoClean) btnUndoClean.style.display = 'inline-block';

                if (statusBox) {
                    var threatsHtml = '';
                    if (data.threats_removed && data.threats_removed.length > 0) {
                        threatsHtml = '<br><span style="color:#ff6b81;">🛡️ Removido: ' + data.threats_removed.join(', ') + '</span>';
                    }
                    var noteHtml = data.error ? '<br><span style="color:#f59e0b;">⚠️ ' + data.error + '</span>' : '';

                    statusBox.className = 'alert alert-ok';
                    statusBox.style.background = 'rgba(16, 185, 129, 0.1)';
                    statusBox.style.border = '1px solid rgba(16, 185, 129, 0.3)';
                    statusBox.style.color = '#34d399';
                    statusBox.innerHTML = '<strong>✓ Código Organizado!</strong> Player: <code>' + (data.detected_player || 'Player') + '</code>. ' + (data.summary || '') + threatsHtml + noteHtml;
                }
            } else {
                if (statusBox) {
                    statusBox.className = 'alert alert-error';
                    statusBox.style.background = 'rgba(239, 68, 68, 0.1)';
                    statusBox.style.border = '1px solid rgba(239, 68, 68, 0.3)';
                    statusBox.style.color = '#f87171';
                    statusBox.innerHTML = '<strong>❌ Falha ao organizar:</strong> ' + (data.error || 'Erro inesperado.');
                }
            }
        })
        .catch(function(err) {
            if (btnCleanAi) {
                btnCleanAi.disabled = false;
                btnCleanAi.innerHTML = originalAiText;
            }
            if (btnCleanDet) {
                btnCleanDet.disabled = false;
                btnCleanDet.innerHTML = originalDetText;
            }
            if (statusBox) {
                statusBox.className = 'alert alert-error';
                statusBox.style.background = 'rgba(239, 68, 68, 0.1)';
                statusBox.style.border = '1px solid rgba(239, 68, 68, 0.3)';
                statusBox.style.color = '#f87171';
                statusBox.innerHTML = '<strong>❌ Erro de comunicação:</strong> ' + err.message;
            }
        });
    }

    if (btnCleanAi) {
        btnCleanAi.addEventListener('click', function() {
            runCleanCode('ai');
        });
    }

    if (btnCleanDet) {
        btnCleanDet.addEventListener('click', function() {
            runCleanCode('deterministic');
        });
    }

    if (btnUndoClean) {
        btnUndoClean.addEventListener('click', function() {
            if (previousSourceCode !== null && areaSource) {
                areaSource.value = previousSourceCode;
                previousSourceCode = null;
                btnUndoClean.style.display = 'none';
                if (statusBox) {
                    statusBox.style.display = 'block';
                    statusBox.className = 'alert alert-hint';
                    statusBox.style.background = 'rgba(255, 255, 255, 0.05)';
                    statusBox.style.border = '1px solid var(--line)';
                    statusBox.style.color = 'var(--text)';
                    statusBox.innerHTML = 'Código original restaurado.';
                }
            }
        });
    }
});
</script>

<?php admin_footer(); ?>
