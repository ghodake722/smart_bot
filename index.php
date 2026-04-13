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
        "SELECT id FROM flattrade_tokens 
         WHERE DATE(updated_at) = CURDATE() 
         ORDER BY updated_at DESC LIMIT 1"
    );
    $hasFreshToken = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Silent fail for UI simplicity
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flattrade Session Engine</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0f172a;
            --container-bg: rgba(30, 41, 59, 0.7);
            --accent-color: #38bdf8;
            --accent-hover: #0ea5e9;
            --text-primary: #f8fafc;
            --text-secondary: #94a3b8;
            --success-color: #22c55e;
            --error-color: #ef4444;
            --border-color: rgba(255, 255, 255, 0.1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(circle at 0% 0%, rgba(56, 189, 248, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 100% 100%, rgba(56, 189, 248, 0.05) 0%, transparent 50%);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 800px;
            background: var(--container-bg);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        h1 { font-size: 2rem; font-weight: 700; margin-bottom: 8px; letter-spacing: -0.025em; }
        .subtitle { color: var(--text-secondary); margin-bottom: 32px; font-size: 1rem; }

        .top-nav {
            position: absolute;
            top: 40px;
            right: 40px;
            display: flex;
            gap: 12px;
        }

        .nav-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 10px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s;
            cursor: pointer;
        }

        .nav-btn:hover { background: rgba(255, 255, 255, 0.1); border-color: var(--accent-color); }
        .nav-btn.primary { background: var(--accent-color); color: #000; border: none; }
        .nav-btn.primary:hover { background: var(--accent-hover); transform: translateY(-1px); }

        /* Search Styles */
        .search-section { margin-bottom: 32px; background: rgba(0,0,0,0.2); padding: 24px; border-radius: 16px; border: 1px solid var(--border-color); }
        .search-section h2 { font-size: 1.1rem; margin-bottom: 16px; color: var(--accent-color); display: flex; align-items: center; gap: 8px; }
        .search-row { display: flex; gap: 12px; margin-bottom: 12px; }
        .search-row input { flex: 1; background: #000; border: 1px solid var(--border-color); border-radius: 10px; padding: 12px 16px; color: #fff; font-size: 1rem; outline: none; }
        .search-row input:focus { border-color: var(--accent-color); }
        .search-row select { background: #000; border: 1px solid var(--border-color); border-radius: 10px; padding: 0 12px; color: #fff; cursor: pointer; }
        .search-btn { background: var(--accent-color); color: #000; border: none; border-radius: 10px; padding: 0 24px; font-weight: 600; cursor: pointer; transition: 0.2s; }
        .search-btn:hover { background: var(--accent-hover); }
        .search-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        #search-results { margin-top: 16px; display: none; }
        #search-results table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        #search-results th { text-align: left; padding: 12px; border-bottom: 2px solid var(--border-color); color: var(--text-secondary); font-weight: 500; }
        #search-results td { padding: 12px; border-bottom: 1px solid var(--border-color); }
        #search-results tr:last-child td { border: none; }
        .results-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--border-color); }
        .latency { font-size: 0.75rem; color: var(--text-secondary); background: rgba(255,255,255,0.05); padding: 2px 8px; border-radius: 4px; }

        .search-error { display: none; margin-top: 16px; padding: 16px; background: rgba(239, 68, 68, 0.1); border: 1px solid var(--error-color); border-radius: 10px; color: #ff9999; font-size: 0.9rem; overflow-x: auto; }

        .spinner {
            display: none;
            width: 40px;
            height: 40px;
            border: 3px solid rgba(56, 189, 248, 0.1);
            border-top: 3px solid var(--accent-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        #margin-display {
            margin-top: 24px;
            padding: 24px;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            display: none;
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
            <div style="background:rgba(34, 197, 94, 0.1); border:1px solid var(--success-color); padding:16px; border-radius:12px; color:var(--success-color); margin-bottom:24px; font-size:0.9rem">
                <strong>Success!</strong> The Access Token for today has been safely stored. You can now fetch your margin limits.
            </div>
        <?php elseif (isset($_GET['error'])): ?>
            <div style="background:rgba(239, 68, 68, 0.1); border:1px solid var(--error-color); padding:16px; border-radius:12px; color:var(--error-color); margin-bottom:24px; font-size:0.9rem">
                <strong>Authentication Failed:</strong> <?= htmlspecialchars($_GET['error']) ?>
            </div>
        <?php endif; ?>
        
        <!-- Loading spinner for API tasks -->
        <div id="loader" class="spinner"></div>

        <!-- Displays output here -->
        <div id="margin-display"></div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const searchBtn     = document.getElementById('searchBtn');
            const searchInput   = document.getElementById('searchInput');
            const exchangeSel   = document.getElementById('exchangeSelect');
            const searchResults = document.getElementById('search-results');
            const searchError   = document.getElementById('search-error');
            const displayArea   = document.getElementById('margin-display');
            const loader        = document.getElementById('loader');

            async function executeSearch() {
                const stext = searchInput.value.trim();
                if (!stext) return;

                searchBtn.disabled    = true;
                searchBtn.textContent = 'Searching...';
                searchError.style.display   = 'none';
                searchResults.style.display = 'none';

                try {
                    // VERSIONED API TO BYPASS OPCACHE
                    const res = await fetch('api_v4/search_scrip_v4.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ stext: stext, exch: exchangeSel.value })
                    });

                    // Hardened JSON Parsing
                    const text = await res.text();
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        searchError.style.display = 'block';
                        searchError.innerHTML = `<strong>Invalid Server Response (Non-JSON)</strong><pre>${text}</pre>`;
                        return;
                    }

                    if (data.s === 'error') {
                        searchError.style.display = 'block';
                        searchError.innerHTML = `<strong>API Error</strong><br>${data.m || 'Unknown error'}` 
                            + (data.debug ? `<hr><small>${data.debug}</small>` : '')
                            + (data.file ? `<br><small>File: ${data.file}:${data.line}</small>` : '');
                        return;
                    }

                    // Render results table
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

            // Margin Fetch Handler
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

            // Place Order Handler
            const orderBtn = document.getElementById('placeOrderBtn');
            if (orderBtn) {
                orderBtn.addEventListener('click', async () => {
                    displayArea.style.display = 'none';
                    loader.style.display = 'block';
                    try {
                        const res = await fetch('api/signal_router.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'place', exch: 'NSE', tsym: 'ACC-EQ', qty: '1',
                                prc: '1400', prd: 'I', trantype: 'B', prctyp: 'LMT', ret: 'DAY'
                            })
                        });
                        const data = await res.json();
                        loader.style.display = 'none';
                        displayArea.style.display = 'block';
                        displayArea.innerHTML = `
                            <h2 style="font-size:1.1rem;margin-bottom:12px;color:var(--success-color)">Signal Dispatched</h2>
                            <pre style="font-size:0.8rem;overflow-x:auto;color:var(--text-secondary)">${JSON.stringify(data,null,2)}</pre>`;
                    } catch {
                        loader.style.display = 'none';
                        displayArea.style.display = 'block';
                        displayArea.innerHTML = `<span style="color:var(--error-color)">Order Request Failed</span>`;
                    }
                });
            }
        });
    </script>
</body>
</html>
