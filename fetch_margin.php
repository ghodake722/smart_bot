<?php
declare(strict_types=1);

require_once __DIR__ . '/api/engine.php';

ft_enforce_method('POST');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }

    $session = ft_authenticate(
        ft_extract_bearer(),
        ft_extract_requested_user_id($input),
        ft_extract_requested_session_token($input)
    );

    $result = ft_fetch_margin($session);

    if ($result['s'] === 'success') {
        echo json_encode(['status' => 'success', 'payload' => $result['data']]);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $result['m'] ?? 'Unable to fetch margin']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
