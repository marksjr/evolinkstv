<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/layout.php';

$user = require_auth($pdo);

$errors = [];
$msg = (string)($_GET['msg'] ?? '');

$proApiKey = setting($pdo, 'pro_api_key', '');
if ($proApiKey === '') {
    $proApiKey = setting($pdo, 'api_key', '');
    if ($proApiKey === '') {
        $proApiKey = hash('sha256', (string)($GLOBALS['config']['app']['key'] ?? 'pro_default_secret'));
        settings_save($pdo, ['pro_api_key' => $proApiKey]);
    }
}

$current = [
    'protect_iframe'      => setting_bool($pdo, 'protect_iframe', true) ? '1' : '0',
    'block_direct_access' => setting_bool($pdo, 'block_direct_access', true) ? '1' : '0',
    'anti_adblock'        => setting_bool($pdo, 'anti_adblock', true) ? '1' : '0',
    'anti_sandbox'        => setting_bool($pdo, 'anti_sandbox', true) ? '1' : '0',
    'inspect_protection'  => setting_bool($pdo, 'inspect_protection', true) ? '1' : '0',
    'redirect_url'        => setting($pdo, 'redirect_url', ''),
    'watermark_text'      => setting($pdo, 'watermark_text', ''),
    'player_brand_name'   => setting($pdo, 'player_brand_name', 'Ao Vivo'),
    'msg_adblock_title'   => ($v = trim(setting($pdo, 'msg_adblock_title', ''))) !== '' ? $v : 'Desative o bloqueador de anúncios',
    'msg_adblock_desc'    => ($v = trim(setting($pdo, 'msg_adblock_desc', ''))) !== '' ? $v : 'Este player não funciona com AdBlock ativo. Desative o bloqueador para continuar.',
    'msg_adblock_button'  => ($v = trim(setting($pdo, 'msg_adblock_button', ''))) !== '' ? $v : 'Já desativei — recarregar',
    'msg_sandbox_title'   => ($v = trim(setting($pdo, 'msg_sandbox_title', ''))) !== '' ? $v : 'Sandbox detectado',
    'msg_sandbox_desc'    => ($v = trim(setting($pdo, 'msg_sandbox_desc', ''))) !== '' ? $v : 'Este player não funciona dentro de um iframe com atributos de restrição (sandbox). Remova o sandbox para continuar.',
    'msg_sandbox_button'  => ($v = trim(setting($pdo, 'msg_sandbox_button', ''))) !== '' ? $v : 'Recarregar',
    'msg_inspect_title'   => ($v = trim(setting($pdo, 'msg_inspect_title', ''))) !== '' ? $v : 'Inspeção não permitida.',
    'msg_direct_title'    => ($v = trim(setting($pdo, 'msg_direct_title', ''))) !== '' ? $v : 'Acesso Não Permitido',
    'msg_direct_desc'     => ($v = trim(setting($pdo, 'msg_direct_desc', ''))) !== '' ? $v : 'Este player não pode ser aberto diretamente na barra do navegador. Assista através do site oficial incorporado.',
    'msg_unauthorized_title'=> ($v = trim(setting($pdo, 'msg_unauthorized_title', ''))) !== '' ? $v : 'Acesso não autorizado',
    'msg_unauthorized_desc' => ($v = trim(setting($pdo, 'msg_unauthorized_desc', ''))) !== '' ? $v : 'A reprodução deste canal é restrita a domínios previamente autorizados.',
    'player_ads_code'     => setting($pdo, 'player_ads_code', ''),
    'openrouter_api_key'  => setting($pdo, 'openrouter_api_key', ''),
    'openrouter_model'    => openrouter_get_model($pdo),
    'pro_api_key'         => $proApiKey,
];

$domains = domains_list(normalize_domains(setting($pdo, 'allowed_domains', '')));

