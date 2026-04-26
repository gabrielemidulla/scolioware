-- P1: optional DICOM source metadata (JSON) per report; no R2/PHI in this column, only study tags.

SET NAMES utf8mb4;
USE `php_commerce`;

ALTER TABLE `reports`
  ADD COLUMN `dicom_metadata_json` LONGTEXT NULL
    COMMENT 'If upload was DICOM: study/patient/series + pixel spacing when available'
    AFTER `report_metadata_json`;
