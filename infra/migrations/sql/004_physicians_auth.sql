-- Auth: physicians + password reset tokens. Soft-delete via deleted_at.

SET NAMES utf8mb4;

USE `php_commerce`;

CREATE TABLE IF NOT EXISTS `physicians` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(64) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `is_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `display_name` VARCHAR(191) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  -- Generated column: holds username only when active so UNIQUE allows reuse after soft delete.
  `username_active` VARCHAR(64) GENERATED ALWAYS AS
    (CASE WHEN `deleted_at` IS NULL THEN LOWER(`username`) END) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_physicians_username_active` (`username_active`),
  KEY `idx_physicians_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `physician_id` BIGINT UNSIGNED NOT NULL,
  `token` CHAR(64) NOT NULL,
  `created_by_physician_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL,
  `used_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prt_token` (`token`),
  KEY `idx_prt_physician` (`physician_id`),
  CONSTRAINT `fk_prt_physician`
    FOREIGN KEY (`physician_id`) REFERENCES `physicians` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_prt_admin`
    FOREIGN KEY (`created_by_physician_id`) REFERENCES `physicians` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
