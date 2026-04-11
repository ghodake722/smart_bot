<?php
/**
 * ============================================================================
 *  FLATTRADE ULTRA-LOW LATENCY TRADING ENGINE
 * ============================================================================
 *  Architecture: Zero-dependency, Singleton-pattern, connection-pooled
 *  Target:       Sub-millisecond auth, <500ms broker round-trip
 *  Runtime:      PHP 8.x + PHP-FPM + OPcache + JIT
 *
 *  Connection Strategy:
 *    - Redis:  pconnect() persistent socket (survives request lifecycle)
 *    - MySQL:  PDO::ATTR_PERSISTENT (pooled across FPM workers)
 *    - cURL:   Handle reuse with TCP_KEEPALIVE (no TLS renegotiation)
 *
 *  Auth Flow:  Bearer Token → Redis L1 → MySQL L2 → Cache-Back → Execute
 * ============================================================================
 */

declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=utf-8');

// ─── Hardcoded Credentials (bypasses Dotenv/file I/O entirely) ──────────────
define('FT_DB_HOST',    'localhost');
define('FT_DB_NAME',    'mytptd_c1_db');
define('FT_DB_USER',    'mytptd_c1_root');
define('FT_DB_PASS',    'ptP_*yOV?7QM');
define('FT_USER_ID',    'FT041391');
define('FT_REDIS_HOST', '127.0.0.1');
define('FT_REDIS_PORT', 6379);
define('FT_REDIS_TTL',  86400); // 24h TTL — tokens expire daily anyway
define('FT_BROKER_BASE', 'https://piconnect.flattrade.in/PiConnectAPI/');
define('FT_CURL_TIMEOUT_MS', 500); // Aggressive 500ms broker timeout

// ─── Microsecond timer for internal diagnostics ─────────────────────────────
$_ENGINE_T0 = hrtime(true); // nanosecond-precision monotonic clock


// ============================================================================
//  SINGLETON: Redis (Persistent Connection)
// ============================================================================
final class RedisPool
{
    private static ?Redis $instance = null;

    public static function get(): ?Redis
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        if (!class_exists('Redis')) {
            return null; // Graceful degradation if ext-redis not installed
        }
        try {
            $r = new Redis();
            // pconnect() reuses the socket across PHP-FPM requests on the same worker
            $r->pconnect(FT_REDIS_HOST, FT_REDIS_PORT, 2.0, 'ft_pool');
            $r->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
            $r->setOption(Redis::OPT_READ_TIMEOUT, 1);
            self::$instance = $r;
            return $r;
        } catch (RedisException $e) {
            // Redis down → fall through to MySQL. Do NOT block.
            error_log('[FT_ENGINE] Redis connect failed: ' . $e->getMessage());
            return null;
        }
    }
}


// ============================================================================
//  SINGLETON: MySQL PDO (Persistent Connection)
// ============================================================================
final class DbPool
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        $dsn = 'mysql:host=' . FT_DB_HOST
             . ';dbname=' . FT_DB_NAME
             . ';charset=utf8mb4';

        self::$instance = new PDO($dsn, FT_DB_USER, FT_DB_PASS, [
            PDO::ATTR_PERSISTENT         => true,   // Survives FPM worker lifecycle
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,   // Native prepared statements
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        return self::$instance;
    }
}


// ============================================================================
//  SINGLETON: cURL Handle (Connection Pooling + TCP Keep-Alive)
// ============================================================================
final class CurlPool
{
    private static $handle = null;

    /**
     * Returns a reusable cURL handle pre-configured for the Flattrade API.
     * TCP keep-alive prevents TLS renegotiation on subsequent requests.
     */
    public static function get(): CurlHandle
    {
        if (self::$handle !== null) {
            // Reset per-request fields only, keep the socket alive
            curl_reset(self::$handle);
            self::applyBaseOpts(self::$handle);
            return self::$handle;
        }

        $ch = curl_init();
        self::applyBaseOpts($ch);
        self::$handle = $ch;
        return $ch;
    }

