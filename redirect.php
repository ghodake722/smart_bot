<?php
/**
 * OAuth Callback — Flattrade token exchange
 * Redirect target: receives ?code=... from Flattrade, exchanges for access_token
 * Runs once/day — not on critical trading path, but still optimized
 */

declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');

// Hardcoded credentials (no Dotenv overhead)
$api_key    = '2e42645836894d0f8bb71f02f2903b39';
$api_secret = '2026.9d216d1a0b864d6da6df088e346ebb718dcd036c8b676a10';
$db_host    = 'localhost';
$db_name    = 'mytptd_c1_db';
$db_user    = 'mytptd_c1_root';
$db_pass    = 'ptP_*yOV?7QM';

try {
    if (!isset($_GET['code']) || empty($_GET['code'])) {
        header('Location: index.php');
        exit;
    }

    $request_code    = trim($_GET['code']);
    $api_secret_hash = hash('sha256', $api_key . $request_code . $api_secret);

    // Exchange token via native cURL
    $ch = curl_init('https://authapi.flattrade.in/trade/apitoken');
    $post_body = json_encode([
        'api_key'      => $api_key,
        'request_code' => $request_code,
        'api_secret'   => $api_secret_hash,
    ]);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post_body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
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

    $client_id    = $data['client'] ?? '';
    $access_token = $data['token'] ?? '';

    if (empty($client_id) || empty($access_token)) {
        throw new Exception('API returned Ok but missing client/token fields');
    }

    // Persist to MySQL
    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user, $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo->prepare(
        'INSERT INTO flattrade_tokens (client_id, access_token)
         VALUES (:cid, :tok)
         ON DUPLICATE KEY UPDATE
         access_token = VALUES(access_token),
         updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([':cid' => $client_id, ':tok' => $access_token]);

    header('Location: index.php?success=1');
    exit;

} catch (Exception $e) {
    header('Location: index.php?error=' . urlencode($e->getMessage()));
    exit;
}
