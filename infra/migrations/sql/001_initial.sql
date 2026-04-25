-- Initial schema: patients + reports (idempotent CREATE IF NOT EXISTS for first apply).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

USE `php_commerce`;

CREATE TABLE IF NOT EXISTS `patients` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name` VARCHAR(191) NOT NULL,
  `last_name` VARCHAR(191) NOT NULL,
  `tax_code` VARCHAR(32) NOT NULL,
  `birth_date` DATE NOT NULL,
  `gender` ENUM('male', 'female', 'non_binary') NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_patients_tax_code` (`tax_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
  `error_message` TEXT NULL,
  `original_object_key` VARCHAR(512) NULL,
  `computed_object_key` VARCHAR(512) NULL,
  `detections_json` LONGTEXT NULL,
  `landmarks_json` LONGTEXT NULL,
  `angles_json` LONGTEXT NULL,
  `midpoint_lines_json` LONGTEXT NULL,
  `curve_type` ENUM('C', 'S') NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reports_status` (`status`),
  KEY `idx_reports_patient` (`patient_id`),
  CONSTRAINT `fk_reports_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
