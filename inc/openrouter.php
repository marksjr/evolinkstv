<?php
declare(strict_types=1);

/**
 * Cliente para API OpenRouter
 */

function openrouter_get_key(PDO $pdo): string
{
    return trim(setting($pdo, 'openrouter_api_key', ''));
}

function openrouter_get_model(PDO $pdo): string
{
    $model = trim(setting($pdo, 'openrouter_model', ''));
    return $model !== '' ? $model : 'google/gemini-2.5-flash';
}

function openrouter_is_configured(PDO $pdo): bool
{
    return openrouter_get_key($pdo) !== '';
}

/**
 * Envia mensagens para o OpenRouter e retorna resposta tratada.
 *
 * @param PDO $pdo
 * @param array<array{role: string, content: string}> $messages
 * @param array<string, mixed> $options
 * @return array{ok: bool, content: string, data: ?array, error: ?string}
 */
function openrouter_chat(PDO $pdo, array $messages, array $options = []): array
{
    $apiKey = openrouter_get_key($pdo);
    if ($apiKey === '') {
        return [
            'ok'      => false,
            'content' => '',
            'data'    => null,
            'error'   => 'Chave da API OpenRouter não configurada. Acesse Configurações para informar a chave.',
        ];
    }

    $model = (string)($options['model'] ?? openrouter_get_model($pdo));
    $temperature = (float)($options['temperature'] ?? 0.2);
    $jsonMode = !empty($options['json']);
    $timeout = (int)($options['timeout'] ?? 60);

    $payload = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => $temperature,
    ];

    if ($jsonMode) {
        $payload['response_format'] = ['type' => 'json_object'];
    }

    $appUrl = (string)($GLOBALS['config']['app']['url'] ?? 'https://site.com');
    $appName = (string)($GLOBALS['config']['app']['name'] ?? 'Evo Links TV');

    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'HTTP-Referer: ' . $appUrl,
        'X-Title: ' . $appName,
    ];

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    if ($ch === false) {
        return [
            'ok'      => false,
            'content' => '',
            'data'    => null,
            'error'   => 'Falha ao inicializar cURL.',
        ];
    }

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $caFile = APP_ROOT . '/inc/cacert.pem';

    $curlOptions = [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonPayload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ];

    if (file_exists($caFile)) {
        $curlOptions[CURLOPT_CAINFO] = $caFile;
    }

    curl_setopt_array($ch, $curlOptions);

    $rawResponse = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Fallback de contingência para erros de certificado SSL em ambientes locais Windows
    if ($curlErr !== '' && (str_contains(strtolower($curlErr), 'certificate') || str_contains(strtolower($curlErr), 'ssl'))) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        $rawResponse = curl_exec($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    }

    curl_close($ch);

    if ($curlErr !== '') {
        return [
            'ok'      => false,
            'content' => '',
            'data'    => null,
            'error'   => 'Erro de conexão cURL com OpenRouter: ' . $curlErr,
        ];
    }

    if (!is_string($rawResponse) || $rawResponse === '') {
        return [
            'ok'      => false,
            'content' => '',
            'data'    => null,
            'error'   => 'Resposta vazia da API OpenRouter (HTTP ' . $httpCode . ').',
        ];
    }

    $decoded = json_decode($rawResponse, true);
    if (!is_array($decoded)) {
        return [
            'ok'      => false,
            'content' => '',
            'data'    => null,
            'error'   => 'Resposta inválida recebida da API OpenRouter (não é JSON).',
        ];
    }

    if ($httpCode >= 400 || isset($decoded['error'])) {
        $msg = 'Erro OpenRouter (HTTP ' . $httpCode . '): ';
        if (isset($decoded['error'])) {
            $msg .= is_array($decoded['error'])
                ? ($decoded['error']['message'] ?? json_encode($decoded['error']))
                : (string)$decoded['error'];
        } else {
            $msg .= $rawResponse;
        }

        return [
            'ok'      => false,
            'content' => '',
            'data'    => $decoded,
            'error'   => $msg,
        ];
    }

    $content = (string)($decoded['choices'][0]['message']['content'] ?? '');

    return [
        'ok'      => true,
        'content' => $content,
        'data'    => $decoded,
        'error'   => null,
    ];
}

/**
 * Testa a conexão com OpenRouter fazendo uma requisição mínima.
 */
function openrouter_test(PDO $pdo): array
{
    $res = openrouter_chat($pdo, [
        ['role' => 'user', 'content' => 'Responda apenas com a palavra OK.'],
    ], [
        'temperature' => 0.0,
        'timeout'     => 20,
    ]);

    return $res;
}
