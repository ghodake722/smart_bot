<?php
// Load composer dependencies
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

try {
    // 1. Bootstrap .env
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();

    $api_key = $_ENV['FLATTRADE_API_KEY'] ?? $_ENV['API_KEY'] ?? '';

    if (empty($api_key)) {
        throw new Exception("Missing FLATTRADE API credentials in the .env file.");
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
    <title>Flattrade Session Engine</title>
    <style>
        :root {
            --bg-color: #0f172a;
            --container-bg: rgba(30, 41, 59, 0.7);
            --border-color: rgba(255, 255, 255, 0.1);
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --accent-color: #3b82f6;
            --accent-hover: #2563eb;
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
    </style>
</head>
<body>

    <div class="container">
        <h1>Flattrade OAuth Session Hub</h1>
        <p class="subtitle">Official Browser Redirection Flow</p>

        <!-- Proceed to Phase 1: Redirecting to Official Flattrade Auth Portal -->
        <a href="https://auth.flattrade.in/?app_key=<?= htmlspecialchars($api_key) ?>" class="btn">Login with Flattrade</a>
        <p style="font-size: 12px; margin-top: 16px; color: var(--text-secondary);">You will be securely redirected to Flattrade Pi context.</p>
    </div>

</body>
</html>
