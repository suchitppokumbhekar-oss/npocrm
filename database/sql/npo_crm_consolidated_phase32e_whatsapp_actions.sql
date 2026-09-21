-- NPO CRM consolidated schema additions for FIX32E + Universal WhatsApp + Action Audit
-- Safe for phpMyAdmin. Run once; safe to run again.

SET @db := DATABASE();

-- FIX32E workflow/scheduling/transfer fields
SET @sql := IF(NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='agents' AND COLUMN_NAME='whatsapp_personal_number'),
  'ALTER TABLE agents ADD COLUMN whatsapp_personal_number VARCHAR(20) NULL AFTER phone', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='agents' AND COLUMN_NAME='whatsapp_business_number'),
  'ALTER TABLE agents ADD COLUMN whatsapp_business_number VARCHAR(20) NULL AFTER whatsapp_personal_number', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leads' AND COLUMN_NAME='parent_lead_id'),
  'ALTER TABLE leads ADD COLUMN parent_lead_id BIGINT UNSIGNED NULL AFTER customer_id', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leads' AND COLUMN_NAME='origin_type'),
  'ALTER TABLE leads ADD COLUMN origin_type VARCHAR(50) NULL AFTER parent_lead_id', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leads' AND COLUMN_NAME='origin_note'),
  'ALTER TABLE leads ADD COLUMN origin_note VARCHAR(1000) NULL AFTER origin_type', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='leads' AND INDEX_NAME='idx_leads_parent_lead_id'),
  'ALTER TABLE leads ADD INDEX idx_leads_parent_lead_id (parent_lead_id)', 'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FIX32E status transition
INSERT INTO lead_status_transitions (from_status_id, to_status_id)
SELECT fs.id, ts.id FROM lead_statuses fs JOIN lead_statuses ts ON ts.`key`='visit_done'
WHERE fs.`key`='contacted' AND NOT EXISTS (SELECT 1 FROM lead_status_transitions x WHERE x.from_status_id=fs.id AND x.to_status_id=ts.id);

-- Universal Personal / Business WhatsApp identities for every CRM user
CREATE TABLE IF NOT EXISTS user_whatsapp_accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  account_type ENUM('personal','business') NOT NULL,
  phone VARCHAR(30) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_whatsapp_type (user_id, account_type),
  KEY idx_user_whatsapp_user_active (user_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manual vs automated action audit
SET @has_action_source := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='activities' AND COLUMN_NAME='action_source');
SET @sql := IF(@has_action_source=0,
  'ALTER TABLE activities ADD COLUMN action_source VARCHAR(20) NOT NULL DEFAULT ''manual'', ADD INDEX idx_activities_action_source (action_source)',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@has_action_source=0,
  'UPDATE activities SET action_source=''legacy''',
  'SELECT 1'); PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
