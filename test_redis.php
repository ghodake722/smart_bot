<?php
require_once __DIR__ . '/api/engine.php';
$redis = RedisPool::get();
if (!$redis) {
    echo "NO REDIS\n";
    exit;
}
$key = ft_session_bundle_cache_key();
$data = $redis->get($key);
echo "Raw Redis Data:\n";
var_dump($data);

$authKey = "flattrade_auth:" . hash('sha1', ft_get_latest_session_row()['header_auth_token'] ?? '');
$authData = $redis->get($authKey);
echo "\nAuth Data:\n";
var_dump($authData);
