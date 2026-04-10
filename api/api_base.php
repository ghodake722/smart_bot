<?php
date_default_timezone_set("Asia/Kolkata");
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;

header('Content-Type: application/json');

/**
 * Appends a debug step to php_debug.log
 */
function api_log($step, $data = null) {
    // Write directly inside the api/ folder to avoid server write-permission blocks on the root workspace
    $logFile = __DIR__ . '/api_debug.log';
    $time = date('Y-m-d H:i:s');
    $logEntry = "[$time] STEP: $step\n";
    if ($data !== null) {
        $logEntry .= "DATA: " . (is_string($data) ? $data : json_encode($data, JSON_PRETTY_PRINT)) . "\n";
    }
    $logEntry .= "----------------------------------------\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

/**
 * Validates the request method.
 * @param string $expectedMethod
 */
function enforceMethod($expectedMethod) {
    if ($_SERVER['REQUEST_METHOD'] !== $expectedMethod) {
        http_response_code(405);
        echo json_encode([
            'status' => 'error',
            'message' => "Method Not Allowed. Expected $expectedMethod."
        ]);
        exit;
    }
}

/**
 * Validates the headers and retrieves the Flattrade session token.
 * @return array ['user_id' => string, 'access_token' => string]
 */
function authenticateAndGetSession() {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();

    api_log("Starting Authentication Check", ["URI" => $_SERVER['REQUEST_URI'] ?? '', "Method" => $_SERVER['REQUEST_METHOD'] ?? '']);
    $headers = getallheaders();
    $requestToken = '';

    if (isset($headers['Authorization'])) {
        // Bearer <token>
        $parts = explode(' ', $headers['Authorization']);
        $requestToken = isset($parts[1]) ? $parts[1] : $parts[0];
    } elseif (isset($headers['X-Auth-Token'])) {
        $requestToken = $headers['X-Auth-Token'];
    }

    if (empty($requestToken)) {
        api_log("Auth Failed", "Missing Token Header");
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Missing API Token Header (Authorization or X-Auth-Token).']);
        exit;
    }

    try {
        $dsn = "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=" . ($_ENV['DB_NAME'] ?? '') . ";charset=utf8mb4";
        $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->query("SELECT access_token, header_auth_token, updated_at FROM flattrade_tokens ORDER BY updated_at DESC LIMIT 1");
        $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tokenRow) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Server Error: No Flattrade tokens configured in DB.']);
            exit;
        }

        if ($tokenRow['header_auth_token'] !== $requestToken) {
            api_log("Auth Failed", "Token mismatch. Received: " . $requestToken);
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid API Token.']);
            exit;
        }

        $user_id = $_ENV['USER_ID'] ?? '';
        if (empty($user_id)) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Server Error: USER_ID missing from environment.']);
            exit;
        }

        api_log("Auth Success", "User ID: $user_id");
        return [
            'user_id' => $user_id,
            'access_token' => $tokenRow['access_token']
        ];
    } catch (Exception $e) {
        api_log("Database Error", $e->getMessage());
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    }
}

/**
 * Sends a standard POST to the Flattrade PiConnect REST API
 */
function dispatchFlattradePost($endpoint, $payload, $jKey) {
    try {
        api_log("Dispatching to Flattrade URL: $endpoint", $payload);
        $client = new Client(['timeout' => 15.0]);
        $raw_payload = 'jData=' . json_encode($payload) . '&jKey=' . $jKey;
        
        $response = $client->request('POST', 'https://piconnect.flattrade.in/PiConnectAPI/' . $endpoint, [
            'body' => $raw_payload,
            'headers' => [
                'Content-Type' => 'text/plain' 
            ]
        ]);

        $bodyContents = $response->getBody()->getContents();
        $data = json_decode($bodyContents, true);

        api_log("Flattrade HTTP 200 Response", $data);
        // Map Flattrade status responses properly.
        $isOk = (isset($data['status']) && $data['status'] === 'Ok') || (isset($data['stat']) && $data['stat'] === 'Ok');

        if ($isOk) {
            echo json_encode(['status' => 'success', 'data' => $data]);
        } else {
            http_response_code(400); // Bad Request for Rejected Orders
            echo json_encode(['status' => 'error', 'message' => 'Flattrade API Error', 'data' => $data]);
        }
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        http_response_code(400); 
        $errorData = null;
        $msg = 'Remote Flattrade Server Error';

        if ($e->hasResponse()) {
            $errResponseBody = $e->getResponse()->getBody()->getContents();
            $errorData = json_decode($errResponseBody, true);
            if ($errorData && isset($errorData['emsg'])) {
                $msg = $errorData['emsg'];
            }
        }

        if (!$errorData) {
            $msg .= ': ' . $e->getMessage();
        }

        api_log("Flattrade RequestException HTTP Error", ['message' => $msg, 'data' => $errorData]);

        echo json_encode([
            'status' => 'error', 
            'message' => $msg, 
            'data' => $errorData
        ]);
    }
}
