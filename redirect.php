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

        $authStatus = 'success';
    } else {
        // If someone visits redirect.php directly without a code, send them back to index.php
        header("Location: index.php");
        exit;
    }
} catch (Exception $e) {
    echo "<h1>Critical Error</h1><p>" . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flattrade Session Authorized</title>
    <style>
        :root {
            --bg-color: #0f172a;
            --container-bg: rgba(30, 41, 59, 0.7);
            --border-color: rgba(255, 255, 255, 0.1);
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --accent-color: #3b82f6;
            --accent-hover: #2563eb;
            --success-color: #10b981;
        }
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        body {
            background-color: var(--bg-color);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background-image: radial-gradient(circle at top right, rgba(59, 130, 246, 0.1), transparent 400px);
        }
        .container {
            background: var(--container-bg);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 40px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            text-align: center;
        }
        h1 {
            font-size: 1.5rem;
            margin-bottom: 8px;
            background: linear-gradient(to right, #60a5fa, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        p.subtitle {
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-bottom: 32px;
        }
        .btn {
            display: inline-block;
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            background-color: var(--accent-color);
            color: white;
            text-decoration: none;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn:hover {
            background-color: var(--accent-hover);
            transform: translateY(-1px);
        }
        .success-box {
            margin-top: 24px;
            padding: 24px;
            border-radius: 12px;
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid var(--success-color);
        }
        .success-box h2 {
            color: var(--success-color);
            font-size: 1.2rem;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>

    <div class="container">
        <h1>Flattrade OAuth Session Hub</h1>
        <p class="subtitle">Official Browser Redirection Flow</p>

        <?php if ($authStatus === 'success'): ?>
            <div class="success-box">
                <h2>Authentication Successful!</h2>
                <p>The <strong>request_code</strong> was successfully intercepted.<br>The SHA-256 validation completed securely, and the Access Token for Client ID <strong><?= htmlspecialchars($client_id) ?></strong> has been saved directly to your MySQL Database.</p>
            </div>
            <a href="index.php" class="btn" style="margin-top: 24px; background: rgba(255,255,255,0.1);">Return to Home</a>
        <?php endif; ?>
    </div>

</body>
</html>
