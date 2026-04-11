<?php
/**
 * FLATTRADE ULTRA-LOW LATENCY TRADING ENGINE
 * Zero-dependency | Singleton pools | Sub-ms auth | fastcgi_finish_request()
 */

declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=utf-8');

// ─── Hardcoded Credentials ──────────────────────────────────────────────────
define('FT_DB_HOST',    'localhost');
define('FT_DB_NAME',    'mytptd_c1_db');
define('FT_DB_USER',    'mytptd_c1_root');
define('FT_DB_PASS',    'ptP_*yOV?7QM');
define('FT_USER_ID',    'FT041391');
define('FT_REDIS_HOST', '127.0.0.1');
define('FT_REDIS_PORT', 6379);
define('FT_REDIS_TTL',  86400);
define('FT_BROKER_BASE', 'https://piconnect.flattrade.in/PiConnectAPI/');
define('FT_CURL_TIMEOUT_MS', 500);

$_ENGINE_T0 = hrtime(true);


// ── SINGLETON: Redis ─────────────────────────────────────────────────────────
final class RedisPool
{
    private static ?Redis $instance = null;

    public static function get(): ?Redis
    {
        if (self::$instance !== null) return self::$instance;
        if (!class_exists('Redis')) return null;
        try {
            $r = new Redis();
            $r->pconnect(FT_REDIS_HOST, FT_REDIS_PORT, 2.0, 'ft_pool');
            $r->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
            self::$instance = $r;
            return $r;
        } catch (RedisException) {
            return null;
        }
    }
}


// ── SINGLETON: MySQL PDO ─────────────────────────────────────────────────────
final class DbPool
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance !== null) return self::$instance;

        self::$instance = new PDO(
            'mysql:host=' . FT_DB_HOST . ';dbname=' . FT_DB_NAME . ';charset=utf8mb4',
            FT_DB_USER, FT_DB_PASS,
            [
                PDO::ATTR_PERSISTENT         => true,
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]
        );
        return self::$instance;
    }
}


// ── SINGLETON: cURL Handle ───────────────────────────────────────────────────
final class CurlPool
{
    private static $handle = null;

    public static function get(): CurlHandle
    {
        if (self::$handle !== null) {
            curl_reset(self::$handle);
            self::applyOpts(self::$handle);
            return self::$handle;
        }
        $ch = curl_init();
        self::applyOpts($ch);
        self::$handle = $ch;
        return $ch;
    }

    private static function applyOpts(CurlHandle $ch): void
    {
        curl_setopt_array($ch, [
            CURLOPT_FORBID_REUSE      => false,
            CURLOPT_FRESH_CONNECT     => false,
            CURLOPT_TCP_KEEPALIVE     => 1,
            CURLOPT_TCP_KEEPIDLE      => 120,
            CURLOPT_TIMEOUT_MS        => FT_CURL_TIMEOUT_MS,
            CURLOPT_CONNECTTIMEOUT_MS => 200,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_ENCODING          => '',
            CURLOPT_HTTP_VERSION      => CURL_HTTP_VERSION_2_0,
            CURLOPT_SSL_VERIFYPEER    => true,
            CURLOPT_SSL_VERIFYHOST    => 2,
            CURLOPT_DNS_CACHE_TIMEOUT => 300,
            CURLOPT_POST              => true,
            CURLOPT_HTTPHEADER        => ['Content-Type: text/plain'],
        ]);
    }
}


// ── Request Method Gate ──────────────────────────────────────────────────────
function ft_enforce_method(string $expected): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $expected) {
        http_response_code(405);
        echo '{"s":"error","m":"Method Not Allowed"}';
        exit;
    }
}


// ── Bearer Token Extraction ──────────────────────────────────────────────────
function ft_extract_bearer(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $v = $_SERVER['HTTP_AUTHORIZATION'];
        return (strncasecmp($v, 'Bearer ', 7) === 0) ? substr($v, 7) : $v;
    }
    if (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
        return $_SERVER['HTTP_X_AUTH_TOKEN'];
    }
    http_response_code(401);
    echo '{"s":"error","m":"Unauthorized: No Bearer token"}';
    exit;
}


