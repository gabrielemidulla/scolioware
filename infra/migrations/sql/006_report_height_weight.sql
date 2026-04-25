-- Per-report anthropometrics + denormalized last values on patients.

SET NAMES utf8mb4;

USE `php_commerce`;

ALTER TABLE `reports`
  ADD COLUMN `height_cm` DECIMAL(5, 2) NULL DEFAULT NULL COMMENT 'At exam (cm)' AFTER `patient_id`,
  ADD COLUMN `weight_kg` DECIMAL(6, 2) NULL DEFAULT NULL COMMENT 'At exam (kg)' AFTER `height_cm`,
  ADD KEY `idx_reports_height_cm` (`height_cm`),
  ADD KEY `idx_reports_weight_kg` (`weight_kg`);

ALTER TABLE `patients`
  ADD COLUMN `last_height_cm` DECIMAL(5, 2) NULL DEFAULT NULL COMMENT 'Latest report with vitals' AFTER `gender`,
  ADD COLUMN `last_weight_kg` DECIMAL(6, 2) NULL DEFAULT NULL AFTER `last_height_cm`,
  ADD KEY `idx_patients_last_height` (`last_height_cm`),
  ADD KEY `idx_patients_last_weight` (`last_weight_kg`);

-- Backfill patient last vitals from most recent report that has both values.
UPDATE `patients` p
SET
  `last_height_cm` = (
    SELECT `height_cm` FROM `reports` r
    WHERE r.`patient_id` = p.`id` AND r.`height_cm` IS NOT NULL AND r.`weight_kg` IS NOT NULL
    ORDER BY r.`created_at` DESC, r.`id` DESC LIMIT 1
  ),
  `last_weight_kg` = (
    SELECT `weight_kg` FROM `reports` r
    WHERE r.`patient_id` = p.`id` AND r.`height_cm` IS NOT NULL AND r.`weight_kg` IS NOT NULL
    ORDER BY r.`created_at` DESC, r.`id` DESC LIMIT 1
  );
