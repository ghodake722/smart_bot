<?php
// Load composer dependencies
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// Initialize state
$authStatus = null;
$errorMsg = null;
$client_id = null;

try {
    // 1. Bootstrap .env
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $api_key = $_ENV['FLATTRADE_API_KEY'] ?? $_ENV['API_KEY'] ?? '';
    $raw_api_secret = $_ENV['FLATTRADE_API_SECRET'] ?? $_ENV['API_SECRET'] ?? '';

    if (empty($api_key) || empty($raw_api_secret)) {
        throw new Exception("Missing FLATTRADE API credentials in the .env file.");
    }

    // 2. Check if we are returning from Flattrade OAuth (Phase 2)
    // Flattrade appends `?code=...&client=...` upon redirect.
    if (isset($_GET['code']) && !empty($_GET['code'])) {
        $request_code = trim($_GET['code']);
        
        // Connect to Database
        $dsn = "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=" . ($_ENV['DB_NAME'] ?? '') . ";charset=utf8mb4";
        $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Compute the secure hash purely in the backend as mandated 
        $hash_string = $api_key . $request_code . $raw_api_secret;
        $api_secret_hash = hash('sha256', $hash_string);

        // Exchange for token
        $client = new Client(['timeout'  => 15.0]);
        $response = $client->request('POST', 'https://authapi.flattrade.in/trade/apitoken', [
            'json' => [
                'api_key'      => $api_key,
                'request_code' => $request_code,
                'api_secret'   => $api_secret_hash 
            ]
        ]);

        $bodyContents = $response->getBody()->getContents();
        $data = json_decode($bodyContents, true);

        // Flattrade API inconsistently returns 'stat' or 'status'
        $isOk = (isset($data['status']) && $data['status'] === 'Ok') || (isset($data['stat']) && $data['stat'] === 'Ok');

        if (!$isOk) {
            $errorDetail = !empty($data['emsg']) ? $data['emsg'] : "Unknown API rejection.";
            throw new Exception("API Rejected Exchange. Detail: " . $errorDetail . " | Full Payload: " . $bodyContents);
        }

        $client_id = $data['client'] ?? '';
        $access_token = $data['token'] ?? '';

        if (empty($client_id) || empty($access_token)) {
            throw new Exception("Flattrade API returned 'Ok' but payload lacked client_id or token. Payload: " . $bodyContents);
        }

        // Store the token safely
        $sql = "INSERT INTO flattrade_tokens (client_id, access_token) 
                VALUES (:client_id, :access_token) 
                ON DUPLICATE KEY UPDATE 
                access_token = VALUES(access_token),
                updated_at = CURRENT_TIMESTAMP";
                
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':client_id' => $client_id,
            ':access_token' => $access_token
        ]);

        header("Location: index.php?success=1");
        exit;
    } else {
        // If someone visits redirect.php directly without a code, send them back to index.php
        header("Location: index.php");
        exit;
    }
} catch (Exception $e) {
    header("Location: index.php?error=" . urlencode($e->getMessage()));
    exit;
}
?>
