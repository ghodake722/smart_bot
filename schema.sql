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
