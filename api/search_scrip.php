<?php
/**
 * SearchScrip API — Symbol search with token refresh logic
 * POST /api/search_scrip.php
 *
 * Uses ft_resolve_session_token() which implements:
 *   Redis L1 (1hr TTL) → MySQL L2 → Write-Back → Audit Log
 *
 * Request body (JSON):
 *   { "stext": "NIFTY", "exch": "NSE" }
 */

declare(strict_types=1);
require_once __DIR__ . '/engine.php';

ft_enforce_method('POST');

try {
    // ── Parse & Validate Input ──────────────────────────────────────────────
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || empty($input['stext'])) {
        http_response_code(400);
        echo json_encode(['s' => 'error', 'm' => 'Missing required field: stext']);
        exit;
    }

    $stext = trim($input['stext']);
    $exch  = trim($input['exch'] ?? 'NSE');

    // ── Resolve Session Token (Redis → MySQL → Write-Back, 1hr TTL) ─────────
    $access_token = ft_resolve_session_token();

    // ── Dispatch to Flattrade SearchScrip API ───────────────────────────────
    $payload = [
        'uid'   => FT_USER_ID,
        'stext' => $stext,
        'exch'  => $exch,
    ];

    $result = ft_dispatch('SearchScrip', $payload, $access_token, false);

    if ($result['s'] === 'success') {
        echo json_encode([
            's'          => 'success',
            'm'          => 'Search completed',
            'payload'    => $result['data'],
            'latency_us' => $result['latency_us'],
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            's' => 'error',
            'm' => $result['m'],
        ]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        's' => 'error',
        'm' => $e->getMessage(),
        'debug' => $e->getFile() . ':' . $e->getLine(),
    ]);
}
