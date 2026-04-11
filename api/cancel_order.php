<?php
/**
 * Legacy Cancel Order Endpoint (synchronous mode)
 * For the async/early-response pipeline, use signal_router.php instead.
 */
require_once __DIR__ . '/engine.php';

ft_enforce_method('DELETE');
$session = ft_authenticate(ft_extract_bearer());

$input = json_decode(file_get_contents('php://input'), true);

// Support DELETE with query param fallback
if (!$input && isset($_GET['norenordno'])) {
    $input = ['norenordno' => $_GET['norenordno']];
}

if (!$input || empty($input['norenordno'])) {
    http_response_code(400);
    echo '{"s":"error","m":"Missing norenordno for cancellation"}';
    exit;
}

$payload = [
    'uid'        => $session['client_id'],
    'norenordno' => $input['norenordno'],
];

ft_dispatch('CancelOrder', $payload, $session['access_token'], true);
