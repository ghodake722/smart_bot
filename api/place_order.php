<?php
require_once __DIR__ . '/api_base.php';

enforceMethod('POST');
$session = authenticateAndGetSession();

// Get the raw POST body
$inputRaw = file_get_contents("php://input");
$inputData = json_decode($inputRaw, true);

if (!$inputData) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid or missing JSON body.']);
    exit;
}

// Set required basic elements globally
$inputData['uid'] = $session['user_id'];
$inputData['actid'] = $session['user_id'];

// Forward the POST request to Flattrade PlaceOrder endpoint
dispatchFlattradePost('PlaceOrder', $inputData, $session['access_token']);
