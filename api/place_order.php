<?php
/**
 * Legacy Place Order Endpoint (synchronous mode)
 * For the async/early-response pipeline, use signal_router.php instead.
 */
require_once __DIR__ . '/engine.php';

ft_enforce_method('POST');
$session = ft_authenticate(ft_extract_bearer());

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo '{"s":"error","m":"Invalid or missing JSON body"}';
    exit;
}

$input['uid']   = $session['client_id'];
$input['actid'] = $session['client_id'];

ft_dispatch('PlaceOrder', $input, $session['access_token'], true);