if (is_post()) {
    csrf_check();

    $action = (string)($_POST['action'] ?? 'save_options');

    if ($action === 'add_domain') {
        $raw = trim((string)($_POST['domain'] ?? ''));
        $normalized = normalize_domains($raw);
        $items = $normalized === '' ? [] : explode(',', $normalized);

        if (count($items) !== 1) {
            $errors[] = 'Informe apenas um domínio válido. Ex.: seusite.com ou *.seusite.com.';
        } else {
            $domain = $items[0];

            if (in_array($domain, $domains, true)) {
                $errors[] = 'Este domínio já está na lista.';
            } else {
                $domains[] = $domain;
                settings_save($pdo, ['allowed_domains' => implode(',', $domains)]);
                redirect('settings.php?msg=domain_added');
            }
        }
    } elseif ($action === 'remove_domain') {
        $remove = strtolower(trim((string)($_POST['domain'] ?? '')));
        $newDomains = array_values(array_filter(
            $domains,
            static fn(string $domain): bool => $domain !== $remove
        ));

        if (count($newDomains) === count($domains)) {
            $errors[] = 'Domínio não encontrado na lista.';
        } else {
            settings_save($pdo, ['allowed_domains' => implode(',', $newDomains)]);
            redirect('settings.php?msg=domain_removed');
        }
    } elseif ($action === 'test_openrouter') {
        $testResult = openrouter_test($pdo);
        if ($testResult['ok']) {
            redirect('settings.php?msg=openrouter_ok');
        } else {
            $errors[] = 'Falha no teste OpenRouter: ' . ($testResult['error'] ?? 'Erro desconhecido');
        }
    } else {
        $newProApiKey = trim((string)($_POST['pro_api_key'] ?? ''));
        if ($newProApiKey === '') {
            $newProApiKey = bin2hex(random_bytes(32));
        }

        $current = [
            'protect_iframe'        => isset($_POST['protect_iframe']) ? '1' : '0',
            'block_direct_access'   => isset($_POST['block_direct_access']) ? '1' : '0',
            'anti_adblock'          => isset($_POST['anti_adblock']) ? '1' : '0',
            'anti_sandbox'          => isset($_POST['anti_sandbox']) ? '1' : '0',
            'inspect_protection'    => isset($_POST['inspect_protection']) ? '1' : '0',
            'redirect_url'          => trim((string)($_POST['redirect_url'] ?? '')),
            'watermark_text'        => trim((string)($_POST['watermark_text'] ?? '')),
            'player_brand_name'     => trim((string)($_POST['player_brand_name'] ?? 'Ao Vivo')),
            'msg_adblock_title'     => trim((string)($_POST['msg_adblock_title'] ?? '')),
            'msg_adblock_desc'      => trim((string)($_POST['msg_adblock_desc'] ?? '')),
            'msg_adblock_button'    => trim((string)($_POST['msg_adblock_button'] ?? '')),
            'msg_sandbox_title'     => trim((string)($_POST['msg_sandbox_title'] ?? '')),
            'msg_sandbox_desc'      => trim((string)($_POST['msg_sandbox_desc'] ?? '')),
            'msg_sandbox_button'    => trim((string)($_POST['msg_sandbox_button'] ?? '')),
            'msg_inspect_title'     => trim((string)($_POST['msg_inspect_title'] ?? '')),
            'msg_direct_title'      => trim((string)($_POST['msg_direct_title'] ?? '')),
            'msg_direct_desc'       => trim((string)($_POST['msg_direct_desc'] ?? '')),
            'msg_unauthorized_title'=> trim((string)($_POST['msg_unauthorized_title'] ?? '')),
            'msg_unauthorized_desc' => trim((string)($_POST['msg_unauthorized_desc'] ?? '')),
            'player_ads_code'       => (string)($_POST['player_ads_code'] ?? ''),
            'openrouter_api_key'    => trim((string)($_POST['openrouter_api_key'] ?? '')),
            'openrouter_model'      => trim((string)($_POST['openrouter_model'] ?? 'google/gemini-2.5-flash')),
            'pro_api_key'           => $newProApiKey,
        ];

        if ($current['redirect_url'] !== '' && !valid_http_url($current['redirect_url'])) {
            $errors[] = 'URL de redirecionamento inválida. Use http:// ou https://.';
        }

        if (mb_strlen($current['watermark_text']) > 80) {
            $errors[] = "Marca d'água deve ter no máximo 80 caracteres.";
        }

        if ($current['openrouter_model'] === '') {
            $current['openrouter_model'] = 'google/gemini-2.5-flash';
        }

        if (!$errors) {
            settings_save($pdo, $current);
            redirect('settings.php?msg=saved');
        }
    }
}

