<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');
header('Content-Type: application/json; charset=utf-8');

define('FT_DB_HOST', 'localhost');
define('FT_DB_NAME', 'mytptd_c1_db');
define('FT_DB_USER', 'mytptd_c1_root');
define('FT_DB_PASS', 'ptP_*yOV?7QM');
define('FT_USER_ID', 'FT041391');
define('FT_REDIS_HOST', '127.0.0.1');
define('FT_REDIS_PORT', 6379);
define('FT_REDIS_TTL', 86400);
define('FT_BROKER_BASE', 'https://piconnect.flattrade.in/PiConnectAPI/');
define('FT_CURL_TIMEOUT_MS', 500);
define('FT_TOKEN_TTL', 3600);
define('FT_SESSION_MAX_AGE', 3600);
define('FT_AUTH_COOKIE', 'ft_dashboard_auth');

function ft_audit_log(string $event, string $message, string $source = 'redis', ?int $ttl = null): void
{
    try {
        $pdo = DbPool::get();
        $stmt = $pdo->prepare(
            'INSERT INTO ft_token_audit_log (event_type, message, client_id, source, ttl_remaining)
             VALUES (:ev, :msg, :cid, :src, :ttl)'
        );
        $stmt->execute([
            ':ev' => $event,
            ':msg' => $message,
            ':cid' => FT_USER_ID,
            ':src' => $source,
            ':ttl' => $ttl,
        ]);
    } catch (\Throwable) {
        // Audit must never block trading requests.
    }
}

function ft_session_cache_key(): string
{
    return 'ft_session_token:' . FT_USER_ID;
}

function ft_session_bundle_cache_key(): string
{
    return 'ft_session_bundle:' . FT_USER_ID;
}

function ft_auth_cache_key(string $bearerToken): string
{
    return 'flattrade_auth:' . hash('sha1', $bearerToken);
}


function ft_normalize_session_row(array $row): array
{
    return [
        'client_id' => (string)($row['client_id'] ?? ''),
        'access_token' => (string)($row['access_token'] ?? ''),
        'header_auth_token' => (string)($row['header_auth_token'] ?? ''),
        'updated_at' => (string)($row['updated_at'] ?? date('Y-m-d H:i:s')),
        'last_updated' => (int)($row['last_updated'] ?? time()),
    ];
}

function ft_is_session_bundle_fresh(array $bundle): bool
{
    $lastUpdated = (int)($bundle['last_updated'] ?? 0);
    return $lastUpdated > 0 && (time() - $lastUpdated) < FT_SESSION_MAX_AGE;
}

function ft_cache_session_token(string $token, int $ttl = FT_REDIS_TTL): void
{
    $redis = RedisPool::get();
    if ($redis === null || $token === '') {
        return;
    }

    try {
        $redis->setex(ft_session_cache_key(), $ttl, $token);
    } catch (RedisException) {
        // Ignore cache write failures.
    }
}

function ft_delete_session_cache(): void
{
    $redis = RedisPool::get();
    if ($redis === null) {
        return;
    }

    try {
        $redis->del(ft_session_cache_key());
        $redis->del(ft_session_bundle_cache_key());
    } catch (RedisException) {
        // Ignore cache delete failures.
    }
}

function ft_cache_auth_identity(string $bearerToken, string $clientId): void
{
    $redis = RedisPool::get();
    if ($redis === null || $bearerToken === '' || $clientId === '') {
        return;
    }

    try {
        $redis->setex(ft_auth_cache_key($bearerToken), FT_REDIS_TTL, json_encode(['client_id' => $clientId], JSON_UNESCAPED_SLASHES));
    } catch (RedisException) {
        // Ignore cache write failures.
    }
}

function ft_cache_session_bundle(array $row, int $ttl = FT_REDIS_TTL): array
{
    $bundle = ft_normalize_session_row($row);
    $bundle['last_updated'] = time(); // Always stamp with current time on cache write
    $redis = RedisPool::get();
    if ($redis !== null) {
        try {
            $redis->setex(ft_session_bundle_cache_key(), $ttl, json_encode($bundle, JSON_UNESCAPED_SLASHES));
        } catch (RedisException) {
            // Ignore cache write failures.
        }
    }

    ft_cache_session_token($bundle['access_token'], $ttl);
    ft_cache_auth_identity($bundle['header_auth_token'], $bundle['client_id']);

    return $bundle;
}

function ft_get_latest_session_row(): array
{
    $pdo = DbPool::get();
    $stmt = $pdo->prepare(
        'SELECT client_id, access_token, header_auth_token, updated_at
         FROM flattrade_tokens
         WHERE DATE(updated_at) = CURDATE()
         ORDER BY updated_at DESC
         LIMIT 1'
    );
    $stmt->execute();
    $row = $stmt->fetch();

    if (!$row || empty($row['access_token']) || empty($row['header_auth_token'])) {
        ft_audit_log('TOKEN_ERROR', 'No active session credentials found in MySQL for today', 'mysql');
        throw new Exception('No active session token. Please login with Flattrade.');
    }

    return $row;
}

