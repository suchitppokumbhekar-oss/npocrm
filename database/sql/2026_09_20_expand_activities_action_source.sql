-- NPO CRM — Production hotfix: activities.action_source width
-- Run once in phpMyAdmin against the live NPO CRM database.
-- customer_conversation and customer_confirmation are 21 characters; the live
-- action_source column was VARCHAR(20), causing SQLSTATE[22001]/1406.

ALTER TABLE `activities`
  MODIFY COLUMN `action_source` VARCHAR(50) NOT NULL DEFAULT 'manual';
