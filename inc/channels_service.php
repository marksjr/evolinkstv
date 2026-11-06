<?php
declare(strict_types=1);

/**
 * Serviço de gerenciamento, importação e migração em massa de canais.
 */

/**
 * Garante que a estrutura das tabelas contenha os campos do Player Nativo e Anúncios.
 */
function channels_ensure_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    try {
        $cols = $pdo->query('SHOW COLUMNS FROM `channels`')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('player_type', $cols, true)) {
            $pdo->exec("ALTER TABLE `channels` ADD COLUMN `player_type` VARCHAR(20) NOT NULL DEFAULT 'auto' AFTER `category`");
        }
        if (!in_array('drm_key', $cols, true)) {
            $pdo->exec("ALTER TABLE `channels` ADD COLUMN `drm_key` VARCHAR(255) DEFAULT NULL AFTER `player_type`");
        }
        if (!in_array('popup_ads', $cols, true)) {
            $pdo->exec("ALTER TABLE `channels` ADD COLUMN `popup_ads` MEDIUMTEXT DEFAULT NULL AFTER `drm_key`");
        }
        $pdo->exec("INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('player_ads_code', '')");
    } catch (Throwable $e) {
        error_log('[player] Schema ensure error: ' . $e->getMessage());
    }
}

/**
 * Verifica se uma URL é stream direto HLS (.m3u8) ou MPEG-DASH (.mpd).
 */
function is_stream_url(string $url): bool
{
    return (bool)preg_match('/\.(m3u8|mpd)($|[?&#])/i', $url);
}

/**
 * Detecta o tipo de player com base na extensão ('native' para streams, 'iframe' para outros).
 */
function detect_stream_player_type(string $url): string
{
    return is_stream_url($url) ? 'native' : 'iframe';
}

/**
 * Verifica se um slug já existe no banco de dados.
 */
function channel_slug_exists(PDO $pdo, string $slug, ?int $ignoreId = null): bool
{
    $sql = 'SELECT 1 FROM channels WHERE slug = ?' . ($ignoreId !== null ? ' AND id <> ?' : '') . ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $params = $ignoreId !== null ? [$slug, $ignoreId] : [$slug];
    $stmt->execute($params);

    return (bool)$stmt->fetchColumn();
}

/**
 * Resolve o slug único para o canal conforme a regra de opções:
 * Se o canal base já existir, gera op2, op3, op4...
 * Ex.: espn -> espnop2 -> espnop3
 * Ex.: espn2 -> espn2op2 -> espn2op3
 *
 * @param PDO $pdo
 * @param string $baseSlug
 * @param int|null $ignoreId
 * @param array<string> $reservedSlugs Slugs já reservados no lote atual em memória
 * @return string
 */
/**
 * Resolve duplicidade de canal atribuindo nome sequencial "Canal Alt X" e slug "canalAltX" (camelCase).
 * Exemplo:
 * ESPN -> espn
 * ESPN (duplicado) -> ESPN Alt 2 -> espnAlt2
 * BandSports (duplicado) -> BandSports Alt 2 -> bandSportsAlt2
 *
 * @param PDO $pdo
 * @param string $rawName Nome do canal (ex: "ESPN", "BandSports")
 * @param string $rawSlug Slug sugerido ou base (ex: "espn", "bandSports")
 * @param int|null $ignoreId
 * @param array<string> $reservedSlugs Slugs já reservados no lote atual em memória
 * @return array{name: string, slug: string, is_alt: bool, base_name: string, base_slug: string, alt_number: int}
 */
function resolve_channel_duplicate(
    PDO $pdo,
    string $rawName,
    string $rawSlug = '',
    ?int $ignoreId = null,
    array $reservedSlugs = []
): array {
    $rawName = trim($rawName);
    if ($rawName === '') {
        $rawName = 'Canal';
    }

    // Extrai o nome base limpo sem sufixo "Alt \d+" prévio
    $baseName = $rawName;
    if (preg_match('/^(.*?)(?:\s+[-_]?\s*Alt\s*\d+)$/i', $rawName, $nm)) {
        $clean = trim($nm[1]);
        if ($clean !== '') {
            $baseName = $clean;
        }
    }

    // Determina o base slug em camelCase
    if ($rawSlug !== '') {
        $baseSlug = slugify_camel($rawSlug);
    } else {
        $baseSlug = slugify_camel($baseName);
    }

    // Se o baseSlug já tiver sufixo Alt\d+, remove para obter o slug base raiz
    if (preg_match('/^(.*?)(?:Alt\d+)$/i', $baseSlug, $sm)) {
        $cleanSlug = trim($sm[1]);
        if ($cleanSlug !== '') {
            $baseSlug = $cleanSlug;
        }
    }

    // Verifica se o slug base já existe no lote em memória ou no banco
    $baseExists = in_array($baseSlug, $reservedSlugs, true) || channel_slug_exists($pdo, $baseSlug, $ignoreId);

    if (!$baseExists) {
        return [
            'name'        => $baseName,
            'slug'        => $baseSlug,
            'is_alt'      => false,
            'base_name'   => $baseName,
            'base_slug'   => $baseSlug,
            'alt_number'  => 1,
        ];
    }

    // Se já existe, busca o próximo sufixo Alt sequencial (Alt 2, Alt 3...)
    for ($i = 2; $i <= 9999; $i++) {
        $candidateSlug = $baseSlug . 'Alt' . $i;
        if (!in_array($candidateSlug, $reservedSlugs, true) && !channel_slug_exists($pdo, $candidateSlug, $ignoreId)) {
            return [
                'name'        => $baseName . ' Alt ' . $i,
                'slug'        => $candidateSlug,
                'is_alt'      => true,
                'base_name'   => $baseName,
                'base_slug'   => $baseSlug,
                'alt_number'  => $i,
            ];
        }
    }

    $fallbackNum = time();
    return [
        'name'        => $baseName . ' Alt ' . $fallbackNum,
        'slug'        => $baseSlug . 'Alt' . $fallbackNum,
        'is_alt'      => true,
        'base_name'   => $baseName,
        'base_slug'   => $baseSlug,
        'alt_number'  => $fallbackNum,
    ];
}

