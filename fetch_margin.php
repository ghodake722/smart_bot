<?php
/**
 * Fetch Margin Limits — uses ft_fast_token() (no token validation)
 * Token lifecycle is managed exclusively by SearchScrip API.
 * Called via AJAX from the dashboard (no Bearer auth required — internal only)
 */

declare(strict_types=1);
require_once __DIR__ . '/api/engine.php';

try {
    // Token comes from Redis (populated by SearchScrip) — zero validation overhead
    $access_token = ft_fast_token();

    $payload = ['uid' => FT_USER_ID, 'actid' => FT_USER_ID];
    $result  = ft_dispatch('Limits', $payload, $access_token, false);

    if ($result['s'] === 'success') {
        echo json_encode(['status' => 'success', 'payload' => $result['data']]);
    } else {
        echo json_encode(['status' => 'error', 'message' => $result['m']]);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
