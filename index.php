<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');

// Hardcoded — no Dotenv, no autoloader overhead
$api_key = '2e42645836894d0f8bb71f02f2903b39';
$db_host = 'localhost';
$db_name = 'mytptd_c1_db';
$db_user = 'mytptd_c1_root';
$db_pass = 'ptP_*yOV?7QM';

$hasFreshToken = false;

try {
    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user, $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $stmt = $pdo->query(
        "SELECT updated_at FROM flattrade_tokens
         WHERE DATE(updated_at) = CURDATE()
         ORDER BY updated_at DESC LIMIT 1"
    );
    $hasFreshToken = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Silent — dashboard still renders, just without trading buttons
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
        .msg-box {
            margin-bottom: 24px;
            padding: 20px;
            border-radius: 12px;
            display: none;
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
        #margin-display {
            display: none;
            margin-top: 24px;
            background: var(--margin-bg);
            border: 1px solid var(--accent-color);
            border-radius: 12px;
            padding: 24px;
            text-align: left;
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
    <div class="top-nav">
        <?php if ($hasFreshToken): ?>
            <button id="fetchMarginBtn" class="nav-btn">Fetch Margin</button>
            <button id="placeOrderBtn" class="nav-btn">Place Order</button>
        <?php endif; ?>
        <a href="https://auth.flattrade.in/?app_key=<?= htmlspecialchars($api_key) ?>" class="nav-btn primary">Login with Flattrade</a>
    </div>

    <div class="container">
        <h1>Flattrade OAuth Dashboard</h1>
        <p class="subtitle">Secure API Integration Engine</p>

        <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
            <div class="msg-box success">
                <strong>Authentication Successful!</strong><br>
                The Access Token for today has been safely stored. You can now fetch your margin limits.
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error']) && !empty($_GET['error'])): ?>
            <div class="msg-box error">
                <strong>Authentication Failed!</strong><br>
                <?= htmlspecialchars($_GET['error']) ?>
            </div>
        <?php endif; ?>

        <div id="loader" class="spinner"></div>
        <div id="margin-display"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const displayArea = document.getElementById('margin-display');
            const loader = document.getElementById('loader');

            const fetchBtn = document.getElementById('fetchMarginBtn');
            if (fetchBtn) {
                fetchBtn.addEventListener('click', async () => {
                    displayArea.style.display = 'none';
                    loader.style.display = 'block';
                    try {
                        const res = await fetch('fetch_margin.php');
                        const data = await res.json();
                        loader.style.display = 'none';
                        displayArea.style.display = 'block';
                        if (data.status === 'error') {
                            displayArea.innerHTML = `<span style="color:var(--error-color)">Error: ${data.message}</span>`;
                        } else {
                            const l = data.payload;
                            displayArea.innerHTML = `
                                <h2 style="font-size:1.1rem;margin-bottom:12px;color:var(--text-primary)">Margin Limits (IST)</h2>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;font-size:0.9rem">
                                    <div style="background:rgba(255,255,255,0.03);padding:12px;border-radius:8px">
                                        <span style="color:var(--text-secondary)">Available Cash</span><br>
                                        <strong style="font-size:1.1rem;color:var(--success-color)">₹${l.cash||'0.00'}</strong>
                                    </div>
                                    <div style="background:rgba(255,255,255,0.03);padding:12px;border-radius:8px">
                                        <span style="color:var(--text-secondary)">Margin Used</span><br>
                                        <strong style="font-size:1.1rem;color:var(--error-color)">₹${l.marginused||'0.00'}</strong>
                                    </div>
                                    <div><span style="color:var(--text-secondary)">Payin:</span> <strong style="float:right">₹${l.payin||'0.00'}</strong></div>
                                    <div><span style="color:var(--text-secondary)">Payout:</span> <strong style="float:right">₹${l.payout||'0.00'}</strong></div>
                                    <div><span style="color:var(--text-secondary)">Gross Exposure:</span> <strong style="float:right">₹${l.grexpo||'0.00'}</strong></div>
                                    <div><span style="color:var(--text-secondary)">M2M:</span> <strong style="float:right">₹${l.urmtom||'0.00'}</strong></div>
                                </div>`;
                        }
                    } catch {
                        loader.style.display = 'none';
                        displayArea.style.display = 'block';
                        displayArea.innerHTML = `<span style="color:var(--error-color)">Network Error: Could not reach server</span>`;
                    }
                });
            }

            const orderBtn = document.getElementById('placeOrderBtn');
            if (orderBtn) {
                orderBtn.addEventListener('click', async () => {
                    displayArea.style.display = 'none';
                    loader.style.display = 'block';
                    const t0 = performance.now();
                    try {
                        const res = await fetch('api/signal_router.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer __REPLACE_WITH_LIVE_TOKEN__' },
                            body: JSON.stringify({
                                action: 'place', exch: 'NSE', tsym: 'ACC-EQ', qty: '1',
                                prc: '1400', prd: 'I', trantype: 'B', prctyp: 'LMT', ret: 'DAY'
                            })
                        });
                        const data = await res.json();
                        const dt = performance.now() - t0;
                        loader.style.display = 'none';
                        displayArea.style.display = 'block';
                        displayArea.innerHTML = `
                            <h2 style="font-size:1.1rem;margin-bottom:12px;color:var(--success-color)">Signal Dispatched</h2>
                            <div style="background:rgba(255,255,255,0.03);padding:16px;border-radius:8px;margin-bottom:16px;border-left:4px solid var(--accent-color)">
                                <div><strong style="color:var(--accent-color);font-size:1.2rem">Round-trip: ${dt.toFixed(1)} ms</strong></div>
                                <div>Request ID: <code>${data.request_id||'N/A'}</code></div>
                            </div>
                            <pre style="font-size:0.8rem;overflow-x:auto;color:var(--text-secondary)">${JSON.stringify(data,null,2)}</pre>`;
                    } catch {
                        loader.style.display = 'none';
                        displayArea.style.display = 'block';
                        displayArea.innerHTML = `<span style="color:var(--error-color)">Request Failed</span>`;
                    }
                });
            }
        });
    </script>
</body>
</html>
