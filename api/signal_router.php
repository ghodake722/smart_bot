<?php
/**
 * SIGNAL ROUTER - Unified async trading entry point
 * POST /api/signal_router.php | Authorization: Bearer <token>
 *
 * Supported actions: place, modify, cancel
 *
 * Accepted payload formats:
 *   Flat:    { "action": "place", "exch": "NSE", "tsym": "SBIN-EQ", ... }
 *   Nested:  { "requestId": "...", "orderPayload": { "exch": "NSE", ... } }
 *   No auth header? Include "session_token": "<token>" in the JSON body.
 */

declare(strict_types=1);
require_once __DIR__ . '/engine.php';

ft_enforce_method('POST');

// ── 1. Read php://input ONCE ─────────────────────────────────────────────────
// php://input is a read-once stream. We store the raw string and reuse it for
// both JSON parsing and the session_token bearer fallback in ft_extract_bearer().
$rawInput = (string)file_get_contents('php://input');
$signal   = json_decode($rawInput, true);

if (!is_array($signal) || $signal === []) {
    http_response_code(400);
    echo '{"s":"error","m":"Invalid or empty JSON body"}';
    exit;
}

// ── 2. Authenticate ───────────────────────────────────────────────────────────
// Pass pre-read body so ft_extract_bearer() can check 'session_token' in it
// without re-reading the already-drained stream.
$session = ft_authenticate_fast(ft_extract_bearer($rawInput));

// Guard: session must resolve a non-empty client_id before we build the payload.
// An empty string here means Redis and MySQL both failed; don't send broken uid to Flattrade.
if (empty($session['client_id'])) {
    http_response_code(500);
    echo '{"s":"error","m":"Internal error: session client_id is empty. Please login again."}';
    exit;
}

// ── 3. Unwrap nested orderPayload envelope ────────────────────────────────────
// Supports: { "requestId": "...", "orderPayload": { "exch": "NSE", ... } }
if (isset($signal['orderPayload']) && is_array($signal['orderPayload'])) {
    $envelope = $signal;
    $signal   = $signal['orderPayload'];
    // Carry forward 'action' if it was set at envelope level
    if (!isset($signal['action']) && isset($envelope['action'])) {
        $signal['action'] = $envelope['action'];
    }
}

// ── 4. Resolve action ─────────────────────────────────────────────────────────
if (!isset($signal['action'])) {
    $signal['action'] = 'place'; // Default: treat bare order payload as a place request
}

$action = strtolower(trim((string)$signal['action']));
unset($signal['action']);

$map = ['place' => 'PlaceOrder', 'modify' => 'ModifyOrder', 'cancel' => 'CancelOrder'];
if (!isset($map[$action])) {
    http_response_code(400);
    echo '{"s":"error","m":"Unknown action. Valid: place, modify, cancel"}';
    exit;
}

$endpoint = $map[$action];

// ── 5. Validate required fields ───────────────────────────────────────────────
if (($action === 'cancel' || $action === 'modify') && empty($signal['norenordno'])) {
    http_response_code(400);
    echo '{"s":"error","m":"' . ucfirst($action) . ' requires norenordno"}';
    exit;
}

if ($action === 'place') {
    $required = ['exch', 'tsym', 'qty', 'prc', 'prd', 'trantype', 'prctyp', 'ret'];
    // Use !isset / === '' so that prc="0" (market order) is NOT treated as missing
    $missing = array_filter($required, fn($f) => !isset($signal[$f]) || $signal[$f] === '');
    if ($missing) {
        http_response_code(400);
        echo json_encode(['s' => 'error', 'm' => 'Missing required fields: ' . implode(', ', $missing)]);
        exit;
    }
}

// ── 6. Build clean payload ────────────────────────────────────────────────────
// uid and actid are ALWAYS first — Flattrade's parser is key-order sensitive.
// All values are cast to string — Flattrade rejects non-string types (e.g. qty=1 vs qty="1").
$cleanPayload = [
    'uid'   => $session['client_id'],
    'actid' => $session['client_id'],
];

$allowedFields = [
    'exch', 'tsym', 'qty', 'prc', 'prd', 'trantype', 'prctyp', 'ret',
    'norenordno', 'trgprc', 'dscqty', 'pCode', 'amo',
];

foreach ($allowedFields as $field) {
    if (isset($signal[$field])) {
        $cleanPayload[$field] = (string)$signal[$field];
    }
}

// ── 7. Fire and forget ────────────────────────────────────────────────────────
$requestId = bin2hex(random_bytes(8));
ft_early_response($requestId); // Flush 200 to caller immediately

// Everything below runs after the HTTP connection is closed
$result = ft_dispatch($endpoint, $cleanPayload, $session['access_token'], false, false, $requestId);
ft_log_order($requestId, $endpoint, $cleanPayload, $result);