/**
 * Encontra um slug único no banco aplicando regra camelCase e alternativas Alt2, Alt3...
 *
 * @param PDO $pdo
 * @param string $baseSlug
 * @param int|null $ignoreId
 * @param array<string> $reservedSlugs Slugs já reservados no lote atual em memória
 * @return string
 */
function resolve_unique_channel_slug(
    PDO $pdo,
    string $baseSlug,
    ?int $ignoreId = null,
    array $reservedSlugs = []
): string {
    $baseSlug = trim($baseSlug);
    if ($baseSlug === '') {
        $baseSlug = 'canal';
    }

    $baseSlug = slugify_camel($baseSlug);

    // Se nem no banco nem no lote em memória existir, pode usar diretamente
    if (!in_array($baseSlug, $reservedSlugs, true) && !channel_slug_exists($pdo, $baseSlug, $ignoreId)) {
        return $baseSlug;
    }

    // Se o slug já termina em Alt\d+ ou op\d+, extrai o prefixo original e o número inicial
    if (preg_match('/^(.*?)(?:Alt|op)(\d+)$/i', $baseSlug, $matches)) {
        $root = $matches[1];
        $start = max(2, (int)$matches[2] + 1);
    } else {
        $root = $baseSlug;
        $start = 2;
    }

    for ($i = $start; $i <= 9999; $i++) {
        $candidate = $root . 'Alt' . $i;
        if (!in_array($candidate, $reservedSlugs, true) && !channel_slug_exists($pdo, $candidate, $ignoreId)) {
            return $candidate;
        }
    }

    return $root . 'Alt' . time();
}

/**
 * Extrai o identificador ou slug provável de uma URL de streaming/player em camelCase.
 * Exemplos:
 * http://site.com/canal.php?id=sbt -> sbt
 * http://site.com/canais.php?v=espn2 -> espn2
 * http://site.com/embed/band-sports -> bandSports
 */
function extract_slug_from_url(string $url): string
{
    $parsed = parse_url($url);
    if (!is_array($parsed)) {
        return '';
    }

    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $query);
        foreach (['id', 'v', 'ch', 'channel', 'stream', 'c', 'name', 'slug'] as $param) {
            if (!empty($query[$param]) && is_string($query[$param])) {
                return slugify_camel($query[$param]);
            }
        }
    }

    if (!empty($parsed['path'])) {
        $segments = array_values(array_filter(explode('/', trim($parsed['path'], '/'))));
        if ($segments) {
            $last = end($segments);
            // remove extensão ex: sbt.php -> sbt ou sbt.html -> sbt
            $last = preg_replace('/\.(php|html|htm|m3u8|mpd)$/i', '', $last) ?? $last;
            if ($last !== '' && !in_array(strtolower($last), ['canal', 'canais', 'player', 'play', 'live', 'stream', 'embed', 'tv'], true)) {
                return slugify_camel($last);
            }
        }
    }

    return '';
}

/**
 * Parser determinístico para linhas de texto / arquivo .txt.
 * Aceita múltiplos formatos comuns:
 * - "Nome do Canal - https://url"
 * - "Nome do Canal | https://url"
 * - "https://url"
 * - "Nome, https://url, Categoria"
 * - Listas m3u (#EXTINF:-1,Nome \n url)
 *
 * @param string $text
 * @return array<array{name: string, url: string, url_backup: string, category: string, slug: string}>
 */
