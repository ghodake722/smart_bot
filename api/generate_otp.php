<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PragmaRX\Google2FA\Google2FA;

header('Content-Type: application/json');

try {
    // 1. Bootstrap .env
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../');
    $dotenv->load();

    // 2. Fetch the TOTP_KEY
    $totp_key = $_ENV['TOTP_KEY'] ?? '';
    if (empty($totp_key)) {
        throw new Exception("TOTP_KEY missing from .env");
    }

    // 3. Generate current time-slice OTP programmatically
    $google2fa = new Google2FA();
    $otp = $google2fa->getCurrentOtp($totp_key);

    echo json_encode(['status' => 'success', 'otp' => $otp]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
