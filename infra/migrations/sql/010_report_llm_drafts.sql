-- Audit trail of LLM-generated preliminary X-ray descriptions.
-- Every "Generate draft" click writes a new row. The latest row is shown
-- to the physician but earlier ones remain queryable for review/QA.

SET NAMES utf8mb4;
USE `php_commerce`;

CREATE TABLE IF NOT EXISTS `report_llm_drafts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id` BIGINT UNSIGNED NOT NULL,
  `physician_id` BIGINT UNSIGNED NULL,
  `model_tag` VARCHAR(64) NOT NULL,
  `prompt_version` VARCHAR(32) NOT NULL,
  `response_text` MEDIUMTEXT NOT NULL,
  `latency_ms` INT UNSIGNED NOT NULL DEFAULT 0,
  `prompt_tokens` INT UNSIGNED NULL,
  `completion_tokens` INT UNSIGNED NULL,
  `created_at` TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_lmd_report` (`report_id`, `created_at`),
  CONSTRAINT `fk_lmd_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lmd_physician` FOREIGN KEY (`physician_id`) REFERENCES `physicians` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
