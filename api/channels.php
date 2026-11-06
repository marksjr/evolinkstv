<?php
declare(strict_types=1);

/**
 * EVO LINKS PRO - REST API PARA INTEGRAÇÃO DE CANAIS
 * Permite integração do catálogo de canais com o ADM (mesmo domínio ou cross-domain).
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once dirname(__DIR__) . '/inc/bootstrap.php';

try {
    // 1. Verificação de Autenticação / API Key
    $configuredKey = setting($pdo, 'pro_api_key', '');
    if ($configuredKey === '') {
        // Inicializa uma chave se não existir
        $configuredKey = setting($pdo, 'api_key', '');
        if ($configuredKey === '') {
            $configuredKey = hash('sha256', (string)($GLOBALS['config']['app']['key'] ?? 'pro_default_secret'));
            settings_save($pdo, ['pro_api_key' => $configuredKey]);
        }
    }

    $providedKey = (string)($_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? $_POST['api_key'] ?? '');
    
    // Suporte também a Bearer token
    if ($providedKey === '' && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.*)$/i', (string)$_SERVER['HTTP_AUTHORIZATION'], $m)) {
            $providedKey = trim($m[1]);
        }
    }

    // Se estiver no mesmo servidor (localhost/127.0.0.1) ou se a chave bater, autoriza
    $remoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $isLocalhost = in_array($remoteIp, ['127.0.0.1', '::1', 'localhost'], true);
    $isDebug = !empty($GLOBALS['config']['app']['debug']);
    $rawAppKey = (string)($GLOBALS['config']['app']['key'] ?? '');
    
    $isAuthorized = false;
    if ($configuredKey !== '' && hash_equals($configuredKey, $providedKey)) {
        $isAuthorized = true;
    } elseif ($rawAppKey !== '' && hash_equals($rawAppKey, $providedKey)) {
        $isAuthorized = true;
    } elseif ($providedKey === '' && $isLocalhost && $isDebug) {
        // Apenas em ambiente de desenvolvimento local COM debug ativo permite consulta sem chave
        $isAuthorized = true;
    }

    if (!$isAuthorized) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'code' => 401,
            'message' => 'Acesso não autorizado. Chave de API inválida ou ausente (X-API-Key).'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $action = (string)($_GET['action'] ?? $_POST['action'] ?? 'list');
    $baseUrl = rtrim(app_base_url(), '/') . '/';

    /**
     * Função auxiliar para desmembrar slug em root e número de opção
     */
    $parseSlugOption = static function (string $slug): array {
        if (preg_match('/^(.*?)op(\d+)$/i', $slug, $matches)) {
            return [
                'root' => strtolower($matches[1]),
                'option' => (int)$matches[2]
            ];
        }
        return [
            'root' => strtolower($slug),
            'option' => 1
        ];
    };

    /**
     * Normalizador fonético e de aliases para correspondência de canais
     */
    $normalizeChannelTerm = static function (string $term): string {
        $term = mb_strtolower(trim($term), 'UTF-8');
        // Remove acentos
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $term);
        if ($ascii !== false) {
            $term = $ascii;
        }
        // Aliases comuns de streaming para canal de TV
        $aliases = [
            'disney+' => 'espn',
            'disney plus' => 'espn',
            'star+' => 'espn',
            'star plus' => 'espn',
            'premiere clubes' => 'premiere',
            'pfc' => 'premiere',
            'caze tv' => 'cazetv',
            'cazé tv' => 'cazetv',
            'cazé' => 'cazetv',
            'youtube cazetv' => 'cazetv',
            'youtube caze tv' => 'cazetv',
            'tnt sports' => 'tnt',
            'hbo max' => 'max',
            'sportv 1' => 'sportv',
            'sportv1' => 'sportv',
            'espn 1' => 'espn',
            'espn1' => 'espn'
        ];

        foreach ($aliases as $needle => $replace) {
            if ($term === $needle || str_contains($term, $needle)) {
                $term = str_replace($needle, $replace, $term);
            }
        }

        // Remove stopwords
        $term = preg_replace('/\b(ao vivo|online|hd|tv|canal|futebol|oficial|play)\b/i', '', $term);
        // Junta letras e números (ex: espn 2 -> espn2)
        $term = preg_replace('/\s+/', ' ', trim($term));
        $term = preg_replace('/([a-z]+)\s+(\d+)/i', '$1$2', $term);
        // Limpa caracteres especiais restantes
        $term = preg_replace('/[^a-z0-9]/i', '', $term);

        return strtolower($term);
    };

    switch ($action) {
        case 'ping':
            $totalChannels = (int)$pdo->query('SELECT COUNT(*) FROM channels WHERE active = 1')->fetchColumn();
            echo json_encode([
                'status' => 'success',
                'app' => 'Evo Links Pro',
                'api_version' => '1.0',
                'base_url' => $baseUrl,
                'active_channels' => $totalChannels,
                'server_time' => date('Y-m-d H:i:s')
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;

        case 'list':
            $stmt = $pdo->query('SELECT id, slug, name, url, category, player_type, drm_key, active, sort_order FROM channels WHERE active = 1 ORDER BY sort_order ASC, name ASC');
            $rows = $stmt->fetchAll();

            $channelsList = [];
            foreach ($rows as $row) {
                $slug = (string)$row['slug'];
                $parsed = $parseSlugOption($slug);
                $pType = (string)($row['player_type'] ?? 'auto');
                $isNative = ($pType === 'native' || ($pType === 'auto' && is_stream_url((string)$row['url'])));

                $channelsList[] = [
                    'id'            => (int)$row['id'],
                    'name'          => (string)$row['name'],
                    'slug'          => $slug,
                    'category'      => (string)($row['category'] ?? ''),
                    'player_type'   => $pType,
                    'is_native'     => $isNative,
                    'root_slug'     => $parsed['root'],
                    'option_number' => $parsed['option'],
                    'embed_url'     => $baseUrl . '?v=' . rawurlencode($slug)
                ];
            }

            echo json_encode([
                'status' => 'success',
                'total' => count($channelsList),
                'base_embed_url' => $baseUrl . '?v=',
                'channels' => $channelsList
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;

        case 'match':
            // Recebe lista de canais do jogo para resolver
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
            if (!is_array($data) || empty($data['channels'])) {
                $channelsRequested = (array)($_POST['channels'] ?? []);
            } else {
                $channelsRequested = (array)$data['channels'];
            }

            if (empty($channelsRequested) && isset($_GET['channels'])) {
                $channelsRequested = explode(',', (string)$_GET['channels']);
            }

            // Carrega todos os canais ativos do banco
            $stmt = $pdo->query('SELECT id, slug, name, category, active, sort_order FROM channels WHERE active = 1 ORDER BY sort_order ASC, id ASC');
            $dbChannels = $stmt->fetchAll();

            // Indexa canais por root_slug
            $channelsByRoot = [];
            foreach ($dbChannels as $c) {
                $slug = (string)$c['slug'];
                $parsed = $parseSlugOption($slug);
                $root = $parsed['root'];
                $option = $parsed['option'];

                $cData = [
                    'id' => (int)$c['id'],
                    'name' => (string)$c['name'],
                    'slug' => $slug,
                    'root_slug' => $root,
                    'option_number' => $option,
                    'embed_url' => $baseUrl . '?v=' . rawurlencode($slug)
                ];

                if (!isset($channelsByRoot[$root])) {
                    $channelsByRoot[$root] = [];
                }
                $channelsByRoot[$root][] = $cData;
            }

            // Ordena cada grupo de root_slug por option_number (1, 2, 3...)
            foreach ($channelsByRoot as $root => &$opts) {
                usort($opts, static fn($a, $b) => $a['option_number'] <=> $b['option_number']);
            }
            unset($opts);

            $matchedStreams = [];
            $unmatchedChannels = [];
            $includedRoots = [];

            foreach ($channelsRequested as $reqChannel) {
                $rawName = trim((string)$reqChannel);
                if ($rawName === '') continue;

                $normTerm = $normalizeChannelTerm($rawName);
                $foundRoot = null;

                // 1. Tentativa por igualdade exata de root_slug
                if (isset($channelsByRoot[$normTerm])) {
                    $foundRoot = $normTerm;
                } else {
                    // 2. Procura em todos os root_slugs e nomes cadastrados
                    foreach ($channelsByRoot as $root => $opts) {
                        $rootNorm = $normalizeChannelTerm($root);
                        if ($rootNorm === $normTerm) {
                            $foundRoot = $root;
                            break;
                        }
                        // Bate com o nome do canal principal
                        $primaryNameNorm = $normalizeChannelTerm($opts[0]['name'] ?? '');
                        if ($primaryNameNorm === $normTerm) {
                            $foundRoot = $root;
                            break;
                        }
                    }
                }

                // 3. Tentativa de Substring (ex: se o canal raspado contiver o root, ou vice-versa)
                if ($foundRoot === null) {
                    foreach ($channelsByRoot as $root => $opts) {
                        $rootNorm = $normalizeChannelTerm($root);
                        if ($rootNorm !== '' && (str_contains($normTerm, $rootNorm) || str_contains($rootNorm, $normTerm))) {
                            // Não casar números divergentes (ex: não casar espn2 com espn)
                            preg_match('/\d+$/', $normTerm, $m1);
                            preg_match('/\d+$/', $rootNorm, $m2);
                            $num1 = $m1[0] ?? '';
                            $num2 = $m2[0] ?? '';
                            if ($num1 === $num2) {
                                $foundRoot = $root;
                                break;
                            }
                        }
                    }
                }

                if ($foundRoot !== null && !empty($channelsByRoot[$foundRoot])) {
                    if (!in_array($foundRoot, $includedRoots, true)) {
                        $includedRoots[] = $foundRoot;
                        $group = $channelsByRoot[$foundRoot];

                        foreach ($group as $idx => $opt) {
                            $optNum = $opt['option_number'];
                            $baseDisplayName = !empty($rawName) ? $rawName : $opt['name'];
                            
                            // Somente o nome do canal (sem texto de opção)
                            $optionTitle = $baseDisplayName;

                            $matchedStreams[] = [
                                'title' => $optionTitle,
                                'title_raw' => $baseDisplayName,
                                'link' => $opt['embed_url'],
                                'slug' => $opt['slug'],
                                'root_slug' => $opt['root_slug'],
                                'option_number' => $optNum,
                                'matched_from' => $rawName
                            ];
                        }
                    }
                } else {
                    $unmatchedChannels[] = $rawName;
                }
            }

            echo json_encode([
                'status' => 'success',
                'count_streams' => count($matchedStreams),
                'streams' => $matchedStreams,
                'unmatched' => $unmatchedChannels,
                'available_roots' => array_keys($channelsByRoot)
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Ação inválida. Use action=list, action=match ou action=ping.'
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'code' => 500,
        'message' => 'Erro interno na API: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
