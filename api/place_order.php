<?php
/**
 * Legacy Place Order Endpoint (synchronous mode)
 * For the async/early-response pipeline, use signal_router.php instead.
 */
require_once __DIR__ . '/engine.php';

ft_enforce_method('POST');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo '{"s":"error","m":"Invalid or missing JSON body"}';
    exit;
}

$session = ft_authenticate_fast(ft_extract_bearer());
ft_place_order($input, $session, true);