function parse_channel_lines(string $text): array
{
    $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
    $channels = [];
    $pendingName = '';
    $pendingCategory = '';

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        // Suporte a cabeçalho M3U
        if (str_starts_with($line, '#EXTINF:')) {
            if (preg_match('/group-title="([^"]+)"/i', $line, $gMatch)) {
                $pendingCategory = trim($gMatch[1]);
            }
            if (preg_match('/,\s*(.+)$/', $line, $nMatch)) {
                $pendingName = trim($nMatch[1]);
            }
            continue;
        }

        if (str_starts_with($line, '#')) {
            continue;
        }

        $name = $pendingName;
        $category = $pendingCategory;
        $url = '';
        $urlBackup = '';

        $pendingName = '';
        $pendingCategory = '';

        // Se tem separador " - ", " | " ou " ; "
        if (preg_match('/^(.*?)\s*[\-\|;]\s*(https?:\/\/.+)$/i', $line, $m)) {
            $name = trim($m[1]);
            $url = trim($m[2]);
        } elseif (preg_match('/^(https?:\/\/[^\s]+)(?:\s+(.+))?$/i', $line, $m)) {
            $url = trim($m[1]);
            if (empty($name) && !empty($m[2])) {
                $name = trim($m[2]);
            }
        } elseif (valid_http_url($line)) {
            $url = $line;
        } else {
            // Tenta separador por vírgula: "ESPN, https://url"
            $parts = explode(',', $line);
            if (count($parts) >= 2 && valid_http_url(trim($parts[1]))) {
                $name = trim($parts[0]);
                $url = trim($parts[1]);
                if (!empty($parts[2])) {
                    $category = trim($parts[2]);
                }
            }
        }

        if ($url === '') {
            continue;
        }

        // Suporte a extração de chave DRM na linha (ex.: | kid:key ou drm=kid:key)
        $drmKey = '';
        if (preg_match('/(?:drm[:=]|key[:=])\s*([a-f0-9]{32}:[a-f0-9]{32}|[^\s]+)/i', $line, $drmMatch)) {
            $drmKey = trim($drmMatch[1]);
        } elseif (preg_match('/\b([a-f0-9]{32}:[a-f0-9]{32})\b/i', $line, $drmMatch)) {
            $drmKey = trim($drmMatch[1]);
        }

        if (str_contains($url, '|')) {
            $uParts = explode('|', $url);
            $url = trim($uParts[0]);
            if ($drmKey === '' && !empty($uParts[1])) {
                $drmKey = trim($uParts[1]);
            }
        }

        // Se não identificou nome, tenta extrair da URL
        if ($name === '') {
            $extracted = extract_slug_from_url($url);
            $name = $extracted !== '' ? ucwords(str_replace(['-', '_'], ' ', $extracted)) : 'Canal ' . (count($channels) + 1);
        }

        $slug = slugify_camel($name);
        if ($slug === '' || $slug === 'canal') {
            $urlSlug = extract_slug_from_url($url);
            if ($urlSlug !== '') {
                $slug = $urlSlug;
            }
        }

        $playerType = detect_stream_player_type($url);

        $channels[] = [
            'name'        => mb_substr($name, 0, 120),
            'url'         => $url,
            'url_backup'  => $urlBackup,
            'category'    => mb_substr($category, 0, 60),
            'slug'        => $slug !== '' ? $slug : 'canal',
            'player_type' => $playerType,
            'drm_key'     => $drmKey,
        ];
    }

    return $channels;
}

/**
 * Insere um lote de canais no banco de dados aplicando a regra de slugs duplicados (op2, op3...).
 *
 * @param PDO $pdo
 * @param array<array{name: string, url: string, url_backup?: string, category?: string, slug?: string, player_type?: string, drm_key?: string, popup_ads?: string}> $items
 * @return array{inserted: int, items: array<array{name: string, slug: string, url: string, player_type: string, status: string}>}
 */
