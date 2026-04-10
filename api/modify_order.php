<?php
require_once __DIR__ . '/api_base.php';

// Accept PUT method as RESTful standard for modifications
enforceMethod('PUT');
$session = authenticateAndGetSession();

$inputRaw = file_get_contents("php://input");
$inputData = json_decode($inputRaw, true);

if (!$inputData || !isset($inputData['norenordno'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON body. Modification requires `norenordno` (Order ID).']);
    exit;
}

$inputData['uid'] = $session['user_id'];
$inputData['actid'] = $session['user_id'];

// Forward logic -> Flattrade accepts modification as POST specifically on its endpoint
dispatchFlattradePost('ModifyOrder', $inputData, $session['access_token']);
