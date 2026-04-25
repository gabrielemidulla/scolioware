-- Cobb summary fields aligned with legacy PDF (ScoliosoftDocument): PT, MT, TL, thoracic/lumbar summaries, max Cobb + vertebra indices.

SET NAMES utf8mb4;

USE `php_commerce`;

ALTER TABLE `reports`
  ADD COLUMN `cobb_pt_deg` DECIMAL(10, 4) NULL AFTER `curve_type`,
  ADD COLUMN `cobb_mt_deg` DECIMAL(10, 4) NULL AFTER `cobb_pt_deg`,
  ADD COLUMN `cobb_tl_deg` DECIMAL(10, 4) NULL AFTER `cobb_mt_deg`,
  ADD COLUMN `cobb_thoracic_deg` DECIMAL(10, 4) NULL COMMENT 'max(PT, MT) when both present' AFTER `cobb_tl_deg`,
  ADD COLUMN `cobb_lumbar_deg` DECIMAL(10, 4) NULL COMMENT 'TL (thoracolumbar / lumbar)' AFTER `cobb_thoracic_deg`,
  ADD COLUMN `cobb_max_region` VARCHAR(8) NULL AFTER `cobb_lumbar_deg`,
  ADD COLUMN `cobb_max_deg` DECIMAL(10, 4) NULL AFTER `cobb_max_region`,
  ADD COLUMN `cobb_max_vert_superior` SMALLINT UNSIGNED NULL AFTER `cobb_max_deg`,
  ADD COLUMN `cobb_max_vert_inferior` SMALLINT UNSIGNED NULL AFTER `cobb_max_vert_superior`,
  ADD COLUMN `image_width` INT UNSIGNED NULL AFTER `cobb_max_vert_inferior`,
  ADD COLUMN `image_height` INT UNSIGNED NULL AFTER `image_width`,
  ADD COLUMN `report_metadata_json` LONGTEXT NULL AFTER `image_height`;
