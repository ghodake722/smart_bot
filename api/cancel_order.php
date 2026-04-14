<?php
/**
 * Legacy Cancel Order Endpoint (synchronous mode)
 * For the async/early-response pipeline, use signal_router.php instead.
 */
require_once __DIR__ . '/engine.php';

ft_enforce_method('DELETE');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

if (empty($input['norenordno']) && isset($_GET['norenordno'])) {
    $input['norenordno'] = $_GET['norenordno'];
}

if (empty($input['norenordno'])) {
    http_response_code(400);
    echo '{"s":"error","m":"Missing norenordno for cancellation"}';
    exit;
}

$session = ft_authenticate_fast(ft_extract_bearer());
$payload = [
    'uid' => $session['client_id'],
    'norenordno' => $input['norenordno'],
];

ft_dispatch('CancelOrder', $payload, $session['access_token'], true, false);