admin_header('Configurações', $user);
?>

<?php if ($msg === 'saved'): ?>
    <div class="alert alert-ok">Configurações salvas.</div>
<?php elseif ($msg === 'openrouter_ok'): ?>
    <div class="alert alert-ok">Conexão com a API OpenRouter testada com sucesso!</div>
<?php elseif ($msg === 'domain_added'): ?>
    <div class="alert alert-ok">Domínio adicionado à lista de autorizados.</div>
<?php elseif ($msg === 'domain_removed'): ?>
    <div class="alert alert-ok">Domínio removido da lista.</div>
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

<?php if ($current['protect_iframe'] !== '1'): ?>
    <div class="alert alert-ok" style="background:rgba(0, 240, 255, 0.08);border:1px solid rgba(0, 240, 255, 0.3);color:var(--cyan);">
        <strong>Modo Distribuidor Aberto Ativo:</strong> A proteção de domínios está desligada. Qualquer site na internet pode incorporar os canais em iframe. As proteções Anti-Sandbox, Anti-Adblock, Anti-DevTools e os Anúncios continuam ativas para todos os iframes.
    </div>
<?php elseif (!$domains): ?>
    <div class="alert alert-warn">
        <strong>Modo Domínio Restrito Ativo:</strong> Nenhum domínio autorizado cadastrado. Com a proteção ligada, somente o próprio domínio consegue incorporar.
    </div>
<?php else: ?>
    <div class="alert alert-ok">
        <strong>Modo Domínio Restrito Ativo:</strong> Apenas os <?= count($domains) ?> domínio(s) cadastrados na lista abaixo podem incorporar o player.
    </div>
<?php endif; ?>

