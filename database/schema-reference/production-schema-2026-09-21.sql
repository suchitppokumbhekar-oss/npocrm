/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
DROP TABLE IF EXISTS `activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `outcome` varchar(255) DEFAULT NULL,
  `outcome_key` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `duration` int(11) DEFAULT NULL,
  `logged_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `action_source` varchar(50) NOT NULL DEFAULT 'manual',
  PRIMARY KEY (`id`),
  KEY `lead_id` (`lead_id`),
  KEY `agent_id` (`agent_id`),
  KEY `idx_activities_action_source` (`action_source`),
  CONSTRAINT `activities_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `activities_ibfk_2` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2212 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `icon` varchar(20) DEFAULT NULL,
  `requires_outcome` tinyint(1) NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `agents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `agents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `salary` decimal(14,2) DEFAULT NULL,
  `joining_date` date DEFAULT NULL,
  `confirmation_date` date DEFAULT NULL,
  `exit_date` date DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `whatsapp_personal_number` varchar(20) DEFAULT NULL,
  `whatsapp_business_number` varchar(20) DEFAULT NULL,
  `max_daily_leads` int(11) DEFAULT 10,
  `current_load` int(11) DEFAULT 0,
  `status` varchar(255) DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `phone` (`phone`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `agents_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `attendances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendances` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint(20) unsigned NOT NULL,
  `shift_date` date NOT NULL,
  `checked_in_at` timestamp NULL DEFAULT NULL,
  `checked_in_lat` decimal(10,7) DEFAULT NULL,
  `checked_in_lng` decimal(10,7) DEFAULT NULL,
  `checked_in_accuracy_m` int(11) DEFAULT NULL,
  `checked_in_distance_m` int(11) DEFAULT NULL,
  `checked_in_ip` varchar(45) DEFAULT NULL,
  `checked_out_at` timestamp NULL DEFAULT NULL,
  `checked_out_lat` decimal(10,7) DEFAULT NULL,
  `checked_out_lng` decimal(10,7) DEFAULT NULL,
  `checked_out_accuracy_m` int(11) DEFAULT NULL,
  `checked_out_ip` varchar(45) DEFAULT NULL,
  `tasks_completed` int(11) NOT NULL DEFAULT 0,
  `tasks_overdue_at_exit` int(11) NOT NULL DEFAULT 0,
  `override_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `override_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_agent_date` (`agent_id`,`shift_date`),
  KEY `idx_shift_date` (`shift_date`),
  KEY `idx_agent` (`agent_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `actor_name` varchar(150) DEFAULT NULL,
  `actor_role` varchar(50) DEFAULT NULL,
  `event_category` varchar(50) NOT NULL,
  `action` varchar(120) NOT NULL,
  `target_type` varchar(100) DEFAULT NULL,
  `target_id` bigint(20) unsigned DEFAULT NULL,
  `route_name` varchar(150) DEFAULT NULL,
  `method` varchar(10) NOT NULL,
  `path` varchar(500) NOT NULL,
  `status_code` smallint(5) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `previous_hash` char(64) DEFAULT NULL,
  `hash` char(64) DEFAULT NULL,
  `hash_version` smallint(5) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `audit_logs_hash_unique` (`hash`),
  KEY `audit_logs_actor_user_id_created_at_index` (`actor_user_id`,`created_at`),
  KEY `audit_logs_event_category_created_at_index` (`event_category`,`created_at`),
  KEY `audit_logs_event_category_index` (`event_category`),
  KEY `audit_logs_action_index` (`action`),
  KEY `audit_logs_target_type_index` (`target_type`),
  KEY `audit_logs_target_id_index` (`target_id`),
  KEY `audit_logs_actor_role_index` (`actor_role`),
  KEY `audit_logs_status_code_index` (`status_code`),
  KEY `audit_logs_ip_address_index` (`ip_address`),
  KEY `audit_logs_created_at_index` (`created_at`),
  KEY `audit_logs_previous_hash_index` (`previous_hash`)
) ENGINE=InnoDB AUTO_INCREMENT=1897 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `booking_brokerage_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_brokerage_components` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_financial_id` bigint(20) unsigned NOT NULL,
  `component_type` varchar(40) NOT NULL,
  `label` varchar(160) NOT NULL,
  `base_amount` decimal(14,2) DEFAULT NULL,
  `rate` decimal(14,6) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sequence_no` int(11) NOT NULL DEFAULT 1,
  `rule_id` bigint(20) unsigned DEFAULT NULL,
  `rule_version` int(11) DEFAULT NULL,
  `snapshot_json` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `booking_brokerage_components_booking_idx` (`booking_financial_id`,`sequence_no`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `booking_brokerage_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_brokerage_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_financial_id` bigint(20) unsigned NOT NULL,
  `receipt_date` date NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `payer_name` varchar(200) DEFAULT NULL,
  `payment_reference` varchar(200) DEFAULT NULL,
  `payment_mode` varchar(60) DEFAULT NULL,
  `notes` varchar(2000) DEFAULT NULL,
  `incentive_collection_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `booking_brokerage_receipts_booking_idx` (`booking_financial_id`,`receipt_date`),
  KEY `booking_brokerage_receipts_collection_idx` (`incentive_collection_id`),
  KEY `booking_brokerage_receipts_reference_idx` (`payment_reference`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `booking_controls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_controls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `requested_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `requested_at` datetime DEFAULT NULL,
  `approved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `decision_note` text DEFAULT NULL,
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`snapshot`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_controls_lead_id_unique` (`lead_id`),
  KEY `booking_controls_status_index` (`status`),
  KEY `booking_controls_requested_by_user_id_index` (`requested_by_user_id`),
  KEY `booking_controls_approved_by_user_id_index` (`approved_by_user_id`),
  KEY `booking_controls_rejected_by_user_id_index` (`rejected_by_user_id`),
  CONSTRAINT `booking_controls_approved_by_user_id_foreign` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `booking_controls_lead_id_foreign` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `booking_controls_rejected_by_user_id_foreign` FOREIGN KEY (`rejected_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `booking_controls_requested_by_user_id_foreign` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `booking_employee_allocations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_employee_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_financial_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `allocation_type` varchar(30) NOT NULL DEFAULT 'percentage',
  `base_type` varchar(60) NOT NULL DEFAULT 'net_brokerage',
  `rate` decimal(14,6) DEFAULT NULL,
  `fixed_amount` decimal(14,2) DEFAULT NULL,
  `calculated_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `incentive_rule_id` bigint(20) unsigned DEFAULT NULL,
  `incentive_rule_version` int(11) DEFAULT NULL,
  `eligibility_snapshot_json` longtext DEFAULT NULL,
  `rule_snapshot_json` longtext DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `booking_employee_allocations_booking_idx` (`booking_financial_id`),
  KEY `booking_employee_allocations_agent_idx` (`agent_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `booking_financial_audits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_financial_audits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_financial_id` bigint(20) unsigned DEFAULT NULL,
  `event_type` varchar(80) NOT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `reason` varchar(2000) DEFAULT NULL,
  `before_json` longtext DEFAULT NULL,
  `after_json` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `booking_financial_audits_booking_idx` (`booking_financial_id`,`created_at`),
  KEY `booking_financial_audits_event_idx` (`event_type`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `booking_financials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_financials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint(20) unsigned DEFAULT NULL,
  `import_batch_id` bigint(20) unsigned DEFAULT NULL,
  `source_type` varchar(30) NOT NULL DEFAULT 'live_lead',
  `source_reference` varchar(255) DEFAULT NULL,
  `customer_name_snapshot` varchar(200) DEFAULT NULL,
  `customer_phone_snapshot` varchar(50) DEFAULT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `project_name_snapshot` varchar(200) DEFAULT NULL,
  `builder_name` varchar(200) DEFAULT NULL,
  `unit_type` varchar(100) DEFAULT NULL,
  `booking_unit` varchar(100) DEFAULT NULL,
  `property_area_sqft` decimal(14,2) DEFAULT NULL,
  `rate_per_sqft` decimal(14,2) DEFAULT NULL,
  `booking_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `booking_date` date NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'draft',
  `brokerage_gross` decimal(14,2) NOT NULL DEFAULT 0.00,
  `brokerage_rule_id` bigint(20) unsigned DEFAULT NULL,
  `brokerage_rule_version` int(11) DEFAULT NULL,
  `brokerage_snapshot_json` longtext DEFAULT NULL,
  `payable_deduction_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `net_brokerage` decimal(14,2) NOT NULL DEFAULT 0.00,
  `incentive_pool` decimal(14,2) NOT NULL DEFAULT 0.00,
  `financial_snapshot_json` longtext DEFAULT NULL,
  `legacy_incentive_deal_id` bigint(20) unsigned DEFAULT NULL,
  `submitted_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `authorized_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `authorized_at` datetime DEFAULT NULL,
  `cancelled_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` varchar(2000) DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_financials_lead_uq` (`lead_id`),
  KEY `booking_financials_date_idx` (`booking_date`,`status`),
  KEY `booking_financials_project_idx` (`project_id`,`booking_date`),
  KEY `booking_financials_builder_idx` (`builder_name`,`booking_date`),
  KEY `booking_financials_import_idx` (`import_batch_id`),
  KEY `booking_financials_status_idx` (`status`),
  KEY `booking_financials_legacy_deal_idx` (`legacy_incentive_deal_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `booking_import_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_import_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_type` varchar(30) NOT NULL DEFAULT 'csv',
  `file_name` varchar(255) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'preview',
  `mapping_json` longtext DEFAULT NULL,
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `valid_rows` int(11) NOT NULL DEFAULT 0,
  `invalid_rows` int(11) NOT NULL DEFAULT 0,
  `duplicate_rows` int(11) NOT NULL DEFAULT 0,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `booking_import_batches_status_idx` (`status`,`created_at`),
  KEY `booking_import_batches_creator_idx` (`created_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `booking_payable_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_payable_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_payable_id` bigint(20) unsigned NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `payment_reference` varchar(200) DEFAULT NULL,
  `payment_mode` varchar(60) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `booking_payable_payments_payable_idx` (`booking_payable_id`,`payment_date`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `booking_payable_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_payable_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `code` varchar(80) NOT NULL,
  `category` varchar(80) NOT NULL DEFAULT 'other',
  `calculation_type` varchar(40) NOT NULL DEFAULT 'manual',
  `base_type` varchar(60) DEFAULT NULL,
  `default_value` decimal(14,6) DEFAULT 0.000000,
  `affects_net_brokerage` tinyint(1) NOT NULL DEFAULT 0,
  `requires_approval` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `description` varchar(1000) DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_payable_types_code_uq` (`code`),
  KEY `booking_payable_types_active_idx` (`active`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `booking_payables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_payables` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `booking_financial_id` bigint(20) unsigned NOT NULL,
  `payable_type_id` bigint(20) unsigned DEFAULT NULL,
  `payable_type_name_snapshot` varchar(120) NOT NULL,
  `category` varchar(80) NOT NULL DEFAULT 'other',
  `payee_name` varchar(200) NOT NULL,
  `payee_reference` varchar(200) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `calculated_amount` decimal(14,2) DEFAULT NULL,
  `calculation_rate` decimal(14,6) DEFAULT NULL,
  `calculation_basis` varchar(60) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'pending',
  `affects_net_brokerage` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `approved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `cancelled_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `booking_payables_booking_idx` (`booking_financial_id`,`status`),
  KEY `booking_payables_due_idx` (`due_date`,`status`),
  KEY `booking_payables_type_idx` (`payable_type_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `call_outcomes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `call_outcomes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `category` enum('positive','neutral','negative') NOT NULL DEFAULT 'neutral',
  `is_connected` tinyint(1) NOT NULL DEFAULT 0,
  `next_action_type_id` bigint(20) unsigned DEFAULT NULL,
  `next_action_delay_hours` int(11) NOT NULL DEFAULT 24,
  `priority` enum('low','normal','high') NOT NULL DEFAULT 'normal',
  `suggested_status_id` bigint(20) unsigned DEFAULT NULL,
  `context_action_key` varchar(50) DEFAULT NULL,
  `contact_status_key` varchar(50) DEFAULT NULL,
  `requires_site_visit_datetime` tinyint(1) NOT NULL DEFAULT 0,
  `next_action_anchor` varchar(30) NOT NULL DEFAULT 'now',
  `activity_type_filter` varchar(50) DEFAULT NULL,
  `prompts_whatsapp_send` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`),
  KEY `fk_outcome_action` (`next_action_type_id`),
  KEY `fk_outcome_status` (`suggested_status_id`),
  CONSTRAINT `fk_outcome_action` FOREIGN KEY (`next_action_type_id`) REFERENCES `followup_action_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_outcome_status` FOREIGN KEY (`suggested_status_id`) REFERENCES `lead_statuses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=107 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `calls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned DEFAULT NULL,
  `lead_id` bigint(20) unsigned DEFAULT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `outcome_key` varchar(50) DEFAULT NULL,
  `connected` tinyint(1) NOT NULL DEFAULT 0,
  `duration_seconds` int(11) NOT NULL DEFAULT 0,
  `called_at` timestamp NOT NULL,
  `notes` text DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'manual',
  `provider_call_id` varchar(100) DEFAULT NULL,
  `recording_url` varchar(500) DEFAULT NULL,
  `raw_payload` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_calls_contact` (`contact_id`),
  KEY `idx_calls_lead` (`lead_id`),
  KEY `idx_calls_agent_date` (`agent_id`,`called_at`),
  KEY `idx_calls_date` (`called_at`),
  KEY `idx_calls_provider` (`provider_call_id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_call_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_call_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `started_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  `call_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_call_sessions_work_idx` (`agent_id`,`completed_at`,`started_at`),
  KEY `contact_call_sessions_contact_idx` (`contact_id`,`completed_at`),
  CONSTRAINT `contact_call_sessions_contact_fk` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_followups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_followups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned DEFAULT NULL,
  `scheduled_for` datetime DEFAULT NULL,
  `action_type` varchar(80) NOT NULL DEFAULT 'followup_call',
  `priority` varchar(20) NOT NULL DEFAULT 'normal',
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `auto_created` tinyint(1) NOT NULL DEFAULT 1,
  `source_call_id` bigint(20) unsigned DEFAULT NULL,
  `notes` varchar(2000) DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `completed_by_agent_id` bigint(20) unsigned DEFAULT NULL,
  `completion_outcome_key` varchar(80) DEFAULT NULL,
  `completion_notes` varchar(2000) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_followups_work_idx` (`agent_id`,`status`,`scheduled_for`),
  KEY `contact_followups_contact_idx` (`contact_id`,`status`),
  KEY `contact_followups_completed_idx` (`completed_by_agent_id`,`completed_at`),
  CONSTRAINT `contact_followups_contact_fk` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_import_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_import_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL,
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `imported` int(11) NOT NULL DEFAULT 0,
  `skipped` int(11) NOT NULL DEFAULT 0,
  `failed` int(11) NOT NULL DEFAULT 0,
  `error_log` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cib_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_project_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_project_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) unsigned NOT NULL,
  `project_id` bigint(20) unsigned NOT NULL,
  `assigned_to_agent_id` bigint(20) unsigned DEFAULT NULL,
  `assigned_by_user_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cpa_contact_status_idx` (`contact_id`,`status`),
  KEY `cpa_project_status_idx` (`project_id`,`status`),
  KEY `cpa_agent_status_idx` (`assigned_to_agent_id`,`status`),
  KEY `cpa_assigned_by_idx` (`assigned_by_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=947 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `source` varchar(50) DEFAULT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'new',
  `assigned_to_agent_id` bigint(20) unsigned DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `connected_count` int(11) NOT NULL DEFAULT 0,
  `last_called_at` timestamp NULL DEFAULT NULL,
  `last_outcome_key` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `promoted_to_lead_id` bigint(20) unsigned DEFAULT NULL,
  `promoted_at` timestamp NULL DEFAULT NULL,
  `imported_from_batch_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `raw_payload` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `site_visit_scheduled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_contacts_phone` (`phone`),
  KEY `idx_contacts_status` (`status`),
  KEY `idx_contacts_assigned` (`assigned_to_agent_id`),
  KEY `idx_contacts_project` (`project_id`),
  KEY `idx_contacts_last_called` (`last_called_at`),
  KEY `contacts_site_visit_scheduled_idx` (`site_visit_scheduled_at`)
) ENGINE=InnoDB AUTO_INCREMENT=474 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `first_seen_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `total_enquiries` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customers_phone_unique` (`phone`),
  KEY `customers_name_idx` (`name`),
  KEY `customers_email_idx` (`email`),
  KEY `customers_last_seen_idx` (`last_seen_at`)
) ENGINE=InnoDB AUTO_INCREMENT=404 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delegated_access_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `delegated_access_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `profile_id` bigint(20) unsigned NOT NULL,
  `permission_key` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dap_permission_unique` (`profile_id`,`permission_key`),
  CONSTRAINT `dap_permission_profile_fk` FOREIGN KEY (`profile_id`) REFERENCES `delegated_access_profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=208 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delegated_access_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `delegated_access_profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by_user_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dap_user_unique` (`user_id`),
  KEY `dap_created_by_user_idx` (`created_by_user_id`),
  CONSTRAINT `dap_created_by_user_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `dap_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delegated_access_teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `delegated_access_teams` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `profile_id` bigint(20) unsigned NOT NULL,
  `team_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dat_profile_team_unique` (`profile_id`,`team_id`),
  KEY `dat_team_idx` (`team_id`),
  CONSTRAINT `dat_profile_fk` FOREIGN KEY (`profile_id`) REFERENCES `delegated_access_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dat_team_fk` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `delegated_access_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `delegated_access_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `profile_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `access_type` enum('include','exclude') NOT NULL DEFAULT 'include',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dau_profile_user_unique` (`profile_id`,`user_id`),
  KEY `dau_user_idx` (`user_id`),
  CONSTRAINT `dau_profile_fk` FOREIGN KEY (`profile_id`) REFERENCES `delegated_access_profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `dau_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `facebook_integrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `facebook_integrations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `fb_user_id` varchar(64) NOT NULL,
  `fb_user_name` varchar(200) DEFAULT NULL,
  `user_access_token` text NOT NULL,
  `token_expires_at` timestamp NULL DEFAULT NULL,
  `pages_json` longtext DEFAULT NULL,
  `selected_page_id` varchar(64) DEFAULT NULL,
  `selected_page_name` varchar(200) DEFAULT NULL,
  `selected_page_token` text DEFAULT NULL,
  `status` enum('connected','expired','disconnected') NOT NULL DEFAULT 'connected',
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fb_user` (`user_id`),
  CONSTRAINT `fk_fb_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `financial_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `financial_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rule_group` varchar(40) NOT NULL,
  `name` varchar(160) NOT NULL,
  `scope_type` varchar(40) NOT NULL DEFAULT 'global',
  `scope_value` varchar(160) DEFAULT NULL,
  `priority` int(11) NOT NULL DEFAULT 100,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `calculation_type` varchar(40) NOT NULL DEFAULT 'percentage',
  `base_type` varchar(60) DEFAULT NULL,
  `fixed_amount` decimal(14,2) DEFAULT NULL,
  `rate` decimal(14,6) DEFAULT NULL,
  `conditions_json` longtext DEFAULT NULL,
  `calculation_json` longtext DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `version` int(11) NOT NULL DEFAULT 1,
  `description` varchar(2000) DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `approved_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `financial_rules_group_date_idx` (`rule_group`,`status`,`effective_from`,`effective_to`),
  KEY `financial_rules_scope_idx` (`scope_type`,`scope_value`,`priority`),
  KEY `financial_rules_version_idx` (`name`,`version`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `followup_action_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `followup_action_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `followups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `followups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `scheduled_for` timestamp NOT NULL,
  `action_type` varchar(255) NOT NULL,
  `priority` varchar(255) DEFAULT 'normal',
  `status` varchar(255) DEFAULT 'pending',
  `escalated_flag` tinyint(1) DEFAULT 0,
  `auto_created` tinyint(1) NOT NULL DEFAULT 0,
  `source_activity_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `lead_id` (`lead_id`),
  KEY `agent_id` (`agent_id`),
  CONSTRAINT `followups_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `followups_ibfk_2` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1436 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incentive_collections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `incentive_collections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `deal_id` bigint(20) unsigned NOT NULL,
  `collection_date` date NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `incentive_collections_deal_date_idx` (`deal_id`,`collection_date`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incentive_deals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `incentive_deals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint(20) unsigned DEFAULT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `booking_date` date NOT NULL,
  `booked_nb` decimal(14,2) NOT NULL DEFAULT 0.00,
  `settled_nb` decimal(14,2) DEFAULT NULL,
  `developer_adjustment` decimal(14,2) DEFAULT NULL,
  `client_passback` decimal(14,2) DEFAULT NULL,
  `sub_broker_share` decimal(14,2) DEFAULT NULL,
  `referral_fee` decimal(14,2) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'booked',
  `notes` text DEFAULT NULL,
  `policy_snapshot_json` longtext DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `incentive_deals_agent_date_idx` (`agent_id`,`booking_date`),
  KEY `incentive_deals_lead_idx` (`lead_id`),
  KEY `incentive_deals_status_idx` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `incentive_payouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `incentive_payouts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint(20) unsigned NOT NULL,
  `deal_id` bigint(20) unsigned DEFAULT NULL,
  `collection_id` bigint(20) unsigned DEFAULT NULL,
  `period_key` varchar(30) NOT NULL,
  `type` varchar(40) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `incentive_payouts_agent_period_idx` (`agent_id`,`period_key`),
  KEY `incentive_payouts_deal_idx` (`deal_id`),
  KEY `incentive_payouts_type_idx` (`type`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lead_agents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lead_agents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `share_type` varchar(20) NOT NULL DEFAULT 'internal',
  `added_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_lead_agent` (`lead_id`,`agent_id`),
  KEY `idx_agent_active` (`agent_id`,`is_active`),
  KEY `idx_lead_primary` (`lead_id`,`is_primary`),
  KEY `fk_la_added_by` (`added_by_user_id`),
  CONSTRAINT `fk_la_added_by` FOREIGN KEY (`added_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_la_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_la_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=563 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lead_external_shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lead_external_shares` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint(20) unsigned NOT NULL,
  `shared_by_user_id` bigint(20) unsigned NOT NULL,
  `group_name` varchar(150) DEFAULT NULL,
  `reason_key` varchar(50) NOT NULL DEFAULT 'follow_up',
  `reason_label` varchar(150) NOT NULL,
  `extra_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_group` (`group_name`),
  KEY `idx_reason` (`reason_key`),
  KEY `idx_shared_by` (`shared_by_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=127 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lead_import_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lead_import_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL,
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `imported_rows` int(11) NOT NULL DEFAULT 0,
  `skipped_rows` int(11) NOT NULL DEFAULT 0,
  `failed_rows` int(11) NOT NULL DEFAULT 0,
  `error_log` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_import_user` (`user_id`),
  CONSTRAINT `fk_import_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lead_label_pivot`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lead_label_pivot` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint(20) unsigned NOT NULL,
  `label_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_pair` (`lead_id`,`label_id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_label` (`label_id`)
) ENGINE=InnoDB AUTO_INCREMENT=44 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lead_labels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lead_labels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `group_key` varchar(50) NOT NULL,
  `group_label` varchar(100) NOT NULL,
  `icon` varchar(20) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_key` (`key`),
  KEY `idx_group` (`group_key`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lead_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lead_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lead_status_transitions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lead_status_transitions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `from_status_id` bigint(20) unsigned NOT NULL,
  `to_status_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_transition` (`from_status_id`,`to_status_id`),
  KEY `fk_to` (`to_status_id`),
  CONSTRAINT `fk_from` FOREIGN KEY (`from_status_id`) REFERENCES `lead_statuses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_to` FOREIGN KEY (`to_status_id`) REFERENCES `lead_statuses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lead_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lead_statuses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `color` varchar(20) NOT NULL DEFAULT 'blue',
  `is_final` tinyint(1) NOT NULL DEFAULT 0,
  `terminal_type` enum('won','lost') DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `requires_datetime` tinyint(1) NOT NULL DEFAULT 0,
  `requires_booking_details` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `lead_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lead_tags` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(50) NOT NULL,
  `label` varchar(100) NOT NULL,
  `icon` varchar(20) DEFAULT NULL,
  `color` varchar(20) NOT NULL DEFAULT 'slate',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `leads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `leads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(255) NOT NULL,
  `intake_source` varchar(50) DEFAULT NULL,
  `intake_ref` varchar(255) DEFAULT NULL,
  `project_id` bigint(20) unsigned DEFAULT NULL,
  `customer_name` varchar(255) NOT NULL,
  `phone` varchar(255) NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `parent_lead_id` bigint(20) unsigned DEFAULT NULL,
  `origin_type` varchar(50) DEFAULT NULL,
  `origin_note` varchar(1000) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `pan_card` varchar(255) DEFAULT NULL,
  `budget` decimal(15,2) DEFAULT NULL,
  `status` varchar(255) DEFAULT 'new',
  `lost_reason` text DEFAULT NULL,
  `lost_reason_key` varchar(80) DEFAULT NULL,
  `previous_status` varchar(50) DEFAULT NULL,
  `agent_id` bigint(20) unsigned DEFAULT NULL,
  `tag_id` bigint(20) unsigned DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `unassigned_alerted_at` timestamp NULL DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `imported_from_batch_id` bigint(20) unsigned DEFAULT NULL,
  `visit_scheduled_at` timestamp NULL DEFAULT NULL,
  `booking_amount` decimal(15,2) DEFAULT NULL,
  `property_area_sqft` decimal(10,2) DEFAULT NULL,
  `rate_per_sqft` decimal(10,2) DEFAULT NULL,
  `booking_unit` varchar(100) DEFAULT NULL,
  `booking_payment_mode` varchar(50) DEFAULT NULL,
  `booking_date` date DEFAULT NULL,
  `brokerage_percentage` decimal(5,2) DEFAULT NULL,
  `brokerage_amount` decimal(15,2) DEFAULT NULL,
  `brokerage_status` enum('pending','invoiced','received','disputed') DEFAULT NULL,
  `brokerage_expected_at` date DEFAULT NULL,
  `brokerage_received_at` date DEFAULT NULL,
  `co_broker_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `utm_source` varchar(100) DEFAULT NULL,
  `utm_medium` varchar(100) DEFAULT NULL,
  `utm_campaign` varchar(200) DEFAULT NULL,
  `utm_content` varchar(200) DEFAULT NULL,
  `utm_term` varchar(200) DEFAULT NULL,
  `click_id` varchar(255) DEFAULT NULL,
  `referrer_url` varchar(500) DEFAULT NULL,
  `raw_payload` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `leads_phone_index` (`phone`),
  KEY `project_id` (`project_id`),
  KEY `agent_id` (`agent_id`),
  KEY `leads_customer_id_idx` (`customer_id`),
  KEY `idx_tag` (`tag_id`),
  KEY `idx_leads_lost_reason_key` (`lost_reason_key`),
  KEY `idx_leads_parent_lead_id` (`parent_lead_id`),
  CONSTRAINT `leads_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `leads_ibfk_2` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=517 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `managed_files`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `managed_files` (
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `managed_files_sha256_index` (`sha256`),
  KEY `managed_files_file_kind_index` (`file_kind`),
  KEY `managed_files_source_label_index` (`source_label`),
  KEY `managed_files_uploaded_by_user_id_index` (`uploaded_by_user_id`),
  KEY `managed_files_created_at_index` (`created_at`),
  KEY `managed_files_file_kind_created_at_index` (`file_kind`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(200) NOT NULL,
  `body` varchar(500) DEFAULT NULL,
  `icon` varchar(20) DEFAULT NULL,
  `action_url` varchar(255) DEFAULT NULL,
  `related_type` varchar(50) DEFAULT NULL,
  `related_id` bigint(20) unsigned DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_read` (`user_id`,`read_at`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6068 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `office_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `office_locations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(100) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `radius_meters` int(11) NOT NULL DEFAULT 150,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_agent`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_agent` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `priority` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_proj_agent` (`project_id`,`agent_id`),
  KEY `fk_pa_agent` (`agent_id`),
  CONSTRAINT `fk_pa_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pa_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1426 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_team`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_team` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `team_id` bigint(20) unsigned NOT NULL,
  `priority` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_proj_team` (`project_id`,`team_id`),
  KEY `fk_pt_team` (`team_id`),
  CONSTRAINT `fk_pt_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pt_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1278 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `location` varchar(255) NOT NULL,
  `tower` varchar(255) DEFAULT NULL,
  `rera_number` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2055 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `projects_import_staging`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects_import_staging` (
  `ci_project_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `location` text DEFAULT NULL,
  `rera_number` varchar(255) DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`ci_project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `push_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `push_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `endpoint` text NOT NULL,
  `p256dh` text NOT NULL,
  `auth` text NOT NULL,
  `content_encoding` varchar(50) NOT NULL DEFAULT 'aes128gcm',
  `last_notification_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `last_error_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `push_subscriptions_user_id_index` (`user_id`),
  KEY `push_subscriptions_last_notification_id_index` (`last_notification_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `salary_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `salary_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `agent_id` bigint(20) unsigned NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `monthly_salary` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(500) DEFAULT NULL,
  `created_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `salary_history_agent_date_idx` (`agent_id`,`effective_from`,`effective_to`),
  KEY `salary_history_creator_idx` (`created_by_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `value` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB AUTO_INCREMENT=54 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `site_visit_projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `site_visit_projects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `site_visit_id` bigint(20) unsigned NOT NULL,
  `project_id` bigint(20) unsigned NOT NULL,
  `outcome_key` varchar(80) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_visit_projects_visit_project_unique` (`site_visit_id`,`project_id`),
  KEY `site_visit_projects_project_id_index` (`project_id`),
  CONSTRAINT `site_visit_projects_project_fk` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`),
  CONSTRAINT `site_visit_projects_visit_fk` FOREIGN KEY (`site_visit_id`) REFERENCES `site_visits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `site_visits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `site_visits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint(20) unsigned NOT NULL,
  `recorded_by_user_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned DEFAULT NULL,
  `visit_at` datetime NOT NULL,
  `client_attended` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `site_visits_lead_id_visit_at_index` (`lead_id`,`visit_at`),
  KEY `site_visits_recorded_by_user_id_index` (`recorded_by_user_id`),
  KEY `site_visits_agent_id_index` (`agent_id`),
  CONSTRAINT `site_visits_agent_fk` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `site_visits_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `site_visits_recorded_by_user_fk` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `super_admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `super_admins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `created_by_user_id` bigint(20) unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `super_admin_user_unique` (`user_id`),
  KEY `super_admin_created_by_idx` (`created_by_user_id`),
  CONSTRAINT `super_admin_created_by_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `super_admin_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `team_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `team_id` bigint(20) unsigned NOT NULL,
  `agent_id` bigint(20) unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_team_agent` (`team_id`,`agent_id`),
  KEY `fk_tm_agent` (`agent_id`),
  CONSTRAINT `fk_tm_agent` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tm_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `teams` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `manager_user_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_team_manager` (`manager_user_id`),
  CONSTRAINT `fk_team_manager` FOREIGN KEY (`manager_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_activity_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_activity_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `session_date` date NOT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `total_active_seconds` int(11) NOT NULL DEFAULT 0,
  `page_views` int(11) NOT NULL DEFAULT 0,
  `last_url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_date` (`user_id`,`session_date`),
  KEY `idx_date` (`session_date`)
) ENGINE=InnoDB AUTO_INCREMENT=111 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_whatsapp_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_whatsapp_accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `account_type` enum('personal','business') NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_whatsapp_accounts_user_type_unique` (`user_id`,`account_type`),
  KEY `user_whatsapp_accounts_user_active_index` (`user_id`,`is_active`),
  CONSTRAINT `user_whatsapp_accounts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `role` enum('admin','agent','team_manager') NOT NULL DEFAULT 'agent',
  `is_telecaller` tinyint(1) NOT NULL DEFAULT 0,
  `is_on_payroll` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

