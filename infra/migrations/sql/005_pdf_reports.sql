-- Physician-authored PDF reports stored in R2; metadata + soft delete in MySQL.

SET NAMES utf8mb4;

USE `php_commerce`;

CREATE TABLE IF NOT EXISTS `pdf_reports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id` BIGINT UNSIGNED NOT NULL,
  `patient_id` BIGINT UNSIGNED NOT NULL,
  `physician_id` BIGINT UNSIGNED NULL,
  `physician_username` VARCHAR(64) NULL,
  `title` VARCHAR(255) NULL,
  `notes` MEDIUMTEXT NULL,
  `pdf_object_key` VARCHAR(512) NOT NULL,
  `pdf_bytes_size` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pdfr_patient` (`patient_id`),
  KEY `idx_pdfr_report` (`report_id`),
  KEY `idx_pdfr_deleted_at` (`deleted_at`),
  CONSTRAINT `fk_pdfr_report` FOREIGN KEY (`report_id`)
    REFERENCES `reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pdfr_patient` FOREIGN KEY (`patient_id`)
    REFERENCES `patients` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pdfr_physician` FOREIGN KEY (`physician_id`)
    REFERENCES `physicians` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