<form method="post" class="card form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_options">

    <div class="check-grid">
        <label class="check">
            <input type="checkbox" name="protect_iframe" value="1" <?= $current['protect_iframe'] === '1' ? 'checked' : '' ?>>
            <span>
                <strong>Proteção de iframe (Modo Restrito)</strong>
                <span>Se ligado: restringe os iframes estritamente aos domínios da lista abaixo. Se desligado: funciona em <strong>Modo Distribuidor Aberto</strong> (qualquer site pode incorporar).</span>
            </span>
        </label>

        <label class="check">
            <input type="checkbox" name="block_direct_access" value="1" <?= $current['block_direct_access'] === '1' ? 'checked' : '' ?>>
            <span>
                <strong>Bloquear acesso direto</strong>
                <span>Impede abrir a URL do player direto na barra do navegador (sem <code>Referer</code> de iframe). Obriga a assistir em site incorporado.</span>
            </span>
        </label>

        <label class="check">
            <input type="checkbox" name="anti_adblock" value="1" <?= $current['anti_adblock'] === '1' ? 'checked' : '' ?>>
            <span>
                <strong>Anti-adblock</strong>
                <span>Detecta bloqueador pelo arquivo-isca e elemento <code>.adsbox</code>. Interrompe o stream e exige desativação para assistir.</span>
            </span>
        </label>

        <label class="check">
            <input type="checkbox" name="anti_sandbox" value="1" <?= $current['anti_sandbox'] === '1' ? 'checked' : '' ?>>
            <span>
                <strong>Anti-sandbox</strong>
                <span>Detecta iframe com atributo <code>sandbox</code> e bloqueia a reprodução, impedindo que terceiros neutralizem seus popups e anúncios.</span>
            </span>
        </label>

        <label class="check">
            <input type="checkbox" name="inspect_protection" value="1" <?= $current['inspect_protection'] === '1' ? 'checked' : '' ?>>
            <span>
                <strong>Proteção contra inspeção</strong>
                <span>Bloqueia botão direito, atalhos (F12, Ctrl+U, Ctrl+Shift+I) e depuradores para proteger códigos, chaves DRM e links.</span>
            </span>
        </label>
    </div>

    <div class="form-grid">
        <div class="field">
            <label for="redirect_url">URL de redirecionamento (opcional)</label>
            <input id="redirect_url" name="redirect_url" type="url" maxlength="2048"
                   value="<?= e($current['redirect_url']) ?>"
                   placeholder="https://www.google.com">
            <p class="hint">
                Usada quando o domínio não é autorizado ou quando a inspeção é detectada.
                Vazio = mostra a página de aviso na tela.
            </p>
        </div>

        <div class="field">
            <label for="watermark_text">Marca d'água (opcional)</label>
            <input id="watermark_text" name="watermark_text" type="text" maxlength="80"
                   value="<?= e($current['watermark_text']) ?>"
                   placeholder="@seucanal">
            <p class="hint">Texto fixo no canto inferior direito. Deixe vazio para desativar.</p>
        </div>
    </div>

    <div class="field mt-16" style="border-top:1px solid var(--line);padding-top:16px;">
        <h2 class="section-title" style="margin-bottom:4px;">🏷️ Personalização White-Label & Mensagens do Player</h2>
        <p class="hint">Personalize o título da aba e todos os textos dos avisos de segurança exibidos aos visitantes. Deixe seu sistema 100% white-label para uso próprio ou distribuição no GitHub.</p>
    </div>

    <div class="form-grid">
        <div class="field">
            <label for="player_brand_name">Título Padrão da Aba do Player</label>
            <input id="player_brand_name" name="player_brand_name" type="text" maxlength="80"
                   value="<?= e($current['player_brand_name']) ?>"
                   placeholder="Ao Vivo">
            <p class="hint">Aparece na aba do navegador quando o canal estiver aberto.</p>
        </div>

        <div class="field">
            <label for="msg_inspect_title">Aviso de Anti-Inspeção / DevTools</label>
            <input id="msg_inspect_title" name="msg_inspect_title" type="text" maxlength="120"
                   value="<?= e($current['msg_inspect_title']) ?>"
                   placeholder="Inspeção não permitida.">
            <p class="hint">Exibido caso o usuário force a abertura do DevTools / F12.</p>
        </div>
    </div>

    <div style="background:rgba(255,255,255,0.02);border:1px solid var(--line);border-radius:10px;padding:14px;margin-top:14px;">
        <strong style="color:var(--text-bright);font-size:13.5px;display:block;margin-bottom:10px;">⚠️ Aviso de Anti-Adblock (Bloqueador de Anúncios)</strong>
        <div class="form-grid">
            <div class="field">
                <label for="msg_adblock_title">Título do Aviso</label>
                <input id="msg_adblock_title" name="msg_adblock_title" type="text" maxlength="120"
                       value="<?= e($current['msg_adblock_title']) ?>"
                       placeholder="Desative o bloqueador de anúncios">
            </div>
            <div class="field">
                <label for="msg_adblock_button">Texto do Botão</label>
                <input id="msg_adblock_button" name="msg_adblock_button" type="text" maxlength="60"
                       value="<?= e($current['msg_adblock_button']) ?>"
                       placeholder="Já desativei — recarregar">
            </div>
        </div>
        <div class="field mt-8">
            <label for="msg_adblock_desc">Descrição do Aviso</label>
            <textarea id="msg_adblock_desc" name="msg_adblock_desc" class="code-area" rows="2"
                      placeholder="Este player não funciona com AdBlock ativo. Desative o bloqueador para continuar."><?= e($current['msg_adblock_desc']) ?></textarea>
        </div>
    </div>

    <div style="background:rgba(255,255,255,0.02);border:1px solid var(--line);border-radius:10px;padding:14px;margin-top:14px;">
        <strong style="color:var(--text-bright);font-size:13.5px;display:block;margin-bottom:10px;">🛡️ Aviso de Anti-Sandbox (Embed com Sandbox Restrito)</strong>
        <div class="form-grid">
            <div class="field">
                <label for="msg_sandbox_title">Título do Aviso</label>
                <input id="msg_sandbox_title" name="msg_sandbox_title" type="text" maxlength="120"
                       value="<?= e($current['msg_sandbox_title']) ?>"
                       placeholder="Sandbox detectado">
            </div>
            <div class="field">
                <label for="msg_sandbox_button">Texto do Botão</label>
                <input id="msg_sandbox_button" name="msg_sandbox_button" type="text" maxlength="60"
                       value="<?= e($current['msg_sandbox_button']) ?>"
                       placeholder="Recarregar">
            </div>
        </div>
        <div class="field mt-8">
            <label for="msg_sandbox_desc">Descrição do Aviso</label>
            <textarea id="msg_sandbox_desc" name="msg_sandbox_desc" class="code-area" rows="2"
                      placeholder="Este player não funciona dentro de um iframe com atributos de restrição (sandbox). Remova o sandbox para continuar."><?= e($current['msg_sandbox_desc']) ?></textarea>
        </div>
    </div>

    <div style="background:rgba(255,255,255,0.02);border:1px solid var(--line);border-radius:10px;padding:14px;margin-top:14px;">
        <strong style="color:var(--text-bright);font-size:13.5px;display:block;margin-bottom:10px;">⛔ Aviso de Bloqueio de Acesso Direto</strong>
        <div class="form-grid">
            <div class="field">
                <label for="msg_direct_title">Título do Aviso</label>
                <input id="msg_direct_title" name="msg_direct_title" type="text" maxlength="120"
                       value="<?= e($current['msg_direct_title']) ?>"
                       placeholder="Acesso Não Permitido">
            </div>
            <div class="field">
                <label for="msg_direct_desc">Descrição do Aviso</label>
                <input id="msg_direct_desc" name="msg_direct_desc" type="text" maxlength="255"
                       value="<?= e($current['msg_direct_desc']) ?>"
                       placeholder="Este player não pode ser aberto diretamente na barra do navegador. Assista através do site oficial incorporado.">
            </div>
        </div>
    </div>

    <div style="background:rgba(255,255,255,0.02);border:1px solid var(--line);border-radius:10px;padding:14px;margin-top:14px;">
        <strong style="color:var(--text-bright);font-size:13.5px;display:block;margin-bottom:10px;">🔒 Aviso de Domínio Não Autorizado (403 - Modo Restrito)</strong>
        <div class="form-grid">
            <div class="field">
                <label for="msg_unauthorized_title">Título do Aviso</label>
                <input id="msg_unauthorized_title" name="msg_unauthorized_title" type="text" maxlength="120"
                       value="<?= e($current['msg_unauthorized_title']) ?>"
                       placeholder="Acesso não autorizado">
            </div>
            <div class="field">
                <label for="msg_unauthorized_desc">Descrição do Aviso</label>
                <input id="msg_unauthorized_desc" name="msg_unauthorized_desc" type="text" maxlength="255"
                       value="<?= e($current['msg_unauthorized_desc']) ?>"
                       placeholder="A reprodução deste canal é restrita a domínios previamente autorizados.">
            </div>
        </div>
    </div>

    <div class="field mt-16" style="border-top:1px solid var(--line);padding-top:16px;">
        <h2 class="section-title" style="margin-bottom:8px;">Anúncios, Popups, Popunders & Analytics do Player</h2>
        <p class="hint">Scripts e tags HTML/JavaScript que serão carregados dentro do player para todos os canais (ex.: PopAds, PropellerAds, Monetag, Google Analytics, Histats, pixels de rastreamento, banners).</p>
    </div>

    <div class="field">
        <label for="player_ads_code">Código HTML / JavaScript dos Anúncios & Rastreamento</label>
        <textarea id="player_ads_code" name="player_ads_code" class="code-area" rows="5"
                  placeholder="<!-- Insira scripts de popups, popunders, analytics ou anúncios aqui -->"><?= e($current['player_ads_code']) ?></textarea>
        <p class="hint">Os scripts são ofuscados e injetados de forma segura após as validações de domínio e adblock.</p>
    </div>

    <div class="field mt-16" style="border-top:1px solid var(--line);padding-top:16px;">
        <h2 class="section-title" style="margin-bottom:8px;">Integração com ADM (API de Canais)</h2>
        <p class="hint">Dados de conexão que devem ser preenchidos no painel ADM (<code>adm/settings.php</code>) para integração com o catálogo deste Pro.</p>
    </div>

    <div class="field">
        <label for="pro_api_url_display">URL da API do Pro (channels.php)</label>
        <div style="display:flex;gap:8px;align-items:center;margin-top:6px;">
            <input id="pro_api_url_display" type="text" readonly
                   value="<?= e(rtrim(app_base_url(), '/') . '/api/channels.php') ?>"
                   style="font-family:monospace;font-size:13px;letter-spacing:0.3px;background:#0d1117;"
                   onclick="this.select()">
            <button class="btn secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('pro_api_url_display').value);alert('URL da API copiada!');" style="white-space:nowrap;">
                Copiar URL
            </button>
        </div>
        <p class="hint">Cole no campo <strong>URL da API do Pro</strong> no painel ADM.</p>
    </div>

    <div class="field mt-12">
        <label for="pro_api_key">Chave de Autenticação da API (X-API-KEY)</label>
        <div style="display:flex;gap:8px;align-items:center;margin-top:6px;">
            <input id="pro_api_key" name="pro_api_key" type="text" readonly
                   value="<?= e($current['pro_api_key']) ?>"
                   style="font-family:monospace;font-size:13px;letter-spacing:0.5px;background:#0d1117;"
                   onclick="this.select()">
            <button class="btn secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('pro_api_key').value);alert('Chave copiada com sucesso!');" style="white-space:nowrap;">
                Copiar Chave
            </button>
            <button class="btn ghost" type="button" onclick="if(confirm('Gerar uma nova chave aleatória? Você precisará salvar e colar a nova chave no ADM.')){document.getElementById('pro_api_key').readOnly=false;document.getElementById('pro_api_key').value=Array.from(crypto.getRandomValues(new Uint8Array(32))).map(b=>b.toString(16).padStart(2,'0')).join('');document.getElementById('pro_api_key').readOnly=true;}" style="white-space:nowrap;">
                Gerar Nova
            </button>
        </div>
        <p class="hint">Cole no campo <strong>Chave de Autenticação da API</strong> no painel ADM.</p>
    </div>

    <div class="field mt-16" style="border-top:1px solid var(--line);padding-top:16px;">
        <h2 class="section-title" style="margin-bottom:8px;">Inteligência Artificial (OpenRouter LLM)</h2>
        <p class="hint">Usada para processar listas de canais em lote e executar migrações de links via Agente IA.</p>
    </div>

    <div class="form-grid">
        <div class="field">
            <label for="openrouter_api_key">Chave de API OpenRouter</label>
            <input id="openrouter_api_key" name="openrouter_api_key" type="password" autocomplete="off"
                   value="<?= e($current['openrouter_api_key']) ?>"
                   placeholder="sk-or-v1-...">
            <p class="hint">Obtenha sua chave em <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai/keys</a>.</p>
        </div>

        <div class="field">
            <label for="openrouter_model">Modelo LLM</label>
            <input id="openrouter_model" name="openrouter_model" type="text"
                   value="<?= e($current['openrouter_model']) ?>"
                   list="models_list"
                   placeholder="google/gemini-2.5-flash">
            <datalist id="models_list">
                <option value="google/gemini-2.5-flash">Google Gemini 2.5 Flash (Rápido e Barato)</option>
                <option value="deepseek/deepseek-chat">DeepSeek Chat V3</option>
                <option value="openai/gpt-4o-mini">OpenAI GPT-4o Mini</option>
                <option value="anthropic/claude-3.5-haiku">Claude 3.5 Haiku</option>
                <option value="anthropic/claude-3.5-sonnet">Claude 3.5 Sonnet</option>
                <option value="meta-llama/llama-3.3-70b-instruct">Llama 3.3 70B Instruct</option>
            </datalist>
            <p class="hint">Exemplos: <code>google/gemini-2.5-flash</code>, <code>deepseek/deepseek-chat</code>, <code>openai/gpt-4o-mini</code>.</p>
        </div>
    </div>

    <div class="row-actions">
        <button class="btn primary" type="submit">Salvar configurações</button>
        <a class="btn ghost" href="channels.php">Voltar aos canais</a>
    </div>
