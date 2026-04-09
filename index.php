<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flattrade Automation Session</title>
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
            transition: all 0.3s ease;
        }

        h1 {
            font-size: 1.5rem;
            margin-bottom: 8px;
            text-align: center;
            background: linear-gradient(to right, #60a5fa, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        p.subtitle {
            color: var(--text-secondary);
            text-align: center;
            font-size: 0.875rem;
            margin-bottom: 32px;
        }

        .btn {
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            background-color: var(--accent-color);
            color: white;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn:hover {
            background-color: var(--accent-hover);
            transform: translateY(-1px);
        }

        .btn:disabled {
            background-color: var(--text-secondary);
            cursor: not-allowed;
            transform: none;
        }

        .progress-box {
            margin-top: 24px;
            border-radius: 12px;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border-color);
            padding: 16px;
            min-height: 150px;
            max-height: 300px;
            overflow-y: auto;
        }

        .log-entry {
            display: flex;
            align-items: flex-start;
            margin-bottom: 12px;
            font-size: 0.875rem;
            opacity: 0;
            transform: translateY(10px);
            animation: slideIn 0.3s forwards;
        }

        .log-entry:last-child {
            margin-bottom: 0;
        }

        @keyframes slideIn {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .icon {
            margin-right: 12px;
            display: inline-flex;
        }

        .icon.spin {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            100% { transform: rotate(360deg); }
        }

        .text {
            line-height: 1.4;
        }

        .success { color: var(--success-color); }
        .error { color: var(--error-color); }
        .info { color: var(--accent-color); }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 4px; }
    </style>
</head>
<body>

    <div class="container">
        <h1>Flattrade Session Engine</h1>
        <p class="subtitle">Automated Headless Authentication</p>
        
        <button id="startBtn" class="btn" onclick="startProcess()">Generate Session Token</button>

        <div class="progress-box" id="progressBox">
            <div class="log-entry" style="color: var(--text-secondary)">
                <div class="text">Ready to initiate secure token generation. System will use local environment credentials.</div>
            </div>
        </div>
    </div>

    <script>
        const progressBox = document.getElementById('progressBox');
        const startBtn = document.getElementById('startBtn');

        function addLog(message, type = 'info', spinner = false) {
            let iconHtml = '';
            if (spinner) {
                iconHtml = `<svg class="icon spin" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>`;
            } else if (type === 'success') {
                iconHtml = `<svg class="icon success" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>`;
            } else if (type === 'error') {
                iconHtml = `<svg class="icon error" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`;
            } else {
                iconHtml = `<svg class="icon info" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>`;
            }

            const html = `<div class="log-entry"><div class="icon-wrap">${iconHtml}</div><div class="text ${type}">${message}</div></div>`;
            
            // Remove previous spinner icon if exists
            const activeSpinners = progressBox.querySelectorAll('.spin');
            activeSpinners.forEach(spin => {
                spin.outerHTML = `<svg class="icon success" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>`;
            });

            progressBox.insertAdjacentHTML('beforeend', html);
            progressBox.scrollTop = progressBox.scrollHeight;
        }

        async function startProcess() {
            startBtn.disabled = true;
            progressBox.innerHTML = ''; // reset
            addLog("Initiating request sequences...", "info");

            try {
                // STEP 1: Generate OTP
                addLog("Reading TOTP_KEY from .env and computing current time-based OTP...", "info", true);
                const step1 = await fetch('api/generate_otp.php');
                const result1 = await step1.json();
                
                if (result1.status !== 'success') {
                    throw new Error(result1.message || "Failed to generate OTP.");
                }
                addLog(`OTP successfully generated: <strong>***${result1.otp.slice(-3)}</strong>`, "success");

                // STEP 2: Authenticate (Automated Login)
                addLog("Sending securely hashed credentials & OTP to Flattrade authentication gateway...", "info", true);
                const step2 = await fetch('api/authenticate.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ otp: result1.otp })
                });
                const result2 = await step2.json();
                
                if (result2.status !== 'success') {
                    throw new Error(result2.message || "Authentication attempt rejected.");
                }
                
                const request_code = result2.request_code;
                addLog(`Login Accepted! Retrieved request_code handler.`, "success");

                // STEP 3: Exchange Token & Store safely in DB
                addLog("Completing OAuth Handshake: Computing SHA-256 securing hash and storing session...", "info", true);
                const step3 = await fetch('api/exchange_token.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ request_code: request_code })
                });
                const result3 = await step3.json();

                if (result3.status !== 'success') {
                    throw new Error(result3.message || "Failed to securely exchange or save token.");
                }

                addLog(`<strong>Success!</strong> Token generated and securely inserted into flattrade_tokens for Client ID: ${result3.client_id}`, "success");
                startBtn.disabled = false;
                startBtn.innerText = "Regenerate Session Token";

            } catch (err) {
                addLog(`<strong>Error:</strong> ${err.message}`, "error");
                startBtn.disabled = false;
                startBtn.innerText = "Retry Generator";
            }
        }
    </script>
</body>
</html>
