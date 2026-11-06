<?php
declare(strict_types=1);

/**
 * Serviço de Proteção e Blindagem de Arquivos de Canais / Streams
 */

/**
 * Lista todos os arquivos protegidos salvos.
 *
 * @param PDO $pdo
 * @return array<array<string, mixed>>
 */
function file_shield_list(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT * FROM `protected_files` ORDER BY `name` ASC, `id` DESC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Obtém os dados de um arquivo protegido pelo ID.
 *
 * @param PDO $pdo
 * @param int $id
 * @return array<string, mixed>|null
 */
function file_shield_get(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM `protected_files` WHERE `id` = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Salva ou atualiza um arquivo protegido no banco de dados.
 *
 * @param PDO $pdo
 * @param array<string, mixed> $data
 * @param int|null $id
 * @return int ID do registro criado ou atualizado
 */
function file_shield_save(PDO $pdo, array $data, ?int $id = null): int
{
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        $name = 'Canal Protegido';
    }

    $filename = trim((string)($data['filename'] ?? 'canal.php'));
    if ($filename === '') {
        $filename = 'canal.php';
    }
    if (!str_ends_with(strtolower($filename), '.php')) {
        $filename .= '.php';
    }
    // Higieniza nome de arquivo
    $filename = preg_replace('/[^a-zA-Z0-9_\.-]/', '', $filename) ?: 'canal.php';

    $allowedDomains = normalize_domains((string)($data['allowed_domains'] ?? ''));
    $blockDirect = !empty($data['block_direct']) ? 1 : 0;
    $blockIframe = !empty($data['block_iframe']) ? 1 : 0;
    $antiAdblock = !empty($data['anti_adblock']) ? 1 : 0;
    $antiSandbox = !empty($data['anti_sandbox']) ? 1 : 0;
    $antiInspect = !empty($data['anti_inspect']) ? 1 : 0;
    $obfuscate = !empty($data['obfuscate']) ? 1 : 0;

    $redirectUrl = trim((string)($data['redirect_url'] ?? ''));
    if ($redirectUrl !== '' && !valid_http_url($redirectUrl)) {
        $redirectUrl = '';
    }

    $popupAds = (string)($data['popup_ads'] ?? '');
    $sourceCode = (string)($data['source_code'] ?? '');

    if ($id !== null && $id > 0) {
        $stmt = $pdo->prepare('
            UPDATE `protected_files`
            SET `name` = ?,
                `filename` = ?,
                `allowed_domains` = ?,
                `block_direct` = ?,
                `block_iframe` = ?,
                `anti_adblock` = ?,
                `anti_sandbox` = ?,
                `anti_inspect` = ?,
                `obfuscate` = ?,
                `redirect_url` = ?,
                `popup_ads` = ?,
                `source_code` = ?,
                `updated_at` = NOW()
            WHERE `id` = ?
        ');
        $stmt->execute([
            $name,
            $filename,
            $allowedDomains,
            $blockDirect,
            $blockIframe,
            $antiAdblock,
            $antiSandbox,
            $antiInspect,
            $obfuscate,
            $redirectUrl,
            $popupAds,
            $sourceCode,
            $id,
        ]);
        return $id;
    }

    $stmt = $pdo->prepare('
        INSERT INTO `protected_files`
        (`name`, `filename`, `allowed_domains`, `block_direct`, `block_iframe`, `anti_adblock`, `anti_sandbox`, `anti_inspect`, `obfuscate`, `redirect_url`, `popup_ads`, `source_code`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    $stmt->execute([
        $name,
        $filename,
        $allowedDomains,
        $blockDirect,
        $blockIframe,
        $antiAdblock,
        $antiSandbox,
        $antiInspect,
        $obfuscate,
        $redirectUrl,
        $popupAds,
        $sourceCode,
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Exclui um arquivo protegido do banco.
 *
 * @param PDO $pdo
 * @param int $id
 * @return bool
 */
function file_shield_delete(PDO $pdo, int $id): bool
{
    if ($id <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('DELETE FROM `protected_files` WHERE `id` = ?');
    return $stmt->execute([$id]);
}

/**
 * Codifica o payload de canal com cifra reversível com chave aleatória
 * para decodificação em runtime no navegador via TextDecoder.
 *
 * @param string $source
 * @return array{key: int, packed: string}
 */
function file_shield_obfuscate_payload(string $source): array
{
    if ($source === '') {
        return ['key' => 42, 'packed' => ''];
    }

    $b64 = base64_encode($source);
    $key = random_int(13, 89);
    $len = strlen($b64);
    $codes = [];

    for ($i = 0; $i < $len; $i++) {
        $codes[] = ord($b64[$i]) ^ $key;
    }

    return [
        'key' => $key,
        'packed' => implode(',', $codes),
    ];
}

/**
 * Gera o trecho PHP de proteção server-side (para colar no topo do arquivo).
 *
 * @param array<string, mixed> $data
 * @return string
 */
function file_shield_generate_php_snippet(array $data): string
{
    $domainsList = domains_list(normalize_domains((string)($data['allowed_domains'] ?? '')));
    $blockDirect = !empty($data['block_direct']);
    $blockIframe = !empty($data['block_iframe']);
    $redirectUrl = trim((string)($data['redirect_url'] ?? ''));
    $name = (string)($data['name'] ?? 'Canal Protegido');

    $exportDomains = var_export($domainsList, true);
    $exportRedirect = var_export($redirectUrl, true);
    $exportBlockDirect = $blockDirect ? 'true' : 'false';
    $exportBlockIframe = $blockIframe ? 'true' : 'false';

    return <<<PHP
<?php
/**
 * Evo Links TV · Shield Protection Header
 * Canal: {$name}
 */
(function() {
    \$allowedDomains   = {$exportDomains};
    \$blockDirect       = {$exportBlockDirect};
    \$blockIframe       = {$exportBlockIframe};
    \$redirectUrl       = {$exportRedirect};

    // 1. Detecção de cabeçalhos e origem
    \$secFetchDest = strtolower((string)(\$_SERVER['HTTP_SEC_FETCH_DEST'] ?? ''));
    \$secFetchMode = strtolower((string)(\$_SERVER['HTTP_SEC_FETCH_MODE'] ?? ''));
    \$referer = trim((string)(\$_SERVER['HTTP_REFERER'] ?? ''));
    \$refererHost = '';

    if (\$referer !== '') {
        \$parsedHost = parse_url(\$referer, PHP_URL_HOST);
        if (is_string(\$parsedHost)) {
            \$refererHost = strtolower(\$parsedHost);
        }
    }

    // É acesso direto se não há referer ou se foi chamado diretamente como documento
    \$isDirectAccess = (\$referer === '' || \$secFetchDest === 'document');

    // 2. Validação de domínio permitido
    \$isDomainAllowed = false;
    if (!empty(\$allowedDomains)) {
        if (\$refererHost !== '') {
            foreach (\$allowedDomains as \$domain) {
                \$domain = strtolower(trim(\$domain));
                if (\$domain === \$refererHost) {
                    \$isDomainAllowed = true;
                    break;
                }
                if (str_starts_with(\$domain, '*.') && str_ends_with(\$refererHost, '.' . substr(\$domain, 2))) {
                    \$isDomainAllowed = true;
                    break;
                }
            }
        }
    } else {
        // Se a lista de domínios estiver vazia, não restringe domínio específico
        \$isDomainAllowed = true;
    }

    // 3. Regra de Bloqueio
    \$blocked = false;
    if (\$blockDirect && \$isDirectAccess) {
        \$blocked = true;
    } elseif (\$blockIframe && !empty(\$allowedDomains) && !\$isDomainAllowed) {
        \$blocked = true;
    }

    if (\$blocked) {
        if (\$redirectUrl !== '' && filter_var(\$redirectUrl, FILTER_VALIDATE_URL)) {
            header('Location: ' . \$redirectUrl, true, 302);
            exit;
        }

        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Acesso Restrito</title>'
            . '<style>'
            . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#05080e;color:#c0cee0;font-family:system-ui,sans-serif;padding:20px;box-sizing:border-box;text-align:center;}'
            . '.card{max-width:400px;padding:32px 24px;background:rgba(11,17,30,0.95);border:1px solid rgba(255,255,255,0.08);border-radius:14px;box-shadow:0 20px 40px rgba(0,0,0,0.6);}'
            . '.badge{display:inline-block;padding:4px 10px;border-radius:999px;background:rgba(255,46,86,0.12);color:#ff2e56;font-size:11px;font-weight:700;margin-bottom:12px;border:1px solid rgba(255,46,86,0.3);}'
            . 'h2{margin:0 0 8px;font-size:18px;color:#fff;}'
            . 'p{margin:0;font-size:13px;color:#7d90a6;line-height:1.5;}'
            . '</style></head>'
            . '<body><div class="card"><span class="badge">ACESSO NEGADO · 403</span><h2>Transmissão Restrita</h2><p>A reprodução deste sinal é autorizada exclusivamente nos sites parceiros autorizados.</p></div></body></html>';
        exit;
    }

    // 4. Cabeçalhos de Segurança e CSP Anti-Clickjacking
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');

    if (\$blockIframe && !empty(\$allowedDomains)) {
        \$cspAncestors = ["'self'"];
        foreach (\$allowedDomains as \$dom) {
            \$dom = strtolower(trim(\$dom));
            if (\$dom === '') continue;
            if (\$dom === 'localhost' || \$dom === '127.0.0.1') {
                \$cspAncestors[] = 'http://localhost:*';
                \$cspAncestors[] = 'http://127.0.0.1:*';
                \$cspAncestors[] = 'https://localhost:*';
                \$cspAncestors[] = 'https://127.0.0.1:*';
                continue;
            }
            if (str_starts_with(\$dom, '*.')) {
                \$base = substr(\$dom, 2);
                \$cspAncestors[] = 'https://' . \$dom;
                \$cspAncestors[] = 'https://' . \$base;
                \$cspAncestors[] = 'http://' . \$dom;
                \$cspAncestors[] = 'http://' . \$base;
            } else {
                \$cspAncestors[] = 'https://' . \$dom;
                \$cspAncestors[] = 'http://' . \$dom;
            }
        }
        \$cspStr = implode(' ', array_values(array_unique(\$cspAncestors)));
        header("Content-Security-Policy: frame-ancestors " . \$cspStr . ";");
    }
})();
?>
PHP;
}

/**
 * Gera o trecho JavaScript e HTML do cliente (anti-adblock, anti-sandbox, ofuscação, anúncios).
 *
 * @param array<string, mixed> $data
 * @return string
 */
function file_shield_generate_client_snippet(array $data): string
{
    $antiAdblock = !empty($data['anti_adblock']);
    $antiSandbox = !empty($data['anti_sandbox']);
    $antiInspect = !empty($data['anti_inspect']);
    $blockDirect = !empty($data['block_direct']);
    $obfuscate   = !empty($data['obfuscate']);
    $popupAds    = trim((string)($data['popup_ads'] ?? ''));
    $sourceCode  = (string)($data['source_code'] ?? '');
    $redirectUrl = trim((string)($data['redirect_url'] ?? ''));

    // Obfuscação do código do player/canal
    $payloadData = $obfuscate ? file_shield_obfuscate_payload($sourceCode) : ['key' => 0, 'packed' => ''];
    $jsonSource = json_encode($sourceCode, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $jsonAds = json_encode($popupAds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $jsonRedirect = json_encode($redirectUrl, JSON_UNESCAPED_SLASHES);

    $jsAntiDirect = $blockDirect ? "
        // Bloqueio de Abertura Direta no Navegador (Client-Side)
        if (window.top === window.self) {
            document.body.innerHTML = '<div class=\"shield-warn\"><div class=\"shield-card\"><span class=\"shield-badge\">ACESSO DIRETO BLOQUEADO</span><h3>Acesso Indisponível</h3><p>Este canal não pode ser aberto diretamente na barra do navegador. Assista através do site oficial incorporado.</p></div></div>';
            return;
        }
    " : "";

    $jsAntiSandbox = $antiSandbox ? "
        // Anti-Sandbox Heurístico (Impede neutralização do player em iframe restrito)
        function checkSandbox() {
            try {
                if (window.frameElement && typeof window.frameElement.hasAttribute === 'function' && window.frameElement.hasAttribute('sandbox')) {
                    return true;
                }
            } catch(e) {}
            try {
                if (document.domain === null || document.domain === '') return true;
                document.domain = document.domain;
            } catch(e) {
                return /sandbox/i.test(String(e)) || document.domain === null || document.domain === '';
            }
            return false;
        }
        if (checkSandbox()) {
            document.body.innerHTML = '<div class=\"shield-warn\"><div class=\"shield-card\"><span class=\"shield-badge\">SANDBOX DETECTADO</span><h3>Embed Não Autorizado</h3><p>A visualização deste canal foi bloqueada devido a atributos de restrição (sandbox) no site transmissor.</p></div></div>';
            return;
        }
    " : "";

    $jsAntiAdblock = $antiAdblock ? "
        // Detecção Anti-Adblock via Isca
        function checkAdblock() {
            var bait = document.querySelector('.adsbox');
            return !bait || bait.offsetHeight === 0 || window.getComputedStyle(bait).display === 'none';
        }
        setTimeout(function() {
            if (checkAdblock()) {
                document.body.innerHTML = '<div class=\"shield-warn\"><div class=\"shield-card\"><span class=\"shield-badge\">BLOQUEADOR DE ANÚNCIOS ATIVO</span><h3>Adblock Detectado</h3><p>Por favor, desative o Adblock ou bloqueador de anúncios no seu navegador para liberar a transmissão deste canal.</p><button onclick=\"location.reload()\" class=\"shield-btn\">Já desativei, recarregar</button></div></div>';
            }
        }, 800);
    " : "";

    $jsAntiInspect = $antiInspect ? "
        // Anti-Inspeção / DevTools Protection
        document.addEventListener('contextmenu', function(e) { e.preventDefault(); }, true);
        document.addEventListener('keydown', function(e) {
            // F12, Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+U, Ctrl+S
            if (e.keyCode === 123 ||
                (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74 || e.keyCode === 67)) ||
                (e.ctrlKey && (e.keyCode === 85 || e.keyCode === 83))) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }
        }, true);
        setInterval(function() {
            var start = performance.now();
            debugger;
            if (performance.now() - start > 150) {
                document.body.innerHTML = '<div class=\"shield-warn\"><div class=\"shield-card\"><h3>Inspeção Não Permitida</h3></div></div>';
            }
        }, 2000);
    " : "";

    $jsAdsMount = $popupAds !== '' ? "
        // Injeção Universal de Anúncios, Popups & Analytics
        (function() {
            var adHtml = {$jsonAds};
            if (!adHtml || !adHtml.trim()) return;

            try {
                var container = document.createElement('div');
                container.id = 'shield-ads-container';
                container.style.cssText = 'position:absolute;top:0;left:0;width:100%;z-index:10;pointer-events:auto;';

                var temp = document.createElement('div');
                temp.innerHTML = adHtml;

                var scripts = Array.from(temp.querySelectorAll('script'));
                var nonScripts = Array.from(temp.childNodes).filter(function(n) { return n.nodeName !== 'SCRIPT'; });

                nonScripts.forEach(function(node) { container.appendChild(node.cloneNode(true)); });
                document.body.appendChild(container);

                if (scripts.length > 0) {
                    var trigger = document.createElement('div');
                    trigger.id = 'shield-popunder-trigger';
                    trigger.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;z-index:8;background:transparent;cursor:pointer;';
                    var onFirstClick = function() {
                        try { trigger.remove(); } catch(e) {}
                    };
                    trigger.addEventListener('click', onFirstClick, { once: true });
                    trigger.addEventListener('touchstart', onFirstClick, { once: true, passive: true });
                    document.body.appendChild(trigger);
                }

                function runScriptAtIndex(idx) {
                    if (idx >= scripts.length) return;
                    var oldScript = scripts[idx];
                    try {
                        var newScript = document.createElement('script');
                        Array.from(oldScript.attributes).forEach(function(attr) {
                            newScript.setAttribute(attr.name, attr.value);
                        });
                        var next = function() { runScriptAtIndex(idx + 1); };
                        if (oldScript.src) {
                            var isAsync = oldScript.async || oldScript.defer;
                            newScript.onload = next;
                            newScript.onerror = next;
                            newScript.src = oldScript.src;
                            document.head.appendChild(newScript);
                            if (isAsync) next();
                        } else {
                            newScript.textContent = oldScript.textContent;
                            document.head.appendChild(newScript);
                            next();
                        }
                    } catch(e) {
                        runScriptAtIndex(idx + 1);
                    }
                }
                runScriptAtIndex(0);
            } catch(e) {}
        })();
    " : "";

    $mountPlayerCode = "";
    if ($obfuscate && $payloadData['packed'] !== '') {
        $keyVal = $payloadData['key'];
        $packedCodes = $payloadData['packed'];
        $mountPlayerCode = <<<JS
        // Decodificação e Montagem do Player Ofuscado em Memória
        (function() {
            var target = document.getElementById('shield-target');
            if (!target) return;
            try {
                var k = {$keyVal};
                var codes = [{$packedCodes}];
                var b64 = '';
                for (var i = 0; i < codes.length; i++) {
                    b64 += String.fromCharCode(codes[i] ^ k);
                }
                var rawBin = atob(b64);
                var bytes = new Uint8Array(rawBin.length);
                for (var j = 0; j < rawBin.length; j++) {
                    bytes[j] = rawBin.charCodeAt(j);
                }
                var html = new TextDecoder('utf-8').decode(bytes);
                target.innerHTML = '';
                var range = document.createRange();
                range.selectNode(target);
                var frag = range.createContextualFragment(html);
                target.appendChild(frag);
            } catch(e) {
                target.innerHTML = '<div class=\"shield-warn\"><div class=\"shield-card\"><p>Falha ao inicializar o player.</p></div></div>';
            }
        })();
JS;
    } else {
        $mountPlayerCode = <<<JS
        // Montagem Direta do Código do Canal
        (function() {
            var target = document.getElementById('shield-target');
            if (!target) return;
            var html = {$jsonSource};
            try {
                target.innerHTML = '';
                var range = document.createRange();
                range.selectNode(target);
                var frag = range.createContextualFragment(html);
                target.appendChild(frag);
            } catch(e) {
                target.innerHTML = html;
            }
        })();
JS;
    }

    return <<<HTML
<!-- Início da Camada de Proteção Shield Client -->
<div class="adsbox ad-zone ad-banner pub_300x250 pub_728x90" style="position:absolute;left:-9999px;width:1px;height:1px;pointer-events:none;" aria-hidden="true">&nbsp;</div>
<div id="shield-target" style="width:100%;height:100%;position:absolute;inset:0;"></div>

<script>
(function() {
    'use strict';
{$jsAntiDirect}
{$jsAntiSandbox}
{$jsAntiInspect}
{$jsAntiAdblock}
{$jsAdsMount}
{$mountPlayerCode}
})();
</script>
<!-- Fim da Camada de Proteção Shield Client -->
HTML;
}

/**
 * Gera o arquivo PHP completo e autocontido pronto para ser hospedado no canal (ex.: espn.php).
 *
 * @param array<string, mixed> $data
 * @return string
 */
function file_shield_generate_full_file(array $data): string
{
    $name = (string)($data['name'] ?? 'Canal Protegido');
    $sourceCode = trim((string)($data['source_code'] ?? ''));
    $phpHeader = file_shield_generate_php_snippet($data);

    // Se o código fornecido já for um arquivo HTML/PHP completo (ex.: p.php com Clappr/Shaka)
    if (str_starts_with(strtolower($sourceCode), '<!doctype') || str_contains(strtolower($sourceCode), '<html')) {
        $finalHtml = $sourceCode;

        // Se anti-inspeção estiver ativo, injeta script de bloqueio de F12 e View Source antes de </body>
        if (!empty($data['anti_inspect']) && str_contains(strtolower($finalHtml), '</body>')) {
            $inspectScript = <<<HTML

<script>
// Evo Links TV · Proteção Anti-DevTools & Proteção de Chaves DRM
(function() {
    document.addEventListener('contextmenu', function(e) { e.preventDefault(); }, true);
    document.addEventListener('keydown', function(e) {
        // F12, Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+Shift+C, Ctrl+U, Ctrl+S
        if (e.keyCode === 123 ||
            (e.ctrlKey && e.shiftKey && (e.keyCode === 73 || e.keyCode === 74 || e.keyCode === 67)) ||
            (e.ctrlKey && (e.keyCode === 85 || e.keyCode === 83))) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
    }, true);
    setInterval(function() {
        var start = performance.now();
        debugger;
        if (performance.now() - start > 150) {
            document.body.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;height:100vh;background:#000;color:#fff;font-family:sans-serif;"><h3>Inspeção não permitida.</h3></div>';
        }
    }, 2000);
})();
</script>
</body>
HTML;
            $finalHtml = preg_replace('/<\/body>/i', $inspectScript, $finalHtml, 1) ?? $finalHtml;
        }

        // Se houver anúncios popup para injetar e existir <body>
        $rawPopupAds = trim((string)($data['popup_ads'] ?? ''));
        $popupAds = preg_replace('/<\?php.*?\?>/is', '', $rawPopupAds) ?? $rawPopupAds;
        $popupAds = trim($popupAds);

        if ($popupAds !== '' && str_contains(strtolower($finalHtml), '<body')) {
            $jsonAds = json_encode($popupAds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $adsScript = <<<HTML

<script>
// Evo Links TV · Injeção Universal de Anúncios, Popups & Analytics
(function() {
    var adHtml = {$jsonAds};
    if (!adHtml || !adHtml.trim()) return;

    try {
        var container = document.createElement('div');
        container.id = 'shield-ads-container';
        container.style.cssText = 'position:absolute;top:0;left:0;width:100%;z-index:10;pointer-events:auto;';

        var temp = document.createElement('div');
        temp.innerHTML = adHtml;

        var scripts = Array.from(temp.querySelectorAll('script'));
        var nonScripts = Array.from(temp.childNodes).filter(function(n) { return n.nodeName !== 'SCRIPT'; });

        nonScripts.forEach(function(node) { container.appendChild(node.cloneNode(true)); });
        document.body.appendChild(container);

        if (scripts.length > 0) {
            var trigger = document.createElement('div');
            trigger.id = 'shield-popunder-trigger';
            trigger.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;z-index:8;background:transparent;cursor:pointer;';
            var onFirstClick = function() {
                try { trigger.remove(); } catch(e) {}
            };
            trigger.addEventListener('click', onFirstClick, { once: true });
            trigger.addEventListener('touchstart', onFirstClick, { once: true, passive: true });
            document.body.appendChild(trigger);
        }

        function runScriptAtIndex(idx) {
            if (idx >= scripts.length) return;
            var oldScript = scripts[idx];
            try {
                var newScript = document.createElement('script');
                Array.from(oldScript.attributes).forEach(function(attr) {
                    newScript.setAttribute(attr.name, attr.value);
                });
                var next = function() { runScriptAtIndex(idx + 1); };
                if (oldScript.src) {
                    var isAsync = oldScript.async || oldScript.defer;
                    newScript.onload = next;
                    newScript.onerror = next;
                    newScript.src = oldScript.src;
                    document.head.appendChild(newScript);
                    if (isAsync) next();
                } else {
                    newScript.textContent = oldScript.textContent;
                    document.head.appendChild(newScript);
                    next();
                }
            } catch(e) {
                runScriptAtIndex(idx + 1);
            }
        }
        runScriptAtIndex(0);
    } catch(e) {}
})();
</script>
HTML;
            $finalHtml = preg_replace('/<\/body>/i', $adsScript . "\n</body>", $finalHtml, 1) ?? $finalHtml;
        }

        return $phpHeader . "\n" . $finalHtml;
    }

    $clientBody = file_shield_generate_client_snippet($data);

    return <<<PHP
{$phpHeader}
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>{$name}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: #000;
            color: #fff;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        #shield-target {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #shield-target iframe {
            width: 100%;
            height: 100%;
            border: 0;
            display: block;
        }
        .shield-warn {
            position: fixed;
            inset: 0;
            z-index: 999999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: radial-gradient(circle at 50% 30%, rgba(0, 240, 255, 0.05) 0%, transparent 60%), #05080e;
        }
        .shield-card {
            width: 100%;
            max-width: 420px;
            padding: 32px 24px;
            background: rgba(11, 17, 30, 0.96);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            text-align: center;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.7);
        }
        .shield-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(255, 46, 86, 0.12);
            border: 1px solid rgba(255, 46, 86, 0.3);
            color: #ff2e56;
            margin-bottom: 12px;
        }
        .shield-card h3 {
            font-size: 19px;
            color: #fff;
            margin: 0 0 10px;
        }
        .shield-card p {
            font-size: 13.5px;
            color: #7d90a6;
            line-height: 1.5;
            margin: 0 0 16px;
        }
        .shield-btn {
            display: inline-block;
            padding: 9px 18px;
            font-size: 13px;
            font-weight: 600;
            color: #fff;
            background: #ff2e56;
            border: 0;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            transition: opacity 0.2s;
        }
        .shield-btn:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
{$clientBody}
</body>
</html>
PHP;
}

/**
 * Organiza e limpa código de player HTML/JS/PHP de forma determinística (sem IA).
 *
 * @param string $sourceCode
 * @return array{ok: bool, mode: string, cleaned_code: string, detected_player: string, threats_removed: list<string>, summary: string, error: ?string}
 */
function file_shield_clean_code_deterministic(string $sourceCode): array
{
    $code = trim($sourceCode);
    if ($code === '') {
        return [
            'ok'              => false,
            'mode'            => 'deterministic',
            'cleaned_code'    => '',
            'detected_player' => 'Nenhum',
            'threats_removed' => [],
            'summary'         => 'Código-fonte vazio.',
            'error'           => 'O campo de código está vazio. Cole o código do player antes de organizar.',
        ];
    }

    // 1. Normalização de quebras de linha e remoção de caracteres nulos
    $code = str_replace(["\r\n", "\r"], "\n", $code);
    $code = str_replace("\0", '', $code);

    // 2. Detecção de tecnologia do player
    $detectedPlayer = 'HTML / Embed Genérico';
    if (preg_match('/Clappr\.Player|clappr\.min\.js/i', $code)) {
        $detectedPlayer = 'Clappr Player';
    } elseif (preg_match('/videojs|video-js/i', $code)) {
        $detectedPlayer = 'Video.js';
    } elseif (preg_match('/shaka\.Player|shaka-player/i', $code)) {
        $detectedPlayer = 'Shaka Player';
    } elseif (preg_match('/Hls\.isSupported|hls\.js/i', $code)) {
        $detectedPlayer = 'Hls.js';
    } elseif (preg_match('/jwplayer/i', $code)) {
        $detectedPlayer = 'JW Player';
    } elseif (preg_match('/<iframe/i', $code)) {
        $detectedPlayer = 'Iframe Incorporado';
    } elseif (preg_match('/<video/i', $code)) {
        $detectedPlayer = 'HTML5 Video';
    }

    // 3. Detecção e remoção segura de scripts suspeitos conhecidos (adware/trackers/miners)
    $threatsRemoved = [];
    $patternsToRemove = [
        '#<script[^>]*src=["\'][^"\']*(?:coinhive|cryptoloot|popcash|adsterra|propellerads|popads|histats)[^"\']*["\'][^>]*>\s*<\/script>#is' => 'Script de rede de anúncio/minerador invasivo de terceiros',
        '#<iframe[^>]*src=["\'](?:about:blank|javascript:void\(0\))["\'][^>]*style=["\'][^"\']*display:\s*none[^"\']*["\'][^>]*><\/iframe>#is' => 'Iframe invisível oculto de terceiros',
    ];

    foreach ($patternsToRemove as $pat => $desc) {
        if (preg_match($pat, $code)) {
            $code = (string)preg_replace($pat, '', $code);
            $threatsRemoved[] = $desc;
        }
    }

    // 4. Limpeza de linhas em branco excessivas (máximo 2 seguidas) e espaços à direita
    $lines = explode("\n", $code);
    $cleanedLines = [];
    $blankCount = 0;
    foreach ($lines as $line) {
        $trimmed = rtrim($line);
        if ($trimmed === '') {
            $blankCount++;
            if ($blankCount <= 2) {
                $cleanedLines[] = '';
            }
        } else {
            $blankCount = 0;
            $cleanedLines[] = $trimmed;
        }
    }
    $cleanedCode = trim(implode("\n", $cleanedLines));

    return [
        'ok'              => true,
        'mode'            => 'deterministic',
        'cleaned_code'    => $cleanedCode,
        'detected_player' => $detectedPlayer,
        'threats_removed' => $threatsRemoved,
        'summary'         => sprintf('Player: %s. Indentação e quebras padronizadas sem alterar streams.', $detectedPlayer),
        'error'           => null,
    ];
}

/**
 * Organiza, limpa e formata código de player usando DeepSeek (ou modelo OpenRouter).
 * Preserva rigorosamente URLs de streaming, tokens e chaves DRM.
 *
 * @param PDO $pdo
 * @param string $sourceCode
 * @param string $model
 * @return array{ok: bool, mode: string, cleaned_code: string, detected_player: string, threats_removed: list<string>, summary: string, error: ?string}
 */
function file_shield_clean_code_ai(PDO $pdo, string $sourceCode, string $model = ''): array
{
    $code = trim($sourceCode);
    if ($code === '') {
        return [
            'ok'              => false,
            'mode'            => 'ai',
            'cleaned_code'    => '',
            'detected_player' => 'Nenhum',
            'threats_removed' => [],
            'summary'         => 'Código-fonte vazio.',
            'error'           => 'O campo de código está vazio. Cole o código do player antes de organizar.',
        ];
    }

    if (!openrouter_is_configured($pdo)) {
        $fallback = file_shield_clean_code_deterministic($code);
        $fallback['error'] = 'Chave OpenRouter não configurada em Configurações. Foi aplicada a organização determinística sem IA.';
        return $fallback;
    }

    $targetModel = trim($model);
    if ($targetModel === '') {
        $targetModel = 'deepseek/deepseek-chat';
    }

    $systemPrompt = <<<SYS
Você é um engenheiro sênior especialista em web streaming, players de vídeo HTML5, JavaScript e segurança web.
Sua missão é inspecionar, limpar, indentar e organizar o código-fonte de um player de canal fornecido pelo usuário.

REGRAS ABSOLUTAS:
1. PRESERVAÇÃO TOTAL: NUNCA altere, quebre ou remova URLs de transmissão (.m3u8, .mpd, links http/https, tokens de autenticação como wmsAuthSign/token/expires, nem chaves DRM/ClearKey).
2. REMOÇÃO DE LIXO: Remova apenas scripts invasivos de terceiros comprovadamente inúteis (adwares, redirecionamentos ocultos, popunders externos indesejados ou mineradores).
3. ESTRUTURAÇÃO: Indente o HTML, CSS e JavaScript de forma limpa, moderna e legível.
4. RETORNO ESTRITO EM JSON: Responda APENAS com um objeto JSON válido, sem tags markdown ou comentários fora do JSON.
SYS;

    $userPrompt = <<<PROMPT
Organize e limpe o seguinte código de player de streaming.

Retorne EXATAMENTE este schema JSON:
{
  "cleaned_code": "o código HTML/JS/PHP completo organizado, limpo e perfeitamente indentado",
  "detected_player": "tecnologia detectada (ex: Clappr, Video.js, Shaka Player, Hls.js, Iframe)",
  "threats_removed": ["lista de anúncios indesejados, rastreadores ou lixos removidos (ou vazio se limpo)"],
  "summary": "resumo em 1 frase do que foi feito"
}

CÓDIGO ORIGINAL:
{$code}
PROMPT;

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt],
    ];

    $res = openrouter_chat($pdo, $messages, [
        'model'       => $targetModel,
        'temperature' => 0.1,
        'json'        => true,
        'timeout'     => 60,
    ]);

    if (!$res['ok']) {
        $fallback = file_shield_clean_code_deterministic($code);
        $fallback['error'] = 'Erro na API OpenRouter (' . ($res['error'] ?? 'Falha de conexão') . '). Foi aplicada a organização determinística como contingência.';
        return $fallback;
    }

    $rawContent = trim($res['content']);
    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $rawContent, $m)) {
        $rawContent = $m[1];
    }

    $decoded = json_decode($rawContent, true);
    if (!is_array($decoded) || empty($decoded['cleaned_code'])) {
        $fallback = file_shield_clean_code_deterministic($code);
        $fallback['error'] = 'A IA não retornou um JSON válido. Foi aplicada a organização determinística como contingência.';
        return $fallback;
    }

    $cleanedCode = trim((string)$decoded['cleaned_code']);
    $detectedPlayer = trim((string)($decoded['detected_player'] ?? 'Player Web'));
    $threatsRemoved = is_array($decoded['threats_removed'] ?? null) ? $decoded['threats_removed'] : [];
    $summary = trim((string)($decoded['summary'] ?? 'Código organizado com sucesso pelo DeepSeek.'));

    return [
        'ok'              => true,
        'mode'            => 'ai',
        'cleaned_code'    => $cleanedCode,
        'detected_player' => $detectedPlayer,
        'threats_removed' => $threatsRemoved,
        'summary'         => $summary,
        'error'           => null,
    ];
}

