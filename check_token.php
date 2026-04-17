<?php
/**
 * Diagnostic: Show current session state from DB + Redis
 * Access via browser: https://test.mytptd.com/check_token.php
 * DELETE THIS FILE after debugging.
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=utf-8');

$result = ['timestamp' => date('Y-m-d H:i:s T'), 'db' => null, 'redis' => null];

// --- MySQL ---
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=mytptd_c1_db;charset=utf8mb4',
        'mytptd_c1_root',
        'ptP_*yOV?7QM',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $pdo->query(
        "SELECT id, client_id, access_token, header_auth_token, updated_at
         FROM flattrade_tokens
         WHERE DATE(updated_at) = CURDATE()
         ORDER BY updated_at DESC LIMIT 1"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $result['db'] = [
            'id' => $row['id'],
            'client_id' => $row['client_id'],
            'access_token' => substr($row['access_token'], 0, 20) . '...',
            'header_auth_token' => $row['header_auth_token'],
            'updated_at' => $row['updated_at'],
        ];
    } else {
        $result['db'] = 'NO row found for today';
    }
} catch (Throwable $e) {
    $result['db'] = 'ERROR: ' . $e->getMessage();
}

// --- Redis ---
if (class_exists('Redis')) {
    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379, 2.0);

        $bundle = $redis->get('ft_session_bundle:FT041391');
        if ($bundle) {
            $data = json_decode($bundle, true);
            $lastUpdated = $data['last_updated'] ?? $data['cached_at'] ?? 0;
            $age = $lastUpdated > 0 ? time() - $lastUpdated : -1;
            $result['redis'] = [
                'client_id' => $data['client_id'] ?? null,
                'access_token' => substr($data['access_token'] ?? '', 0, 20) . '...',
                'header_auth_token' => $data['header_auth_token'] ?? null,
                'updated_at' => $data['updated_at'] ?? null,
                'last_updated' => $lastUpdated,
                'age_seconds' => $age,
                'age_minutes' => round($age / 60, 1),
                'is_fresh' => $age >= 0 && $age < 3600,
                'ttl' => $redis->ttl('ft_session_bundle:FT041391'),
            ];
        } else {
            $result['redis'] = 'NO bundle found in Redis';
        }
    } catch (Throwable $e) {
        $result['redis'] = 'ERROR: ' . $e->getMessage();
    }
} else {
    $result['redis'] = 'Redis extension not loaded';
}

// --- Token match check ---
$dbToken = $result['db']['header_auth_token'] ?? null;
$redisToken = $result['redis']['header_auth_token'] ?? null;
if ($dbToken && $redisToken) {
    $result['token_match'] = hash_equals($dbToken, $redisToken) ? 'MATCH' : 'MISMATCH';
}

$result['postman_hint'] = $dbToken
    ? 'Use this as your Bearer token in Postman: ' . $dbToken
    : 'No header_auth_token found. Login with FlatTrade first.';

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
