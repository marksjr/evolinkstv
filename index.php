<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

function render_player_error(int $code, string $badge, string $title, string $message, ?string $redirectUrl = null, string $brandName = 'Player'): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?= e($title) ?> · <?= e($brandName) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            height: 100%;
            background: #05080e;
            color: #c0cee0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            -webkit-font-smoothing: antialiased;
            overflow: hidden;
        }
        .error-stage {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: radial-gradient(circle at 50% 30%, rgba(0, 240, 255, 0.05) 0%, transparent 60%),
                        radial-gradient(circle at 50% 100%, rgba(255, 46, 86, 0.04) 0%, transparent 50%),
                        #05080e;
        }
        .error-card {
            width: 100%;
            max-width: 420px;
            padding: clamp(20px, 4vw, 32px);
            background: rgba(11, 17, 30, 0.92);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            text-align: center;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.55);
            backdrop-filter: blur(12px);
        }
        .error-icon-box {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: rgba(255, 46, 86, 0.12);
            border: 1px solid rgba(255, 46, 86, 0.3);
            color: #ff2e56;
            margin-bottom: 14px;
        }
        .error-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            padding: 3px 10px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #8fa0b5;
            margin-bottom: 10px;
        }
        .error-title {
            font-size: clamp(16px, 3.5vw, 20px);
            font-weight: 700;
            color: #ffffff;
            letter-spacing: -0.02em;
            margin-bottom: 8px;
        }
        .error-desc {
            font-size: clamp(12.5px, 2.8vw, 13.5px);
            line-height: 1.5;
            color: #7d90a6;
            margin-bottom: 0;
        }
        .error-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 18px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            color: #fff;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .error-btn:hover {
            background: rgba(255, 255, 255, 0.14);
            border-color: rgba(255, 255, 255, 0.25);
        }
    </style>
</head>
<body>
<div class="error-stage">
    <div class="error-card">
        <div class="error-icon-box">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="7" width="20" height="15" rx="2" ry="2"/>
                <polyline points="17 2 12 7 7 2"/>
                <line x1="2" y1="17" x2="22" y2="17"/>
            </svg>
        </div>
        <div><span class="error-badge"><?= e($badge) ?></span></div>
        <h1 class="error-title"><?= e($title) ?></h1>
        <p class="error-desc"><?= e($message) ?></p>
        <?php if ($redirectUrl && valid_http_url($redirectUrl)): ?>
            <a href="<?= e($redirectUrl) ?>" class="error-btn">Voltar para o site</a>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

$slug = isset($_GET['v']) && is_string($_GET['v'])
    ? strtolower(trim($_GET['v']))
    : '';

if (!valid_slug($slug)) {
    render_player_error(
        404,
        'Sinal Indisponível · 404',
        'Canal não encontrado',
        'Nenhum canal válido foi especificado no parâmetro de transmissão.'
    );
}

$stmt = $pdo->prepare('SELECT * FROM channels WHERE slug = ? AND active = 1 LIMIT 1');
$stmt->execute([$slug]);
$channel = $stmt->fetch();

if (!$channel) {
    render_player_error(
        404,
        'Sinal Indisponível · 404',
        'Canal não encontrado',
        'O canal solicitado não existe ou está temporariamente fora do ar.'
    );
}

$protect        = setting_bool($pdo, 'protect_iframe', true);
$blockDirect    = setting_bool($pdo, 'block_direct_access', true);
$antiAdblock    = setting_bool($pdo, 'anti_adblock', true);
$antiSandbox    = setting_bool($pdo, 'anti_sandbox', true);
$inspectProtect = setting_bool($pdo, 'inspect_protection', true);
$domains        = domains_list(normalize_domains(setting($pdo, 'allowed_domains', '')));
$redirectUrl    = setting($pdo, 'redirect_url', '');
$watermark      = setting($pdo, 'watermark_text', '');
$brandName      = setting($pdo, 'player_brand_name', 'Ao Vivo');

$previewToken = isset($_GET['preview']) && is_string($_GET['preview'])
    ? $_GET['preview']
    : '';
