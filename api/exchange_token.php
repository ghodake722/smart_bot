<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use GuzzleHttp\Client;

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $request_code = $input['request_code'] ?? '';

    if (empty($request_code)) {
        throw new Exception("Missing request_code payload.");
    }

    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();

    $api_key = $_ENV['FLATTRADE_API_KEY'] ?? $_ENV['API_KEY'] ?? '';
    $raw_api_secret = $_ENV['FLATTRADE_API_SECRET'] ?? $_ENV['API_SECRET'] ?? '';
    
    // DB Setup
    $dsn = "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=" . ($_ENV['DB_NAME'] ?? '') . ";charset=utf8mb4";
    $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Flattrade Official Handshake Specification
    $hash_string = $api_key . $request_code . $raw_api_secret;
    $api_secret_hash = hash('sha256', $hash_string);

    $client = new Client(['timeout'  => 15.0]);
    $response = $client->request('POST', 'https://authapi.flattrade.in/trade/apitoken', [
        'json' => [
            'api_key'      => $api_key,
            'request_code' => $request_code,
            'api_secret'   => $api_secret_hash 
        ]
    ]);

    $data = json_decode($response->getBody()->getContents(), true);

    if (!isset($data['status']) || $data['status'] !== 'Ok') {
        throw new Exception($data['emsg'] ?? "Token exchange failed.");
    }

    $client_id = $data['client'] ?? '';
    $access_token = $data['token'] ?? '';

    if (empty($client_id) || empty($access_token)) {
        throw new Exception("Token exchange succeeded but missing client ID/Token internally.");
    }

    // Storage Phase -> Use the schema we created earlier
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

    echo json_encode([
        'status' => 'success',
        'client_id' => $client_id
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
