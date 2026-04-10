<?php
date_default_timezone_set("Asia/Kolkata");
// Intentionally bypassing vendor/autoload.php completely for absolute raw execution speed
header('Content-Type: application/json');



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
    $requestToken = '';

    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $parts = explode(' ', $_SERVER['HTTP_AUTHORIZATION']);
        $requestToken = isset($parts[1]) ? $parts[1] : $parts[0];
    } elseif (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
        $requestToken = $_SERVER['HTTP_X_AUTH_TOKEN'];
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $parts = explode(' ', $headers['Authorization']);
            $requestToken = isset($parts[1]) ? $parts[1] : $parts[0];
        } elseif (isset($headers['X-Auth-Token'])) {
            $requestToken = $headers['X-Auth-Token'];
        }
    }

    if (empty($requestToken)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Missing API Token Header (Authorization or X-Auth-Token).']);
        exit;
    }

    // Hardcoded credentials for maximum microsecond execution speed (bypassing slow .env loaders)
    $db_host = 'localhost';
    $db_name = 'mytptd_c1_db';
    $db_user = 'mytptd_c1_root';
    $db_pass = 'ptP_*yOV?7QM';
    $user_id = 'FT041391';

    $cacheFile = __DIR__ . '/ft_session.cache';

    // Ultra-fast CACHE HIT: Bypass MySQL completely if today's token file exists
    if (file_exists($cacheFile)) {
        $cacheData = json_decode(file_get_contents($cacheFile), true);
        if ($cacheData && isset($cacheData['date']) && $cacheData['date'] === date('Y-m-d')) {
            if ($cacheData['header_auth_token'] === $requestToken) {
                return [
                    'user_id' => $cacheData['user_id'],
                    'access_token' => $cacheData['access_token']
                ];
            } else {
                http_response_code(401);
                echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid API Token.']);
                exit;
            }
        }
    }

    // CACHE MISS: Hit database securely, prepare new cache file
    try {
        $dsn = "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4";
        $pdo = new PDO($dsn, $db_user, $db_pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->query("SELECT access_token, header_auth_token FROM flattrade_tokens ORDER BY updated_at DESC LIMIT 1");
        $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$tokenRow) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Server Error: No Flattrade tokens configured in DB.']);
            exit;
        }

        if ($tokenRow['header_auth_token'] !== $requestToken) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid API Token.']);
            exit;
        }

        // Save physical cache for all subsequent fast-path requests today
        file_put_contents($cacheFile, json_encode([
            'date' => date('Y-m-d'),
            'header_auth_token' => $tokenRow['header_auth_token'],
            'access_token' => $tokenRow['access_token'],
            'user_id' => $user_id
        ]));

        return [
            'user_id' => $user_id,
            'access_token' => $tokenRow['access_token']
        ];
    } catch (Exception $e) {
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
        $raw_payload = 'jData=' . json_encode($payload) . '&jKey=' . $jKey;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://piconnect.flattrade.in/PiConnectAPI/" . $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $raw_payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
        // Disable SSL verify temporarily for maximum speed execution without CA checks overhead
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: text/plain"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("cURL Error: $curlError");
        }

        $data = json_decode($response, true);
        
        // Map Flattrade status responses properly.
        $isOk = (isset($data['status']) && $data['status'] === 'Ok') || (isset($data['stat']) && $data['stat'] === 'Ok');

        if ($httpCode >= 200 && $httpCode < 300) {
            if ($isOk) {
                echo json_encode(['status' => 'success', 'data' => $data]);
            } else {
                http_response_code(400); 
                echo json_encode(['status' => 'error', 'message' => 'Flattrade API Error', 'data' => $data]);
            }
        } else {
            // Handle HTTP 400 Bad Requests or 500s directly from Flattrade
            http_response_code($httpCode >= 400 ? $httpCode : 400);
            $msg = 'Remote Flattrade Server Error';
            if (isset($data['emsg'])) {
                $msg = $data['emsg'];
            }
            echo json_encode([
                'status' => 'error', 
                'message' => $msg, 
                'data' => $data
            ]);
        }

    } catch (Exception $e) {
        http_response_code(502); 
        echo json_encode([
            'status' => 'error', 
            'message' => 'Internal Request Error: ' . $e->getMessage()
        ]);
    }
}
