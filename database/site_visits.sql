-- NPO CRM — Structured Multiple Site Visits
-- 2026-09-17
-- Run this once in phpMyAdmin. No existing table/data is modified.

CREATE TABLE IF NOT EXISTS `site_visits` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `lead_id` bigint(20) UNSIGNED NOT NULL,
  `recorded_by_user_id` bigint(20) UNSIGNED NOT NULL,
  `agent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `visit_at` datetime NOT NULL,
  `client_attended` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `site_visits_lead_id_visit_at_index` (`lead_id`,`visit_at`),
  KEY `site_visits_recorded_by_user_id_index` (`recorded_by_user_id`),
  KEY `site_visits_agent_id_index` (`agent_id`),
  CONSTRAINT `site_visits_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `site_visits_recorded_by_user_fk` FOREIGN KEY (`recorded_by_user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `site_visits_agent_fk` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_visit_projects` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_visit_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `outcome_key` varchar(80) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_visit_projects_visit_project_unique` (`site_visit_id`,`project_id`),
  KEY `site_visit_projects_project_id_index` (`project_id`),
  CONSTRAINT `site_visit_projects_visit_fk` FOREIGN KEY (`site_visit_id`) REFERENCES `site_visits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `site_visit_projects_project_fk` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