</form>

<?php if ($current['openrouter_api_key'] !== ''): ?>
    <form method="post" class="card mt-16" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="test_openrouter">
        <div>
            <strong>Testar integração com OpenRouter</strong>
            <p class="hint" style="margin:4px 0 0;">Envia um teste rápido com o modelo <code><?= e($current['openrouter_model']) ?></code> para verificar autenticação e resposta.</p>
        </div>
        <button type="submit" class="btn">Testar conexão agora</button>
    </form>
<?php endif; ?>

<section class="card mt-16">
    <div class="toolbar">
        <div>
            <h2 class="section-title">Domínios autorizados</h2>
            <p class="hint">
                Somente estes domínios podem incorporar o player. Qualquer outro é bloqueado quando a
                proteção de iframe está ligada.
            </p>
        </div>
        <span class="badge <?= $domains ? 'badge-ok' : 'badge-off' ?>">
            <?= count($domains) ?> domínio<?= count($domains) === 1 ? '' : 's' ?>
        </span>
    </div>

    <?php if (!$domains): ?>
        <div class="empty">Nenhum domínio cadastrado.</div>
    <?php else: ?>
        <div class="domain-list">
            <?php foreach ($domains as $domain): ?>
                <div class="domain-item">
                    <code><?= e($domain) ?></code>
                    <form method="post" data-confirm="Remover <?= e($domain) ?> da lista?">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="remove_domain">
                        <input type="hidden" name="domain" value="<?= e($domain) ?>">
                        <button type="submit" class="btn danger small">Remover</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" class="mt-16">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_domain">

        <div class="field">
            <label for="domain">Adicionar domínio</label>
            <div class="input-action-row">
                <input id="domain" name="domain" type="text" required
                       placeholder="seusite.com ou *.seusite.com">
                <button class="btn primary" type="submit">Adicionar domínio</button>
            </div>
            <p class="hint">Aceita com ou sem <code>https://</code>. Um domínio por vez.</p>
        </div>
    </form>

    <div class="help-box">
        Exemplos: <code>site.com</code>, <code>www.site.com</code>, <code>*.site.com</code>,
        <code>parceiro.com</code>.
        O domínio com <code>www</code> e sem <code>www</code> são entradas diferentes.
    </div>
</section>

<?php admin_footer(); ?>
