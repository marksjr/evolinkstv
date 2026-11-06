<?php
declare(strict_types=1);

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
        || (isset($_SERVER['HTTP_CF_VISITOR']) && str_contains((string)$_SERVER['HTTP_CF_VISITOR'], '"https"'))
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}

function app_base_url(): string
{
    $url = trim((string)($GLOBALS['config']['app']['url'] ?? ''));
    if ($url !== '' && !str_contains($url, 'site.com')) {
        return rtrim($url, '/');
    }

    if (isset($_SERVER['HTTP_HOST'])) {
        $proto = is_https() ? 'https://' : 'http://';
        $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
        if (str_contains($host, ',')) {
            $host = trim(explode(',', $host)[0]);
        }
        if (!preg_match('/^[a-zA-Z0-9.-]+(:[0-9]+)?$/', $host)) {
            $host = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
            if (!preg_match('/^[a-zA-Z0-9.-]+(:[0-9]+)?$/', $host)) {
                $host = 'localhost';
            }
        }

        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = dirname($scriptName);
        $baseDir = preg_replace('#/(?:admin|api)(?:/.*)?$#i', '', $dir);
        $baseDir = rtrim(str_replace('\\', '/', $baseDir), '/');

        return $proto . $host . $baseDir;
    }

    return 'http://localhost';
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

function valid_slug(string $slug): bool
{
    return (bool)preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9_-]{0,62}[a-zA-Z0-9])?$/', $slug);
}

function slugify_camel(string $name): string
{
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $str = $ascii !== false ? $ascii : $name;

    // Se já tiver Alt ou op com número no final (ex: espn-alt-2, ESPN Alt 2, espnop2), normaliza a base e o sufixo
    if (preg_match('/^(.*?)(?:[\s\-_]*(?:alt|op)[\s\-_]*(\d+))$/i', $str, $m)) {
        $base = trim($m[1]);
        $num = (int)$m[2];
        return slugify_camel($base) . 'Alt' . $num;
    }

    // Divide em palavras respeitando limites camelCase existentes (ex: BandSports -> Band, Sports) e caracteres não alfanuméricos
    $parts = preg_split('/(?<=[a-z0-9])(?=[A-Z])|[^a-zA-Z0-9]+/', $str, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) {
        return 'canal';
    }

    $slug = '';
    foreach ($parts as $idx => $part) {
        if ($idx === 0) {
            $slug .= strtolower($part);
        } else {
            $slug .= ucfirst(strtolower($part));
        }
    }

    $slug = substr($slug, 0, 64);
    return $slug !== '' ? $slug : 'canal';
}

function slugify(string $name): string
{
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $base = $ascii !== false ? $ascii : $name;
    $base = strtolower($base);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
    $base = trim(substr(trim($base, '-'), 0, 64), '-');

    return $base !== '' ? $base : 'canal';
}

function valid_http_url(string $url): bool
{
    if ($url === '' || strlen($url) > 2048 || preg_match('/\s/', $url)) {
        return false;
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return false;
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));

    return in_array($scheme, ['http', 'https'], true);
}

function normalize_domains(string $raw): string
{
    $tokens = preg_split('/[\s,;]+/', strtolower($raw)) ?: [];
    $out = [];

    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token === '') {
            continue;
        }

        $token = preg_replace('#^https?://#', '', $token) ?? $token;
        $token = explode('/', $token)[0];
        $token = explode(':', $token)[0];

        $wildcard = str_starts_with($token, '*.');
        if ($wildcard) {
            $token = substr($token, 2);
        }

        if ($token === '' || strlen($token) > 253) {
            continue;
        }

        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if (!preg_match('/^(?:' . $label . '\.)*' . $label . '$/', $token)) {
            continue;
        }

        $out[] = ($wildcard ? '*.' : '') . $token;
    }

    return implode(',', array_values(array_unique($out)));
}

function domains_list(string $raw): array
{
    $raw = trim($raw);

    return $raw === ''
        ? []
        : array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function host_allowed(string $host, array $domains): bool
{
    $host = strtolower($host);
    if ($host === '') {
        return false;
    }

    foreach ($domains as $domain) {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            continue;
        }

        if ($domain === $host) {
            return true;
        }

        if (str_starts_with($domain, '*.')) {
            $base = substr($domain, 2);
            if ($host === $base || str_ends_with($host, '.' . $base)) {
                return true;
            }
        }
    }

    return false;
}

function referer_host(): string
{
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referer === '') {
        return '';
    }

    $host = parse_url($referer, PHP_URL_HOST);

    return is_string($host) ? strtolower($host) : '';
}

function csp_ancestors(array $domains): string
{
    $out = ["'self'"];

    foreach ($domains as $domain) {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            continue;
        }

        if ($domain === 'localhost' || $domain === '127.0.0.1') {
            $out[] = 'http://localhost:*';
            $out[] = 'http://127.0.0.1:*';
            $out[] = 'https://localhost:*';
            $out[] = 'https://127.0.0.1:*';
            continue;
        }

        if (str_starts_with($domain, '*.')) {
            $base = substr($domain, 2);
            $out[] = 'https://' . $domain;
            $out[] = 'https://' . $base;
            $out[] = 'http://' . $domain;
            $out[] = 'http://' . $base;
            continue;
        }

        $out[] = 'https://' . $domain;
        $out[] = 'http://' . $domain;
    }

    return implode(' ', array_values(array_unique($out)));
}

function client_ip_hash(string $secret): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    return hash_hmac('sha256', $ip, $secret);
}

function send_admin_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('Cache-Control: no-store');
}

function preview_token(string $slug): string
{
    $expires = time() + 3600;
    $secret = (string)($GLOBALS['config']['app']['key'] ?? '');
    $signature = hash_hmac('sha256', $slug . '|' . $expires, $secret);

    return $expires . '.' . $signature;
}

function preview_valid(string $slug, string $token): bool
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return false;
    }

    [$expires, $signature] = $parts;
    if (!ctype_digit($expires) || (int)$expires < time()) {
        return false;
    }

    $secret = (string)($GLOBALS['config']['app']['key'] ?? '');
    $expected = hash_hmac('sha256', $slug . '|' . $expires, $secret);

    return hash_equals($expected, $signature);
}
