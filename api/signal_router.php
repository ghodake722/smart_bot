<?php
/**
 * SIGNAL ROUTER — Unified async trading entry point
 * POST /api/signal_router.php | Authorization: Bearer <token>
 * Actions: place, modify, cancel
 */

declare(strict_types=1);
require_once __DIR__ . '/engine.php';

// ── Gate ─────────────────────────────────────────────────────────────────────
ft_enforce_method('POST');

// ── Parse Signal ─────────────────────────────────────────────────────────────
$signal = json_decode(file_get_contents('php://input'), true);
if (!is_array($signal)) {
    $signal = [];
}

$session = ft_authenticate(
    ft_extract_bearer(),
    ft_extract_requested_user_id($signal),
    ft_extract_requested_session_token($signal)
);

if (!$signal || !isset($signal['action'])) {
    http_response_code(400);
    echo '{"s":"error","m":"Invalid signal: missing JSON body or action field"}';
    exit;
}

$action = strtolower(trim($signal['action']));
unset($signal['action'], $signal['user_id'], $signal['session_token']);

// ── Resolve Endpoint ────────────────────────────────────────────────────────
$map = ['place' => 'PlaceOrder', 'modify' => 'ModifyOrder', 'cancel' => 'CancelOrder'];

if (!isset($map[$action])) {
    http_response_code(400);
    echo '{"s":"error","m":"Unknown action. Valid: place, modify, cancel"}';
    exit;
}

$endpoint = $map[$action];

// ── Validate ────────────────────────────────────────────────────────────────
if (($action === 'cancel' || $action === 'modify') && empty($signal['norenordno'])) {
    http_response_code(400);
    echo '{"s":"error","m":"' . ucfirst($action) . ' requires norenordno"}';
    exit;
}

if ($action === 'place') {
    $required = ['exch', 'tsym', 'qty', 'prc', 'prd', 'trantype', 'prctyp', 'ret'];
    $missing  = array_filter($required, fn($f) => empty($signal[$f]));
    if ($missing) {
        http_response_code(400);
        echo json_encode(['s' => 'error', 'm' => 'Missing: ' . implode(', ', $missing)]);
        exit;
    }
}

// ── Inject Identity ─────────────────────────────────────────────────────────
$signal['uid']   = $session['client_id'];
$signal['actid'] = $session['client_id'];

// ── Early Response ──────────────────────────────────────────────────────────
$request_id = bin2hex(random_bytes(8));
ft_early_response($request_id);

// ═══════════════════════════════════════════════════════════════════════════
//  BACKGROUND (client disconnected)
// ═══════════════════════════════════════════════════════════════════════════
$result = ft_dispatch($endpoint, $signal, $session['access_token']);
ft_log_order($request_id, $endpoint, $signal, $result);