    private static function applyBaseOpts(CurlHandle $ch): void
    {
        curl_setopt_array($ch, [
            // ── Connection Reuse ─────────────────────────────────
            CURLOPT_FORBID_REUSE      => false,   // Keep connection in pool
            CURLOPT_FRESH_CONNECT     => false,   // Reuse existing connection
            CURLOPT_TCP_KEEPALIVE     => 1,       // Enable TCP keep-alive probes
            CURLOPT_TCP_KEEPIDLE      => 120,     // Start probes after 120s idle

            // ── Aggressive Timeouts ──────────────────────────────
            CURLOPT_TIMEOUT_MS        => FT_CURL_TIMEOUT_MS,
            CURLOPT_CONNECTTIMEOUT_MS => 200,     // 200ms connect ceiling

            // ── Response Handling ────────────────────────────────
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_ENCODING          => '',       // Accept gzip/deflate/br
            CURLOPT_HTTP_VERSION      => CURL_HTTP_VERSION_2_0, // HTTP/2 multiplexing

            // ── SSL (keep enabled for production security) ───────
            CURLOPT_SSL_VERIFYPEER    => true,
            CURLOPT_SSL_VERIFYHOST    => 2,

            // ── DNS Cache ────────────────────────────────────────
            CURLOPT_DNS_CACHE_TIMEOUT => 300,     // Cache DNS for 5 minutes

            // ── POST defaults ────────────────────────────────────
            CURLOPT_POST              => true,
            CURLOPT_HTTPHEADER        => ['Content-Type: text/plain'],
        ]);
    }

    /**
     * Cleanup on script shutdown (optional — FPM handles this anyway)
     */
    public static function close(): void
    {
        if (self::$handle !== null) {
            curl_close(self::$handle);
            self::$handle = null;
        }
    }
}


// ============================================================================
//  FAIL-FAST: Request Method Enforcement
// ============================================================================
function ft_enforce_method(string $expected): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $expected) {
        http_response_code(405);
        echo '{"s":"error","m":"Method Not Allowed"}';
        exit;
    }
}


// ============================================================================
//  FAIL-FAST: Bearer Token Extraction
//  Hierarchy: Authorization: Bearer <T> → X-Auth-Token → Reject
// ============================================================================
function ft_extract_bearer(): string
{
    // Fastest path: Apache/Nginx typically set HTTP_AUTHORIZATION
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $v = $_SERVER['HTTP_AUTHORIZATION'];
        // Strip "Bearer " prefix if present (7 chars)
        return (strncasecmp($v, 'Bearer ', 7) === 0)
            ? substr($v, 7)
            : $v;
    }

    // Fallback: custom header
    if (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
        return $_SERVER['HTTP_X_AUTH_TOKEN'];
    }

    // Nuclear reject — no token provided
    http_response_code(401);
    echo '{"s":"error","m":"Unauthorized: No Bearer token"}';
    exit;
}


// ============================================================================
//  MICROSECOND CACHE-ASIDE AUTHENTICATION
//
//  L1: Redis   →  flattrade_auth:<token_hash>  (40-byte SHA1 key)
//  L2: MySQL   →  flattrade_tokens (LIMIT 1, indexed)
//  Write-Back: On L2 hit, populate L1 for subsequent sub-ms lookups
//
//  Returns: ['client_id' => string, 'access_token' => string]
// ============================================================================
function ft_authenticate(string $bearer_token): array
{
    // Use a hashed key to avoid storing raw tokens in Redis keyspace
    $cache_key = 'flattrade_auth:' . hash('sha1', $bearer_token);

    // ── L1: Redis Lookup (target: <0.1ms) ────────────────────────────────
    $redis = RedisPool::get();
    if ($redis !== null) {
        try {
            $cached = $redis->get($cache_key);
            if ($cached !== false) {
                $data = json_decode($cached, true);
                if ($data && isset($data['access_token'], $data['client_id'])) {
                    return $data;
                }
            }
        } catch (RedisException $e) {
            error_log('[FT_ENGINE] Redis read error: ' . $e->getMessage());
        }
    }

    // ── L2: MySQL Lookup (target: <2ms on localhost) ─────────────────────
    try {
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

        // Validate the presented Bearer token against db
        if (!hash_equals((string)$row['header_auth_token'], $bearer_token)) {
            http_response_code(401);
            echo '{"s":"error","m":"Unauthorized: Invalid token"}';
            exit;
        }

        $auth_payload = [
            'client_id'    => $row['client_id'],
            'access_token' => $row['access_token'],
        ];

        // ── Write-Back to L1 Cache ───────────────────────────────────
        if ($redis !== null) {
            try {
                $redis->setex($cache_key, FT_REDIS_TTL, json_encode($auth_payload));
            } catch (RedisException $e) {
                error_log('[FT_ENGINE] Redis write-back failed: ' . $e->getMessage());
            }
        }

        return $auth_payload;

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['s' => 'error', 'm' => 'DB Error: ' . $e->getMessage()]);
        exit;
    }
}


