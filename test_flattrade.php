<?php
$token = "dummy_token";
$payload = ['uid' => 'FT041391', 'actid' => 'FT041391'];

// Try 1: form-urlencoded (like Python)
$ch = curl_init('https://piconnect.flattrade.in/PiConnectAPI/Limits');
$postData = ['jData' => json_encode($payload), 'jKey' => $token];
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res1 = curl_exec($ch);
echo "1. form-urlencoded: " . $res1 . "\n\n";

// Try 2: JSON encoded
$postData2 = ['jData' => $payload, 'jKey' => $token];
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData2));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res2 = curl_exec($ch);
echo "2. JSON: " . $res2 . "\n\n";

// Try 3: JSON encoded with stringified jData
$postData3 = ['jData' => json_encode($payload), 'jKey' => $token];
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData3));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res3 = curl_exec($ch);
echo "3. JSON stringified: " . $res3 . "\n\n";

// Try 4: Raw POST exactly as documented
curl_setopt($ch, CURLOPT_POSTFIELDS, 'jData={"uid":"FT041391","actid":"FT041391"}&jKey=' . $token);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
$res4 = curl_exec($ch);
echo "4. Raw string: " . $res4 . "\n\n";

// Try 5: Same as Guzzle form_params
$postData5 = ['jData' => json_encode($payload), 'jKey' => $token];
curl_setopt($ch, CURLOPT_POSTFIELDS, $postData5); // curl natively formats this as multipart/form-data
curl_setopt($ch, CURLOPT_HTTPHEADER, []);
$res5 = curl_exec($ch);
echo "5. Multipart format: " . $res5 . "\n\n";