function ft_refresh_session_from_db(): array
{
    $bundle = ft_cache_session_bundle(ft_get_latest_session_row(), FT_REDIS_TTL);
    ft_audit_log('TOKEN_REFRESH', 'Credentials fetched from MySQL and cached in Redis (TTL: ' . FT_REDIS_TTL . 's, last_updated: ' . $bundle['last_updated'] . ')', 'mysql', FT_REDIS_TTL);
    return $bundle;
}

function ft_get_cached_session_bundle(bool $requireFresh = false): ?array
{
    $redis = RedisPool::get();
    if ($redis === null) {
        return null;
    }

    try {
        $cached = $redis->get(ft_session_bundle_cache_key());
        if ($cached === false) {
            return null;
        }

        $bundle = json_decode($cached, true);
        if (!is_array($bundle)) {
            return null;
        }

        $bundle = ft_normalize_session_row($bundle);
        if ($requireFresh && !ft_is_session_bundle_fresh($bundle)) {
            return null;
        }

        return $bundle;
    } catch (RedisException) {
        return null;
    }
}

function ft_resolve_session_bundle(bool $forceRefresh = false): array
{
    if (!$forceRefresh) {
        $bundle = ft_get_cached_session_bundle(true);
        if ($bundle !== null) {
            $redis = RedisPool::get();
            $ttl = null;
            if ($redis !== null) {
                try {
                    $ttlValue = $redis->ttl(ft_session_bundle_cache_key());
                    $ttl = $ttlValue > 0 ? $ttlValue : null;
                } catch (RedisException) {
                    $ttl = null;
                }
            }
            ft_audit_log('TOKEN_HIT', 'Redis cache hit - serving cached credentials', 'redis', $ttl);
            return $bundle;
        }

        ft_audit_log('TOKEN_MISS', 'Redis credentials missing or stale - falling back to MySQL', 'redis');
    }

    return ft_refresh_session_from_db();
}

function ft_fast_session_bundle(): array
{
    $bundle = ft_get_cached_session_bundle(false);
    if ($bundle === null) {
        http_response_code(401);
        echo '{"s":"error","m":"Unauthorized: Search once or login again to warm Redis credentials"}';
        exit;
    }

    return $bundle;
}

function ft_resolve_session_token(bool $forceRefresh = false): string
{
    return (string)ft_resolve_session_bundle($forceRefresh)['access_token'];
}


final class RedisPool
{
    private static ?Redis $instance = null;

    public static function get(): ?Redis
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        if (!class_exists('Redis')) {
            return null;
        }

        try {
            $redis = new Redis();
            $redis->pconnect(FT_REDIS_HOST, FT_REDIS_PORT, 2.0, 'ft_pool');
            $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
            self::$instance = $redis;
            return $redis;
        } catch (RedisException) {
            return null;
        }
    }
}

final class DbPool
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::$instance = new PDO(
            'mysql:host=' . FT_DB_HOST . ';dbname=' . FT_DB_NAME . ';charset=utf8mb4',
            FT_DB_USER,
            FT_DB_PASS,
            [
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]
        );

        return self::$instance;
    }
}

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
            CURLOPT_FORBID_REUSE => false,
            CURLOPT_FRESH_CONNECT => false,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 120,
            CURLOPT_TIMEOUT_MS => FT_CURL_TIMEOUT_MS,
            CURLOPT_CONNECTTIMEOUT_MS => 200,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_DNS_CACHE_TIMEOUT => 300,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: text/plain'],
        ]);
    }
}

function ft_enforce_method(string $expected): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $expected) {
        http_response_code(405);
        echo '{"s":"error","m":"Method Not Allowed"}';
        exit;
    }
}

function ft_extract_bearer(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $value = (string)$_SERVER['HTTP_AUTHORIZATION'];
        return (strncasecmp($value, 'Bearer ', 7) === 0) ? substr($value, 7) : $value;
    }

    if (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) {
        return (string)$_SERVER['HTTP_X_AUTH_TOKEN'];
    }

    if (!empty($_COOKIE[FT_AUTH_COOKIE])) {
        return (string)$_COOKIE[FT_AUTH_COOKIE];
    }

    http_response_code(401);
    echo '{"s":"error","m":"Unauthorized: No dashboard auth token"}';
    exit;
}

function ft_validate_session_context(array $session): array
{
    $clientId = (string)($session['client_id'] ?? '');
    $accessToken = (string)($session['access_token'] ?? '');

    if ($clientId === '' || $accessToken === '') {
        http_response_code(401);
        echo '{"s":"error","m":"Unauthorized: Missing session credentials"}';
        exit;
    }

    if (!hash_equals(FT_USER_ID, $clientId)) {
        http_response_code(401);
        echo '{"s":"error","m":"Unauthorized: User mismatch"}';
        exit;
    }

    return $session;
}

