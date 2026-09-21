-- NPO CRM — Super Admin File Center
-- Run once in cPanel phpMyAdmin against the CRM database.
-- No existing data is modified.

CREATE TABLE IF NOT EXISTS `managed_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `original_name` varchar(255) NOT NULL,
  `storage_path` varchar(500) NOT NULL,
  `disk` varchar(50) NOT NULL DEFAULT 'local',
  `mime_type` varchar(150) DEFAULT NULL,
  `size_bytes` bigint(20) unsigned NOT NULL DEFAULT 0,
  `sha256` char(64) DEFAULT NULL,
  `file_kind` varchar(30) NOT NULL,
  `source_label` varchar(100) DEFAULT NULL,
  `uploaded_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `managed_files_sha256_index` (`sha256`),
  KEY `managed_files_file_kind_index` (`file_kind`),
  KEY `managed_files_source_label_index` (`source_label`),
  KEY `managed_files_uploaded_by_user_id_index` (`uploaded_by_user_id`),
  KEY `managed_files_created_at_index` (`created_at`),
  KEY `managed_files_file_kind_created_at_index` (`file_kind`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
