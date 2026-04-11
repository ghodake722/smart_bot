<?php
/**
 * ============================================================================
 *  SIGNAL ROUTER — Ultra-Low Latency Entry Point
 * ============================================================================
 *  This is the SINGLE endpoint that external signal providers (TradingView,
 *  custom bots, etc.) hit to place/modify/cancel orders via Flattrade.
 *
 *  Endpoint:   POST /api/signal_router.php
 *  Header:     Authorization: Bearer <header_auth_token>
 *  Body:       JSON signal payload (see examples below)
 *
 *  Execution Timeline:
 *    T+0.0ms  → Bearer token extracted
 *    T+0.1ms  → Redis cache hit (auth validated)
 *    T+0.2ms  → HTTP 200 returned to caller (connection CLOSED)
 *    T+0.3ms  → (Background) cURL dispatched to Flattrade broker
 *    T+~50ms  → (Background) Broker response logged to MySQL
 *
 *  Signal Actions:
 *    "place"   → POST /PlaceOrder
 *    "modify"  → POST /ModifyOrder
 *    "cancel"  → POST /CancelOrder
 * ============================================================================
 *
 *  EXAMPLE PAYLOADS:
 *
 *  ─── Place Order ────────────────────────────────────────────────────────
 *  {
 *      "action":   "place",
 *      "exch":     "NSE",
 *      "tsym":     "ACC-EQ",
 *      "qty":      "50",
 *      "prc":      "1400",
 *      "prd":      "I",
 *      "trantype": "B",
 *      "prctyp":   "LMT",
 *      "ret":      "DAY"
 *  }
 *
 *  ─── Modify Order ───────────────────────────────────────────────────────
 *  {
 *      "action":      "modify",
 *      "norenordno":  "24121300000123",
 *      "exch":        "NSE",
 *      "tsym":        "ACC-EQ",
 *      "qty":         "25",
 *      "prc":         "1450",
 *      "prctyp":      "LMT",
 *      "ret":         "DAY"
 *  }
 *
 *  ─── Cancel Order ───────────────────────────────────────────────────────
 *  {
 *      "action":     "cancel",
 *      "norenordno": "24121300000123"
 *  }
 *
 * ============================================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/engine.php';

// ─── STEP 1: Fail-Fast Method Check ─────────────────────────────────────────
ft_enforce_method('POST');

// ─── STEP 2: Fail-Fast Bearer Extraction ────────────────────────────────────
$bearer = ft_extract_bearer();

// ─── STEP 3: Microsecond Cache-Aside Authentication ─────────────────────────
$session = ft_authenticate($bearer);

// ─── STEP 4: Parse Incoming Signal ──────────────────────────────────────────
$raw = file_get_contents('php://input');
$signal = json_decode($raw, true);

if (!$signal || !isset($signal['action'])) {
    http_response_code(400);
    echo '{"s":"error","m":"Invalid signal: missing JSON body or action field"}';
    exit;
}

$action = strtolower(trim($signal['action']));
unset($signal['action']); // Strip action before forwarding to broker

// ─── Resolve Endpoint ───────────────────────────────────────────────────────
$endpoint_map = [
    'place'  => 'PlaceOrder',
    'modify' => 'ModifyOrder',
    'cancel' => 'CancelOrder',
];

if (!isset($endpoint_map[$action])) {
    http_response_code(400);
    echo json_encode([
        's' => 'error',
        'm' => "Unknown action: '$action'. Valid: place, modify, cancel",
    ]);
    exit;
}

$endpoint = $endpoint_map[$action];

// ─── Validate Required Fields per Action ────────────────────────────────────
if ($action === 'cancel' && empty($signal['norenordno'])) {
    http_response_code(400);
    echo '{"s":"error","m":"Cancel requires norenordno"}';
    exit;
}

if ($action === 'modify' && empty($signal['norenordno'])) {
    http_response_code(400);
    echo '{"s":"error","m":"Modify requires norenordno"}';
    exit;
}

if ($action === 'place') {
    $required = ['exch', 'tsym', 'qty', 'prc', 'prd', 'trantype', 'prctyp', 'ret'];
    $missing = [];
    foreach ($required as $field) {
        if (empty($signal[$field])) {
            $missing[] = $field;
        }
    }
    if ($missing) {
        http_response_code(400);
        echo json_encode([
            's' => 'error',
            'm' => 'Place order missing: ' . implode(', ', $missing),
        ]);
        exit;
    }
}

// ─── Inject Identity ────────────────────────────────────────────────────────
$signal['uid']   = $session['client_id'];
$signal['actid'] = $session['client_id'];

// ─── Generate Unique Request ID ─────────────────────────────────────────────
$request_id = bin2hex(random_bytes(8)); // 16-char hex, collision-safe

// ─── STEP 5: EARLY RESPONSE — Close Connection Immediately ─────────────────
//  The caller receives HTTP 200 with a request_id for tracking.
//  The PHP process continues executing the broker dispatch below.
ft_early_response($request_id);

// ═══════════════════════════════════════════════════════════════════════════
//  EVERYTHING BELOW RUNS IN BACKGROUND (client already disconnected)
// ═══════════════════════════════════════════════════════════════════════════

// ─── STEP 6: Dispatch to Flattrade Broker ───────────────────────────────────
$result = ft_dispatch(
    $endpoint,
    $signal,
    $session['access_token'],
    false  // Don't echo — client is already gone
);

// ─── STEP 7: Log the Full Lifecycle ─────────────────────────────────────────
ft_log_order($request_id, $endpoint, $signal, $result);

// ─── STEP 8: Engine Diagnostics ─────────────────────────────────────────────
$total_us = (int)((hrtime(true) - $_ENGINE_T0) / 1000);
error_log(sprintf(
    '[FT_SIGNAL] %s | %s | req=%s | total=%d µs | broker=%d µs | status=%s',
    $action,
    $signal['tsym'] ?? 'N/A',
    $request_id,
    $total_us,
    $result['latency_us'] ?? 0,
    $result['s'] ?? 'unknown'
));
