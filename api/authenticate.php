<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

header('Content-Type: application/json');

try {
    // 1. Capture the frontend OTP
    $input = json_decode(file_get_contents('php://input'), true);
    $otp = $input['otp'] ?? '';

    if (empty($otp)) {
        throw new Exception("OTP is missing from the request payload.");
    }

    // 2. Load .env Configuration
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();

    $user_id = $_ENV['USER_ID'] ?? '';
    // Security: Encrypted raw password string using SHA-256 for the Pi API payload
    $password = hash('sha256', $_ENV['PASSWORD'] ?? ''); 
    $api_key = $_ENV['FLATTRADE_API_KEY'] ?? $_ENV['API_KEY'] ?? '';

    if (empty($user_id) || empty($_ENV['PASSWORD']) || empty($api_key)) {
        throw new Exception("Missing USER_ID, PASSWORD, or API_KEY in the backend .env");
    }

    // 3. Prepare Headless Authentication via Flattrade's UI Endpoint
    $client = new Client([
        'timeout'  => 15.0,
        'cookies'  => true 
    ]);

    // Construct the payload simulating a browser login payload (jData)
    // The exact param structure required by Finvasia/Flattrade Pi systems
    $payloadStructure = [
        'uid' => $user_id,
        'pwd' => $password,
        'factor2' => $otp,
        'vc' => $user_id . '_U', // Vendor code conventions generally map to UID_U for users
        'appkey' => $api_key,
        'imei' => 'api_sys_1234',
        'source' => 'API'
    ];

    $payloadText = "jData=" . urlencode(json_encode($payloadStructure));

    // Notice we use the standard connect login URL (authapi / auth)
    $response = $client->request('POST', 'https://authapi.flattrade.in/auth/session', [
        'body' => $payloadText,
        'headers' => [
            'Content-Type' => 'application/x-www-form-urlencoded'
        ]
    ]);

    $body = $response->getBody()->getContents();
    $data = json_decode($body, true);

    if (isset($data['stat']) && $data['stat'] === 'Ok') {
        // Success. If it acts similarly to QuickAuth, it will give 'susertoken'
        // In the OAuth headless extraction, 'request_code' is technically what we are looking for.
        // We will pass the token out safely.
        $request_code = $data['susertoken'] ?? $data['request_code'] ?? $data['token'] ?? '';
        
        if (empty($request_code)) {
            throw new Exception("Login succeeded but failed to extract the request_code from the response payload.");
        }

        echo json_encode([
            'status' => 'success', 
            'request_code' => $request_code
        ]);
    } else {
        throw new Exception($data['emsg'] ?? "Flattrade Headless API Rejected the Login.");
    }
} catch (RequestException $e) {
    echo json_encode(['status' => 'error', 'message' => "Network Error: " . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
