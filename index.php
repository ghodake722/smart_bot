<?php
declare(strict_types=1);

// Disable Caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

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
            max-width: 720px;
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
        /* ── Stock Search Section ───────────────────────────────── */
        .search-section {
            margin-bottom: 28px;
            text-align: left;
        }
        .search-section h2 {
            font-size: 1rem;
            color: var(--text-secondary);
            margin-bottom: 12px;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            font-weight: 600;
        }
        .search-row {
            display: flex;
            gap: 10px;
        }
        .search-row input[type="text"] {
            flex: 1;
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: rgba(255,255,255,0.06);
            color: var(--text-primary);
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .search-row input[type="text"]:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.25);
        }
        .search-row input[type="text"]::placeholder {
            color: var(--text-secondary);
            opacity: 0.6;
        }
        .search-row select {
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
            background: rgba(255,255,255,0.06);
            color: var(--text-primary);
            font-size: 0.9rem;
            outline: none;
            cursor: pointer;
            min-width: 90px;
        }
        .search-row select option {
            background: #1e293b;
            color: #f8fafc;
        }
        .search-btn {
            padding: 12px 24px;
            border-radius: 10px;
            border: none;
            background: linear-gradient(135deg, var(--accent-color), #6366f1);
            color: #fff;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.2s;
            white-space: nowrap;
        }
        .search-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(59,130,246,0.35);
        }
        .search-btn:active {
            transform: scale(0.97);
        }
        .search-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        /* ── Search Results ─────────────────────────────────────── */
        #search-results {
            margin-top: 16px;
            display: none;
            text-align: left;
        }
        #search-results .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        #search-results .results-header .latency {
            font-family: 'Courier New', monospace;
            color: var(--accent-color);
        }
        #search-results table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }
        #search-results thead th {
            padding: 10px 8px;
            text-align: left;
            color: var(--text-secondary);
            font-weight: 600;
            border-bottom: 1px solid var(--border-color);
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.06em;
        }
        #search-results tbody tr {
            transition: background 0.15s;
        }
        #search-results tbody tr:hover {
            background: rgba(59,130,246,0.08);
        }
        #search-results tbody td {
            padding: 9px 8px;
            border-bottom: 1px solid rgba(255,255,255,0.04);
            color: var(--text-primary);
        }
        /* ── Error Display ──────────────────────────────────────── */
        .search-error {
            margin-top: 14px;
            padding: 16px;
            border-radius: 10px;
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--error-color);
            color: var(--error-color);
            font-size: 0.85rem;
            text-align: left;
            display: none;
            word-break: break-word;
        }
        .search-error pre {
            margin-top: 8px;
            font-size: 0.78rem;
            white-space: pre-wrap;
            color: #fca5a5;
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

        <!-- Scrip Search Section -->
        <div class="search-section">
            <h2>Scrip Search</h2>
            <div class="search-row">
                <input type="text" id="searchInput" placeholder="e.g. NIFTY, BANKNIFTY, RELIANCE …" autocomplete="off" />
                <select id="exchangeSelect">
                    <option value="NFO" selected>NFO</option>
                    <option value="NSE">NSE</option>
                    <option value="BSE">BSE</option>
                    <option value="BFO">BFO</option>
                    <option value="CDS">CDS</option>
                    <option value="MCX">MCX</option>
                </select>
                <button id="searchBtn" class="search-btn">Search</button>
            </div>
            <div id="search-error" class="search-error"></div>
            <div id="search-results"></div>
        </div>
        <hr style="border:none;border-top:1px solid var(--border-color);margin-bottom:24px;">

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

            // ── Stock Search Handler ─────────────────────────────────────────
            const searchInput   = document.getElementById('searchInput');
            const exchangeSel   = document.getElementById('exchangeSelect');
            const searchBtn     = document.getElementById('searchBtn');
            const searchResults = document.getElementById('search-results');
            const searchError   = document.getElementById('search-error');

            async function executeSearch() {
                const stext = searchInput.value.trim();
                if (!stext) { searchInput.focus(); return; }

                // Reset UI
                searchResults.style.display = 'none';
                searchError.style.display   = 'none';
                searchBtn.disabled          = true;
                searchBtn.textContent       = 'Searching…';

                try {
                    const res = await fetch('api/search_scrip.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ stext: stext, exch: exchangeSel.value })
                    });

                    const data = await res.json();

                    if (data.s !== 'success') {
                        // ── Show error clearly ──────────────────────────────
                        searchError.style.display = 'block';
                        searchError.innerHTML = `<strong>API Error (HTTP ${res.status})</strong>: ${data.m || 'Unknown error'}`
                            + (data.debug ? `<pre>${data.debug}</pre>` : '')
                            + `<pre>${JSON.stringify(data, null, 2)}</pre>`;
                        return;
                    }

                    // ── Render results table ────────────────────────────────
                    const items = data.payload?.values || data.payload || [];
                    if (!Array.isArray(items) || items.length === 0) {
                        searchResults.style.display = 'block';
                        searchResults.innerHTML = `<div class="results-header"><span>No results for "<strong>${stext}</strong>"</span></div>`;
                        return;
                    }

                    let html = `<div class="results-header">
                        <span>${items.length} result${items.length > 1 ? 's' : ''} for "<strong>${stext}</strong>" on <strong>${exchangeSel.value}</strong></span>
                        <span class="latency">${data.latency_us ? (data.latency_us / 1000).toFixed(1) + ' ms' : ''}</span>
                    </div>`;
                    html += `<table><thead><tr>
                        <th>Trading Symbol</th><th>Exchange</th><th>Token</th><th>Instrument</th><th>Lot Size</th>
                    </tr></thead><tbody>`;
                    items.forEach(r => {
                        html += `<tr>
                            <td style="font-weight:600">${r.tsym || r.TradingSymbol || '—'}</td>
                            <td>${r.exch || r.Exchange || '—'}</td>
                            <td style="font-family:monospace;color:var(--accent-color)">${r.token || r.Token || '—'}</td>
                            <td>${r.instname || r.Instrument || '—'}</td>
                            <td>${r.ls || r.LotSize || '—'}</td>
                        </tr>`;
                    });
                    html += `</tbody></table>`;

                    searchResults.style.display = 'block';
                    searchResults.innerHTML = html;

                } catch (err) {
                    searchError.style.display = 'block';
                    searchError.innerHTML = `<strong>Network / JS Error</strong><pre>${err.message}\n${err.stack || ''}</pre>`;
                } finally {
                    searchBtn.disabled    = false;
                    searchBtn.textContent = 'Search';
                }
            }

            searchBtn.addEventListener('click', executeSearch);
            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') executeSearch();
            });

            // ── Margin Fetch Handler ─────────────────────────────────────────
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

            // ── Place Order Handler ──────────────────────────────────────────
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
