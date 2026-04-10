<?php
require_once __DIR__ . '/api_base.php';

enforceMethod('DELETE');
$session = authenticateAndGetSession();

$inputRaw = file_get_contents("php://input");
$inputData = json_decode($inputRaw, true);

// For DELETE, some clients use URL params, others use JSON. Check both.
if (!$inputData && isset($_GET['norenordno'])) {
    $inputData = ['norenordno' => $_GET['norenordno']];
}

if (!$inputData || !isset($inputData['norenordno'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing norenordno for cancellation. Send JSON body or ?norenordno=... in URL.']);
    exit;
}

// CancelOrder primarily uses uid and norenordno
$payload = [
    'uid' => $session['user_id'],
    'norenordno' => $inputData['norenordno']
];

dispatchFlattradePost('CancelOrder', $payload, $session['access_token']);