// ============================================================================
//  EARLY RESPONSE: Asynchronous Illusion via fastcgi_finish_request()
//
//  Immediately sends the HTTP response to the caller, then continues
//  executing the broker dispatch in the background.
//  This is the single most impactful latency optimization for the signal sender.
// ============================================================================
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

    // Flush output buffers
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();

    // PHP-FPM: Close the FastCGI connection immediately
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    // Script continues executing below the call site...
}


// ============================================================================
//  BROKER DISPATCH: Pooled cURL → Flattrade PiConnectAPI
//
//  Features:
//    - cURL handle reuse (no TLS handshake on warm connections)
//    - HTTP/2 multiplexing support
//    - Aggressive 500ms timeout
//    - Structured response with diagnostics
//
//  @param string $endpoint  e.g. 'PlaceOrder', 'ModifyOrder', 'CancelOrder'
//  @param array  $payload   The jData fields
//  @param string $jKey      The access_token (session key)
//  @param bool   $echo      Whether to echo the response (false in async mode)
//  @return array            Decoded broker response
// ============================================================================
function ft_dispatch(string $endpoint, array $payload, string $jKey, bool $echo = false): array
{
    $url  = FT_BROKER_BASE . $endpoint;
    $body = 'jData=' . json_encode($payload) . '&jKey=' . $jKey;

    $ch = CurlPool::get();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    $t0       = hrtime(true);
    $response = curl_exec($ch);
    $t1       = hrtime(true);

    $info     = curl_getinfo($ch);
    $httpCode = (int)$info['http_code'];
    $curlErr  = curl_error($ch);

    // ── Diagnostics (logged, not exposed to client) ──────────────────────
    $latency_us = (int)(($t1 - $t0) / 1000); // nanoseconds → microseconds
    error_log(sprintf(
        '[FT_DISPATCH] %s | HTTP %d | %d µs | DNS: %.1fms | Connect: %.1fms | TLS: %.1fms | Transfer: %.1fms',
        $endpoint,
        $httpCode,
        $latency_us,
        $info['namelookup_time'] * 1000,
        $info['connect_time'] * 1000,
        $info['appconnect_time'] * 1000,
        $info['total_time'] * 1000
    ));

    if ($response === false) {
        $result = [
            's'    => 'error',
            'm'    => 'cURL failure: ' . $curlErr,
            'code' => 502,
        ];
        if ($echo) {
            http_response_code(502);
            echo json_encode($result);
        }
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


// ============================================================================
//  LOGGING: Async order result persistence (runs AFTER early response)
// ============================================================================
function ft_log_order(string $request_id, string $endpoint, array $payload, array $result): void
{
    try {
        $pdo = DbPool::get();
        // Best-effort: create log table if not exists (runs once, then cached by MySQL)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `ft_order_log` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `request_id` VARCHAR(36) NOT NULL,
                `endpoint` VARCHAR(32) NOT NULL,
                `payload` JSON NOT NULL,
                `broker_response` JSON NULL,
                `latency_us` INT UNSIGNED NULL,
                `status` VARCHAR(16) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_req_id (`request_id`),
                INDEX idx_created (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

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
    } catch (PDOException $e) {
        error_log('[FT_ENGINE] Order log write failed: ' . $e->getMessage());
    }
}
