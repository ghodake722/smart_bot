<?php
date_default_timezone_set("Asia/Kolkata");
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;

header('Content-Type: application/json');

try {
    // 1. Load Environment Variables
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $user_id = $_ENV['USER_ID'] ?? '';
    if (empty($user_id)) {
        throw new Exception("USER_ID is missing from the .env configuration.");
    }

    // 2. Fetch the Active Token
    $dsn = "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=" . ($_ENV['DB_NAME'] ?? '') . ";charset=utf8mb4";
    $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query("SELECT access_token, updated_at FROM flattrade_tokens ORDER BY updated_at DESC LIMIT 1");
    $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokenRow || empty($tokenRow['access_token'])) {
        throw new Exception("No active session token found in the database. Please Login with Flattrade.");
    }

    $updatedDate = date('Y-m-d', strtotime($tokenRow['updated_at']));
    $currentDate = date('Y-m-d');
    if ($updatedDate !== $currentDate) {
        throw new Exception("Token is expired (not generated today). Please generate a new session.");
    }

    $access_token = $tokenRow['access_token'];

    // 3. Request Margin Limits from Flattrade
    // Flattrade generally accepts form_params with jData and jKey for PiConnect endpoints
    $client = new Client(['timeout'  => 15.0]);
    $payload = [
        'uid' => $user_id,
        'actid' => $user_id
    ];

    $raw_payload = 'jData=' . json_encode($payload) . '&jKey=' . $access_token;
    $response = $client->request('POST', 'https://piconnect.flattrade.in/PiConnectAPI/Limits', [
        'body' => $raw_payload,
        'headers' => [
            'Content-Type' => 'text/plain'
        ]
    ]);

    $bodyContents = $response->getBody()->getContents();
    $data = json_decode($bodyContents, true);

    // Some Flattrade API versions return 'stat' instead of 'status'
    $isOk = (isset($data['status']) && $data['status'] === 'Ok') || (isset($data['stat']) && $data['stat'] === 'Ok');

    if (!$isOk) {
        $errorDetail = !empty($data['emsg']) ? $data['emsg'] : "Unknown API rejection.";
        throw new Exception("Flattrade Rejected Limit Query: " . $errorDetail);
    }

    echo json_encode([
        'status' => 'success',
        'payload' => $data
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