// ── Cache-Aside Authentication (Redis L1 → MySQL L2 → Write-Back) ────────────
function ft_authenticate(string $bearer_token): array
{
    $cache_key = 'flattrade_auth:' . hash('sha1', $bearer_token);

    // L1: Redis
    $redis = RedisPool::get();
    if ($redis !== null) {
        try {
            $cached = $redis->get($cache_key);
            if ($cached !== false) {
                $data = json_decode($cached, true);
                if (isset($data['access_token'], $data['client_id'])) return $data;
            }
        } catch (RedisException) {}
    }

    // L2: MySQL
    $pdo  = DbPool::get();
    $stmt = $pdo->prepare(
        'SELECT client_id, access_token, header_auth_token
         FROM flattrade_tokens
         WHERE DATE(updated_at) = CURDATE()
         ORDER BY updated_at DESC
         LIMIT 1'
    );
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(401);
        echo '{"s":"error","m":"No active session. Generate a new token today."}';
        exit;
    }

    if (!hash_equals((string)$row['header_auth_token'], $bearer_token)) {
        http_response_code(401);
        echo '{"s":"error","m":"Unauthorized: Invalid token"}';
        exit;
    }

    $auth_payload = [
        'client_id'    => $row['client_id'],
        'access_token' => $row['access_token'],
    ];

    // Write-back to L1
    if ($redis !== null) {
        try { $redis->setex($cache_key, FT_REDIS_TTL, json_encode($auth_payload)); }
        catch (RedisException) {}
    }

    return $auth_payload;
}


// ── Early Response (fastcgi_finish_request) ──────────────────────────────────
function ft_early_response(string $request_id): void
{
    $response = json_encode([
        's'          => 'ok',
        'm'          => 'Signal accepted',
        'request_id' => $request_id,
        'ts'         => hrtime(true),
    ]);

    http_response_code(200);
    header('Content-Length: ' . strlen($response));
    header('Connection: close');
    echo $response;

    if (ob_get_level() > 0) ob_end_flush();
    flush();

    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
}


// ── Broker Dispatch (Pooled cURL) ────────────────────────────────────────────
function ft_dispatch(string $endpoint, array $payload, string $jKey, bool $echo = false): array
{
    $ch = CurlPool::get();
    curl_setopt($ch, CURLOPT_URL, FT_BROKER_BASE . $endpoint);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'jData=' . json_encode($payload) . '&jKey=' . $jKey);

    $t0       = hrtime(true);
    $response = curl_exec($ch);
    $t1       = hrtime(true);

    $httpCode   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $latency_us = (int)(($t1 - $t0) / 1000);

    if ($response === false) {
        $result = ['s' => 'error', 'm' => 'cURL failure', 'code' => 502];
        if ($echo) { http_response_code(502); echo json_encode($result); }
        return $result;
    }

    $data = json_decode($response, true) ?? [];
    $isOk = ($data['stat'] ?? $data['status'] ?? '') === 'Ok';

    $result = [
        's'          => $isOk ? 'success' : 'error',
        'm'          => $isOk ? 'Broker confirmed' : ($data['emsg'] ?? 'Broker rejected'),
        'data'       => $data,
        'code'       => $httpCode,
        'latency_us' => $latency_us,
    ];

    if ($echo) {
        http_response_code($isOk ? 200 : ($httpCode >= 400 ? $httpCode : 400));
        echo json_encode($result);
    }

    return $result;
}


// ── Async Order Log (runs AFTER early response) ──────────────────────────────
function ft_log_order(string $request_id, string $endpoint, array $payload, array $result): void
{
    try {
        $pdo  = DbPool::get();
        $stmt = $pdo->prepare(
            'INSERT INTO ft_order_log (request_id, endpoint, payload, broker_response, latency_us, status)
             VALUES (:rid, :ep, :pl, :br, :lat, :st)'
        );
        $stmt->execute([
            ':rid' => $request_id,
            ':ep'  => $endpoint,
            ':pl'  => json_encode($payload),
            ':br'  => json_encode($result['data'] ?? []),
            ':lat' => $result['latency_us'] ?? 0,
            ':st'  => $result['s'] ?? 'unknown',
        ]);
    } catch (PDOException) {
        // Silent — post-response background, never block
    }
}
