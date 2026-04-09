<?php
date_default_timezone_set("Asia/Kolkata");
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

    // Check for a freshly generated token today
    $hasFreshToken = false;
    $dsn = "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=" . ($_ENV['DB_NAME'] ?? '') . ";charset=utf8mb4";
    $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASS'] ?? '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query("SELECT updated_at FROM flattrade_tokens ORDER BY updated_at DESC LIMIT 1");
    $token = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($token && !empty($token['updated_at'])) {
        $updatedDate = date('Y-m-d', strtotime($token['updated_at']));
        $currentDate = date('Y-m-d');
        if ($updatedDate === $currentDate) {
            $hasFreshToken = true;
        }
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
            --success-color: #10b981;
            --error-color: #ef4444;
            --margin-bg: rgba(59, 130, 246, 0.1);
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
        
        /* Top Navigation Area */
        .top-nav {
            position: absolute;
            top: 20px;
            right: 20px;
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .nav-btn {
            display: inline-block;
            padding: 10px 20px;
            border-radius: 8px;
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid var(--border-color);
            transition: all 0.2s;
            outline: none;
        }
        .nav-btn:hover {
            background-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-1px);
        }
        .nav-btn.primary {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
        }
        .nav-btn.primary:hover {
            background-color: var(--accent-hover);
        }

        .container {
            background: var(--container-bg);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 40px;
            width: 100%;
            max-width: 600px;
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
            margin-bottom: 24px;
        }

        /* Messaging Boxes */
        .msg-box {
            margin-bottom: 24px;
            padding: 20px;
            border-radius: 12px;
            display: none; /* dynamically shown */
        }
        .msg-box.success {
            display: block;
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid var(--success-color);
            color: var(--success-color);
        }
        .msg-box.error {
            display: block;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--error-color);
            color: var(--error-color);
        }

        /* Margin Display Area */
        #margin-display {
            display: none;
            margin-top: 24px;
            background: var(--margin-bg);
            border: 1px solid var(--accent-color);
            border-radius: 12px;
            padding: 24px;
            text-align: left;
        }
        #margin-display pre {
            white-space: pre-wrap;
            word-wrap: break-word;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        .spinner {
            display: none;
            width: 24px;
            height: 24px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: var(--accent-color);
            animation: spin 1s ease-in-out infinite;
            margin: 0 auto 16px auto;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>

    <!-- Top Navigation with conditional Fetch Margin -->
    <div class="top-nav">
        <?php if ($hasFreshToken): ?>
            <button id="fetchMarginBtn" class="nav-btn">Fetch Margin</button>
        <?php endif; ?>
        <a href="https://auth.flattrade.in/?app_key=<?= htmlspecialchars($api_key) ?>" class="nav-btn primary">Login with Flattrade</a>
    </div>

    <!-- Main Dashboard -->
    <div class="container">
        <h1>Flattrade OAuth Dashboard</h1>
        <p class="subtitle">Secure API Integration Engine</p>

        <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
            <div class="msg-box success">
                <strong>Authentication Successful!</strong><br>
                The Access Token for today has been safely stored in your database. You can now fetch your margin limits.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error']) && !empty($_GET['error'])): ?>
            <div class="msg-box error">
                <strong>Authentication Failed!</strong><br>
                <?= htmlspecialchars($_GET['error']) ?>
            </div>
        <?php endif; ?>

        <!-- Loading spinner for API tasks -->
        <div id="loader" class="spinner"></div>

        <!-- Margin Response JSON displays here -->
        <div id="margin-display"></div>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const fetchBtn = document.getElementById('fetchMarginBtn');
            const displayArea = document.getElementById('margin-display');
            const loader = document.getElementById('loader');

            if (fetchBtn) {
                fetchBtn.addEventListener('click', async () => {
                    displayArea.style.display = 'none';
                    loader.style.display = 'block';

                    try {
                        const response = await fetch('fetch_margin.php');
                        const data = await response.json();
                        
                        loader.style.display = 'none';
                        displayArea.style.display = 'block';

                        if (data.status === 'error') {
                            displayArea.innerHTML = `<span style="color: var(--error-color)">Error: ${data.message}</span>`;
                        } else {
                            const limits = data.payload;
                            let html = `<h2 style="font-size:1.1rem; margin-bottom: 12px; color:var(--text-primary);">Margin Limits (IST)</h2>`;
                            html += `<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; font-size: 0.9rem;">`;
                            html += `<div style="background: rgba(255,255,255,0.03); padding: 12px; border-radius: 8px;">`;
                            html += `<span style="color:var(--text-secondary)">Available Cash</span><br><strong style="font-size:1.1rem; color:var(--success-color)">₹${limits.cash || '0.00'}</strong></div>`;
                            html += `<div style="background: rgba(255,255,255,0.03); padding: 12px; border-radius: 8px;">`;
                            html += `<span style="color:var(--text-secondary)">Margin Used</span><br><strong style="font-size:1.1rem; color:var(--error-color)">₹${limits.marginused || '0.00'}</strong></div>`;
                            html += `<div><span style="color:var(--text-secondary)">Payin:</span> <strong style="float:right">₹${limits.payin || '0.00'}</strong></div>`;
                            html += `<div><span style="color:var(--text-secondary)">Payout:</span> <strong style="float:right">₹${limits.payout || '0.00'}</strong></div>`;
                            html += `<div><span style="color:var(--text-secondary)">Gross Exposure:</span> <strong style="float:right">₹${limits.grexpo || '0.00'}</strong></div>`;
                            html += `<div><span style="color:var(--text-secondary)">M2M (urmtom):</span> <strong style="float:right">₹${limits.urmtom || '0.00'}</strong></div>`;
                            html += `</div>`;
                            displayArea.innerHTML = html;
                        }
                    } catch (e) {
                        loader.style.display = 'none';
                        displayArea.style.display = 'block';
                        displayArea.innerHTML = `<span style="color: var(--error-color)">Network Error: Could not connect to fetch_margin.php</span>`;
                    }
                });
            }
        });
    </script>

</body>
</html>
