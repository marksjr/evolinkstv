<?php
declare(strict_types=1);

function crypto_key(string $secret): string
{
    return hash('sha256', $secret, true);
}

function crypto_encrypt(array $data, string $secret): string
{
    $key = crypto_key($secret);
    $iv = random_bytes(12);
    $tag = '';

    $json = json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );

    $ciphertext = openssl_encrypt(
        $json,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if ($ciphertext === false || $tag === '') {
        throw new RuntimeException('Falha ao criptografar o payload.');
    }

    return base64_encode($iv . $ciphertext . $tag);
}

function crypto_decrypt(string $blob, string $secret): ?array
{
    $raw = base64_decode($blob, true);
    if ($raw === false || strlen($raw) < 29) {
        return null;
    }

    $key = crypto_key($secret);
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, -16);
    $ciphertext = substr($raw, 12, -16);

    $json = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);

    return is_array($data) ? $data : null;
}

function crypto_key_parts(string $secret): array
{
    $encoded = base64_encode(crypto_key($secret));

    return array_reverse(str_split($encoded, 8));
}
