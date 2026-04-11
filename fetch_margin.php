<?php
/**
 * Fetch Margin Limits — uses engine.php connection pools
 * Called via AJAX from the dashboard (no Bearer auth required — internal only)
 */

declare(strict_types=1);
require_once __DIR__ . '/api/engine.php';

try {
    $pdo  = DbPool::get();
    $stmt = $pdo->prepare(
        'SELECT access_token FROM flattrade_tokens
         WHERE DATE(updated_at) = CURDATE()
         ORDER BY updated_at DESC LIMIT 1'
    );
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        throw new Exception('No active session token. Please login with Flattrade.');
    }

    $payload = ['uid' => FT_USER_ID, 'actid' => FT_USER_ID];
    $result  = ft_dispatch('Limits', $payload, $row['access_token'], false);

    if ($result['s'] === 'success') {
        echo json_encode(['status' => 'success', 'payload' => $result['data']]);
    } else {
        echo json_encode(['status' => 'error', 'message' => $result['m']]);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
