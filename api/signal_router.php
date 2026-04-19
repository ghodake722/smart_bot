<?php
/**
 * SIGNAL ROUTER - Unified async trading entry point
 * POST /api/signal_router.php | Authorization: Bearer <token>
 * Actions: place, modify, cancel
 */

declare(strict_types=1);
require_once __DIR__ . '/engine.php';

ft_enforce_method('POST');
$signal = json_decode(file_get_contents('php://input'), true);
if (!is_array($signal)) {
    $signal = [];
}

$session = ft_authenticate_fast(ft_extract_bearer());

if (!isset($signal['action'])) {
    $signal['action'] = 'place';
}

$action = strtolower(trim($signal['action']));
unset($signal['action']);

$map = ['place' => 'PlaceOrder', 'modify' => 'ModifyOrder', 'cancel' => 'CancelOrder'];
if (!isset($map[$action])) {
    http_response_code(400);
    echo '{"s":"error","m":"Unknown action. Valid: place, modify, cancel"}';
    exit;
}

$endpoint = $map[$action];

if (($action === 'cancel' || $action === 'modify') && empty($signal['norenordno'])) {
    http_response_code(400);
    echo '{"s":"error","m":"' . ucfirst($action) . ' requires norenordno"}';
    exit;
}

if ($action === 'place') {
    $required = ['exch', 'tsym', 'qty', 'prc', 'prd', 'trantype', 'prctyp', 'ret'];
    $missing = array_filter($required, fn($field) => !isset($signal[$field]) || $signal[$field] === '');
    if ($missing) {
        http_response_code(400);
        echo json_encode(['s' => 'error', 'm' => 'Missing: ' . implode(', ', $missing)]);
        exit;
    }
}

$cleanPayload = [
    'uid' => $session['client_id'],
    'actid' => $session['client_id']
];

$allowedFields = [
    'exch', 'tsym', 'qty', 'prc', 'prd', 'trantype', 'prctyp', 'ret', 
    'norenordno', 'trgprc', 'dscqty', 'pCode', 'amo'
];

foreach ($allowedFields as $field) {
    if (isset($signal[$field])) {
        $cleanPayload[$field] = (string)$signal[$field];
    }
}

$signal = $cleanPayload;

$requestId = bin2hex(random_bytes(8));
ft_early_response($requestId);

$result = ft_dispatch($endpoint, $signal, $session['access_token'], false, false);
ft_log_order($requestId, $endpoint, $signal, $result);
