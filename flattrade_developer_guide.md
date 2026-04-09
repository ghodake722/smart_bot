# Flattrade Developer Guide

This guide explains the two methodologies for securing API Access Tokens via Flattrade's ecosystem.

## 1. Official Browser OAuth Flow (Standard)
This is the flow documented in the [Flattrade Pi Docs](https://pi.flattrade.in/):

1. **Redirect**: Direct the user to `https://auth.flattrade.in/?app_key=YOUR_API_KEY`.
2. **Login**: User manually enters their User ID, Password, and TOTP on Flattrade's platform.
3. **Capture**: Flattrade redirects the user back to your App's `REDIRECT_URL` bearing a `request_code` query param.
4. **Exchange**: Your server hashes the secret: `SHA256(API_KEY + request_code + API_SECRET)` and sends a POST request to `https://authapi.flattrade.in/trade/apitoken` to get the `access_token` (jKey).

## 2. Headless Automation Flow (Our Application)
Our application relies on automating the above using `.env` variables, preventing the user from needing to leave our application at all!

### How it works natively:
The `index.php` triggers AJAX calls natively orchestrating these steps:
- **`generate_otp.php`**: Utilizing `pragmarx/google2fa`, we programmatically construct the active TOTP purely from the generic 32-character `TOTP_KEY` inside `.env`.
- **`authenticate.php`**: Simulates the typical form submission (sending `uid`, `pwd` (hashed), and `factor2`) to retrieve the `request_code` directly bridging directly without requiring interaction.
- **`exchange_token.php`**: Operates exactly identically to the standard flow, securing the final session token and persisting it into `flattrade_tokens`.
