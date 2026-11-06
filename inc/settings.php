<?php
declare(strict_types=1);

function settings_all(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
    $cache = [];

    foreach ($rows as $row) {
        $cache[$row['setting_key']] = $row['setting_value'];
    }

    return $cache;
}

function setting(PDO $pdo, string $key, string $default = ''): string
{
    $all = settings_all($pdo);

    return array_key_exists($key, $all) && $all[$key] !== null
        ? (string)$all[$key]
        : $default;
}

function setting_bool(PDO $pdo, string $key, bool $default = false): bool
{
    $value = strtolower(setting($pdo, $key, $default ? '1' : '0'));

    return in_array($value, ['1', 'true', 'on', 'yes'], true);
}

function settings_save(PDO $pdo, array $values): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = ?'
    );

    $pdo->beginTransaction();
    try {
        foreach ($values as $key => $value) {
            $stmt->execute([$key, (string)$value, (string)$value]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
