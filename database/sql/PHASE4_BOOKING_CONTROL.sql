-- NPO CRM — Phase 4 Booking Control
-- Run once in phpMyAdmin against the CRM database after replacing the PHP files.
-- This creates the booking-control ledger and backfills existing Booking leads as historical approved records.

CREATE TABLE IF NOT EXISTS `booking_controls` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `requested_by_user_id` bigint unsigned DEFAULT NULL,
  `requested_at` datetime DEFAULT NULL,
  `approved_by_user_id` bigint unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejected_by_user_id` bigint unsigned DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `decision_note` text DEFAULT NULL,
  `snapshot` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `booking_controls_lead_id_unique` (`lead_id`),
  KEY `booking_controls_status_index` (`status`),
  KEY `booking_controls_requested_by_user_id_index` (`requested_by_user_id`),
  KEY `booking_controls_approved_by_user_id_index` (`approved_by_user_id`),
  KEY `booking_controls_rejected_by_user_id_index` (`rejected_by_user_id`),
  CONSTRAINT `booking_controls_lead_id_foreign` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `booking_controls_requested_by_user_id_foreign` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `booking_controls_approved_by_user_id_foreign` FOREIGN KEY (`approved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `booking_controls_rejected_by_user_id_foreign` FOREIGN KEY (`rejected_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `booking_controls` (
  `lead_id`, `status`, `requested_by_user_id`, `requested_at`, `approved_by_user_id`, `approved_at`,
  `rejected_by_user_id`, `rejected_at`, `decision_note`, `snapshot`, `created_at`, `updated_at`
)
SELECT
  l.id,
  'approved',
  NULL,
  CASE WHEN l.booking_date IS NOT NULL THEN CONCAT(l.booking_date, ' 00:00:00') ELSE l.created_at END,
  NULL,
  NULL,
  NULL,
  NULL,
  'Historical booking backfilled as approved during Phase 4 Booking Control deployment.',
  JSON_OBJECT(
    'booking_amount', l.booking_amount,
    'property_area_sqft', l.property_area_sqft,
    'rate_per_sqft', l.rate_per_sqft,
    'booking_unit', l.booking_unit,
    'booking_payment_mode', l.booking_payment_mode,
    'booking_date', l.booking_date,
    'brokerage_percentage', l.brokerage_percentage,
    'brokerage_amount', l.brokerage_amount,
    'brokerage_status', l.brokerage_status,
    'brokerage_expected_at', l.brokerage_expected_at,
    'brokerage_received_at', l.brokerage_received_at,
    'co_broker_name', l.co_broker_name
  ),
  NOW(),
  NOW()
FROM `leads` l
LEFT JOIN `booking_controls` bc ON bc.lead_id = l.id
WHERE l.status = 'booking' AND bc.id IS NULL;
