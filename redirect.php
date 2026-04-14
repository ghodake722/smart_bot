<?php
/**
 * OAuth Callback - Flattrade token exchange
 */

declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');

$api_key    = '2e42645836894d0f8bb71f02f2903b39';
$api_secret = '2026.9d216d1a0b864d6da6df088e346ebb718dcd036c8b676a10';
$db_host    = 'localhost';
$db_name    = 'mytptd_c1_db';
$db_user    = 'mytptd_c1_root';
$db_pass    = 'ptP_*yOV?7QM';
$redis_host = '127.0.0.1';
$redis_port = 6379;
$user_id    = 'FT041391';
$auth_cookie_name = 'ft_dashboard_auth';

try {
    if (!isset($_GET['code']) || empty($_GET['code'])) {
        header('Location: index.php');
        exit;
    }

    $request_code = trim($_GET['code']);
    $api_secret_hash = hash('sha256', $api_key . $request_code . $api_secret);

    $ch = curl_init('https://authapi.flattrade.in/trade/apitoken');
    $post_body = json_encode([
        'api_key' => $api_key,
        'request_code' => $request_code,
        'api_secret' => $api_secret_hash,
    ]);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post_body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('cURL Error: ' . curl_error($ch));
    }
    curl_close($ch);

    $data = json_decode($response, true);
    $isOk = ($data['stat'] ?? $data['status'] ?? '') === 'Ok';
    if (!$isOk) {
        throw new Exception('API Rejected: ' . ($data['emsg'] ?? 'Unknown error'));
    }

    $client_id = $data['client'] ?? '';
    $access_token = $data['token'] ?? '';
    $header_auth_token = bin2hex(random_bytes(32));

    if (empty($client_id) || empty($access_token)) {
        throw new Exception('API returned Ok but missing client/token fields');
    }

    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare(
        'INSERT INTO flattrade_tokens (client_id, access_token, header_auth_token)
         VALUES (:cid, :tok, :hat)
         ON DUPLICATE KEY UPDATE
         access_token = VALUES(access_token),
         header_auth_token = VALUES(header_auth_token),
         updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        ':cid' => $client_id,
        ':tok' => $access_token,
        ':hat' => $header_auth_token,
    ]);

    if (class_exists('Redis')) {
        try {
            $redis = new Redis();
            $redis->connect($redis_host, $redis_port, 2.0);
            $sessionBundle = json_encode([
                'client_id' => $client_id,
                'access_token' => $access_token,
                'header_auth_token' => $header_auth_token,
                'updated_at' => date('Y-m-d H:i:s'),
                'cached_at' => time(),
            ], JSON_UNESCAPED_SLASHES);
            $redis->setex('ft_session_bundle:' . $user_id, 3600, $sessionBundle);
            $redis->setex('ft_session_token:' . $user_id, 3600, $access_token);
            $redis->setex(
                'flattrade_auth:' . hash('sha1', $header_auth_token),
                86400,
                json_encode(['client_id' => $client_id], JSON_UNESCAPED_SLASHES)
            );
        } catch (Throwable) {
            // Ignore Redis write failures during callback; MySQL remains the source of truth.
        }
    }

    if (!headers_sent()) {
        setcookie($auth_cookie_name, $header_auth_token, [
            'expires' => time() + 86400,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    header('Location: index.php?success=1');
    exit;
} catch (Exception $e) {
    header('Location: index.php?error=' . urlencode($e->getMessage()));
    exit;
}
