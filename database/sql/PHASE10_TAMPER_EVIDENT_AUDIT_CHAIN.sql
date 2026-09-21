-- NPO CRM Phase 10 — Tamper-Evident Audit Chain
-- 1) Upload/replace the Phase 10 application files.
-- 2) Run this ALTER in phpMyAdmin.
-- 3) Sign in as Super Admin and open Vigilance Audit.
-- 4) Click "Initialize audit chain" once if legacy events are shown.
-- The initialization rebuilds integrity metadata only; it does not change audit event content.

ALTER TABLE `audit_logs`
  ADD COLUMN `previous_hash` CHAR(64) NULL AFTER `created_at`,
  ADD COLUMN `hash` CHAR(64) NULL AFTER `previous_hash`,
  ADD COLUMN `hash_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER `hash`,
  ADD INDEX `audit_logs_previous_hash_index` (`previous_hash`),
  ADD UNIQUE KEY `audit_logs_hash_unique` (`hash`);