function ft_authenticate_search(string $bearerToken): array
{
    $session = ft_resolve_session_bundle(false);
    if (empty($session['header_auth_token']) || !hash_equals((string)$session['header_auth_token'], $bearerToken)) {
        http_response_code(401);
        echo '{"s":"error","m":"Unauthorized: Invalid token"}';
        exit;
    }

    ft_cache_auth_identity($bearerToken, (string)$session['client_id']);
    return ft_validate_session_context($session);
}

function ft_authenticate_fast(string $bearerToken): array
{
    $session = ft_fast_session_bundle();
    if (empty($session['header_auth_token']) || !hash_equals((string)$session['header_auth_token'], $bearerToken)) {
        http_response_code(401);
        echo '{"s":"error","m":"Unauthorized: Invalid token"}';
        exit;
    }

    ft_cache_auth_identity($bearerToken, (string)$session['client_id']);
    return ft_validate_session_context($session);
}

function ft_early_response(string $request_id): void
{
    $response = json_encode([
        's' => 'ok',
        'm' => 'Signal accepted',
        'request_id' => $request_id,
        'ts' => hrtime(true),
    ]);

    http_response_code(200);
    header('Content-Length: ' . strlen($response));
    header('Connection: close');
    echo $response;

    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

function ft_is_session_expired_payload(array $data): bool
{
    $message = strtolower((string)($data['emsg'] ?? $data['message'] ?? ''));
    return $message !== '' && (
        str_contains($message, 'invalid session key') ||
        str_contains($message, 'session expired') ||
        str_contains($message, 'session expired :') ||
        str_contains($message, 'invalid session')
    );
}

function ft_dispatch(string $endpoint, array $payload, string $jKey, bool $echo = false, bool $allowRetry = true): array
{
    $ch = CurlPool::get();
    curl_setopt($ch, CURLOPT_URL, FT_BROKER_BASE . $endpoint);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'jData=' . json_encode($payload) . '&jKey=' . $jKey);

    $t0 = hrtime(true);
    $response = curl_exec($ch);
    $t1 = hrtime(true);

    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $latencyUs = (int)(($t1 - $t0) / 1000);

    if ($response === false) {
        $result = ['s' => 'error', 'm' => 'cURL failure', 'code' => 502];
        if ($echo) {
            http_response_code(502);
            echo json_encode($result);
        }
        return $result;
    }

    $data = json_decode($response, true) ?? [];
    if ($allowRetry && ft_is_session_expired_payload($data)) {
        ft_audit_log('TOKEN_ERROR', 'Broker rejected cached session key - refreshing from MySQL', 'redis');
        ft_delete_session_cache();
        try {
            $freshToken = ft_resolve_session_token(true);
            if ($freshToken !== '') {
                return ft_dispatch($endpoint, $payload, $freshToken, $echo, false);
            }
        } catch (\Throwable) {
            // Return original broker error below.
        }
    }

    $isOk = ($data['stat'] ?? $data['status'] ?? '') === 'Ok';
    $result = [
        's' => $isOk ? 'success' : 'error',
        'm' => $isOk ? 'Broker confirmed' : ($data['emsg'] ?? 'Broker rejected'),
        'data' => $data,
        'code' => $httpCode,
        'latency_us' => $latencyUs,
    ];

    if ($echo) {
        http_response_code($isOk ? 200 : ($httpCode >= 400 ? $httpCode : 400));
        echo json_encode($result);
    }

    return $result;
}

function ft_log_order(string $request_id, string $endpoint, array $payload, array $result): void
{
    try {
        $pdo = DbPool::get();
        $stmt = $pdo->prepare(
            'INSERT INTO ft_order_log (request_id, endpoint, payload, broker_response, latency_us, status)
             VALUES (:rid, :ep, :pl, :br, :lat, :st)'
        );
        $stmt->execute([
            ':rid' => $request_id,
            ':ep' => $endpoint,
            ':pl' => json_encode($payload),
            ':br' => json_encode($result['data'] ?? []),
            ':lat' => $result['latency_us'] ?? 0,
            ':st' => $result['s'] ?? 'unknown',
        ]);
    } catch (PDOException) {
        // Logging must never block requests.
    }
}

function ft_search_scrip(string $stext, string $exch, array $session): array
{
    return ft_dispatch('SearchScrip', [
        'uid' => (string)$session['client_id'],
        'stext' => $stext,
        'exch' => $exch,
    ], (string)$session['access_token'], false, true);
}

function ft_fetch_margin(array $session): array
{
    return ft_dispatch('Limits', [
        'uid' => (string)$session['client_id'],
        'actid' => (string)$session['client_id'],
    ], (string)$session['access_token'], false, false);
}
