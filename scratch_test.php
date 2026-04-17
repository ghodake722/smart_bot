<?php
require_once __DIR__ . '/api/engine.php';

$session = ft_refresh_session_from_db(); // Get a real DB session.
// Mock signal
$signal = [
    'exch' => 'NSE',
    'tsym' => 'SBIN-EQ',
    'qty' => '1',
    'prc' => '0',
    'prd' => 'I',
    'trantype' => 'B',
    'prctyp' => 'MKT',
    'ret' => 'DAY'
];

$signal = array_merge([
    'uid' => $session['client_id'],
    'actid' => $session['client_id']
], $signal);

echo "JSON PAYLOAD: \n" . json_encode($signal) . "\n\n";

$result = ft_dispatch('PlaceOrder', $signal, $session['access_token'], false, false);
echo "BROKER RESPONSE: \n";
print_r($result);
