-- NPO CRM — Contact Pitch Project / Assignment History
-- Apply manually in phpMyAdmin before deploying the PHP/UI package.
-- Safe to run once; table creation is guarded.

CREATE TABLE IF NOT EXISTS `contact_project_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `contact_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `assigned_to_agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `assigned_by_user_id` bigint(20) UNSIGNED NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing contacts.project_id remains as the current pitch-project compatibility field.
-- New assignments are the authoritative history. The application synchronizes the
-- current active assignment into contacts.project_id / assigned_to_agent_id.
