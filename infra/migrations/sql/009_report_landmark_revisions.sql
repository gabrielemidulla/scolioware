-- P1: audit trail of physician-edited landmark sets (recompute from manual points).

SET NAMES utf8mb4;
USE `php_commerce`;

CREATE TABLE IF NOT EXISTS `report_landmark_revisions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id` BIGINT UNSIGNED NOT NULL,
  `physician_id` BIGINT UNSIGNED NULL,
  `landmarks_json` LONGTEXT NOT NULL,
  `source` VARCHAR(32) NOT NULL DEFAULT 'manual_json',
  `created_at` TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_rlm_report` (`report_id`, `created_at`),
  CONSTRAINT `fk_rlm_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rlm_physician` FOREIGN KEY (`physician_id`) REFERENCES `physicians` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
