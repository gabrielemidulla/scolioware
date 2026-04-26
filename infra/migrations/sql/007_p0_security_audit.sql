-- P0: password rotation flag, login throttles, PHI access audit (append-only).

SET NAMES utf8mb4;

USE `php_commerce`;

ALTER TABLE `physicians`
  ADD COLUMN `password_must_change` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = user must set a new password before using the app'
    AFTER `password_hash`;

-- Failed login throttling: fixed 5-minute sliding window (cleaned on insert in app).
CREATE TABLE IF NOT EXISTS `login_throttle` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope` ENUM('ip', 'user') NOT NULL,
  `identity` VARCHAR(256) NOT NULL,
  `attempted_at` TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_login_throttle_scope_identity_time` (`scope`, `identity`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who viewed what PHI; append-only (app user: INSERT only, no UPDATE/DELETE).
CREATE TABLE IF NOT EXISTS `phi_access_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `physician_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(32) NOT NULL,
  `patient_id` BIGINT UNSIGNED NULL,
  `report_id` BIGINT UNSIGNED NULL,
  `pdf_report_id` BIGINT UNSIGNED NULL,
  `ip` VARCHAR(45) NULL,
  `user_agent` VARCHAR(512) NULL,
  `path` VARCHAR(255) NULL,
  `created_at` TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_phi_log_physician` (`physician_id`, `created_at`),
  KEY `idx_phi_log_patient` (`patient_id`, `created_at`),
  KEY `idx_phi_log_report` (`report_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
