<?php
declare(strict_types=1);

require_once __DIR__ . '/engine.php';

ft_enforce_method('POST');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || empty($input['stext'])) {
        http_response_code(400);
        echo json_encode(['s' => 'error', 'm' => 'Missing required field: stext']);
        exit;
    }

    $stext = trim((string)$input['stext']);
    $exch = strtoupper(trim((string)($input['exch'] ?? 'NFO')));
    $allowedExchanges = ['NFO', 'NSE', 'BSE', 'BFO', 'CDS', 'MCX'];
    if ($exch === '' || !in_array($exch, $allowedExchanges, true)) {
        $exch = 'NFO';
    }

    $session = ft_authenticate_search(ft_extract_bearer());
    $result = ft_search_scrip($stext, $exch, $session);

    if ($result['s'] !== 'success') {
        http_response_code(400);
        echo json_encode([
            's' => 'error',
            'm' => $result['m'] ?? 'Search failed',
        ]);
        exit;
    }

    echo json_encode([
        's' => 'success',
        'm' => 'Search completed',
        'payload' => $result['data'] ?? [],
        'latency_us' => $result['latency_us'] ?? null,
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        's' => 'error',
        'm' => $e->getMessage(),
    ]);
}
