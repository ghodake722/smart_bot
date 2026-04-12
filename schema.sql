CREATE TABLE IF NOT EXISTS `flattrade_tokens` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_id` VARCHAR(50) NOT NULL UNIQUE,
  `access_token` TEXT NOT NULL,
  `header_auth_token` VARCHAR(255) NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `ft_order_log` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `request_id` VARCHAR(36) NOT NULL,
  `endpoint` VARCHAR(32) NOT NULL,
  `payload` JSON NOT NULL,
  `broker_response` JSON NULL,
  `latency_us` INT UNSIGNED NULL,
  `status` VARCHAR(16) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_req_id (`request_id`),
  INDEX idx_created (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
