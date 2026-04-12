<?php
/**
 * One-time migration: create ft_token_audit_log table
 * Run: php _migrate_audit.php
 * Safe to delete after running.
 */
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=mytptd_c1_db;charset=utf8mb4',
        'mytptd_c1_root',
        'ptP_*yOV?7QM',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ft_token_audit_log` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `event_type` VARCHAR(32) NOT NULL COMMENT 'TOKEN_HIT | TOKEN_REFRESH | TOKEN_MISS | TOKEN_ERROR',
            `message` VARCHAR(255) NOT NULL,
            `client_id` VARCHAR(50) NOT NULL,
            `source` VARCHAR(16) NOT NULL DEFAULT 'redis' COMMENT 'redis | mysql | fallback',
            `ttl_remaining` INT NULL COMMENT 'Seconds remaining on cached token',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event (`event_type`),
            INDEX idx_client (`client_id`),
            INDEX idx_audit_created (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    echo "SUCCESS: ft_token_audit_log table created (or already exists)\n";

    // Verify
    $stmt = $pdo->query("SHOW COLUMNS FROM ft_token_audit_log");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Columns: " . implode(', ', $cols) . "\n";

} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