function import_channels_batch(PDO $pdo, array $items): array
{
    channels_ensure_schema($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO channels (slug, name, url, url_backup, category, player_type, drm_key, popup_ads, sort_order, active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1)'
    );

    $reservedSlugs = [];
    $processed = [];
    $insertedCount = 0;

    $pdo->beginTransaction();
    try {
        foreach ($items as $item) {
            $name = trim((string)($item['name'] ?? ''));
            $url = trim((string)($item['url'] ?? ''));
            $urlBackup = trim((string)($item['url_backup'] ?? ''));
            $category = trim((string)($item['category'] ?? ''));
            $baseSlug = trim((string)($item['slug'] ?? ''));
            $playerType = trim((string)($item['player_type'] ?? 'auto'));
            $drmKey = trim((string)($item['drm_key'] ?? ''));
            $popupAds = trim((string)($item['popup_ads'] ?? ''));

            if (!in_array($playerType, ['auto', 'native', 'iframe'], true)) {
                $playerType = detect_stream_player_type($url);
            }

            if ($url === '' || !valid_http_url($url)) {
                continue;
            }

            if ($name === '') {
                $name = 'Canal ' . ($insertedCount + 1);
            }

            // Atribui nome sequencial (ex.: ESPN Alt 2) e slug camelCase (ex.: espnAlt2)
            $resolution = resolve_channel_duplicate($pdo, $name, $baseSlug, null, $reservedSlugs);
            $finalName = $resolution['name'];
            $finalSlug = $resolution['slug'];
            $reservedSlugs[] = $finalSlug;

            $stmt->execute([
                $finalSlug,
                mb_substr($finalName, 0, 120),
                $url,
                $urlBackup !== '' && valid_http_url($urlBackup) ? $urlBackup : null,
                $category !== '' ? mb_substr($category, 0, 60) : null,
                $playerType,
                $drmKey !== '' ? $drmKey : null,
                $popupAds !== '' ? $popupAds : null,
            ]);

            $insertedCount++;
            $processed[] = [
                'name'        => $finalName,
                'slug'        => $finalSlug,
                'url'         => $url,
                'player_type' => $playerType,
                'status'      => !$resolution['is_alt'] ? 'criado' : 'alternativa (' . $finalSlug . ')',
            ];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return [
        'inserted' => $insertedCount,
        'items'    => $processed,
    ];
}

/**
 * Usa a IA OpenRouter para processar uma lista não estruturada de canais.
 *
 * @param PDO $pdo
 * @param string $rawText
 * @return array{ok: bool, channels: array, error: ?string}
 */
function llm_parse_channel_list(PDO $pdo, string $rawText): array
{
    channels_ensure_schema($pdo);

    $prompt = <<<PROMPT
Você é um assistente especializado em processamento e normalização de listas de canais de streaming e TV.
Analise a entrada de texto abaixo e extraia cada canal no formato JSON estrito.

Regras obrigatórias:
1. Extraia o nome do canal (ex.: "ESPN", "SBT", "Globo SP", "Combate", "BandSports").
   - IMPORTANTE: Se houver canais repetidos ou alternativos na lista, nomeie as opções alternativas adicionando o sufixo "Alt 2", "Alt 3", etc. ao nome (exemplo: primeiro "ESPN", segundo "ESPN Alt 2", terceiro "ESPN Alt 3"; primeiro "BandSports", segundo "BandSports Alt 2").
2. Identifique a URL principal válida (começando com http:// ou https://).
3. Se houver link backup ou alternativo, preencha em "url_backup", senão deixe vazio "".
4. Sugira a categoria quando identificável (ex.: "Esportes", "Abertos", "Filmes", "Notícias", etc.).
5. Crie um "suggested_slug" normalizado em camelCase: a primeira palavra em minúsculas e cada palavra subsequente começando com letra maiúscula, SEM hifens, underlines ou acentos.
   - Exemplos de slugs: "espn", "bandSports", "globoSp", "telecinePremium".
   - Em caso de canais repetidos/alternativos, o slug DEVE seguir o padrão camelCase com "Alt2", "Alt3" (ex.: "espnAlt2", "espnAlt3", "bandSportsAlt2"). NUNCA use hifens como "espn-alt-2".
6. Identifique o tipo de player ("player_type"):
   - Se a URL contiver ou terminar com .m3u8 ou .mpd (streaming direto HLS ou MPEG-DASH), defina "player_type": "native".
   - Se a URL for um link comum de página/player (ex.: .php, .html, embed, portal que já possui player), defina "player_type": "iframe".
7. Se for identificado formato de chave DRM ClearKey (kid:key), preencha no campo "drm_key", senão deixe vazio "".
8. Responda ESTRITAMENTE com o JSON válido, sem explicações extras, no seguinte formato:

{
  "channels": [
    {
      "name": "Nome do Canal",
      "url": "https://...",
      "url_backup": "",
      "category": "Esportes",
      "suggested_slug": "nomeDoCanal",
      "player_type": "native",
      "drm_key": ""
    }
  ]
}

Texto bruto com canais:
PROMPT;

    $messages = [
        ['role' => 'system', 'content' => 'Você é um extrator de dados de canais de streaming e responde somente em JSON válido.'],
        ['role' => 'user', 'content' => $prompt . "\n" . $rawText],
    ];

    $res = openrouter_chat($pdo, $messages, [
        'temperature' => 0.1,
        'json'        => true,
        'timeout'     => 90,
    ]);

    if (!$res['ok']) {
        return ['ok' => false, 'channels' => [], 'error' => $res['error']];
    }

    $rawContent = trim($res['content']);
    // Limpa eventuais blocos markdown ```json
    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $rawContent, $m)) {
        $rawContent = $m[1];
    }

    $decoded = json_decode($rawContent, true);
    if (!is_array($decoded) || !isset($decoded['channels']) || !is_array($decoded['channels'])) {
        return [
            'ok'       => false,
            'channels' => [],
            'error'    => 'A IA respondeu em formato não reconhecido. Tente usar a importação direta.',
        ];
    }

    $normalized = [];
    foreach ($decoded['channels'] as $c) {
        if (!is_array($c) || empty($c['url']) || !valid_http_url((string)$c['url'])) {
            continue;
        }
        $url = trim((string)$c['url']);
        $pType = trim((string)($c['player_type'] ?? ''));
        if (!in_array($pType, ['native', 'iframe'], true)) {
            $pType = detect_stream_player_type($url);
        }
        $normalized[] = [
            'name'           => trim((string)($c['name'] ?? '')),
            'url'            => $url,
            'url_backup'     => trim((string)($c['url_backup'] ?? '')),
            'category'       => trim((string)($c['category'] ?? '')),
            'suggested_slug' => trim((string)($c['suggested_slug'] ?? '')),
            'player_type'    => $pType,
            'drm_key'        => trim((string)($c['drm_key'] ?? '')),
        ];
    }

    return [
        'ok'       => true,
        'channels' => $normalized,
        'error'    => null,
    ];
}

/**
 * Simula a alteração em massa de links sem IA.
 *
 * @param PDO $pdo
 * @param string $search
 * @param string $replace
 * @param string $target 'url', 'url_backup' ou 'both'
 * @param string $mode 'replace' ou 'prefix'
 * @return array{total_found: int, affected_count: int, diffs: array}
 */
function channels_mass_replace_preview(
    PDO $pdo,
    string $search,
    string $replace,
    string $target = 'both',
    string $mode = 'replace'
): array {
    $search = trim($search);
    $replace = trim($replace);

    if ($search === '') {
        return ['total_found' => 0, 'affected_count' => 0, 'diffs' => []];
    }

    $channels = $pdo->query('SELECT id, name, slug, url, url_backup FROM channels ORDER BY id ASC')->fetchAll();

    $diffs = [];
    $affectedIds = [];

    foreach ($channels as $ch) {
        $id = (int)$ch['id'];
        $changedInThis = false;

        $fieldsToTest = [];
        if ($target === 'url' || $target === 'both') {
            $fieldsToTest['url'] = (string)$ch['url'];
        }
        if ($target === 'url_backup' || $target === 'both') {
            $fieldsToTest['url_backup'] = (string)($ch['url_backup'] ?? '');
        }

        foreach ($fieldsToTest as $field => $oldVal) {
            if ($oldVal === '') {
                continue;
            }

            $newVal = channels_transform_url($oldVal, $search, $replace, $mode);
            if ($newVal !== $oldVal) {
                $changedInThis = true;
                $diffs[] = [
                    'id'      => $id,
                    'name'    => (string)$ch['name'],
                    'slug'    => (string)$ch['slug'],
                    'field'   => $field,
                    'old_url' => $oldVal,
                    'new_url' => $newVal,
                ];
            }
        }

        if ($changedInThis) {
            $affectedIds[$id] = true;
        }
    }

    return [
        'total_found'    => count($channels),
        'affected_count' => count($affectedIds),
        'diffs'          => $diffs,
    ];
}

/**
 * Transforma uma URL baseada em busca/substituição ou prefixo inteligente.
 */
function channels_transform_url(string $url, string $search, string $replace, string $mode): string
{
    if ($search === '') {
        return $url;
    }

    if ($mode === 'prefix') {
        // Normaliza ignorando esquema http/https na busca caso o usuário não tenha informado
        $searchClean = preg_replace('#^https?://#i', '', $search) ?? $search;
        $urlClean = preg_replace('#^https?://#i', '', $url) ?? $url;

        if (str_starts_with($urlClean, $searchClean)) {
            $remainder = substr($urlClean, strlen($searchClean));
            // Monta nova url preservando esquema se não fornecido em $replace
            $scheme = (str_starts_with($url, 'https://')) ? 'https://' : 'http://';
            if (preg_match('#^https?://#i', $replace)) {
                return rtrim($replace, '/') . (str_starts_with($remainder, '/') ? '' : '') . $remainder;
            }

            return $scheme . rtrim($replace, '/') . (str_starts_with($remainder, '/') ? '' : '') . $remainder;
        }
    }

    // Modo substituição direta / substring
    if (str_contains($url, $search)) {
        return str_replace($search, $replace, $url);
    }

    // Se a busca não tiver esquema mas a URL tiver
    $searchNoScheme = preg_replace('#^https?://#i', '', $search);
    if ($searchNoScheme !== '' && str_contains($url, $searchNoScheme)) {
        $replaceNoScheme = preg_replace('#^https?://#i', '', $replace);
        return str_replace($searchNoScheme, $replaceNoScheme, $url);
    }

    return $url;
}

/**
 * Executa a substituição em massa de links sem IA.
 */
function channels_mass_replace_execute(
    PDO $pdo,
    string $search,
    string $replace,
    string $target = 'both',
    string $mode = 'replace'
): int {
    $preview = channels_mass_replace_preview($pdo, $search, $replace, $target, $mode);
    if ($preview['affected_count'] === 0) {
        return 0;
    }

    $updatesByChannel = [];
    foreach ($preview['diffs'] as $diff) {
        $id = $diff['id'];
        $field = $diff['field'];
        $newUrl = $diff['new_url'];
        $updatesByChannel[$id][$field] = $newUrl;
    }

    $stmtUrl = $pdo->prepare('UPDATE channels SET url = ?, updated_at = NOW() WHERE id = ?');
    $stmtBackup = $pdo->prepare('UPDATE channels SET url_backup = ?, updated_at = NOW() WHERE id = ?');
    $stmtBoth = $pdo->prepare('UPDATE channels SET url = ?, url_backup = ?, updated_at = NOW() WHERE id = ?');

    $updatedCount = 0;
    $pdo->beginTransaction();
    try {
        foreach ($updatesByChannel as $id => $fields) {
            if (isset($fields['url']) && isset($fields['url_backup'])) {
                $stmtBoth->execute([$fields['url'], $fields['url_backup'], $id]);
            } elseif (isset($fields['url'])) {
                $stmtUrl->execute([$fields['url'], $id]);
            } elseif (isset($fields['url_backup'])) {
                $stmtBackup->execute([$fields['url_backup'], $id]);
            }
            $updatedCount++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $updatedCount;
}

/**
 * Agente IA LLM: Analisa comando do usuário em linguagem natural e planeja a alteração no banco.
 *
 * @param PDO $pdo
 * @param string $userPrompt
 * @return array{ok: bool, explanation: string, diffs: array, error: ?string}
 */
function llm_channels_mass_replace_plan(PDO $pdo, string $userPrompt): array
{
    $channels = $pdo->query('SELECT id, name, slug, url, url_backup FROM channels ORDER BY id ASC LIMIT 500')->fetchAll();
    if (!$channels) {
        return [
            'ok'          => false,
            'explanation' => '',
            'diffs'       => [],
            'error'       => 'Nenhum canal cadastrado no banco de dados para alterar.',
        ];
    }

    $systemPrompt = <<<SYS
Você é um Agente Administrador de Banco de Dados especializado em roteamento de streams de canais.
Sua função é ler a solicitação do usuário e a lista atual de canais, e gerar com precisão cirúrgica as alterações de URLs necessárias.

Regras estritas:
1. Modifique APENAS os canais que se enquadram na ordem do usuário.
2. Não altere o identificador/slug específico do canal na URL a menos que o usuário peça expressamente.
3. Se for trocar domínio ou caminho (ex: site.com/canal.php?id=sbt para sitecanal.com/canal.php?id=sbt), mantenha o "sbt" intacto.
4. Toda nova URL deve ser válida (começar com http:// ou https://).
5. Se uma URL ou URL_backup não for afetada, não a inclua nas alterações.
6. Responda ESTRITAMENTE em formato JSON no schema especificado abaixo. Não inclua texto antes ou depois.

Schema de Resposta:
{
  "explanation": "Explicação resumida do que foi planejado",
  "changes": [
    {
      "id": 123,
      "name": "Nome",
      "slug": "slug",
      "field": "url",
      "old_url": "http://antigo.com/...",
      "new_url": "http://novo.com/..."
    }
  ]
}
SYS;

    $userPayload = [
        'instruction' => $userPrompt,
        'channels'    => $channels,
    ];

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => json_encode($userPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
    ];

    $res = openrouter_chat($pdo, $messages, [
        'temperature' => 0.1,
        'json'        => true,
        'timeout'     => 90,
    ]);

    if (!$res['ok']) {
        return [
            'ok'          => false,
            'explanation' => '',
            'diffs'       => [],
            'error'       => $res['error'],
        ];
    }

    $raw = trim($res['content']);
    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $raw, $m)) {
        $raw = $m[1];
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['changes']) || !is_array($data['changes'])) {
        return [
            'ok'          => false,
            'explanation' => '',
            'diffs'       => [],
            'error'       => 'A IA não retornou uma estrutura de mudanças válida.',
        ];
    }

    $validDiffs = [];
    foreach ($data['changes'] as $change) {
        $id = (int)($change['id'] ?? 0);
        $field = (string)($change['field'] ?? 'url');
        $oldUrl = (string)($change['old_url'] ?? '');
        $newUrl = (string)($change['new_url'] ?? '');

        if ($id > 0 && in_array($field, ['url', 'url_backup'], true) && valid_http_url($newUrl)) {
            $validDiffs[] = [
                'id'      => $id,
                'name'    => (string)($change['name'] ?? ''),
                'slug'    => (string)($change['slug'] ?? ''),
                'field'   => $field,
                'old_url' => $oldUrl,
                'new_url' => $newUrl,
            ];
        }
    }

    return [
        'ok'          => true,
        'explanation' => (string)($data['explanation'] ?? 'Alterações planejadas pela IA.'),
        'diffs'       => $validDiffs,
        'error'       => null,
    ];
}

/**
 * Aplica uma lista específica de diffs/atualizações selecionadas.
 *
 * @param PDO $pdo
 * @param array<array{id: int, field: string, new_url: string}> $diffs
 * @return int
 */
function channels_apply_diffs(PDO $pdo, array $diffs): int
{
    if (!$diffs) {
        return 0;
    }

    $stmtUrl = $pdo->prepare('UPDATE channels SET url = ?, updated_at = NOW() WHERE id = ?');
    $stmtBackup = $pdo->prepare('UPDATE channels SET url_backup = ?, updated_at = NOW() WHERE id = ?');

    $applied = 0;
    $pdo->beginTransaction();
    try {
        foreach ($diffs as $d) {
            $id = (int)($d['id'] ?? 0);
            $field = (string)($d['field'] ?? 'url');
            $newUrl = (string)($d['new_url'] ?? '');

            if ($id <= 0 || !valid_http_url($newUrl)) {
                continue;
            }

            if ($field === 'url') {
                $stmtUrl->execute([$newUrl, $id]);
                $applied++;
            } elseif ($field === 'url_backup') {
                $stmtBackup->execute([$newUrl, $id]);
                $applied++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $applied;
}

/**
 * Resolve a URL final do canal para exportação (player embed, stream original ou backup).
 */
function channels_resolve_export_url(array $channel, string $linkType, string $baseUrl): string
{
    if ($linkType === 'stream') {
        return (string)($channel['url'] ?? '');
    }
    if ($linkType === 'backup') {
        $backup = (string)($channel['url_backup'] ?? '');
        return $backup !== '' ? $backup : (string)($channel['url'] ?? '');
    }
    // 'player' padrão: URL pública de incorporação/compartilhamento
    return rtrim($baseUrl, '/') . '/?v=' . rawurlencode((string)($channel['slug'] ?? ''));
}

/**
 * Gera conteúdo em formato TXT (Nome e abaixo o link do canal).
 */
function channels_export_to_txt(array $channels, string $linkType, string $baseUrl): string
{
    $lines = [];
    foreach ($channels as $c) {
        $name = trim((string)($c['name'] ?? ''));
        $url = channels_resolve_export_url($c, $linkType, $baseUrl);
        if ($name === '' && $url === '') {
            continue;
        }
        $lines[] = $name;
        $lines[] = $url;
        $lines[] = ''; // Linha em branco separando os canais
    }
    return implode("\r\n", $lines);
}

/**
 * Gera página HTML completa, responsiva e independente com botão Copiar ao lado de cada canal.
 */
function channels_export_to_html(array $channels, string $linkType, string $baseUrl, string $appName): string
{
    $txtExport = channels_export_to_txt($channels, $linkType, $baseUrl);
    $total = count($channels);
    $genDate = date('d/m/Y H:i:s');
    $linkTypeName = match ($linkType) {
        'stream' => 'Link de Origem (Stream Direto)',
        'backup' => 'Link Reserva (Backup)',
        default => 'Link do Player (Embed Oficial)',
    };

    ob_start();
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?> · Lista de Canais</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #05080e;
            --panel: #0b111e;
            --panel-card: rgba(14, 22, 38, 0.85);
            --line: rgba(255, 255, 255, 0.08);
            --line-cyan: rgba(0, 240, 255, 0.25);
            --text: #c0cee0;
            --text-bright: #ffffff;
            --muted: #647b96;
            --cyan: #00f0ff;
            --cyan-glow: rgba(0, 240, 255, 0.4);
            --ok: #00ff9d;
            --ok-glow: rgba(0, 255, 157, 0.4);
            --accent: #ff2e56;
            --radius: 12px;
            --font: 'Plus Jakarta Sans', system-ui, sans-serif;
            --mono: 'JetBrains Mono', monospace;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--bg);
            background-image: radial-gradient(circle at 50% 0%, rgba(0, 240, 255, 0.06) 0%, transparent 50%),
                              radial-gradient(circle at 85% 30%, rgba(255, 46, 86, 0.04) 0%, transparent 40%);
            background-attachment: fixed;
            color: var(--text);
            font: 14px/1.5 var(--font);
            padding: 24px 16px 60px;
            min-height: 100vh;
        }
        .container {
            max-width: 1100px;
            margin: 0 auto;
        }
        .header {
            background: var(--panel-card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(16px);
        }
        .header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 16px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .brand-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 12px var(--accent);
            display: inline-block;
        }
        .brand h1 {
            font-size: 22px;
            font-weight: 800;
            color: var(--text-bright);
            letter-spacing: -0.02em;
        }
        .meta-badges {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--line);
            color: var(--text-bright);
        }
        .badge-cyan {
            background: rgba(0, 240, 255, 0.1);
            border-color: rgba(0, 240, 255, 0.3);
            color: var(--cyan);
        }
        .badge-ok {
            background: rgba(0, 255, 157, 0.1);
            border-color: rgba(0, 255, 157, 0.3);
            color: var(--ok);
        }
        .actions-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            padding-top: 16px;
            border-top: 1px solid var(--line);
        }
        .search-box {
            flex: 1 1 280px;
            position: relative;
        }
        .search-box input {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border-radius: 8px;
            background: rgba(4, 7, 12, 0.9);
            border: 1px solid var(--line);
            color: var(--text-bright);
            font: 13.5px var(--font);
            outline: 0;
            transition: all 0.2s;
        }
        .search-box input:focus {
            border-color: var(--cyan);
            box-shadow: 0 0 14px var(--cyan-glow);
        }
        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--muted);
            pointer-events: none;
        }
        .btn-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 15px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.04);
            color: var(--text-bright);
            font: 12.5px/1 var(--font);
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .btn:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--cyan);
            color: #fff;
            transform: translateY(-1px);
        }
        .btn-primary {
            background: linear-gradient(135deg, #ff2e56 0%, #d8163e 100%);
            border-color: rgba(255, 46, 86, 0.4);
            color: #fff;
            box-shadow: 0 4px 16px rgba(255, 46, 86, 0.3);
        }
        .btn-primary:hover {
            box-shadow: 0 6px 20px rgba(255, 46, 86, 0.5);
        }
        .channel-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .channel-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            background: var(--panel-card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 14px 18px;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .channel-card:hover {
            border-color: var(--line-cyan);
            background: rgba(18, 28, 48, 0.95);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            transform: translateX(2px);
        }
        .channel-main {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
            min-width: 0;
        }
        .channel-title-row {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .channel-name {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-bright);
            letter-spacing: -0.01em;
        }
        .channel-cat {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.05);
            color: var(--muted);
            border: 1px solid var(--line);
        }
        .channel-slug {
            font-family: var(--mono);
            font-size: 11.5px;
            color: var(--cyan);
            opacity: 0.85;
        }
        .channel-link-box {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .channel-url {
            font-family: var(--mono);
            font-size: 12px;
            color: #8ed6ff;
            word-break: break-all;
            line-height: 1.4;
        }
        .channel-action {
            flex-shrink: 0;
        }
        .btn-copy {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            background: rgba(0, 240, 255, 0.06);
            border: 1px solid rgba(0, 240, 255, 0.25);
            color: var(--cyan);
            font: 12.5px/1 var(--font);
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            white-space: nowrap;
        }
        .btn-copy:hover {
            background: rgba(0, 240, 255, 0.18);
            border-color: var(--cyan);
            color: #fff;
            box-shadow: 0 0 14px var(--cyan-glow);
            transform: scale(1.03);
        }
        .btn-copy.copied {
            background: rgba(0, 255, 157, 0.2) !important;
            border-color: var(--ok) !important;
            color: #fff !important;
            box-shadow: 0 0 16px var(--ok-glow) !important;
        }
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--muted);
            background: var(--panel-card);
            border-radius: var(--radius);
            border: 1px dashed var(--line);
        }
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            padding: 12px 20px;
            border-radius: 8px;
            background: rgba(6, 12, 22, 0.95);
            border: 1px solid var(--ok);
            color: #fff;
            box-shadow: 0 8px 28px rgba(0, 0, 0, 0.6), 0 0 16px var(--ok-glow);
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            z-index: 9999;
        }
        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }
        @media (max-width: 720px) {
            .channel-card {
                flex-direction: column;
                align-items: stretch;
            }
            .btn-copy {
                width: 100%;
                justify-content: center;
            }
        }
        @media print {
            body { background: #fff; color: #000; }
            .header, .channel-card { background: #fff; border: 1px solid #ccc; box-shadow: none; }
            .btn, .search-box, .btn-copy { display: none !important; }
            .channel-url { color: #000; }
        }
    </style>
</head>
<body>
<div class="container">
    <header class="header">
        <div class="header-top">
            <div class="brand">
                <span class="brand-dot"></span>
                <h1><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></h1>
            </div>
            <div class="meta-badges">
                <span class="badge badge-ok"><span id="visible-count"><?= $total ?></span> canais</span>
                <span class="badge badge-cyan"><?= htmlspecialchars($linkTypeName, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="badge"><?= htmlspecialchars($genDate, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>

        <div class="actions-bar">
            <div class="search-box">
                <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="text" id="filter" placeholder="🔍 Filtrar por nome, slug, categoria ou link..." oninput="filterChannels()">
            </div>
            <div class="btn-group">
                <button type="button" class="btn btn-primary" onclick="copyAllTxt()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                    </svg>
                    <span>Copiar Todos (TXT)</span>
                </button>
                <button type="button" class="btn" onclick="downloadTxtFile()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    <span>Baixar TXT</span>
                </button>
                <button type="button" class="btn" onclick="window.print()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 6 2 18 2 18 9"/>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                        <rect x="6" y="14" width="12" height="8"/>
                    </svg>
                    <span>Imprimir / PDF</span>
                </button>
            </div>
        </div>
    </header>

    <main class="channel-list" id="list">
        <?php foreach ($channels as $idx => $c): ?>
            <?php
            $url = channels_resolve_export_url($c, $linkType, $baseUrl);
            $searchKey = mb_strtolower((string)$c['name'] . ' ' . (string)$c['slug'] . ' ' . (string)($c['category'] ?? '') . ' ' . $url);
            ?>
            <article class="channel-card" data-search="<?= htmlspecialchars($searchKey, ENT_QUOTES, 'UTF-8') ?>">
                <div class="channel-main">
                    <div class="channel-title-row">
                        <span class="channel-name"><?= htmlspecialchars((string)$c['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if (!empty($c['category'])): ?>
                            <span class="channel-cat"><?= htmlspecialchars((string)$c['category'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <span class="channel-slug">?v=<?= htmlspecialchars((string)$c['slug'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="channel-link-box">
                        <span class="channel-url" id="link-<?= $idx ?>"><?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>
                <div class="channel-action">
                    <button type="button" class="btn-copy" onclick="copyChannelLink('link-<?= $idx ?>', this)">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                        </svg>
                        <span>Copiar</span>
                    </button>
                </div>
            </article>
        <?php endforeach; ?>

        <div class="empty-state" id="empty" style="display:none;">
            Nenhum canal encontrado para o filtro digitado.
        </div>
    </main>
</div>

<div class="toast" id="toast">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--ok)" stroke-width="2.5">
        <polyline points="20 6 9 17 4 12"/>
    </svg>
    <span id="toast-text">Link copiado com sucesso!</span>
</div>

<textarea id="raw-txt" style="display:none;"><?= htmlspecialchars($txtExport, ENT_QUOTES, 'UTF-8') ?></textarea>

<script>
async function copyToClipboard(text) {
    if (navigator.clipboard && window.isSecureContext) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch (e) {}
    }
    const t = document.createElement('textarea');
    t.value = text;
    t.style.position = 'fixed';
    t.style.opacity = '0';
    document.body.appendChild(t);
    t.focus();
    t.select();
    const ok = document.execCommand('copy');
    t.remove();
    return ok;
}

function showToast(msg) {
    const toast = document.getElementById('toast');
    const toastText = document.getElementById('toast-text');
    toastText.textContent = msg;
    toast.classList.add('show');
    clearTimeout(window._toastTimeout);
    window._toastTimeout = setTimeout(() => {
        toast.classList.remove('show');
    }, 2200);
}

async function copyChannelLink(elementId, btn) {
    const el = document.getElementById(elementId);
    if (!el) return;
    const url = el.innerText.trim();
    const ok = await copyToClipboard(url);
    if (ok) {
        const originalHtml = btn.innerHTML;
        btn.classList.add('copied');
        btn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> <span>Copiado!</span>`;
        showToast('Link do canal copiado!');
        setTimeout(() => {
            btn.classList.remove('copied');
            btn.innerHTML = originalHtml;
        }, 1800);
    }
}

async function copyAllTxt() {
    const txt = document.getElementById('raw-txt').value;
    const ok = await copyToClipboard(txt);
    if (ok) {
        showToast('Todos os canais copiados no formato TXT!');
    }
}

function downloadTxtFile() {
    const txt = document.getElementById('raw-txt').value;
    const blob = new Blob([txt], { type: 'text/plain;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'canais-' + new Date().toISOString().slice(0, 10) + '.txt';
    a.click();
    URL.revokeObjectURL(a.href);
    showToast('Download do arquivo TXT iniciado!');
}

function filterChannels() {
    const q = document.getElementById('filter').value.toLowerCase().trim();
    const cards = document.querySelectorAll('.channel-card');
    let visible = 0;

    cards.forEach(card => {
        const key = card.getAttribute('data-search') || '';
        const match = q === '' || key.includes(q);
        card.style.display = match ? 'flex' : 'none';
        if (match) visible++;
    });

    const empty = document.getElementById('empty');
    if (empty) empty.style.display = visible === 0 ? 'block' : 'none';
    const counter = document.getElementById('visible-count');
    if (counter) counter.textContent = String(visible);
}
</script>
</body>
</html>
    <?php
    return ob_get_clean();
}

