-- curve_type: free text -> ENUM('C','S') matching inference (C-shaped / S-shaped scoliosis).

SET NAMES utf8mb4;

USE `php_commerce`;

UPDATE `reports`
SET `curve_type` = UPPER(TRIM(`curve_type`))
WHERE `curve_type` IS NOT NULL;

UPDATE `reports`
SET `curve_type` = NULL
WHERE `curve_type` IS NOT NULL
  AND `curve_type` NOT IN ('C', 'S');

ALTER TABLE `reports`
  MODIFY COLUMN `curve_type` ENUM('C', 'S') NULL DEFAULT NULL;
