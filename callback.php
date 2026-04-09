<?php
/**
 * Flattrade OAuth-style Login Callback
 * 
 * This script handles the redirect from Flattrade's authentication page,
 * capturing the `request_code`, calculating the secure SHA-256 hash, and
 * trading the `request_code` for a final `access_token` which is stored 
 * securely in the database.
 */

// 1. Bootstrap & Env
// Require the Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

try {
    // Load environment variables from the .env file
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    die("Error loading environment variables: " . $e->getMessage());
}

// 2. Database Connection
try {
    // Set up a secure PDO MySQL connection using .env variables
    $dsn = "mysql:host=" . $_ENV['DB_HOST'] . ";dbname=" . $_ENV['DB_NAME'] . ";charset=utf8mb4";
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS']);
    
    // Enable exception mode for errors for better handling and security
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Use associative arrays for fetches by default
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// 3. Capture Request Code
// Flattrade sends the request_code via GET parameters in the callback URL
if (!isset($_GET['request_code']) || trim($_GET['request_code']) === '') {
    http_response_code(400); // Bad Request
    die("Error: Missing 'request_code' parameter in the callback URL.");
}
$request_code = trim($_GET['request_code']);

// Load API keys from environment (falling back to API_KEY/API_SECRET if the specific ones are missing)
$api_key = $_ENV['FLATTRADE_API_KEY'] ?? $_ENV['API_KEY'] ?? '';
$raw_api_secret = $_ENV['FLATTRADE_API_SECRET'] ?? $_ENV['API_SECRET'] ?? '';

if (empty($api_key) || empty($raw_api_secret)) {
    die("Error: Missing Flattrade API credentials in the environment variables.");
}

// 4. The Security Hash (CRITICAL)
// Hash calculation MUST strictly be: SHA-256 (api_key + request_code + raw_api_secret)
// We absolutely ensure $raw_api_secret is NOT sent over the wire.
$hash_string = $api_key . $request_code . $raw_api_secret;
$api_secret_hash = hash('sha256', $hash_string);

// 5. API Request with Guzzle
$client = new Client([
    'base_uri' => 'https://authapi.flattrade.in',
    'timeout'  => 15.0,
]);

try {
    // Endpoint: POST https://authapi.flattrade.in/trade/apitoken
    $response = $client->request('POST', '/trade/apitoken', [
        'json' => [
            'api_key'      => $api_key,
            'request_code' => $request_code,
            'api_secret'   => $api_secret_hash // Include the computed SHA-256 hash here
        ]
    ]);

    // 6. Handle Response
    $body = $response->getBody()->getContents();
    $responseData = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        die("Error: Received invalid JSON response from Flattrade API.");
    }

    // Check if the response status is exactly 'Ok'
    if (!isset($responseData['status']) || $responseData['status'] !== 'Ok') {
        $error_msg = $responseData['emsg'] ?? 'Unknown error from Flattrade API.';
        die("Flattrade API Error: " . htmlspecialchars($error_msg));
    }

    // If 'Ok', extract the client_id and the token
    $client_id = $responseData['client'] ?? '';
    $access_token = $responseData['token'] ?? '';

    if (empty($client_id) || empty($access_token)) {
        die("Error: Flattrade API returned 'Ok' status but client ID or token is missing.");
    }

    // 7. Database Storage
    // Use INSERT ... ON DUPLICATE KEY UPDATE to ensure we either create the token 
    // or update the token daily for existing clients.
    $sql = "INSERT INTO flattrade_tokens (client_id, access_token) 
            VALUES (:client_id, :access_token) 
            ON DUPLICATE KEY UPDATE 
            access_token = VALUES(access_token),
            updated_at = CURRENT_TIMESTAMP"; // updated_at is handled by the database schema but we can force it if needed
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':client_id' => $client_id,
        ':access_token' => $access_token
    ]);

    // 8. Output
    // Echo a success message verifying the client ID that was successfully authorized
    echo "<h1>Authorization Successful</h1>";
    echo "<p>Authentication process complete. The access token for Client ID <strong>" . htmlspecialchars($client_id) . "</strong> has been securely stored/updated.</p>";

} catch (RequestException $e) {
    // Catch Guzzle-specific network/HTTP errors
    die("HTTP Request Error linking with Flattrade: " . htmlspecialchars($e->getMessage()));
} catch (Exception $e) {
    // Catch any other general errors
    die("An unexpected error occurred during authorization: " . htmlspecialchars($e->getMessage()));
}