$isPreview = $previewToken !== '' && preview_valid($slug, $previewToken);

$referer = referer_host();
$hasReferer = trim((string)($_SERVER['HTTP_REFERER'] ?? '')) !== '';
$isDirect = !$hasReferer;

if (!$isPreview) {
    $deny = false;
    $denyReason = '';

    if ($isDirect && $blockDirect) {
        $deny = true;
        $denyReason = 'direct';
    } elseif ($protect) {
        $allowed = host_allowed($referer, $domains);
        if (!$allowed) {
            $deny = true;
            $denyReason = 'unauthorized';
        }
    }

    if ($deny) {
        if (valid_http_url($redirectUrl)) {
            header('Location: ' . $redirectUrl, true, 302);
            exit;
        }

        $dirTitle   = ($v = trim(setting($pdo, 'msg_direct_title', ''))) !== '' ? $v : 'Acesso Não Permitido';
        $dirDesc    = ($v = trim(setting($pdo, 'msg_direct_desc', ''))) !== '' ? $v : 'Este player não pode ser aberto diretamente na barra do navegador. Assista através do site oficial incorporado.';
        $unauthTitle = ($v = trim(setting($pdo, 'msg_unauthorized_title', ''))) !== '' ? $v : 'Acesso não autorizado';
        $unauthDesc  = ($v = trim(setting($pdo, 'msg_unauthorized_desc', ''))) !== '' ? $v : 'A reprodução deste canal é restrita a domínios previamente autorizados.';

        $badge = $denyReason === 'direct'
            ? $dirTitle
            : 'Acesso Restrito · 403';

        $title = $denyReason === 'direct'
            ? $dirTitle
            : $unauthTitle;

        $msgText = $denyReason === 'direct'
            ? $dirDesc
            : $unauthDesc;

        render_player_error(403, $badge, $title, $msgText, $redirectUrl, $brandName);
    }
}

$playerType = (string)($channel['player_type'] ?? 'auto');
$channelUrl = (string)$channel['url'];
$isNativePlayer = ($playerType === 'native' || ($playerType === 'auto' && is_stream_url($channelUrl)));

$globalAds = (string)setting($pdo, 'player_ads_code', '');
$channelAds = (string)($channel['popup_ads'] ?? '');
$combinedAds = trim($globalAds . "\n" . $channelAds);

// Remove eventuais tags PHP coladas acidentalmente pelo usuario para nao poluir o HTML
$clientAds = preg_replace('/<\?php.*?\?>/is', '', $combinedAds) ?? $combinedAds;
$clientAds = trim($clientAds);

$frameAncestors = $protect ? csp_ancestors($domains) : '*';

$scriptSources = ["'self'", "'unsafe-inline'", "'unsafe-eval'", 'https:', 'http:', 'blob:', 'data:'];
$scriptSrc = implode(' ', array_unique($scriptSources));

header(
    "Content-Security-Policy: default-src 'self' data: blob:; "
    . "script-src {$scriptSrc}; "
    . "style-src 'self' 'unsafe-inline' https: http:; "
    . "img-src * data: blob:; "
    . "media-src * blob: data:; "
    . "connect-src * blob: data:; "
    . "font-src * data:; "
    . "worker-src * blob:; "
    . "child-src * blob: data:; "
    . "frame-src *; "
    . "frame-ancestors {$frameAncestors}; "
    . "base-uri 'self'"
);

$payload = [
    'u'   => $channelUrl,
    'b'   => (string)($channel['url_backup'] ?? ''),
    't'   => $isNativePlayer ? 'native' : 'iframe',
    'k'   => (string)($channel['drm_key'] ?? ''),
    'a'   => $antiAdblock ? 1 : 0,
    's'   => $antiSandbox ? 1 : 0,
    'i'   => $inspectProtect ? 1 : 0,
    'w'   => $watermark,
    'r'   => valid_http_url($redirectUrl) ? $redirectUrl : '',
    'ads' => $clientAds,
    'msg' => [
        'sb_t' => ($v = trim(setting($pdo, 'msg_sandbox_title', ''))) !== '' ? $v : 'Sandbox detectado',
        'sb_d' => ($v = trim(setting($pdo, 'msg_sandbox_desc', ''))) !== '' ? $v : 'Este player não funciona dentro de um iframe com atributos de restrição (sandbox). Remova o sandbox para continuar.',
        'sb_b' => ($v = trim(setting($pdo, 'msg_sandbox_button', ''))) !== '' ? $v : 'Recarregar',
        'ab_t' => ($v = trim(setting($pdo, 'msg_adblock_title', ''))) !== '' ? $v : 'Desative o bloqueador de anúncios',
        'ab_d' => ($v = trim(setting($pdo, 'msg_adblock_desc', ''))) !== '' ? $v : 'Este player não funciona com AdBlock ativo. Desative o bloqueador para continuar.',
        'ab_b' => ($v = trim(setting($pdo, 'msg_adblock_button', ''))) !== '' ? $v : 'Já desativei — recarregar',
        'in_t' => ($v = trim(setting($pdo, 'msg_inspect_title', ''))) !== '' ? $v : 'Inspeção não permitida.',
    ],
];

$appKey = (string)($GLOBALS['config']['app']['key'] ?? ($config['app']['key'] ?? ''));
$blob = crypto_encrypt($payload, $appKey);
$keyParts = crypto_key_parts($appKey);

$vendorDir = __DIR__ . '/assets/vendor';
$useLocalVendor = file_exists($vendorDir . '/clappr.min.js')
    && file_exists($vendorDir . '/shaka-player.ui.js');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title><?= e($brandName) ?></title>
    <link rel="stylesheet" href="assets/player.css?v=4">
    <?php if ($isNativePlayer): ?>
        <?php if ($useLocalVendor): ?>
            <script src="assets/vendor/clappr.min.js"></script>
            <script src="assets/vendor/mux.min.js"></script>
            <script src="assets/vendor/clappr-pip.min.js"></script>
            <script src="assets/vendor/level-selector.min.js"></script>
            <script src="assets/vendor/shaka-player.ui.js"></script>
            <script src="assets/vendor/dash-shaka-playback.external.min.js"></script>
        <?php else: ?>
            <script src="https://cdn.jsdelivr.net/npm/@clappr/player@0.4.0/dist/clappr.min.js"></script>
            <script src="https://cdn.jsdelivr.net/npm/mux.js@5.6.7/dist/mux.min.js"></script>
            <script src="https://cdn.jsdelivr.net/npm/clappr-pip@latest/dist/clappr-pip.min.js"></script>
            <script src="https://cdn.jsdelivr.net/gh/clappr/clappr-level-selector-plugin@latest/dist/level-selector.min.js"></script>
            <script src="https://ajax.googleapis.com/ajax/libs/shaka-player/4.14.10/shaka-player.ui.js"></script>
            <script src="https://cdn.jsdelivr.net/gh/clappr/dash-shaka-playback@latest/dist/dash-shaka-playback.external.min.js"></script>
        <?php endif; ?>
    <?php endif; ?>
</head>
<body>
    <div id="player-root"
         data-p="<?= e($blob) ?>"
         data-k="<?= e(implode('|', $keyParts)) ?>"
         aria-live="polite">
        <div class="loading" id="loading">Carregando…</div>
    </div>

    <noscript>
        <div class="error">Ative o JavaScript para assistir.</div>
    </noscript>

    <div id="ad-banner" class="adsbox ad-banner ad-unit google-ad text-ad pub_300x250 pub_728x90" style="position:absolute;top:-9999px;left:-9999px;width:100px;height:100px;" aria-hidden="true">
        <ins class="adsbygoogle" style="display:block;width:100px;height:100px;" data-ad-client="ca-pub-1234567890123456"></ins>
    </div>

    <script src="assets/ads-bait.js"></script>

    <?php if ($inspectProtect): ?>
        <!-- Carregado apenas quando a proteção contra inspeção está ligada. -->
        <script disable-devtool-auto src="https://cdn.jsdelivr.net/npm/disable-devtool@latest"></script>
    <?php endif; ?>

    <script src="assets/player.js?v=4"></script>
</body>
</html>
